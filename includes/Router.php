<?php
/**
 * Front-end router: detects requests to a test URL and rewrites the WP query
 * to load the variant post. The visitor's URL bar stays on the test URL.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Router {

	private static ?self $instance = null;

	private ?\WP_Post $current_experiment = null;
	private string $current_variant       = 'A';
	private string $current_test_url      = '';
	private bool $current_is_tracked      = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// parse_request fires before WP resolves query_vars to a post.
		// Priority 1 keeps us ahead of most rewriting plugins.
		add_action( 'parse_request', [ $this, 'maybe_route' ], 1 );

		// Force the canonical link to point at the test URL, not the served post.
		add_filter( 'get_canonical_url', [ $this, 'filter_canonical_url' ], 10, 2 );
		add_filter( 'wpseo_canonical', [ $this, 'filter_canonical_string' ] );
		add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_canonical_string' ] );

		// Don't let WP redirect /promo/ to the served post's permalink.
		add_filter( 'redirect_canonical', [ $this, 'maybe_block_canonical_redirect' ], 10, 2 );
	}

	public function maybe_route( \WP $wp ): void {
		if ( is_admin() ) {
			return;
		}

		$path = $this->extract_path_from_request( $wp );
		if ( '' === $path ) {
			return;
		}

		$experiment = Experiment::find_running_for_url( $path );
		if ( null === $experiment ) {
			return;
		}

		$bypass         = $this->should_bypass();
		// A cache-diagnostic probe (admin's browser checking whether this URL is cached)
		// must render fresh so it reveals the cache + gets the X-Abtest-Bypass header, but
		// it must NOT count: no impression, no variant cookie, no cache-resilient redirect.
		$is_probe       = CacheBypass::is_cache_probe();
		$has_underlying = $this->url_resolves_to_public_page( $path );
		$preview        = $this->read_preview_param();
		$labels         = Experiment::get_variant_labels( $experiment->ID ); // baseline = single label A, multi = A B C…
		$has_variant_b  = in_array( 'B', $labels, true );

		// Targeting check. Admin/bot bypass always passes — preview is independent of
		// the previewer's device/country. For out-of-target visitors :
		// - if URL has an underlying public page, fall through to WP's normal render;
		// - otherwise (custom URL with no real page), serve the baseline variant A
		// SILENTLY: no cookie set, no impression logged, no tracker.js enqueued.
		// This way Adwords/Lemlist clicks from outside the target audience still see a
		// real page (no 404), but they don't pollute the experiment's stats.
		$out_of_target = ! $bypass && ! Targeting::matches( $experiment->ID );

		// Consent gate (RGPD) : if the admin enabled "Require consent" and no consent
		// signal is detected via the `abtest_visitor_has_consent` filter, treat the
		// visitor exactly like out-of-target — silent baseline, no cookie, no event.
		// Bypass (admins/bots) is exempt so previews still work without a consent banner.
		$consent_blocked = ! $bypass && Consent::is_blocked();
		$out_of_target   = $out_of_target || $consent_blocked;

		if ( $out_of_target && $has_underlying ) {
			return;
		}

		// If we're bypassing AND no explicit preview param AND the URL has an underlying public page,
		// step out and let WP serve that page (admins see the original content as visitors did).
		// If the URL is custom (no real page behind), we MUST still serve a variant — otherwise
		// admins get a 404 on URLs that don't exist as posts.
		if ( $bypass && '' === $preview && $has_underlying ) {
			$this->expose_admin_marker( $experiment, $path, $has_underlying, $has_variant_b, 'original' );
			return;
		}
		// Explicit "?abtest_preview=original" on an URL with an underlying page: also fall through.
		if ( $bypass && 'original' === $preview && $has_underlying ) {
			$this->expose_admin_marker( $experiment, $path, $has_underlying, $has_variant_b, 'original' );
			return;
		}

		// Variant pick:
		// - Out-of-target: force baseline (A), don't touch the cookie.
		// - Bypass (admin/bot): force the previewed label if valid, else first label (A).
		// - Baseline mode (only one variant): everyone gets it; cookie set so conversion endpoint works.
		// - Standard multi-variant: persistent cookie, uniform-random pick from configured labels.
		if ( $out_of_target ) {
			$variant = $labels[0] ?? 'A';
		} elseif ( $bypass ) {
			$preview_upper = strtoupper( $preview );
			$variant       = in_array( $preview_upper, $labels, true ) ? $preview_upper : ( $labels[0] ?? 'A' );
		} elseif ( count( $labels ) <= 1 ) {
			$variant = $labels[0] ?? 'A';
			if ( ! $is_probe && null === Cookie::get_variant( $experiment->ID, $labels ) ) {
				Cookie::set_variant( $experiment->ID, $variant );
			}
		} else {
			$variant = Cookie::get_variant( $experiment->ID, $labels );
			if ( null === $variant ) {
				$variant = Cookie::pick_variant( $labels );
				if ( ! $is_probe ) {
					Cookie::set_variant( $experiment->ID, $variant );
				}
			}
		}

		$variant_post_id = Experiment::get_variant_post_id( $experiment->ID, $variant );
		if ( $variant_post_id <= 0 ) {
			return;
		}

		$variant_post = get_post( $variant_post_id );
		if ( ! $variant_post instanceof \WP_Post ) {
			return;
		}

		// Rewrite query_vars so WP loads the variant post natively.
		// Clear pagename/name from any matched rewrite rule so they don't fight ours.
		$wp->query_vars = array_merge(
			$wp->query_vars,
			[
				( 'page' === $variant_post->post_type ? 'page_id' : 'p' ) => $variant_post_id,
				'post_type' => $variant_post->post_type,
			]
		);
		unset( $wp->query_vars['pagename'], $wp->query_vars['name'], $wp->query_vars['error'] );

		$this->current_experiment  = $experiment;
		$this->current_variant     = $variant;
		$this->current_test_url    = Experiment::get_test_url( $experiment->ID );
		// Tracked = the visitor counts in the test stats. Out-of-target, admin/bot
		// bypass, and cache-diagnostic probes do NOT count: they see a variant but no
		// impression/conversion is logged.
		$this->current_is_tracked  = ! $bypass && ! $out_of_target && ! $is_probe;

		// Send no-cache headers IMMEDIATELY on every test page so caches at any layer
		// (server, plugin, edge CDN like Cloudflare/Kinsta) never store this response.
		// Critical for the 50/50 split to work in production.
		CacheBypass::send_no_cache_headers();

		// Variant pages live in `private` status to hide them from public direct access.
		// When OUR router serves them, allow that status in the main query and unblock
		// post visibility checks that would otherwise 404 for non-logged-in visitors.
		add_action(
			'pre_get_posts',
			function ( \WP_Query $query ) use ( $variant_post_id ) {
				if ( ! $query->is_main_query() ) {
					return;
				}
				if ( (int) $query->get( 'page_id' ) === $variant_post_id || (int) $query->get( 'p' ) === $variant_post_id ) {
					$query->set( 'post_status', [ 'publish', 'private' ] );
					$query->is_404 = false;
				}
			},
			1
		);

		// Final safety net: if WP_Query still returned empty (private post visibility),
		// inject the variant post directly so the request renders 200, not 404.
		add_filter(
			'posts_results',
			function ( $posts, \WP_Query $query ) use ( $variant_post, $variant_post_id ) {
				if ( ! $query->is_main_query() ) {
					return $posts;
				}
				$queried_id = (int) $query->get( 'page_id' ) ?: (int) $query->get( 'p' );
				if ( $queried_id !== $variant_post_id ) {
					return $posts;
				}
				if ( empty( $posts ) ) {
					$query->is_404           = false;
					$query->is_singular      = true;
					$query->is_page          = ( 'page' === $variant_post->post_type );
					$query->is_single        = ( 'page' !== $variant_post->post_type );
					$query->queried_object   = $variant_post;
					$query->queried_object_id = $variant_post_id;
					return [ $variant_post ];
				}
				return $posts;
			},
			10,
			2
		);

		// Inject per-URL tracking scripts (Adwords / pixels / Lemlist) on themed pages.
		// Blank Canvas pages handle their own injection inside the template.
		add_action( 'wp_body_open', [ $this, 'print_scripts_after_body_open' ], 1 );
		add_action( 'wp_footer', [ $this, 'print_scripts_before_body_close' ], 99 );

		// Log impression once per request — but never for bypassed admins/bots, nor for
		// out-of-target visitors (they see the baseline silently, not part of the test).
		if ( $this->current_is_tracked ) {
			add_action(
				'wp',
				function () use ( $experiment, $variant ) {
					Tracker::instance()->log_impression( $experiment->ID, $variant, $this->current_test_url );
				},
				1
			);
		} elseif ( $bypass ) {
			$this->expose_admin_marker( $experiment, $path, $has_underlying, $has_variant_b, $variant );
		}
		// out_of_target: no impression, no admin marker — visitor silently sees baseline.

		add_filter( 'abtest_current_experiment', fn() => $experiment );
		add_filter( 'abtest_current_variant', fn() => $variant );
	}

	/**
	 * Extract the request URL (path + query) and normalize it.
	 * Includes the query string so query-param-targeted experiments
	 * (e.g. test_url=/promo/?campaign=fb) can match.
	 *
	 * The final normalized path passes through the `abtest_request_path` filter
	 * so callers can rewrite it before the matcher runs. The plugin auto-uses
	 * this hook to strip WPML / Polylang language prefixes (`/fr/promo/` →
	 * `/promo/`) so a single experiment applies across translations.
	 */
	private function extract_path_from_request( \WP $wp ): string {
		$path = isset( $wp->request ) && '' !== $wp->request
			? '/' . trim( (string) $wp->request, '/' ) . '/'
			: '';

		if ( '' === $path && isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$parsed = wp_parse_url( $uri, PHP_URL_PATH );
			$path   = is_string( $parsed ) ? $parsed : '';
		}

		// Append the request's query string so subset-matching can run.
		$query = isset( $_SERVER['QUERY_STRING'] )
			? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) )
			: '';
		if ( '' !== $query ) {
			$path .= '?' . $query;
		}

		$normalized = Experiment::normalize_path( $path );

		/**
		 * Filter the normalized request path before the experiment matcher runs.
		 *
		 * Used by the bundled MultiLanguage helper to strip WPML / Polylang
		 * language prefixes. Custom multilingual setups can wire their own logic.
		 *
		 * @param string $normalized Path with leading/trailing slashes, lowercased,
		 *                           query string canonicalized.
		 */
		return (string) apply_filters( 'abtest_request_path', $normalized );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post is part of the get_canonical_url filter signature
	public function filter_canonical_url( $url, $post ) {
		if ( null === $this->current_experiment || '' === $this->current_test_url ) {
			return $url;
		}
		return home_url( $this->current_test_url );
	}

	public function filter_canonical_string( $url ) {
		if ( null === $this->current_experiment || '' === $this->current_test_url ) {
			return $url;
		}
		return home_url( $this->current_test_url );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $requested_url is part of the redirect_canonical filter signature
	public function maybe_block_canonical_redirect( $redirect_url, $requested_url ) {
		if ( null !== $this->current_experiment ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * True if this path resolves to a published, public post that WP can serve on its own.
	 * Used to decide whether bypass mode should fall through to WP's normal rendering.
	 */
	private function url_resolves_to_public_page( string $path ): bool {
		$slug = trim( $path, '/' );
		if ( '' === $slug ) {
			return false;
		}
		$post = get_page_by_path( $slug, OBJECT, [ 'page', 'post' ] );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return 'publish' === $post->post_status;
	}

	/**
	 * Output admin-curated tracking scripts (Adwords / FB Pixel / Lemlist) at the
	 * `wp_body_open` slot. Goes through {@see UrlScripts::print_for_position()}
	 * which wraps each entry via wp_print_inline_script_tag() — the WP-blessed
	 * inline-script helper. Trust model gate is at intake:
	 * Admin\Admin::handle_save() requires `manage_options` + nonce +
	 * `unfiltered_html` before persisting any code body.
	 */
	public function print_scripts_after_body_open(): void {
		if ( '' === $this->current_test_url ) {
			return;
		}
		UrlScripts::print_for_position( $this->current_test_url, UrlScripts::POSITION_AFTER_BODY_OPEN );
	}

	/**
	 * Same as {@see print_scripts_after_body_open()} but rendered just before `</body>`.
	 */
	public function print_scripts_before_body_close(): void {
		if ( '' === $this->current_test_url ) {
			return;
		}
		UrlScripts::print_for_position( $this->current_test_url, UrlScripts::POSITION_BEFORE_BODY_CLOSE );
	}

	private function should_bypass(): bool {
		$settings = (array) get_option( 'abtest_settings', [] );

		if ( ! empty( $settings['bypass_admins'] ) && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		if ( ! empty( $settings['bypass_bots'] ) && $this->is_bot() ) {
			return true;
		}

		return false;
	}

	private function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|fetch|monitor|preview/i', $ua );
	}

	/**
	 * Read & sanitize the ?abtest_preview= query param. Returns '' if absent/invalid.
	 * Accepts: 'a', 'b', 'original'.
	 */
	private function read_preview_param(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['abtest_preview'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		$value = sanitize_key( wp_unslash( (string) $_GET['abtest_preview'] ) );
		return in_array( $value, [ 'a', 'b', 'original' ], true ) ? $value : '';
	}

	/**
	 * Build a URL on the test path with a preview parameter set.
	 */
	private function preview_url( string $path, string $preview ): string {
		$base = home_url( $path );
		return add_query_arg( 'abtest_preview', $preview, $base );
	}

	/**
	 * @param \WP_Post $experiment      The matched experiment.
	 * @param string   $path            Current normalized request path.
	 * @param bool     $has_underlying  True when the URL also resolves to an existing public page.
	 * @param bool     $has_variant_b   True when the experiment defines a variant B (multi-variant).
	 * @param string   $current_view    Either 'A', 'B' or 'original' — what the admin is currently seeing.
	 */
	private function expose_admin_marker( \WP_Post $experiment, string $path, bool $has_underlying, bool $has_variant_b, string $current_view ): void {
		$current_view = strtoupper( $current_view ) === 'ORIGINAL' ? 'original' : strtoupper( $current_view );

		add_action(
			'admin_bar_menu',
			function ( $bar ) use ( $experiment, $path, $has_underlying, $has_variant_b, $current_view ) {
				if ( ! current_user_can( 'edit_posts' ) ) {
					return;
				}

				$label_map = [
					'A'        => __( 'Variant A', 'variolab-ab-testing' ),
					'B'        => __( 'Variant B', 'variolab-ab-testing' ),
					'original' => __( 'Original page', 'variolab-ab-testing' ),
				];
				$current_label = $label_map[ $current_view ] ?? $current_view;

				$mode_suffix = $has_variant_b ? '' : ' · ' . __( 'baseline', 'variolab-ab-testing' );

				$bar->add_node(
					[
						'id'    => 'abtest-preview',
						'title' => sprintf(
							/* translators: 1: experiment title, 2: current variant label, 3: mode suffix */
							esc_html__( 'A/B: %1$s — viewing %2$s%3$s', 'variolab-ab-testing' ),
							esc_html( get_the_title( $experiment ) ),
							esc_html( $current_label ),
							esc_html( $mode_suffix )
						),
						'href'  => '#',
						'meta'  => [ 'class' => 'abtest-admin-bar-' . sanitize_html_class( strtolower( $current_view ) ) ],
					]
				);

				$variants = [
					'a' => [ 'label' => __( 'View Variant A', 'variolab-ab-testing' ), 'view' => 'A' ],
				];
				if ( $has_variant_b ) {
					$variants['b'] = [ 'label' => __( 'View Variant B', 'variolab-ab-testing' ), 'view' => 'B' ];
				}
				if ( $has_underlying ) {
					$variants['original'] = [ 'label' => __( 'View original page', 'variolab-ab-testing' ), 'view' => 'original' ];
				}

				foreach ( $variants as $key => $opt ) {
					$is_current = ( $opt['view'] === $current_view );
					$bar->add_node(
						[
							'parent' => 'abtest-preview',
							'id'     => 'abtest-preview-' . $key,
							'title'  => $is_current
								? sprintf( '✓ %s', esc_html( $opt['label'] ) )
								: esc_html( $opt['label'] ),
							'href'   => $is_current ? '#' : $this->preview_url( $path, $key ),
						]
					);
				}

				$bar->add_node(
					[
						'parent' => 'abtest-preview',
						'id'     => 'abtest-preview-edit',
						'title'  => esc_html__( 'Edit experiment', 'variolab-ab-testing' ),
						'href'   => admin_url( 'admin.php?page=ab-testing&action=edit&experiment=' . $experiment->ID ),
					]
				);
			},
			999
		);
	}

	public function get_current_experiment(): ?\WP_Post {
		return $this->current_experiment;
	}

	public function get_current_variant(): string {
		return $this->current_variant;
	}

	public function get_current_test_url(): string {
		return $this->current_test_url;
	}

	/**
	 * True when the current request is being tracked as part of the experiment
	 * (vs. served silently because the visitor is out-of-target or in admin/bot bypass).
	 * Used by Tracker to decide whether to enqueue the front-end conversion script.
	 */
	public function is_current_tracked(): bool {
		return $this->current_is_tracked;
	}
}

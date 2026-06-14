<?php
/**
 * Cache bypass — ensures pages under A/B test are NEVER served from a cache.
 *
 * Strategies (combined):
 *   1. Send `Cache-Control: no-store` headers from Router on every test page response.
 *      Universal — respected by Kinsta, Cloudflare, Varnish, and most server caches.
 *   2. Hook each known cache plugin's URL-rejection API where it exists (WP Rocket,
 *      LiteSpeed) so the plugin self-excludes the test URLs.
 *   3. Surface a host-aware admin notice so the user knows what to verify (Kinsta
 *      Cache Bypass, WP Rocket exclusion list, etc.).
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class CacheBypass {

	private const KNOWN_CACHE_PLUGINS = [
		'wp-rocket/wp-rocket.php'             => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
		'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
	];

	/**
	 * Wire URL-level exclusion filters for cache plugins. Called once at boot.
	 */
	public static function register(): void {
		// WP Rocket: filter that takes an array of regex patterns to never cache.
		add_filter( 'rocket_cache_reject_uri', [ self::class, 'add_test_urls_to_rejection_list' ] );

		// LiteSpeed: filter that takes URL patterns to mark non-cacheable.
		add_filter( 'litespeed_force_nocache_url', [ self::class, 'add_test_urls_to_rejection_list' ] );

		// Cache-resilient mode (opt-in): inject a client-side cache-buster redirect
		// HIGH in <head> on test pages, for server/edge caches we can't exclude via a
		// plugin API (Cloudways/Varnish, generic nginx page caches). Blank Canvas pages
		// inject it in their own template; this covers themed pages.
		add_action( 'wp_head', [ self::class, 'print_cache_buster' ], 0 );

		// No top-of-page admin notice: the caching diagnostic + host-specific guidance
		// now live in the "Cache check" box on the A/B Tests list (see ExperimentsList),
		// right next to the per-URL pills they refer to. Detection helpers below
		// (detect_active_plugin / is_kinsta / is_cloudflare) feed that box.
	}

	/**
	 * Whether the opt-in cache-resilient mode is enabled in settings.
	 */
	public static function is_resilient_mode(): bool {
		$settings = (array) get_option( 'abtest_settings', [] );
		return ! empty( $settings['cache_resilient'] );
	}

	/**
	 * Whether the current request is a cache-diagnostic probe.
	 *
	 * The admin's browser sends `X-Abtest-Cache-Check: 1` (a custom request header,
	 * which does NOT change the cache key) when checking whether a test URL is being
	 * cached. The Router uses this to skip impression logging + variant cookie, and
	 * {@see cache_buster_script_tag()} uses it to skip the redirect — so the probe
	 * reaches the origin and reveals the real cache state without polluting stats.
	 */
	public static function is_cache_probe(): bool {
		return ! empty( $_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- presence check only; value never used.
	}

	/**
	 * Build the cache-buster `<script>` for the current request, or '' when it
	 * should not run.
	 *
	 * When the page is served from a cache we can't exclude (Varnish, a generic
	 * server/edge cache), WordPress never runs on the page load — so the variant is
	 * frozen for everyone and no impression is logged. This tiny script, placed high
	 * in the document, redirects a cache-served page to a one-time unique URL
	 * (`?_abtcb=…`) that the cache can't have stored, forcing a fresh server render
	 * (correct 50/50 split + impression). On the fresh URL the param is already
	 * present, so it is a no-op (no loop). Skipped for logged-in users (they bypass
	 * server caches) and when there is no experiment on the URL.
	 */
	public static function cache_buster_script_tag(): string {
		if ( self::is_cache_probe() ) {
			return ''; // The diagnostic probe must reach the origin, never be redirected.
		}
		if ( ! self::is_resilient_mode() ) {
			return '';
		}
		if ( null === Router::instance()->get_current_experiment() ) {
			return '';
		}
		if ( is_user_logged_in() ) {
			return '';
		}

		$js = "(function(){try{var P='_abtcb',u=new URL(window.location.href);"
			. 'if(!u.searchParams.has(P)){u.searchParams.set(P,Date.now().toString(36)+Math.random().toString(36).slice(2,8));'
			. 'window.location.replace(u.toString());}}catch(e){}})();';

		return wp_get_inline_script_tag( $js, [ 'id' => 'abtest-cache-buster' ] );
	}

	/**
	 * Output the cache-buster on themed pages (wp_head). Blank Canvas injects via
	 * the same builder inside its template.
	 */
	public static function print_cache_buster(): void {
		$tag = self::cache_buster_script_tag();
		if ( '' !== $tag ) {
			echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() output is already a safe script tag.
		}
	}

	/**
	 * Send no-cache headers on the current response. Called by Router when
	 * routing an experiment, so the response is never cached at any layer.
	 */
	public static function send_no_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		// Belt and suspenders: explicit Cache-Control for edge CDNs that ignore some flags.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
		header( 'Pragma: no-cache', true );
		// Custom marker for debugging on the wire.
		header( 'X-Abtest-Bypass: 1', true );
	}

	/**
	 * Filter callback for both rocket_cache_reject_uri and litespeed_force_nocache_url.
	 * Both expect an array of regex-like patterns relative to the site root.
	 *
	 * @param array $patterns
	 * @return array
	 */
	public static function add_test_urls_to_rejection_list( $patterns ): array {
		if ( ! is_array( $patterns ) ) {
			$patterns = [];
		}
		foreach ( self::get_running_test_urls() as $url ) {
			// Both plugins want regex; we anchor on start and end-of-line for safety.
			$patterns[] = '^' . preg_quote( $url, '/' ) . '$';
		}
		return $patterns;
	}

	/**
	 * @return string[] List of URL paths (e.g. "/promo/") with at least one running experiment.
	 */
	public static function get_running_test_urls(): array {
		$post_ids = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => Experiment::META_STATUS,
						'value' => Experiment::STATUS_RUNNING,
					],
				],
			]
		);
		$urls = [];
		foreach ( $post_ids as $id ) {
			$url = (string) get_post_meta( (int) $id, Experiment::META_TEST_URL, true );
			if ( '' !== $url ) {
				$urls[ $url ] = true; // de-duplicate
			}
		}
		return array_keys( $urls );
	}

	/**
	 * A random published page/post URL that is NOT part of any A/B test, used as the
	 * "classic page" cache baseline (it SHOULD be cached, unlike test pages). Excludes
	 * experiment variant pages and Watcher-managed imports. Falls back to the site
	 * home URL when nothing else qualifies.
	 */
	public static function random_classic_url(): string {
		$variant_ids = [];
		$experiments = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			]
		);
		foreach ( $experiments as $exp_id ) {
			foreach ( Experiment::get_variants( (int) $exp_id ) as $variant ) {
				$variant_ids[] = (int) $variant['post_id'];
			}
		}

		$candidates = get_posts(
			[
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'rand',
				'fields'         => 'ids',
				'post__not_in'   => $variant_ids,
				'meta_query'     => [
					[
						'key'     => '_abtest_watcher_slug',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		if ( ! empty( $candidates ) ) {
			$url = get_permalink( (int) $candidates[0] );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	public static function detect_active_plugin(): ?string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( self::KNOWN_CACHE_PLUGINS as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				return $label;
			}
		}
		return null;
	}

	/**
	 * Detect that the current request came through Cloudflare (edge cache / CDN).
	 */
	public static function is_cloudflare(): bool {
		return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * Detect Kinsta hosting environment (edge cache via Cloudflare + nginx page cache).
	 */
	public static function is_kinsta(): bool {
		if ( defined( 'KINSTA_CACHE_ZONE' ) || defined( 'KINSTAMU_VERSION' ) ) {
			return true;
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR . '/kinsta-mu-plugins' ) ) {
			return true;
		}
		return false;
	}
}

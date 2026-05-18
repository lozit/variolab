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

		// Admin notice (one-time per page load) when a cache plugin is detected.
		add_action( 'admin_notices', [ self::class, 'maybe_render_notice' ] );
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
	private static function get_running_test_urls(): array {
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

	public static function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::has_running_experiment() ) {
			return;
		}

		$plugin = self::detect_active_plugin();
		$kinsta = self::is_kinsta();

		if ( null === $plugin && ! $kinsta ) {
			return;
		}

		$messages = [];

		if ( null !== $plugin ) {
			if ( in_array( $plugin, [ 'WP Rocket', 'LiteSpeed Cache' ], true ) ) {
				$messages[] = sprintf(
					/* translators: %s: plugin name */
					esc_html__( '%s detected — running experiments are auto-excluded from cache.', 'variolab-ab-testing' ),
					esc_html( $plugin )
				);
			} else {
				$messages[] = sprintf(
					/* translators: %s: plugin name */
					esc_html__( '%s detected. No automatic exclusion API for this plugin — manually add your test URLs to its cache exclusion list, otherwise all visitors will see the same variant.', 'variolab-ab-testing' ),
					esc_html( $plugin )
				);
			}
		}

		if ( $kinsta ) {
			$messages[] = sprintf(
				/* translators: %s: link to Kinsta cache bypass docs */
				esc_html__( 'Kinsta hosting detected. We send no-store headers on every test page so the edge cache should bypass them. For 100%% safety, also add your test URLs to %s.', 'variolab-ab-testing' ),
				'<a href="https://kinsta.com/help/cache-control-bypass/" target="_blank" rel="noopener">MyKinsta → Tools → Cache → Cache Bypass</a>'
			);
		}

		$allowed_tags = [
			'br'     => [],
			'strong' => [],
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
			],
		];
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'A/B Testing — caching:', 'variolab-ab-testing' ),
			wp_kses( implode( '<br>', $messages ), $allowed_tags )
		);
	}

	private static function has_running_experiment(): bool {
		$running = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => Experiment::META_STATUS,
						'value' => Experiment::STATUS_RUNNING,
					],
				],
			]
		);
		return ! empty( $running );
	}
}

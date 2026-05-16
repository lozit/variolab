<?php
/**
 * Per-URL flags shared across every experiment that runs on the same URL.
 *
 * Today's only flag : `noindex` — emit `<meta name="robots" content="noindex,nofollow">`
 * AND a matching `X-Robots-Tag` HTTP header on every request to the URL, so
 * search engines never index dedicated test landing pages (paid traffic only,
 * variant content, etc.) regardless of which experiment is currently running.
 *
 * Storage : a single WP option `abtest_url_settings` keyed by URL path. Empty
 * settings (all defaults) are pruned so the option doesn't grow unbounded.
 *
 * @package Abtest
 */

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class UrlSettings {

	public const OPTION_KEY = 'abtest_url_settings';

	/** Default value for every supported flag. */
	private const DEFAULTS = [
		'noindex' => false,
	];

	public static function register(): void {
		// On every front-end request, decide if the current URL is no-indexed
		// and, if so, hook the robots meta tag + X-Robots-Tag header.
		add_action( 'send_headers', [ self::class, 'maybe_send_noindex_header' ] );
		add_filter( 'wp_robots', [ self::class, 'maybe_filter_robots_meta' ] );
	}

	/**
	 * Get the settings for a given URL. Always returns an array with every
	 * default key filled in — callers can `(bool) $settings['noindex']` safely.
	 *
	 * @return array{noindex: bool}
	 */
	public static function get( string $url ): array {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return self::DEFAULTS;
		}
		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) || ! isset( $all[ $url ] ) || ! is_array( $all[ $url ] ) ) {
			return self::DEFAULTS;
		}
		return [
			'noindex' => ! empty( $all[ $url ]['noindex'] ),
		];
	}

	/**
	 * Persist the settings for a URL. If every flag is at its default value,
	 * the entry is removed from the option so the storage stays compact.
	 *
	 * @param string                $url      Test URL path (will be normalized).
	 * @param array{noindex?: bool} $settings New settings for that URL.
	 */
	public static function set( string $url, array $settings ): void {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return;
		}
		$clean = [
			'noindex' => ! empty( $settings['noindex'] ),
		];

		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		if ( self::DEFAULTS === $clean ) {
			unset( $all[ $url ] );
		} else {
			$all[ $url ] = $clean;
		}

		update_option( self::OPTION_KEY, $all );
	}

	/** Convenience accessor used by both the Router runtime and the admin UI. */
	public static function is_noindex( string $url ): bool {
		return (bool) self::get( $url )['noindex'];
	}

	/**
	 * Send `X-Robots-Tag: noindex, nofollow` when the current request hits a
	 * URL flagged no-index. Hooked on `send_headers` so it fires before the
	 * page body is built.
	 */
	public static function maybe_send_noindex_header(): void {
		if ( ! self::current_url_is_noindex() ) {
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	/**
	 * Inject `noindex, nofollow` into WP's robots meta tag when the current
	 * request hits a URL flagged no-index. WP's `wp_robots` filter (since 5.7)
	 * is the modern way to add directives — `wp_no_robots()` is deprecated.
	 *
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public static function maybe_filter_robots_meta( $robots ) {
		if ( ! is_array( $robots ) || ! self::current_url_is_noindex() ) {
			return $robots;
		}
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	/**
	 * Resolve whether the current request is on a URL the admin marked as
	 * no-index. Path extraction mirrors Router::extract_path_from_request()
	 * so the URL key matches what the admin sees in the experiment form.
	 */
	private static function current_url_is_noindex(): bool {
		if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$parsed = wp_parse_url( $uri, PHP_URL_PATH );
		$path   = is_string( $parsed ) ? $parsed : '';
		if ( '' === $path ) {
			return false;
		}
		return self::is_noindex( $path );
	}
}

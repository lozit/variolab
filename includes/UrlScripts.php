<?php
/**
 * Per-URL tracking scripts (Adwords / pixels / Lemlist beacons).
 *
 * Storage: a single WP option `abtest_url_scripts` keyed by URL path.
 * Each URL maps to a list of { position, code } entries.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class UrlScripts {

	public const OPTION_KEY = 'abtest_url_scripts';

	public const POSITION_AFTER_BODY_OPEN   = 'after_body_open';
	public const POSITION_BEFORE_BODY_CLOSE = 'before_body_close';

	public static function valid_positions(): array {
		return [ self::POSITION_AFTER_BODY_OPEN, self::POSITION_BEFORE_BODY_CLOSE ];
	}

	/**
	 * Get the list of scripts configured for a given URL path.
	 *
	 * @return array<int, array{position:string, code:string}>
	 */
	public static function get( string $url ): array {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return [];
		}
		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) || ! isset( $all[ $url ] ) || ! is_array( $all[ $url ] ) ) {
			return [];
		}
		// Defensive normalization.
		$out = [];
		foreach ( $all[ $url ] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$position = isset( $entry['position'] ) ? (string) $entry['position'] : '';
			$code     = isset( $entry['code'] ) ? (string) $entry['code'] : '';
			if ( ! in_array( $position, self::valid_positions(), true ) || '' === trim( $code ) ) {
				continue;
			}
			$out[] = [ 'position' => $position, 'code' => $code ];
		}
		return $out;
	}

	/**
	 * Replace the full list of scripts for a URL. Empty list deletes the entry.
	 *
	 * @param string                                            $url     Test URL path.
	 * @param array<int, array{position:string, code:string}>  $scripts List of script entries (position + raw code).
	 */
	public static function set( string $url, array $scripts ): void {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return;
		}

		$clean = [];
		foreach ( $scripts as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$position = isset( $entry['position'] ) ? (string) $entry['position'] : '';
			$code     = isset( $entry['code'] ) ? (string) $entry['code'] : '';
			if ( ! in_array( $position, self::valid_positions(), true ) ) {
				continue;
			}
			if ( '' === trim( $code ) ) {
				continue;
			}
			$clean[] = [ 'position' => $position, 'code' => $code ];
		}

		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		if ( empty( $clean ) ) {
			unset( $all[ $url ] );
		} else {
			$all[ $url ] = $clean;
		}

		// wp_slash() the values so wp_unslash inside update_option doesn't strip backslashes
		// from JS regex / JSON payloads in the script bodies.
		update_option( self::OPTION_KEY, wp_slash( $all ) );
	}

	/**
	 * Print every script entry for the given URL+position, wrapped via
	 * {@see wp_print_inline_script_tag()} — the WP-blessed inline-script
	 * helper. Each entry's stored body is run through {@see parse_script_input()}
	 * which silently strips any `<script ...>` / `</script>` the admin pasted,
	 * extracting `src` / `async` / `defer` / `type` / `id` attributes if present
	 * so the final tag matches what was originally pasted.
	 *
	 * Why not `echo`: passing through wp_print_inline_script_tag avoids tripping
	 * `WordPress.Security.EscapeOutput.OutputNotEscaped` (we no longer raw-echo
	 * variables) and signals intent to wp.org Plugin Check scanners.
	 */
	public static function print_for_position( string $url, string $position ): void {
		$scripts = self::get( $url );
		foreach ( $scripts as $s ) {
			if ( $s['position'] !== $position ) {
				continue;
			}
			$parsed = self::parse_script_input( $s['code'] );
			wp_print_inline_script_tag( $parsed['body'], $parsed['attrs'] );
		}
	}

	/**
	 * Normalize a stored script entry into a { body, attrs } shape compatible
	 * with {@see wp_print_inline_script_tag()}.
	 *
	 * Cases handled:
	 *   - Plain JS body (no wrapper):        body = input, attrs = []
	 *   - `<script>JS</script>`:             body = JS, attrs = []
	 *   - `<script async src="...">`:        body = '', attrs = [src, async]
	 *   - `<script type="...">JSON</script>`: body = JSON, attrs = [type]
	 *
	 * Multi-`<script>` snippets degrade to "naive strip everything" — the admin
	 * should split such snippets across multiple entries (one entry per script
	 * tag) for clean attribute preservation.
	 *
	 * @return array{body:string, attrs:array<string,string|bool>}
	 */
	public static function parse_script_input( string $code ): array {
		$code = trim( $code );

		// Single `<script ...>...</script>` wrapper — extract attrs + body.
		// `.*` is greedy so on multi-script input it would capture the inner
		// `</script><script>...` sequence; we sanity-check the body doesn't
		// still contain a `<script>` tag and fall through to the degraded
		// strip mode below if it does.
		if ( preg_match( '#^<script\b([^>]*)>(.*)</script>\s*$#is', $code, $m ) ) {
			$body = $m[2];
			if ( false === stripos( $body, '<script' ) && false === stripos( $body, '</script>' ) ) {
				$attrs = self::parse_script_attrs( trim( $m[1] ) );
				return [ 'body' => $body, 'attrs' => $attrs ];
			}
		}

		// Mixed / multi-script / orphan opening tag — strip every `<script ...>` and `</script>`
		// from the input. Loses individual attributes (degraded mode) but stays safe.
		if ( preg_match( '#</?script\b#i', $code ) ) {
			$body = (string) preg_replace( '#<script\b[^>]*>|</script>#i', '', $code );
			return [ 'body' => trim( $body ), 'attrs' => [] ];
		}

		// Plain JS body — passthrough.
		return [ 'body' => $code, 'attrs' => [] ];
	}

	/**
	 * Parse a `<script>`-opening-tag attribute string into a key/value array
	 * suitable for wp_print_inline_script_tag's $attributes argument.
	 */
	private static function parse_script_attrs( string $attrs_str ): array {
		$attrs = [];
		// Match quoted key/value pairs (double or single quotes).
		if ( preg_match_all( '#(\w[\w-]*)\s*=\s*(["\'])([^"\']*)\2#', $attrs_str, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attrs[ strtolower( $match[1] ) ] = $match[3];
			}
		}
		// Boolean attributes (async, defer, nomodule) appearing as bare words.
		foreach ( [ 'async', 'defer', 'nomodule' ] as $bool_attr ) {
			if ( preg_match( '#\b' . $bool_attr . '\b(?![=-])#i', $attrs_str ) && ! isset( $attrs[ $bool_attr ] ) ) {
				$attrs[ $bool_attr ] = true;
			}
		}
		return $attrs;
	}
}

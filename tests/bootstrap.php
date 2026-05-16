<?php
/**
 * Bootstrap for unit tests — does NOT load WordPress.
 *
 * For the few WP constants/functions our code touches, we stub them here so
 * pure-logic classes (Stats, Cookie::pick_variant) can be tested in isolation.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

// Define ABSPATH so plugin files don't exit on load.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WordPress time constants used by Cookie.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'COOKIEPATH' ) ) {
	define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
	define( 'COOKIE_DOMAIN', '' );
}

if ( ! defined( 'ABTEST_PLUGIN_DIR' ) ) {
	define( 'ABTEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Minimal stubs for the WP functions used by pure-logic paths under test.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		$str = (string) $str;
		$str = wp_check_invalid_utf8( $str );
		$str = strip_tags( $str );
		// Replace line breaks / tabs / multiple spaces with a single space, then trim.
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( $str, $strip = false ): string {
		return (string) $str;
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return false;
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}
if ( ! function_exists( '__return_null' ) ) {
	function __return_null() {
		return null;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals, '.', ',' );
	}
}

// In-memory option store so unit tests can simulate get_option / update_option
// without booting WordPress.
$GLOBALS['__abtest_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['__abtest_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['__abtest_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		unset( $GLOBALS['__abtest_options'][ $key ] );
		return true;
	}
}

// Minimal hook stubs for unit tests that touch add_filter / apply_filters / remove_filter.
$GLOBALS['__abtest_filters'] = [];
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['__abtest_filters'][ $tag ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $tag, callable $cb, int $priority = 10 ): bool {
		if ( ! isset( $GLOBALS['__abtest_filters'][ $tag ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['__abtest_filters'][ $tag ] as $i => $registered ) {
			if ( $registered === $cb ) {
				unset( $GLOBALS['__abtest_filters'][ $tag ][ $i ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		$args = func_get_args();
		array_shift( $args ); // drop $tag
		if ( empty( $GLOBALS['__abtest_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['__abtest_filters'][ $tag ] as $cb ) {
			$args[0] = $cb( ...$args );
		}
		return $args[0];
	}
}

// Composer autoload if available, else fallback.
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	require_once dirname( __DIR__ ) . '/includes/Autoload.php';
	\Abtest\Autoload::register();
}

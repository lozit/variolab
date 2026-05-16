<?php
/**
 * Bootstrap for integration tests — boots WP via wp-phpunit.
 *
 * Designed to run inside the wp-env tests container :
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/AB-testing-wordpress \
 *       phpunit -c phpunit-integration.xml.dist
 *
 * Resolution order for WP_PHPUNIT__DIR (path to the wp-phpunit lib) :
 *   1. WP_PHPUNIT__DIR env var (CI)
 *   2. composer-installed copy at vendor/wp-phpunit/wp-phpunit
 *   3. wp-env's internal path (fallback if running inside the container)
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

$abtest_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $abtest_phpunit_dir || ! is_dir( $abtest_phpunit_dir ) ) {
	$candidate = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
	if ( is_dir( $candidate ) ) {
		$abtest_phpunit_dir = $candidate;
	}
}

if ( ! $abtest_phpunit_dir || ! is_dir( $abtest_phpunit_dir ) ) {
	fwrite(
		STDERR,
		"wp-phpunit not found.\n" .
		"Run `composer install` first, or set WP_PHPUNIT__DIR env var.\n"
	);
	exit( 1 );
}

// Composer autoload (for our plugin classes + phpunit polyfills).
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// wp-phpunit's bootstrap looks for wp-tests-config.php in vendor/wp-phpunit/ by default.
// Override the lookup to point at our plugin-root config (which lives at our level).
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__, 2 ) . '/wp-tests-config.php' );
}

// 1. Tell wp-phpunit where the WP test suite lives.
require_once $abtest_phpunit_dir . '/includes/functions.php';

// 2. Hook the plugin into the test bootstrap so it's loaded before the test suite runs.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/variolab.php';
	}
);

// 3. Activation hook — install our schema / options on each fresh test DB.
tests_add_filter(
	'setup_theme',
	static function (): void {
		\Abtest\Plugin::activate();
	}
);

// 4. Boot the WP test environment (this is the actual phpunit bootstrap).
require $abtest_phpunit_dir . '/includes/bootstrap.php';

<?php
/**
 * Plugin Name:       Variolab – A/B Testing
 * Plugin URI:        https://github.com/lozit/variolab
 * Description:       Lightweight A/B testing for pages: internal tracking, persistent-cookie 50/50 split, GDPR-friendly. No third-party dependency.
 * Version:           0.17.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Guillaume Ferrari
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       variolab-ab-testing
 * Domain Path:       /languages
 *
 * @package Abtest
 */

defined( 'ABSPATH' ) || exit;

define( 'ABTEST_VERSION', '0.17.1' );
define( 'ABTEST_DB_VERSION', '1.4.0' );
define( 'ABTEST_PLUGIN_FILE', __FILE__ );
define( 'ABTEST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABTEST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$abtest_autoload = ABTEST_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $abtest_autoload ) ) {
	require_once $abtest_autoload;
} else {
	require_once ABTEST_PLUGIN_DIR . 'includes/Autoload.php';
	\Abtest\Autoload::register();
}

register_activation_hook( __FILE__, [ \Abtest\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Abtest\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \Abtest\Plugin::class, 'boot' ] );

<?php
/**
 * Uninstall handler — runs once when the plugin is deleted from wp-admin.
 *
 * @package Abtest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}abtest_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'abtest_db_version' );
delete_option( 'abtest_settings' );
delete_option( 'abtest_hash_salt' );

// Cover both the current CPT slug (`abtest_experiment` since v0.14.0 / DB v1.4.0)
// and the legacy one (`ab_experiment`, used through v0.13.0) so uninstalling an
// old install that never got the v1.4.0 upgrade still removes every experiment.
$abtest_experiments = get_posts(
	[
		'post_type'      => [ 'abtest_experiment', 'ab_experiment' ],
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	]
);
foreach ( $abtest_experiments as $abtest_experiment_id ) {
	wp_delete_post( (int) $abtest_experiment_id, true );
}

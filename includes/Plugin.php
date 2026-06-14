<?php
/**
 * Plugin orchestrator.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();
		// Note : we don't call load_plugin_textdomain() — wordpress.org auto-loads
		// translations for plugins hosted there since WP 4.6, and WP 6.7+ logs a
		// notice when a plugin calls it manually before `init`. The text-domain
		// remains declared in the plugin header so JIT loading still works.
		$plugin->maybe_upgrade_schema();
		$plugin->register_components();
	}

	public static function activate(): void {
		Schema::install();
		add_option( 'abtest_db_version', ABTEST_DB_VERSION );
		add_option(
			'abtest_settings',
			[
				'cookie_days'              => 30,
				'bypass_admins'            => true,
				'bypass_bots'              => true,
				'require_consent'          => false,
				'cache_resilient'          => false,
				'cache_check_mode'         => 'smart',
				'delete_data_on_uninstall' => false,
			]
		);

		Experiment::register();
		Scheduler::activate();
		Watcher::activate();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Scheduler::deactivate();
		Watcher::deactivate();
		flush_rewrite_rules();
	}

	private function maybe_upgrade_schema(): void {
		$installed = get_option( 'abtest_db_version' );
		if ( ABTEST_DB_VERSION === $installed ) {
			return;
		}

		// 1.3.0 shrinks visitor_hash from CHAR(64) to CHAR(16). Truncate stored
		// values FIRST so dbDelta's ALTER doesn't lose data (or fail under strict
		// sql_mode). Idempotent — SUBSTRING is a no-op once values are already 16.
		if ( '' !== (string) $installed && version_compare( (string) $installed, '1.3.0', '<' ) ) {
			self::pre_install_truncate_visitor_hash();
		}

		// 1.4.0 renames the CPT slug from `ab_experiment` to `abtest_experiment`
		// (wp.org Plugin Review requires ≥4-char prefixes). Must run BEFORE the
		// CPT registers on `init`, otherwise WP queries against the new slug
		// won't find the old rows. Runs at plugins_loaded via this function.
		// Idempotent — second run finds 0 rows to update.
		if ( '' !== (string) $installed && version_compare( (string) $installed, '1.4.0', '<' ) ) {
			self::pre_install_rename_post_type();
		}

		// Schema changes (CREATE/ALTER) — safe at plugins_loaded.
		Schema::install();

		// Data migrations that depend on WP runtime (get_permalink, wp_update_post)
		// must run on `init` priority 20 (after our CPT registration on init/10).
		if ( '' === (string) $installed || version_compare( (string) $installed, '1.1.0', '<' ) ) {
			add_action( 'init', [ self::class, 'migrate_to_1_1_0' ], 20 );
		}
		if ( '' === (string) $installed || version_compare( (string) $installed, '1.2.0', '<' ) ) {
			add_action( 'init', [ self::class, 'migrate_to_1_2_0' ], 21 );
		}

		update_option( 'abtest_db_version', ABTEST_DB_VERSION );
	}

	/**
	 * Truncate every existing visitor_hash to Cookie::HASH_LENGTH chars BEFORE
	 * the schema ALTER shrinks the column. Idempotent: SUBSTRING(x, 1, 16) on
	 * an already-16-char value returns the same value.
	 */
	private static function pre_install_truncate_visitor_hash(): void {
		global $wpdb;
		$table = Schema::events_table();
		// $table from Schema::events_table() is plugin-controlled. Safe to interpolate.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
		if ( $exists !== $table ) {
			return; // First install — no rows to truncate.
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET visitor_hash = SUBSTRING(visitor_hash, 1, %d)", Cookie::HASH_LENGTH ) );
		// phpcs:enable
	}

	/**
	 * Rename the CPT slug from `ab_experiment` (used through v0.13.0) to
	 * `abtest_experiment` (v0.14.0+). wp.org Plugin Review requires ≥4-char
	 * prefixes on CPT names, so `ab_` was rejected. Updates every post row
	 * in one statement. Idempotent: a second run matches 0 rows.
	 */
	private static function pre_install_rename_post_type(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
				'abtest_experiment',
				'ab_experiment'
			)
		);
	}

	/**
	 * Backfill `_abtest_variants` from the legacy `_abtest_control_id` /
	 * `_abtest_variant_id` pair so multi-variant code paths have a uniform shape.
	 * Idempotent : skips experiments that already have a variants array set.
	 */
	public static function migrate_to_1_2_0(): void {
		$ids = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$existing = get_post_meta( $id, Experiment::META_VARIANTS, true );
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				continue;
			}

			$variants = [];
			$control  = (int) get_post_meta( $id, Experiment::META_CONTROL_ID, true );
			if ( $control > 0 ) {
				$variants[] = [ 'post_id' => $control ];
			}
			$variant = (int) get_post_meta( $id, Experiment::META_VARIANT_ID, true );
			if ( $variant > 0 ) {
				$variants[] = [ 'post_id' => $variant ];
			}
			if ( ! empty( $variants ) ) {
				Experiment::set_variants( $id, $variants );
			}
		}
	}

	/**
	 * Backfill _abtest_test_url for experiments created before v1.1.0,
	 * and force their A/B pages into the `private` post status.
	 *
	 * Idempotent: skips experiments that already have a test URL set.
	 */
	public static function migrate_to_1_1_0(): void {
		$experiments = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( $experiments as $experiment_id ) {
			$existing = (string) get_post_meta( (int) $experiment_id, Experiment::META_TEST_URL, true );
			// If we have a stored value but it's not a normalized path (e.g. "/?page_id=5" from
			// an early backfill on a site without pretty permalinks), reset and re-derive.
			if ( '' !== $existing && self::looks_like_clean_path( $existing ) ) {
				continue;
			}

			$control_id = Experiment::get_control_id( (int) $experiment_id );
			if ( $control_id <= 0 ) {
				continue;
			}

			$path = self::derive_path_for_post( $control_id );
			if ( '' === $path ) {
				continue;
			}

			update_post_meta( (int) $experiment_id, Experiment::META_TEST_URL, $path );
			self::ensure_private_status( $control_id );
			self::ensure_private_status( Experiment::get_variant_id( (int) $experiment_id ) );
		}
	}

	private static function looks_like_clean_path( string $path ): bool {
		return (bool) preg_match( '#^/(?:[a-z0-9_\-]+/)+$#', $path );
	}

	/**
	 * Try get_permalink() first; if WP gave us an ugly ?page_id= URL, fall back to "/$post_name/".
	 */
	private static function derive_path_for_post( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$path = (string) wp_make_link_relative( $permalink );
			if ( self::looks_like_clean_path( Experiment::normalize_path( $path ) ) ) {
				return Experiment::normalize_path( $path );
			}
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && '' !== $post->post_name ) {
			return Experiment::normalize_path( '/' . $post->post_name . '/' );
		}

		return '';
	}

	/**
	 * Resume a paused experiment by creating a fresh duplicate in RUNNING state.
	 *
	 * Each run period gets its own experiment row with clean started_at/ended_at
	 * so analytics across iterations stay clean.
	 *
	 * The original PAUSED experiment is left untouched (with its locked period dates).
	 *
	 * @return int|\WP_Error New experiment ID on success.
	 */
	public static function duplicate_for_resume( int $original_id ) {
		$original = get_post( $original_id );
		if ( ! $original instanceof \WP_Post || Experiment::POST_TYPE !== $original->post_type ) {
			return new \WP_Error( 'not_found', __( 'Original experiment not found.', 'variolab-ab-testing' ) );
		}

		$new_id = wp_insert_post(
			[
				'post_type'    => Experiment::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $original->post_title ),
			],
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy configuration meta (URL, pages, goal). Status / dates are reset below.
		foreach (
			[
				Experiment::META_TEST_URL,
				Experiment::META_CONTROL_ID,
				Experiment::META_VARIANT_ID,
				Experiment::META_GOAL_TYPE,
				Experiment::META_GOAL_VALUE,
			] as $key
		) {
			$value = get_post_meta( $original_id, $key, true );
			update_post_meta( (int) $new_id, $key, $value );
		}

		// Hide variant pages from public direct access (idempotent).
		self::ensure_private_status( (int) get_post_meta( (int) $new_id, Experiment::META_CONTROL_ID, true ) );
		$variant_id = (int) get_post_meta( (int) $new_id, Experiment::META_VARIANT_ID, true );
		if ( $variant_id > 0 ) {
			self::ensure_private_status( $variant_id );
		}

		// Determine final status: try RUNNING, but if another experiment is already running
		// on this URL, downgrade to DRAFT (consistent with the regular save flow).
		$test_url    = (string) get_post_meta( (int) $new_id, Experiment::META_TEST_URL, true );
		$conflict    = '' !== $test_url ? Experiment::find_running_for_url( $test_url ) : null;
		$has_conflict = ( $conflict instanceof \WP_Post && (int) $conflict->ID !== (int) $new_id );

		if ( $has_conflict ) {
			update_post_meta( (int) $new_id, Experiment::META_STATUS, Experiment::STATUS_DRAFT );
		} else {
			update_post_meta( (int) $new_id, Experiment::META_STATUS, Experiment::STATUS_RUNNING );
			update_post_meta( (int) $new_id, Experiment::META_STARTED_AT, current_time( 'mysql', true ) );
		}

		// The original PAUSED experiment is now ENDED — its run period is final.
		// ended_at was already locked when it was paused; we don't overwrite it.
		update_post_meta( $original_id, Experiment::META_STATUS, Experiment::STATUS_ENDED );

		return (int) $new_id;
	}

	public static function ensure_private_status( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( in_array( $post->post_status, [ 'private', 'draft', 'pending', 'future', 'trash' ], true ) ) {
			return;
		}
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'private',
			]
		);
	}

	private function register_components(): void {
		// CPT registration must happen on `init` — $wp_rewrite is not built before then.
		add_action( 'init', [ Experiment::class, 'register' ] );

		Router::instance()->register();
		Tracker::instance()->register();
		Template::instance()->register();
		Rest\ConvertController::instance()->register();
		Rest\StatsController::instance()->register();
		Scheduler::register();
		Watcher::register();
		PrivacyPolicy::register();
		MultiLanguage::register();
		UrlSettings::register();
		Integrations\Ga4::instance()->register();
		Integrations\Webhook::instance()->register();

		// admin_menu / admin_notices only fire in the admin context anyway —
		// safer to register unconditionally than to rely on is_admin() at this hook timing.
		Admin\Admin::instance()->register();
		CacheBypass::register();
	}
}

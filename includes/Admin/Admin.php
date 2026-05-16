<?php
/**
 * Admin UI orchestrator.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Experiment;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const MENU_SLUG = 'ab-testing';
	private const NONCE     = 'abtest_save_experiment';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_abtest_save_experiment', [ $this, 'handle_save' ] );
		add_action( 'admin_post_abtest_set_status', [ $this, 'handle_status_change' ] );
		add_action( 'admin_post_abtest_replace_running', [ $this, 'handle_replace_running' ] );
		add_action( 'admin_post_abtest_resume', [ $this, 'handle_resume' ] );
		add_action( 'admin_post_abtest_delete_experiment', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_abtest_import_html', [ HtmlImport::class, 'handle_upload' ] );
		add_action( 'admin_post_abtest_watch_scan', [ HtmlImport::class, 'handle_scan_now' ] );
		add_action( 'admin_post_abtest_save_settings', [ Settings::class, 'handle_save' ] );
		add_action( 'admin_post_abtest_test_webhook', [ Settings::class, 'handle_test_webhook' ] );
		add_action( 'admin_post_abtest_export_csv', [ CsvExport::class, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		HelpTabs::register();
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'A/B Testing', 'variolab' ),
			__( 'A/B Tests', 'variolab' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-chart-bar',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Import HTML', 'variolab' ),
			__( 'Import HTML', 'variolab' ),
			'manage_options',
			self::MENU_SLUG . '&action=import',
			'__return_null'
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'variolab' ),
			__( 'Settings', 'variolab' ),
			'manage_options',
			self::MENU_SLUG . '&action=settings',
			'__return_null'
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'abtest-admin',
			ABTEST_PLUGIN_URL . 'assets/css/admin.css',
			[],
			ABTEST_VERSION
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		// Chart.js + our chart bootstrap, only on the main list view.
		// Chart.js is vendored locally under assets/js/vendor/ so we don't violate
		// the WordPress.org plugin guideline against remote-loading code at runtime.
		// See assets/js/vendor/README.md for source / license / update instructions.
		if ( 'list' === $action ) {
			wp_enqueue_script(
				'abtest-chartjs',
				ABTEST_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
				[],
				'4.4.1',
				true
			);
			wp_enqueue_script(
				'abtest-url-charts',
				ABTEST_PLUGIN_URL . 'assets/js/url-charts.js',
				[ 'abtest-chartjs' ],
				ABTEST_VERSION,
				true
			);
		}

		// URL-scripts editor JS only on the experiment edit screen.
		if ( in_array( $action, [ 'new', 'edit' ], true ) ) {
			wp_enqueue_script(
				'abtest-url-scripts-editor',
				ABTEST_PLUGIN_URL . 'assets/js/url-scripts-editor.js',
				[],
				ABTEST_VERSION,
				true
			);
			wp_enqueue_script(
				'abtest-variants-editor',
				ABTEST_PLUGIN_URL . 'assets/js/variants-editor.js',
				[],
				ABTEST_VERSION,
				true
			);
		}

		// Webhooks editor JS only on the settings screen.
		if ( 'settings' === $action ) {
			wp_enqueue_script(
				'abtest-webhooks-editor',
				ABTEST_PLUGIN_URL . 'assets/js/webhooks-editor.js',
				[],
				ABTEST_VERSION,
				true
			);
		}

		// HTML import editor (drag & drop + preview iframe) only on the import screen.
		if ( 'import' === $action ) {
			wp_enqueue_script(
				'abtest-html-import-editor',
				ABTEST_PLUGIN_URL . 'assets/js/html-import-editor.js',
				[],
				ABTEST_VERSION,
				true
			);
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'variolab' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no mutation
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		switch ( $action ) {
			case 'new':
			case 'edit':
				ExperimentEdit::render( $this->current_experiment_id() );
				break;
			case 'import':
				HtmlImport::render();
				break;
			case 'settings':
				Settings::render();
				break;
			case 'list':
			default:
				ExperimentsList::render();
				break;
		}
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab' ), 403 );
		}
		check_admin_referer( self::NONCE, '_abtest_nonce' );

		$id          = isset( $_POST['experiment_id'] ) ? absint( wp_unslash( $_POST['experiment_id'] ) ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$test_url    = isset( $_POST['test_url'] ) ? Experiment::normalize_path( sanitize_text_field( wp_unslash( $_POST['test_url'] ) ) ) : '';
		$goal_type   = isset( $_POST['goal_type'] ) ? sanitize_key( wp_unslash( $_POST['goal_type'] ) ) : Experiment::GOAL_URL;
		$goal_value  = isset( $_POST['goal_value'] ) ? sanitize_text_field( wp_unslash( $_POST['goal_value'] ) ) : '';
		$status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : Experiment::STATUS_DRAFT;

		// Read variants[] array. Each entry has [post_id]; positional → label A/B/C/D.
		$variants_input = isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ? wp_unslash( $_POST['variants'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$variants_clean = [];
		foreach ( $variants_input as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$pid = isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0;
			if ( $pid > 0 ) {
				$variants_clean[] = [ 'post_id' => $pid ];
			}
			if ( count( $variants_clean ) >= Experiment::MAX_VARIANTS ) {
				break;
			}
		}
		$control_id = $variants_clean[0]['post_id'] ?? 0;
		$variant_id = $variants_clean[1]['post_id'] ?? 0; // first non-baseline (used by validate + ensure_private)

		$errors = $this->validate_multi( $title, $test_url, $variants_clean, $goal_type, $goal_value, $status, $id );
		if ( ! empty( $errors ) ) {
			$this->redirect_with_notice( 'error', implode( ' | ', $errors ), $id ? [ 'action' => 'edit', 'experiment' => $id ] : [ 'action' => 'new' ] );
		}

		$post_data = [
			'post_type'   => Experiment::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		];

		if ( $id > 0 ) {
			$post_data['ID'] = $id;
			wp_update_post( $post_data, true );
		} else {
			$id = (int) wp_insert_post( $post_data, true );
		}

		if ( ! $id || $id < 1 ) {
			$this->redirect_with_notice( 'error', __( 'Failed to save the experiment.', 'variolab' ), [ 'action' => 'new' ] );
		}

		$prev_status = $id > 0 ? Experiment::get_status( $id ) : Experiment::STATUS_DRAFT;
		// New posts always start in DRAFT before the user's chosen status applies.
		$baseline_for_transition = ( '' === $prev_status ) ? Experiment::STATUS_DRAFT : $prev_status;

		update_post_meta( $id, Experiment::META_TEST_URL, $test_url );
		Experiment::set_variants( $id, $variants_clean );  // also syncs legacy control_id / variant_id meta
		update_post_meta( $id, Experiment::META_GOAL_TYPE, $goal_type );
		update_post_meta( $id, Experiment::META_GOAL_VALUE, $goal_value );

		// Enforce state machine on the requested status. If the requested transition
		// isn't allowed (e.g. PAUSED → RUNNING — that's the Resume action), fall back
		// to the previous status with a warning.
		$effective_status   = $status;
		$transition_message = '';
		if ( ! Experiment::is_transition_allowed( $baseline_for_transition, $status ) ) {
			$effective_status   = $baseline_for_transition;
			$transition_message = sprintf(
				/* translators: 1: from status, 2: to status */
				__( 'Status kept at %1$s — transition to %2$s is not allowed. Use the Resume action for paused experiments.', 'variolab' ),
				$baseline_for_transition,
				$status
			);
		}

		// Soft-downgrade: if requested status is `running` but another experiment is already
		// running on the same URL, save as `draft` instead and surface a warning notice.
		$conflict_message = '';
		if ( Experiment::STATUS_RUNNING === $effective_status ) {
			$conflict = Experiment::find_running_for_url( $test_url );
			if ( $conflict && (int) $conflict->ID !== $id ) {
				$effective_status = Experiment::STATUS_DRAFT;
				$conflict_message = sprintf(
					/* translators: %s: title of the running experiment that owns the URL */
					__( 'Saved as Draft because "%s" is already running on this URL. Pause that experiment first to start this one.', 'variolab' ),
					get_the_title( $conflict )
				);
			}
		}
		update_post_meta( $id, Experiment::META_STATUS, $effective_status );

		if ( Experiment::STATUS_RUNNING === $effective_status && Experiment::STATUS_RUNNING !== $prev_status ) {
			update_post_meta( $id, Experiment::META_STARTED_AT, current_time( 'mysql', true ) );
		}
		// Lock the run-period end date the FIRST time the experiment leaves RUNNING.
		if ( in_array( $effective_status, [ Experiment::STATUS_PAUSED, Experiment::STATUS_ENDED ], true ) ) {
			$existing_end = (string) get_post_meta( $id, Experiment::META_ENDED_AT, true );
			if ( '' === $existing_end ) {
				update_post_meta( $id, Experiment::META_ENDED_AT, current_time( 'mysql', true ) );
			}
		}

		// When the experiment is running, hide every variant page from public view.
		if ( Experiment::STATUS_RUNNING === $effective_status ) {
			foreach ( $variants_clean as $v ) {
				\Abtest\Plugin::ensure_private_status( (int) $v['post_id'] );
			}
		}

		// Persist targeting (devices + countries).
		$raw_devices = isset( $_POST['target_devices'] ) && is_array( $_POST['target_devices'] ) ? wp_unslash( $_POST['target_devices'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean_devices = array_values( array_intersect( array_map( 'sanitize_key', $raw_devices ), Experiment::DEVICES ) );
		update_post_meta( $id, Experiment::META_TARGET_DEVICES, $clean_devices );

		$raw_countries = isset( $_POST['target_countries'] ) ? sanitize_text_field( wp_unslash( $_POST['target_countries'] ) ) : '';
		$clean_countries = [];
		foreach ( preg_split( '/[\s,;]+/', $raw_countries ) as $code ) {
			$norm = strtoupper( trim( (string) $code ) );
			if ( preg_match( '/^[A-Z]{2}$/', $norm ) ) {
				$clean_countries[] = $norm;
			}
		}
		update_post_meta( $id, Experiment::META_TARGET_COUNTRIES, array_values( array_unique( $clean_countries ) ) );

		// Persist optional schedule_start_at / schedule_end_at (datetime-local format).
		$raw_start = isset( $_POST['schedule_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_start_at'] ) ) : '';
		$raw_end   = isset( $_POST['schedule_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_end_at'] ) ) : '';
		$norm_dt   = static function ( string $v ): string {
			if ( '' === $v ) { return ''; }
			// Accept both "YYYY-MM-DDTHH:MM" (datetime-local) and "YYYY-MM-DD HH:MM:SS".
			$ts = strtotime( str_replace( 'T', ' ', $v ) );
			return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
		};
		$start_dt = $norm_dt( $raw_start );
		$end_dt   = $norm_dt( $raw_end );
		if ( '' !== $start_dt ) {
			update_post_meta( $id, Experiment::META_SCHEDULE_START_AT, $start_dt );
		} else {
			delete_post_meta( $id, Experiment::META_SCHEDULE_START_AT );
		}
		if ( '' !== $end_dt ) {
			update_post_meta( $id, Experiment::META_SCHEDULE_END_AT, $end_dt );
		} else {
			delete_post_meta( $id, Experiment::META_SCHEDULE_END_AT );
		}

		// Persist URL-level tracking scripts (shared across every experiment on this URL).
		// Trust model: scripts are admin-curated raw JS/HTML (Adwords pixels, FB pixels,
		// Lemlist beacons). The `unfiltered_html` capability is the WP-canonical gate for
		// users who can input raw HTML/JS (same gate used by the Custom HTML widget and
		// the post editor's "Custom HTML" block). Single-site admins have it by default;
		// multisite admins only get it explicitly granted by super-admins (correct behavior:
		// don't let a tenant admin inject a pixel that fires on another tenant's traffic).
		// Position is sanitized; code body is stored as-is so JS regex / JSON payloads
		// survive the round-trip.
		if ( '' !== $test_url && current_user_can( 'unfiltered_html' ) ) {
			$raw_scripts = isset( $_POST['url_scripts'] ) && is_array( $_POST['url_scripts'] )
				? wp_unslash( $_POST['url_scripts'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw code bodies are admin-trusted by design (unfiltered_html cap above)
				: [];
			$entries     = [];
			foreach ( $raw_scripts as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$position = isset( $row['position'] ) ? sanitize_key( (string) $row['position'] ) : '';
				$code     = isset( $row['code'] ) ? (string) $row['code'] : '';
				$entries[] = [ 'position' => $position, 'code' => $code ];
			}
			\Abtest\UrlScripts::set( $test_url, $entries );
		}

		// Persist URL-level flags (today: noindex). Same shared-across-experiments scope.
		if ( '' !== $test_url ) {
			\Abtest\UrlSettings::set(
				$test_url,
				[
					'noindex' => ! empty( $_POST['url_noindex'] ),
				]
			);
		}

		if ( '' !== $transition_message ) {
			$this->redirect_with_notice( 'warning', $transition_message, [ 'action' => 'edit', 'experiment' => $id ] );
		}
		if ( '' !== $conflict_message ) {
			$this->redirect_with_notice( 'warning', $conflict_message, [ 'action' => 'edit', 'experiment' => $id ] );
		}
		$this->redirect_with_notice( 'success', __( 'Experiment saved.', 'variolab' ), [ 'action' => 'edit', 'experiment' => $id ] );
	}

	public function handle_status_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab' ), 403 );
		}
		check_admin_referer( 'abtest_status_change' );

		$id     = isset( $_GET['experiment'] ) ? absint( wp_unslash( $_GET['experiment'] ) ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$valid  = [ Experiment::STATUS_RUNNING, Experiment::STATUS_PAUSED, Experiment::STATUS_ENDED, Experiment::STATUS_DRAFT ];

		if ( $id <= 0 || ! in_array( $status, $valid, true ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid status change.', 'variolab' ) );
		}

		$prev = Experiment::get_status( $id );

		// Enforce state machine: refuse invalid transitions outright. This prevents
		// e.g. PAUSED → RUNNING via the dropdown (which must go through Resume to keep dates clean)
		// or any transition out of ENDED.
		if ( ! Experiment::is_transition_allowed( $prev, $status ) ) {
			$this->redirect_with_notice(
				'warning',
				sprintf(
					/* translators: 1: from status, 2: to status */
					__( 'Transition not allowed: %1$s → %2$s. Use the Resume action to re-run a paused experiment.', 'variolab' ),
					$prev,
					$status
				)
			);
		}

		// Soft-block start if another experiment already running on the same URL.
		if ( Experiment::STATUS_RUNNING === $status ) {
			$test_url = Experiment::get_test_url( $id );
			$conflict = '' !== $test_url ? Experiment::find_running_for_url( $test_url ) : null;
			if ( $conflict && (int) $conflict->ID !== $id ) {
				$this->redirect_with_notice(
					'warning',
					sprintf(
						/* translators: %s: title of the experiment that already runs on the same URL */
						__( 'Cannot start: "%s" is already running on this URL. Pause it first.', 'variolab' ),
						get_the_title( $conflict )
					)
				);
			}
		}

		update_post_meta( $id, Experiment::META_STATUS, $status );

		if ( Experiment::STATUS_RUNNING === $status && Experiment::STATUS_RUNNING !== $prev ) {
			update_post_meta( $id, Experiment::META_STARTED_AT, current_time( 'mysql', true ) );
			\Abtest\Plugin::ensure_private_status( Experiment::get_control_id( $id ) );
			$variant_id = Experiment::get_variant_id( $id );
			if ( $variant_id > 0 ) {
				\Abtest\Plugin::ensure_private_status( $variant_id );
			}
		}

		// Lock the run-period end as soon as the experiment leaves RUNNING.
		// Only set ended_at the FIRST time (don't overwrite a paused-then-ended trail).
		if ( in_array( $status, [ Experiment::STATUS_PAUSED, Experiment::STATUS_ENDED ], true ) ) {
			$existing_end = (string) get_post_meta( $id, Experiment::META_ENDED_AT, true );
			if ( '' === $existing_end ) {
				update_post_meta( $id, Experiment::META_ENDED_AT, current_time( 'mysql', true ) );
			}
		}

		$this->redirect_with_notice( 'success', __( 'Status updated.', 'variolab' ) );
	}

	/**
	 * Resume a PAUSED experiment by creating a duplicate in RUNNING state.
	 * The original keeps its locked started_at / ended_at (its single run period).
	 * Each future resume creates yet another duplicate, so each row = one continuous run.
	 */
	public function handle_resume(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab' ), 403 );
		}
		check_admin_referer( 'abtest_resume' );

		$id = isset( $_GET['experiment'] ) ? absint( wp_unslash( $_GET['experiment'] ) ) : 0;
		if ( $id <= 0 || ! get_post( $id ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid experiment.', 'variolab' ) );
		}

		$current_status = Experiment::get_status( $id );
		if ( Experiment::STATUS_PAUSED !== $current_status ) {
			$this->redirect_with_notice(
				'warning',
				__( 'Resume is only available for paused experiments.', 'variolab' )
			);
		}

		$new_id = \Abtest\Plugin::duplicate_for_resume( $id );
		if ( is_wp_error( $new_id ) ) {
			$this->redirect_with_notice( 'error', $new_id->get_error_message() );
		}

		$status_after = Experiment::get_status( (int) $new_id );
		if ( Experiment::STATUS_RUNNING === $status_after ) {
			$this->redirect_with_notice(
				'success',
				sprintf(
					/* translators: %d: new experiment ID */
					__( 'Resumed as a new experiment (#%d, now running). The original keeps its locked dates.', 'variolab' ),
					(int) $new_id
				)
			);
		}
		// Status downgraded to draft because of URL conflict.
		$this->redirect_with_notice(
			'warning',
			sprintf(
				/* translators: %d: new experiment ID */
				__( 'Created a new draft experiment (#%d) because another one is already running on this URL.', 'variolab' ),
				(int) $new_id
			)
		);
	}

	/**
	 * Atomic swap on a URL: pause the experiment currently running on this URL
	 * (if any), then start the requested one. Useful when the user has prepared
	 * a new draft variant and wants to switch in one click.
	 */
	public function handle_replace_running(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab' ), 403 );
		}
		check_admin_referer( 'abtest_replace_running' );

		$id = isset( $_GET['experiment'] ) ? absint( wp_unslash( $_GET['experiment'] ) ) : 0;
		if ( $id <= 0 || ! get_post( $id ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid experiment.', 'variolab' ) );
		}

		$test_url = Experiment::get_test_url( $id );
		if ( '' === $test_url ) {
			$this->redirect_with_notice( 'error', __( 'This experiment has no test URL.', 'variolab' ) );
		}

		$paused_title = '';
		$running      = Experiment::find_running_for_url( $test_url );
		if ( $running && (int) $running->ID !== $id ) {
			update_post_meta( (int) $running->ID, Experiment::META_STATUS, Experiment::STATUS_PAUSED );
			$paused_title = (string) get_the_title( $running );
		}

		// Now start the requested one.
		update_post_meta( $id, Experiment::META_STATUS, Experiment::STATUS_RUNNING );
		update_post_meta( $id, Experiment::META_STARTED_AT, current_time( 'mysql', true ) );
		\Abtest\Plugin::ensure_private_status( Experiment::get_control_id( $id ) );
		$variant_id = Experiment::get_variant_id( $id );
		if ( $variant_id > 0 ) {
			\Abtest\Plugin::ensure_private_status( $variant_id );
		}

		if ( '' !== $paused_title ) {
			$this->redirect_with_notice(
				'success',
				sprintf(
					/* translators: 1: paused experiment title, 2: started experiment title */
					__( 'Replaced "%1$s" (paused) with "%2$s" (now running).', 'variolab' ),
					$paused_title,
					(string) get_the_title( $id )
				)
			);
		}
		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %s: started experiment title */
				__( '"%s" is now running.', 'variolab' ),
				(string) get_the_title( $id )
			)
		);
	}

	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab' ), 403 );
		}
		check_admin_referer( 'abtest_delete_experiment' );

		$id = isset( $_GET['experiment'] ) ? absint( wp_unslash( $_GET['experiment'] ) ) : 0;
		if ( $id > 0 ) {
			wp_delete_post( $id, true );
		}
		$this->redirect_with_notice( 'success', __( 'Experiment deleted.', 'variolab' ) );
	}

	public static function is_valid_test_url( string $path ): bool {
		if ( '/' === $path ) {
			return true; // root, unusual but allowed
		}

		// Split path and query. Query is optional (?key=value).
		$query = '';
		$qmark = strpos( $path, '?' );
		if ( false !== $qmark ) {
			$query = (string) substr( $path, $qmark + 1 );
			$path  = (string) substr( $path, 0, $qmark );
		}

		// Path: lowercase Unicode letters/digits + _ - / between slashes, no doubles.
		// \p{Ll} = lowercase letter (Unicode-aware), \p{N} = any number.
		if ( ! preg_match( '#^/(?:[\p{Ll}\p{N}_\-]+/)+$#u', $path ) ) {
			return false;
		}

		if ( '' === $query ) {
			return true;
		}

		// Query: simple key=value pairs separated by &. Keys must be ASCII for sanity.
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9_\-]+=.+$/i', $pair ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Multi-variant aware validation. Replaces the old A/B-specific `validate()`.
	 *
	 * @param string                          $title       Experiment title.
	 * @param string                          $test_url    Normalized test URL path.
	 * @param array<int, array{post_id:int}>  $variants    List of variant entries.
	 * @param string                          $goal_type   Goal type (Experiment::GOAL_*).
	 * @param string                          $goal_value  Goal value (URL or selector).
	 * @param string                          $status      Requested status (Experiment::STATUS_*).
	 * @param int                             $editing_id  Existing experiment ID when editing, 0 when creating new.
	 * @return string[] List of validation error messages (empty when valid).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $editing_id reserved for future "uniqueness vs other experiments" check
	private function validate_multi( string $title, string $test_url, array $variants, string $goal_type, string $goal_value, string $status, int $editing_id = 0 ): array {
		$errors = [];
		if ( '' === $title ) {
			$errors[] = __( 'Title is required.', 'variolab' );
		}
		if ( '' === $test_url ) {
			$errors[] = __( 'Test URL is required.', 'variolab' );
		} elseif ( ! self::is_valid_test_url( $test_url ) ) {
			$errors[] = __( 'Test URL must look like /path/ (lowercase, letters, numbers, hyphens, underscores).', 'variolab' );
		}

		if ( empty( $variants ) ) {
			$errors[] = __( 'Variant A is required.', 'variolab' );
		} else {
			$seen = [];
			foreach ( $variants as $i => $v ) {
				$pid = (int) $v['post_id'];
				if ( $pid <= 0 || ! get_post( $pid ) ) {
					$errors[] = sprintf(
						/* translators: %s: variant label */
						__( 'Variant %s page does not exist.', 'variolab' ),
						Experiment::VARIANT_LABELS[ $i ] ?? '?'
					);
					continue;
				}
				if ( isset( $seen[ $pid ] ) ) {
					$errors[] = __( 'Each variant must use a different page — duplicates detected.', 'variolab' );
					break;
				}
				$seen[ $pid ] = true;
			}
		}

		if ( ! in_array( $goal_type, [ Experiment::GOAL_URL, Experiment::GOAL_SELECTOR ], true ) ) {
			$errors[] = __( 'Invalid goal type.', 'variolab' );
		}
		if ( '' === $goal_value ) {
			$errors[] = __( 'Goal value is required.', 'variolab' );
		}
		$valid_status = [ Experiment::STATUS_DRAFT, Experiment::STATUS_RUNNING, Experiment::STATUS_PAUSED, Experiment::STATUS_ENDED ];
		if ( ! in_array( $status, $valid_status, true ) ) {
			$errors[] = __( 'Invalid status.', 'variolab' );
		}
		return $errors;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- legacy A/B validate, $editing_id kept for signature parity with validate_multi
	private function validate( string $title, string $test_url, int $control_id, int $variant_id, string $goal_type, string $goal_value, string $status, int $editing_id = 0 ): array {
		$errors = [];
		if ( '' === $title ) {
			$errors[] = __( 'Title is required.', 'variolab' );
		}
		if ( '' === $test_url ) {
			$errors[] = __( 'Test URL is required.', 'variolab' );
		} elseif ( ! self::is_valid_test_url( $test_url ) ) {
			$errors[] = __( 'Test URL must look like /path/ (lowercase, letters, numbers, hyphens, underscores).', 'variolab' );
		}
		// Note: URL uniqueness against another running experiment is NOT a hard error here.
		// `handle_save` and `handle_status_change` softly downgrade the requested status to
		// `draft` instead, so the user's work is never lost on conflict.
		if ( $control_id <= 0 || ! get_post( $control_id ) ) {
			$errors[] = __( 'Variant A is required.', 'variolab' );
		}
		// Variant B is optional — leaving it empty puts the experiment in "baseline" mode
		// where everyone sees Variant A. Only validate if a value was provided.
		if ( $variant_id > 0 && ! get_post( $variant_id ) ) {
			$errors[] = __( 'Variant B page does not exist.', 'variolab' );
		}
		if ( $variant_id > 0 && $control_id > 0 && $control_id === $variant_id ) {
			$errors[] = __( 'Variant A and Variant B must be different posts.', 'variolab' );
		}
		if ( ! in_array( $goal_type, [ Experiment::GOAL_URL, Experiment::GOAL_SELECTOR ], true ) ) {
			$errors[] = __( 'Invalid goal type.', 'variolab' );
		}
		if ( '' === $goal_value ) {
			$errors[] = __( 'Goal value is required.', 'variolab' );
		}
		$valid_status = [ Experiment::STATUS_DRAFT, Experiment::STATUS_RUNNING, Experiment::STATUS_PAUSED, Experiment::STATUS_ENDED ];
		if ( ! in_array( $status, $valid_status, true ) ) {
			$errors[] = __( 'Invalid status.', 'variolab' );
		}
		return $errors;
	}

	private function current_experiment_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only ID for view, no mutation
		if ( ! isset( $_GET['experiment'] ) ) {
			return 0;
		}
		return absint( wp_unslash( $_GET['experiment'] ) );
		// phpcs:enable
	}

	/**
	 * @param string                $type       Notice severity ('success', 'warning', 'info', 'error').
	 * @param string                $message    Human-readable message.
	 * @param array<string,scalar>  $extra_args Extra query args appended to the redirect URL.
	 */
	private function redirect_with_notice( string $type, string $message, array $extra_args = [] ): void {
		$valid_types = [ 'success', 'warning', 'info', 'error' ];
		$args = array_merge(
			[
				'page'            => self::MENU_SLUG,
				'abtest_notice'   => rawurlencode( $message ),
				'abtest_notice_t' => in_array( $type, $valid_types, true ) ? $type : 'error',
			],
			$extra_args
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function maybe_render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['abtest_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() wraps the whole expression
		$message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['abtest_notice'] ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_type = isset( $_GET['abtest_notice_t'] ) ? sanitize_key( wp_unslash( $_GET['abtest_notice_t'] ) ) : 'error';
		$type     = in_array( $raw_type, [ 'success', 'warning', 'info', 'error' ], true ) ? $raw_type : 'error';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	public static function nonce_action(): string {
		return self::NONCE;
	}

	public static function menu_slug(): string {
		return self::MENU_SLUG;
	}
}

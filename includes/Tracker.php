<?php
/**
 * Tracker — writes impression and conversion events to the custom table.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Tracker {

	public const EVENT_IMPRESSION = 'impression';
	public const EVENT_CONVERSION = 'conversion';

	private const SESSION_DEDUP_TRANSIENT_TTL = HOUR_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker_js' ] );

		// Keep the conversion tracker out of "Delay JavaScript Execution" optimisers.
		// WP Rocket (and Perfmatters, etc.) rewrite <script type> to defer ALL JS until
		// the first user interaction. That breaks click/URL goals: the visitor's first
		// click only *wakes* the tracker — the listener isn't attached yet, so that click
		// isn't recorded (the "had to click twice" symptom). The tracker is tiny and must
		// run on load, so we exclude it. Both filters share the same array-of-patterns
		// shape and no-op when the plugin isn't installed. Other delay-JS tools may need a
		// manual exclusion of `variolab-ab-testing/assets/js/tracker.js` + `AbtestTracker`.
		add_filter( 'rocket_delay_js_exclusions', [ $this, 'exclude_from_delay_js' ] );
		add_filter( 'perfmatters_delay_js_exclusions', [ $this, 'exclude_from_delay_js' ] );
	}

	/**
	 * Add the tracker (external script + inline config) to a delay-JS optimiser's
	 * exclusion list so it executes on load instead of on first interaction.
	 *
	 * @param mixed $exclusions Current exclusion patterns (array).
	 * @return mixed
	 */
	public function exclude_from_delay_js( $exclusions ) {
		if ( ! is_array( $exclusions ) ) {
			return $exclusions;
		}
		$exclusions[] = 'variolab-ab-testing/assets/js/tracker.js';
		$exclusions[] = 'AbtestTracker';
		return $exclusions;
	}

	/**
	 * Build the front-end tracker config for the current request, or null when no
	 * tracker should load.
	 *
	 * Returns config when the visitor is tracked (real conversions) OR is a
	 * logged-in editor/admin being bypassed (preview mode — clicks show a toast
	 * but log nothing, so admins can verify a goal without polluting stats).
	 * Genuine untracked visitors (bots, out-of-target, consent-blocked) get null.
	 *
	 * @return array{experimentId:int,restUrl:string,nonce:string,goalType:string,goalValue:string,preview:bool}|null
	 */
	public function script_config(): ?array {
		$experiment = Router::instance()->get_current_experiment();
		if ( null === $experiment ) {
			return null;
		}

		$tracked = Router::instance()->is_current_tracked();
		$preview = ! $tracked && is_user_logged_in() && current_user_can( 'edit_posts' );
		if ( ! $tracked && ! $preview ) {
			return null;
		}

		$goal = Experiment::get_goal( $experiment->ID );
		return [
			'experimentId' => (int) $experiment->ID,
			'restUrl'      => esc_url_raw( rest_url( 'abtest/v1/convert' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'goalType'     => (string) $goal['type'],
			'goalValue'    => (string) $goal['value'],
			'preview'      => $preview,
		];
	}

	/**
	 * Enqueue the tracker on themed pages (those that run wp_head/wp_footer).
	 * Blank Canvas pages bypass this pipeline — see {@see blank_canvas_script_tags()}.
	 */
	public function enqueue_tracker_js(): void {
		$config = $this->script_config();
		if ( null === $config ) {
			return;
		}

		$handle = 'abtest-tracker';
		wp_register_script(
			$handle,
			ABTEST_PLUGIN_URL . 'assets/js/tracker.js',
			[],
			ABTEST_VERSION,
			true
		);
		wp_localize_script( $handle, 'AbtestTracker', $config );
		wp_enqueue_script( $handle );
	}

	/**
	 * Build the tracker `<script>` tags for the Blank Canvas template.
	 *
	 * Imported-HTML pages render raw via templates/blank-canvas.php and never run
	 * wp_enqueue_scripts, so {@see enqueue_tracker_js()} cannot reach them and the
	 * conversion tracker was previously absent on every imported landing. This
	 * returns the inline config + the tracker `<script src>` (both via WP-blessed
	 * helpers) so the template can inject them before `</body>`. Returns '' when no
	 * tracker should load.
	 */
	public function blank_canvas_script_tags(): string {
		$config = $this->script_config();
		if ( null === $config ) {
			return '';
		}

		$tags  = wp_get_inline_script_tag(
			'window.AbtestTracker = ' . wp_json_encode( $config ) . ';',
			[ 'id' => 'abtest-tracker-config' ]
		);
		$tags .= wp_get_script_tag(
			[
				'id'  => 'abtest-tracker-js',
				'src' => add_query_arg( 'ver', rawurlencode( ABTEST_VERSION ), ABTEST_PLUGIN_URL . 'assets/js/tracker.js' ),
			]
		);
		return $tags;
	}

	public function log_impression( int $experiment_id, string $variant, string $test_url = '' ): void {
		$visitor = Cookie::visitor_hash();
		if ( $this->already_logged_today( $experiment_id, $variant, self::EVENT_IMPRESSION, $visitor ) ) {
			return;
		}
		$this->insert( $experiment_id, $variant, self::EVENT_IMPRESSION, $visitor, $test_url );
		$this->mark_logged( $experiment_id, $variant, self::EVENT_IMPRESSION, $visitor );
	}

	public function log_conversion( int $experiment_id, string $variant, string $visitor, string $test_url = '' ): bool {
		if ( $this->already_logged( $experiment_id, $variant, self::EVENT_CONVERSION, $visitor ) ) {
			return false;
		}
		$this->insert( $experiment_id, $variant, self::EVENT_CONVERSION, $visitor, $test_url );
		return true;
	}

	/**
	 * Whether this (experiment, variant, visitor) has a recorded impression.
	 *
	 * Impressions are only ever written server-side by {@see Router} when the
	 * test page is actually served to a tracked visitor, so this is the proof
	 * the public /convert endpoint uses to reject forged conversions: a request
	 * carrying a hand-crafted cookie for a guessed experiment has no matching
	 * impression row and is turned away. See ConvertController::handle().
	 */
	public function has_impression( int $experiment_id, string $variant, string $visitor ): bool {
		return $this->already_logged( $experiment_id, $variant, self::EVENT_IMPRESSION, $visitor );
	}

	/**
	 * Variant recorded in this visitor's most recent impression for the experiment,
	 * or null if they have none. This is the server-side source of truth for the
	 * variant — used by the conversion endpoint when the client variant cookie is
	 * missing or stale (e.g. a CDN stripped Set-Cookie, or first-paint timing), so
	 * a real conversion isn't lost just because the cookie didn't arrive.
	 */
	public function impression_variant( int $experiment_id, string $visitor ): ?string {
		global $wpdb;
		$table   = Schema::events_table();
		$variant = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT variant FROM {$table} WHERE experiment_id = %d AND event_type = %s AND visitor_hash = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$experiment_id,
				self::EVENT_IMPRESSION,
				$visitor
			)
		);
		return is_string( $variant ) && '' !== $variant ? $variant : null;
	}

	private function insert( int $experiment_id, string $variant, string $event_type, string $visitor, string $test_url = '' ): void {
		global $wpdb;

		// Resolve the test URL from the experiment if not passed (e.g. async conversion call).
		if ( '' === $test_url ) {
			$test_url = Experiment::get_test_url( $experiment_id );
		}

		$wpdb->insert(
			Schema::events_table(),
			[
				'experiment_id' => $experiment_id,
				'variant'       => $variant,
				'test_url'      => '' === $test_url ? null : $test_url,
				'event_type'    => $event_type,
				'visitor_hash'  => $visitor,
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		/**
		 * Fires after an event row is written. v2 GA4/webhook integrations will hook here.
		 *
		 * @param int    $experiment_id
		 * @param string $variant       'A' or 'B'
		 * @param string $event_type    'impression' or 'conversion'
		 * @param string $visitor       sha256 hash
		 * @param string $test_url      The URL the test was served on (may be empty for legacy events)
		 */
		do_action( 'abtest_event_logged', $experiment_id, $variant, $event_type, $visitor, $test_url );
	}

	private function already_logged( int $experiment_id, string $variant, string $event_type, string $visitor ): bool {
		global $wpdb;
		$table = Schema::events_table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE experiment_id = %d AND variant = %s AND event_type = %s AND visitor_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$experiment_id,
				$variant,
				$event_type,
				$visitor
			)
		);
		return $count > 0;
	}

	private function already_logged_today( int $experiment_id, string $variant, string $event_type, string $visitor ): bool {
		$key = $this->dedup_key( $experiment_id, $variant, $event_type, $visitor );
		return (bool) get_transient( $key );
	}

	private function mark_logged( int $experiment_id, string $variant, string $event_type, string $visitor ): void {
		set_transient(
			$this->dedup_key( $experiment_id, $variant, $event_type, $visitor ),
			1,
			self::SESSION_DEDUP_TRANSIENT_TTL
		);
	}

	private function dedup_key( int $experiment_id, string $variant, string $event_type, string $visitor ): string {
		return 'abtest_dedup_' . md5( $experiment_id . '_' . $variant . '_' . $event_type . '_' . $visitor );
	}
}

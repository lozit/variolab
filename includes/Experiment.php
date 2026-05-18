<?php
/**
 * Experiment custom post type and meta accessors.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Experiment {

	public const POST_TYPE = 'abtest_experiment';

	public const META_TEST_URL          = '_abtest_test_url';
	public const META_VARIANTS          = '_abtest_variants';
	public const META_CONTROL_ID        = '_abtest_control_id';      // legacy — mirrors first variant.
	public const META_VARIANT_ID        = '_abtest_variant_id';      // legacy — mirrors second variant.
	public const META_GOAL_TYPE         = '_abtest_goal_type';
	public const META_GOAL_VALUE        = '_abtest_goal_value';
	public const META_STATUS            = '_abtest_status';
	public const META_STARTED_AT        = '_abtest_started_at';
	public const META_ENDED_AT          = '_abtest_ended_at';
	public const META_SCHEDULE_START_AT = '_abtest_schedule_start_at';
	public const META_SCHEDULE_END_AT   = '_abtest_schedule_end_at';
	public const META_TARGET_DEVICES    = '_abtest_target_devices';
	public const META_TARGET_COUNTRIES  = '_abtest_target_countries';

	public const DEVICES = [ 'mobile', 'tablet', 'desktop' ];

	public const MAX_VARIANTS = 4;
	public const VARIANT_LABELS = [ 'A', 'B', 'C', 'D' ];

	public const STATUS_DRAFT   = 'draft';
	public const STATUS_RUNNING = 'running';
	public const STATUS_PAUSED  = 'paused';
	public const STATUS_ENDED   = 'ended';

	public const GOAL_URL      = 'url';
	public const GOAL_SELECTOR = 'selector';

	public static function register(): void {
		$labels = [
			'name'          => __( 'A/B Tests', 'variolab-ab-testing' ),
			'singular_name' => __( 'A/B Test', 'variolab-ab-testing' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => 'page',
				'supports'        => [ 'title' ],
			]
		);

		register_post_meta( self::POST_TYPE, self::META_TEST_URL, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_VARIANTS, [ 'type' => 'array', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_CONTROL_ID, [ 'type' => 'integer', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_VARIANT_ID, [ 'type' => 'integer', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_GOAL_TYPE, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_GOAL_VALUE, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_STATUS, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_STARTED_AT, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_ENDED_AT, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_SCHEDULE_START_AT, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_SCHEDULE_END_AT, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_TARGET_DEVICES, [ 'type' => 'array', 'single' => true, 'show_in_rest' => false ] );
		register_post_meta( self::POST_TYPE, self::META_TARGET_COUNTRIES, [ 'type' => 'array', 'single' => true, 'show_in_rest' => false ] );
	}

	/**
	 * Find the running experiment matching the given request URL.
	 *
	 * Two-step matching:
	 *   1. Path-only candidate lookup (SQL `LIKE 'path%'` over META_TEST_URL).
	 *   2. PHP filter: a candidate matches if every key/value pair in its
	 *      stored query string is also present in the visitor's request.
	 *      Extra visitor params (utm_*, fbclid, gclid, …) don't break the match.
	 *
	 * Path-only match (no `?` in either) preserves the legacy behaviour.
	 */
	public static function find_running_for_url( string $request_url ): ?\WP_Post {
		$normalized = self::normalize_path( $request_url );
		if ( '' === $normalized ) {
			return null;
		}
		$req_path   = self::path_only( $normalized );
		$req_params = self::query_params( $normalized );

		global $wpdb;
		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				// Pull every running experiment whose stored test_url starts with the
				// request path (so /promo/ matches both stored "/promo/" and "/promo/?campaign=fb").
				"SELECT m.post_id
				   FROM {$wpdb->postmeta} m
				   JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s AND s.meta_value = %s
				  WHERE m.meta_key = %s
				    AND ( m.meta_value = %s OR m.meta_value LIKE %s )",
				self::META_STATUS,
				self::STATUS_RUNNING,
				self::META_TEST_URL,
				$req_path,
				$wpdb->esc_like( $req_path ) . '?%'
			)
		);

		foreach ( (array) $candidates as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || self::POST_TYPE !== $post->post_type ) {
				continue;
			}
			$stored = self::normalize_path( (string) get_post_meta( (int) $post_id, self::META_TEST_URL, true ) );
			if ( self::path_only( $stored ) !== $req_path ) {
				continue;
			}
			// Subset check: every required param of the stored URL must be present in the request.
			$required = self::query_params( $stored );
			$ok = true;
			foreach ( $required as $k => $v ) {
				if ( ! array_key_exists( $k, $req_params ) || (string) $req_params[ $k ] !== (string) $v ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Normalize a URL path + optional query into the canonical stored form.
	 *
	 * - Path: leading slash, trailing slash, lowercase (Unicode-aware).
	 * - Query string (if present): kept, with params sorted alphabetically by key
	 *   so two visitor URLs with the same params in different order produce the
	 *   same canonical form.
	 *
	 * Returns empty string for invalid input.
	 */
	public static function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '';
		}

		// Drop the URL fragment (#section) — never sent to the server anyway.
		$hash_pos = strpos( $path, '#' );
		if ( false !== $hash_pos ) {
			$path = substr( $path, 0, $hash_pos );
		}

		// Split path and query.
		$query = '';
		$qmark = strpos( $path, '?' );
		if ( false !== $qmark ) {
			$query = (string) substr( $path, $qmark + 1 );
			$path  = (string) substr( $path, 0, $qmark );
		}

		// Decode percent-encoding so unicode paths like "/promotion-%C3%A9t%C3%A9/" match
		// the stored "/promotion-été/" form.
		$path = rawurldecode( $path );

		// Ensure leading slash on path.
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		// Ensure trailing slash (root path "/" stays "/").
		if ( '/' !== substr( $path, -1 ) ) {
			$path .= '/';
		}
		// Lowercase using mb_strtolower so "É" → "é" works and other non-ASCII letters fold properly.
		$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );

		// Sort query params alphabetically by key for canonical form.
		if ( '' !== $query ) {
			parse_str( $query, $params );
			if ( ! empty( $params ) ) {
				ksort( $params );
				$path .= '?' . http_build_query( $params );
			}
		}

		return $path;
	}

	/**
	 * Pull the path-only part of a normalized URL (drops the ?query if present).
	 * Used for the SQL lookup key — query subset is checked in PHP afterwards.
	 */
	public static function path_only( string $normalized_url ): string {
		$qmark = strpos( $normalized_url, '?' );
		return false === $qmark ? $normalized_url : substr( $normalized_url, 0, $qmark );
	}

	/**
	 * Parse the ?query of a normalized URL into an assoc array of key=>value.
	 *
	 * @return array<string,string>
	 */
	public static function query_params( string $normalized_url ): array {
		$qmark = strpos( $normalized_url, '?' );
		if ( false === $qmark ) {
			return [];
		}
		parse_str( substr( $normalized_url, $qmark + 1 ), $out );
		return is_array( $out ) ? $out : [];
	}

	public static function get_test_url( int $experiment_id ): string {
		return (string) get_post_meta( $experiment_id, self::META_TEST_URL, true );
	}

	/**
	 * Return the experiment's variants as a list of [label, post_id] pairs.
	 * Falls back to the legacy single-pair meta (control_id + variant_id) if
	 * `_abtest_variants` was never populated (pre-v1.2.0 experiments).
	 *
	 * @return array<int, array{label:string, post_id:int}>
	 */
	public static function get_variants( int $experiment_id ): array {
		$raw = get_post_meta( $experiment_id, self::META_VARIANTS, true );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			$out = [];
			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$label   = isset( $entry['label'] ) ? (string) $entry['label'] : '';
				$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
				if ( '' !== $label && $post_id > 0 ) {
					$out[] = [ 'label' => $label, 'post_id' => $post_id ];
				}
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		// Legacy fallback : reconstruct from control_id + variant_id.
		$out = [];
		$control = (int) get_post_meta( $experiment_id, self::META_CONTROL_ID, true );
		if ( $control > 0 ) {
			$out[] = [ 'label' => 'A', 'post_id' => $control ];
		}
		$variant = (int) get_post_meta( $experiment_id, self::META_VARIANT_ID, true );
		if ( $variant > 0 ) {
			$out[] = [ 'label' => 'B', 'post_id' => $variant ];
		}
		return $out;
	}

	/**
	 * Persist the variants list, syncing the legacy single-pair meta for
	 * back-compat code that still calls get_control_id / get_variant_id.
	 *
	 * @param int                                            $experiment_id Experiment post ID.
	 * @param array<int, array{label:string, post_id:int}>  $variants      Ordered variants list (max MAX_VARIANTS).
	 */
	public static function set_variants( int $experiment_id, array $variants ): void {
		$clean = [];
		foreach ( $variants as $i => $entry ) {
			if ( $i >= self::MAX_VARIANTS ) {
				break;
			}
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			if ( $post_id <= 0 ) {
				continue;
			}
			$label   = self::VARIANT_LABELS[ count( $clean ) ] ?? '';
			if ( '' === $label ) {
				break;
			}
			$clean[] = [ 'label' => $label, 'post_id' => $post_id ];
		}

		update_post_meta( $experiment_id, self::META_VARIANTS, $clean );

		// Sync legacy meta keys for any older callers still reading them directly.
		$control = $clean[0]['post_id'] ?? 0;
		$variant = $clean[1]['post_id'] ?? 0;
		update_post_meta( $experiment_id, self::META_CONTROL_ID, (int) $control );
		update_post_meta( $experiment_id, self::META_VARIANT_ID, (int) $variant );
	}

	public static function get_variant_post_id( int $experiment_id, string $label ): int {
		foreach ( self::get_variants( $experiment_id ) as $v ) {
			if ( strcasecmp( (string) $v['label'], $label ) === 0 ) {
				return (int) $v['post_id'];
			}
		}
		return 0;
	}

	/**
	 * Return the labels (e.g. ['A','B','C']) configured on this experiment.
	 *
	 * @return string[]
	 */
	public static function get_variant_labels( int $experiment_id ): array {
		$out = [];
		foreach ( self::get_variants( $experiment_id ) as $v ) {
			$out[] = (string) $v['label'];
		}
		return $out;
	}

	public static function get_control_id( int $experiment_id ): int {
		$variants = self::get_variants( $experiment_id );
		return isset( $variants[0]['post_id'] ) ? (int) $variants[0]['post_id'] : 0;
	}

	public static function get_variant_id( int $experiment_id ): int {
		$variants = self::get_variants( $experiment_id );
		return isset( $variants[1]['post_id'] ) ? (int) $variants[1]['post_id'] : 0;
	}

	public static function get_status( int $experiment_id ): string {
		$status = (string) get_post_meta( $experiment_id, self::META_STATUS, true );
		return '' === $status ? self::STATUS_DRAFT : $status;
	}

	/**
	 * @return string[] List of device categories targeted ('mobile', 'tablet', 'desktop'),
	 *                  or empty array when no targeting (= every device).
	 */
	public static function get_target_devices( int $experiment_id ): array {
		$raw = get_post_meta( $experiment_id, self::META_TARGET_DEVICES, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values( array_intersect( $raw, self::DEVICES ) );
	}

	/**
	 * @return string[] List of ISO 3166-1 alpha-2 country codes targeted,
	 *                  or empty array when no targeting (= every country).
	 */
	public static function get_target_countries( int $experiment_id ): array {
		$raw = get_post_meta( $experiment_id, self::META_TARGET_COUNTRIES, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $code ) {
			$norm = strtoupper( trim( (string) $code ) );
			if ( preg_match( '/^[A-Z]{2}$/', $norm ) ) {
				$out[] = $norm;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function get_goal( int $experiment_id ): array {
		return [
			'type'  => (string) get_post_meta( $experiment_id, self::META_GOAL_TYPE, true ),
			'value' => (string) get_post_meta( $experiment_id, self::META_GOAL_VALUE, true ),
		];
	}

	/**
	 * State machine — what statuses can the experiment transition to from its current one?
	 *
	 * - DRAFT   → DRAFT, RUNNING                    (Start)
	 * - RUNNING → RUNNING, PAUSED, ENDED            (Pause | End)
	 * - PAUSED  → PAUSED, ENDED                     (End ; resume is handled via duplicate)
	 * - ENDED   → ENDED                              (terminal — no transitions)
	 *
	 * Resume from PAUSED → RUNNING is NOT in this list: it's done via the "Resume"
	 * action which duplicates the experiment so each run period has its own row
	 * with clean started_at/ended_at.
	 *
	 * @return string[]
	 */
	public static function allowed_next_statuses( string $current ): array {
		switch ( $current ) {
			case self::STATUS_DRAFT:
				return [ self::STATUS_DRAFT, self::STATUS_RUNNING ];
			case self::STATUS_RUNNING:
				return [ self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_ENDED ];
			case self::STATUS_PAUSED:
				return [ self::STATUS_PAUSED, self::STATUS_ENDED ];
			case self::STATUS_ENDED:
				return [ self::STATUS_ENDED ];
			default:
				// Unknown status: allow it to stay where it is, plus draft as a safe baseline.
				return [ $current, self::STATUS_DRAFT ];
		}
	}

	public static function is_transition_allowed( string $from, string $to ): bool {
		return in_array( $to, self::allowed_next_statuses( $from ), true );
	}
}

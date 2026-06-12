<?php
/**
 * REST controller — POST /abtest/v1/convert.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Rest;

use Abtest\Cookie;
use Abtest\Experiment;
use Abtest\Tracker;

defined( 'ABSPATH' ) || exit;

final class ConvertController {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'abtest/v1',
			'/convert',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'experiment_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					],
				],
			]
		);
	}

	/**
	 * Per-IP rate limit on the public `/convert` endpoint. Visitor-hash dedup
	 * already prevents the same browser from inflating its own count, but a
	 * distributed flood from N IPs could still bias stats. Cap each IP to 60
	 * conversions per minute. Filterable for sites with legitimate burst needs.
	 */
	private const RATE_LIMIT_PER_MIN = 60;

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$experiment_id = (int) $request->get_param( 'experiment_id' );

		// Experiment must exist and be running.
		$experiment = get_post( $experiment_id );
		if ( ! $experiment instanceof \WP_Post || Experiment::POST_TYPE !== $experiment->post_type ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'unknown_experiment' ], 404 );
		}
		if ( Experiment::STATUS_RUNNING !== Experiment::get_status( $experiment_id ) ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'not_running' ], 409 );
		}

		if ( $this->is_rate_limited() ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'rate_limited' ], 429 );
		}

		$visitor = Cookie::visitor_hash();

		// The variant is determined from server-side proof — the visitor's logged
		// impression — never trusted from the client. We prefer the variant cookie
		// ONLY when it matches a real impression for this visitor; otherwise we
		// derive the variant from the impression row itself. This does two things:
		//
		// 1. Security: a forged conversion (hand-crafted cookie for a guessed
		// experiment_id) has no matching impression and is rejected below, and
		// a tampered cookie can't pick the arm it skews — the impression wins.
		// Impressions are written server-side only (Router), so the rate stays
		// honest (every conversion is backed by an impression).
		//
		// 2. Robustness: a missing/stale variant cookie — a CDN stripping the
		// Set-Cookie header, or first-paint timing — no longer loses the
		// conversion (the previous "had to click twice" symptom). The impression
		// is the source of truth, so the cookie being absent is fine.
		//
		// Trade-off (accepted): the visitor hash is IP+UA based, so a legitimate
		// visitor whose IP/UA changes between the impression and this POST (mobile
		// Wi-Fi/cellular handoff, CGNAT, a browser update) has no matching impression
		// and is turned away. Fails *closed* (under-counts, never inflates).
		$variant = Cookie::get_variant( $experiment_id );
		if ( null === $variant || ! Tracker::instance()->has_impression( $experiment_id, $variant, $visitor ) ) {
			$variant = Tracker::instance()->impression_variant( $experiment_id, $visitor );
		}
		if ( null === $variant ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'no_impression' ], 409 );
		}

		$logged = Tracker::instance()->log_conversion( $experiment_id, $variant, $visitor );

		return new \WP_REST_Response(
			[
				'logged'  => $logged,
				'variant' => $variant,
			],
			$logged ? 201 : 200
		);
	}

	/**
	 * Transient-backed sliding bucket : 60 conversions / minute / IP. Returns true
	 * (= block this hit) once the bucket is full. The IP itself is hashed with
	 * wp_salt so we never store a raw address in the transient key.
	 */
	private function is_rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$key = 'abtest_convert_rl_' . substr( wp_hash( $ip, 'auth' ), 0, 16 );

		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'abtest_convert_rate_limit_per_min', self::RATE_LIMIT_PER_MIN );

		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}
}

<?php
/**
 * Integration tests : POST /abtest/v1/convert determines the variant from the
 * server-side impression, not the client cookie.
 *
 * Locks in the v0.15.10 robustness fix — a conversion is logged even when the
 * variant cookie is missing/stale (CDN stripping Set-Cookie, first-paint timing),
 * as long as the visitor has a logged impression; and a tampered cookie cannot
 * pick the arm it skews (the impression wins).
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Cookie;
use Abtest\Experiment;
use Abtest\Rest\ConvertController;
use Abtest\Schema;
use Abtest\Tracker;
use WP_REST_Request;
use WP_UnitTestCase;

final class ConvertControllerTest extends WP_UnitTestCase {

	private int $exp_id;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR']     = '198.51.100.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$this->exp_id               = self::factory()->post->create( [ 'post_type' => Experiment::POST_TYPE ] );
		update_post_meta( $this->exp_id, Experiment::META_STATUS, Experiment::STATUS_RUNNING );
		unset( $_COOKIE[ Cookie::name( $this->exp_id ) ] );
	}

	private function insert_impression( string $variant, string $visitor ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::events_table(),
			[
				'experiment_id' => $this->exp_id,
				'variant'       => $variant,
				'test_url'      => '/promo/',
				'event_type'    => Tracker::EVENT_IMPRESSION,
				'visitor_hash'  => $visitor,
				'created_at'    => '2026-06-12 12:00:00',
			]
		);
	}

	private function dispatch(): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/abtest/v1/convert' );
		$req->set_param( 'experiment_id', $this->exp_id );
		return ConvertController::instance()->handle( $req );
	}

	public function test_conversion_logs_without_a_cookie_when_an_impression_exists(): void {
		$this->insert_impression( 'A', Cookie::visitor_hash() ); // server logged it; no cookie sent

		$res  = $this->dispatch();
		$data = $res->get_data();

		$this->assertSame( 201, $res->get_status() );
		$this->assertTrue( $data['logged'], 'Conversion is logged from the impression even with no cookie.' );
		$this->assertSame( 'A', $data['variant'], 'Variant is derived from the impression.' );
	}

	public function test_no_impression_is_rejected(): void {
		$res = $this->dispatch();

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'no_impression', $res->get_data()['reason'] );
	}

	public function test_tampered_cookie_cannot_pick_a_different_arm(): void {
		$this->insert_impression( 'B', Cookie::visitor_hash() ); // real impression is B
		$_COOKIE[ Cookie::name( $this->exp_id ) ] = 'a';         // attacker flips the cookie to A

		$res = $this->dispatch();

		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'B', $res->get_data()['variant'], 'The server-side impression wins over a tampered cookie.' );
	}
}

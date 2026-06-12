<?php
/**
 * Integration tests : conversion logging is gated on a prior impression.
 *
 * Locks in the M1 security fix (audit 2026-06-12) — a conversion is only
 * recorded when this (experiment, variant, visitor) already has a server-side
 * impression, so a forged /convert request for a guessed experiment_id with a
 * hand-crafted cookie cannot inflate stats.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Schema;
use Abtest\Tracker;
use WP_UnitTestCase;

final class TrackerConversionTest extends WP_UnitTestCase {

	private const EXP_ID  = 4242;
	private const VISITOR = 'abcdef0123456789';
	private const OTHER   = '0123456789abcdef';

	private function conversion_count(): int {
		global $wpdb;
		$table = Schema::events_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s", // phpcs:ignore WordPress.DB
				Tracker::EVENT_CONVERSION
			)
		);
	}

	public function test_has_impression_is_false_without_a_prior_impression(): void {
		$this->assertFalse(
			Tracker::instance()->has_impression( self::EXP_ID, 'A', self::VISITOR ),
			'A visitor with no impression row must not be seen as having one.'
		);
	}

	public function test_has_impression_is_true_after_an_impression(): void {
		// Insert the row directly so the visitor hash is deterministic
		// (log_impression() would hash the current request's IP/UA instead).
		$this->insert_impression( self::EXP_ID, 'A', self::VISITOR );

		$this->assertTrue(
			Tracker::instance()->has_impression( self::EXP_ID, 'A', self::VISITOR ),
			'After an impression row exists, has_impression must report it.'
		);
	}

	public function test_has_impression_is_variant_specific(): void {
		$this->insert_impression( self::EXP_ID, 'A', self::VISITOR );

		$this->assertTrue( Tracker::instance()->has_impression( self::EXP_ID, 'A', self::VISITOR ) );
		$this->assertFalse(
			Tracker::instance()->has_impression( self::EXP_ID, 'B', self::VISITOR ),
			'An impression on variant A must not vouch for a conversion on variant B (anti arm-skew).'
		);
		$this->assertFalse(
			Tracker::instance()->has_impression( self::EXP_ID, 'A', self::OTHER ),
			'An impression for one visitor must not vouch for another visitor.'
		);
	}

	public function test_conversion_dedup_still_holds(): void {
		$this->insert_impression( self::EXP_ID, 'A', self::VISITOR );

		$first  = Tracker::instance()->log_conversion( self::EXP_ID, 'A', self::VISITOR, '/promo/' );
		$second = Tracker::instance()->log_conversion( self::EXP_ID, 'A', self::VISITOR, '/promo/' );

		$this->assertTrue( $first, 'First conversion for the visitor is logged.' );
		$this->assertFalse( $second, 'A repeat conversion for the same visitor is deduped.' );
		$this->assertSame( 1, $this->conversion_count(), 'Exactly one conversion row should exist.' );
	}

	private function insert_impression( int $exp_id, string $variant, string $visitor ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::events_table(),
			[
				'experiment_id' => $exp_id,
				'variant'       => $variant,
				'test_url'      => '/promo/',
				'event_type'    => Tracker::EVENT_IMPRESSION,
				'visitor_hash'  => $visitor,
				'created_at'    => '2026-06-12 12:00:00',
			]
		);
	}
}

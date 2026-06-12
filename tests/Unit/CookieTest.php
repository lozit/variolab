<?php
/**
 * Unit tests for Cookie::pick_variant (deterministic split via seed).
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Cookie;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase {

	public function test_picks_only_a_or_b_by_default(): void {
		for ( $i = 0; $i < 100; $i++ ) {
			$variant = Cookie::pick_variant();
			$this->assertContains( $variant, [ 'A', 'B' ] );
		}
	}

	public function test_seed_makes_assignment_deterministic(): void {
		$first  = Cookie::pick_variant( [ 'A', 'B' ], 42 );
		$second = Cookie::pick_variant( [ 'A', 'B' ], 42 );
		$this->assertSame( $first, $second );
	}

	public function test_different_seeds_can_yield_different_variants(): void {
		$samples = [];
		for ( $seed = 0; $seed < 50; $seed++ ) {
			$samples[] = Cookie::pick_variant( [ 'A', 'B' ], $seed );
		}
		$unique = array_unique( $samples );
		$this->assertCount( 2, $unique, 'Across many seeds, both A and B should appear.' );
	}

	public function test_random_split_is_roughly_balanced(): void {
		$counts = [ 'A' => 0, 'B' => 0 ];
		for ( $i = 0; $i < 10000; $i++ ) {
			++$counts[ Cookie::pick_variant() ];
		}
		// 50/50 with 10k samples should sit well within [40%, 60%]
		$ratio = $counts['A'] / 10000;
		$this->assertGreaterThan( 0.40, $ratio );
		$this->assertLessThan( 0.60, $ratio );
	}

	public function test_picks_only_from_provided_labels(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$variant = Cookie::pick_variant( [ 'A', 'B', 'C', 'D' ] );
			$this->assertContains( $variant, [ 'A', 'B', 'C', 'D' ] );
		}
	}

	public function test_three_variant_split_is_roughly_uniform(): void {
		$counts = [ 'A' => 0, 'B' => 0, 'C' => 0 ];
		for ( $i = 0; $i < 9000; $i++ ) {
			++$counts[ Cookie::pick_variant( [ 'A', 'B', 'C' ] ) ];
		}
		// Each variant should be in roughly [25%, 41%] (1/3 ± buffer for variance).
		foreach ( $counts as $label => $n ) {
			$ratio = $n / 9000;
			$this->assertGreaterThan( 0.28, $ratio, "Variant $label too low: $ratio" );
			$this->assertLessThan( 0.39, $ratio, "Variant $label too high: $ratio" );
		}
	}

	public function test_baseline_mode_returns_first_label_only(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame( 'A', Cookie::pick_variant( [ 'A' ] ) );
		}
	}

	public function test_visitor_hash_is_stable_for_same_request_data(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.4';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$first  = Cookie::visitor_hash();
		$second = Cookie::visitor_hash();
		$this->assertSame( $first, $second );
		$this->assertSame( Cookie::HASH_LENGTH, strlen( $first ), 'visitor_hash is truncated to HASH_LENGTH (16) hex chars for RGPD minimization.' );
	}

	public function test_visitor_hash_changes_with_ua(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.4';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$a = Cookie::visitor_hash();
		$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
		$b = Cookie::visitor_hash();
		$this->assertNotSame( $a, $b );
	}

	public function test_visitor_hash_seeds_dedicated_salt_from_auth_salt(): void {
		unset( $GLOBALS['__abtest_options'][ Cookie::HASH_SALT_OPTION ] );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		$hash = Cookie::visitor_hash();

		// The salt option is seeded from wp_salt('auth') on first use, so the
		// existing wp_salt-based hash keeps matching (no one-time dedup reset).
		$this->assertSame( wp_salt( 'auth' ), get_option( Cookie::HASH_SALT_OPTION ) );
		$expected = substr( hash( 'sha256', '203.0.113.9|Mozilla/5.0|' . wp_salt( 'auth' ) ), 0, Cookie::HASH_LENGTH );
		$this->assertSame( $expected, $hash );
	}

	public function test_visitor_hash_uses_stored_salt_decoupled_from_auth_keys(): void {
		// Simulate a site whose salt was already seeded, then an AUTH_SALT rotation:
		// the stored salt must win, so the hash stays stable (dedup is not reset).
		$GLOBALS['__abtest_options'][ Cookie::HASH_SALT_OPTION ] = 'fixed-dedicated-salt';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		$expected = substr( hash( 'sha256', '203.0.113.9|Mozilla/5.0|fixed-dedicated-salt' ), 0, Cookie::HASH_LENGTH );
		$this->assertSame( $expected, Cookie::visitor_hash() );
	}
}

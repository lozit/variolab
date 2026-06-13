<?php
/**
 * Integration test : CacheBypass::random_classic_url() picks a normal page, never
 * an A/B test variant page — used as the cache baseline ("this SHOULD be cached").
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\CacheBypass;
use Abtest\Experiment;
use WP_UnitTestCase;

final class CacheBypassClassicUrlTest extends WP_UnitTestCase {

	public function test_never_returns_a_variant_page(): void {
		$normal       = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Normal' ] );
		$variant_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Variant' ] );

		$exp = self::factory()->post->create( [ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ] );
		Experiment::set_variants( $exp, [ [ 'post_id' => $variant_page ] ] );

		$variant_url = get_permalink( $variant_page );

		// orderby rand → probe several times; it must never be the variant page.
		for ( $i = 0; $i < 6; $i++ ) {
			$url = CacheBypass::random_classic_url();
			$this->assertNotSame( $variant_url, $url, 'The baseline must never be a test variant page.' );
			$this->assertNotEmpty( $url );
		}
	}

	public function test_falls_back_to_home_when_only_variant_pages_exist(): void {
		// A fresh test site may still have a default page; this just asserts we always
		// return a usable URL even when the only content is a variant page.
		$variant_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		$exp          = self::factory()->post->create( [ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ] );
		Experiment::set_variants( $exp, [ [ 'post_id' => $variant_page ] ] );

		$url = CacheBypass::random_classic_url();
		$this->assertNotSame( get_permalink( $variant_page ), $url );
		$this->assertNotEmpty( $url );
	}
}

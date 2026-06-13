<?php
/**
 * Unit test : CacheBypass::is_cache_probe() recognises the diagnostic header.
 *
 * The cache-check feature relies on this to skip impression logging + the
 * cache-resilient redirect for probe requests.
 *
 * @package Abtest\Tests\Unit
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\CacheBypass;
use PHPUnit\Framework\TestCase;

final class CacheProbeTest extends TestCase {

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] );
		parent::tearDown();
	}

	public function test_false_without_the_header(): void {
		unset( $_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] );
		$this->assertFalse( CacheBypass::is_cache_probe() );
	}

	public function test_true_with_the_header(): void {
		$_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] = '1';
		$this->assertTrue( CacheBypass::is_cache_probe() );
	}
}

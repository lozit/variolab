<?php
/**
 * Unit tests for the script-input parser (the function called at output time
 * to extract attrs + body from whatever the admin pasted into the per-URL
 * tracking-scripts field).
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\UrlScripts;
use PHPUnit\Framework\TestCase;

final class UrlScriptsTest extends TestCase {

	public function test_plain_js_body_passes_through(): void {
		$parsed = UrlScripts::parse_script_input( "gtag('event', 'click');" );
		$this->assertSame( "gtag('event', 'click');", $parsed['body'] );
		$this->assertSame( [], $parsed['attrs'] );
	}

	public function test_plain_js_body_is_trimmed(): void {
		$parsed = UrlScripts::parse_script_input( "  \n\nconsole.log(1);  \n" );
		$this->assertSame( 'console.log(1);', $parsed['body'] );
	}

	public function test_simple_script_wrapper_extracts_body(): void {
		$parsed = UrlScripts::parse_script_input( "<script>fbq('track', 'PageView');</script>" );
		$this->assertSame( "fbq('track', 'PageView');", $parsed['body'] );
		$this->assertSame( [], $parsed['attrs'] );
	}

	public function test_script_wrapper_with_src_attribute(): void {
		$parsed = UrlScripts::parse_script_input( '<script async src="https://www.googletagmanager.com/gtag/js?id=AW-XYZ"></script>' );
		$this->assertSame( '', $parsed['body'] );
		$this->assertSame( 'https://www.googletagmanager.com/gtag/js?id=AW-XYZ', $parsed['attrs']['src'] );
		$this->assertTrue( $parsed['attrs']['async'] );
	}

	public function test_script_wrapper_with_defer_and_type(): void {
		$parsed = UrlScripts::parse_script_input( '<script defer type="module">import x from "./y.js";</script>' );
		$this->assertSame( 'import x from "./y.js";', $parsed['body'] );
		$this->assertSame( 'module', $parsed['attrs']['type'] );
		$this->assertTrue( $parsed['attrs']['defer'] );
	}

	public function test_script_wrapper_with_id_attribute(): void {
		$parsed = UrlScripts::parse_script_input( '<script id="gtm-config">var x = 1;</script>' );
		$this->assertSame( 'var x = 1;', $parsed['body'] );
		$this->assertSame( 'gtm-config', $parsed['attrs']['id'] );
	}

	public function test_multi_script_strips_all_tags_degraded(): void {
		$input = '<script async src="https://cdn.example.com/a.js"></script><script>gtag("config", "X");</script>';
		$parsed = UrlScripts::parse_script_input( $input );
		// Degraded mode: both `<script>` tags stripped, bodies concatenated.
		$this->assertStringContainsString( 'gtag("config", "X");', $parsed['body'] );
		$this->assertStringNotContainsString( '<script', $parsed['body'] );
		$this->assertStringNotContainsString( '</script>', $parsed['body'] );
		$this->assertSame( [], $parsed['attrs'] );
	}

	public function test_orphan_opening_tag_is_stripped(): void {
		$parsed = UrlScripts::parse_script_input( '<script type="text/javascript">var leftover;' );
		$this->assertSame( 'var leftover;', $parsed['body'] );
		$this->assertSame( [], $parsed['attrs'] );
	}

	public function test_single_quotes_in_attributes(): void {
		$parsed = UrlScripts::parse_script_input( "<script src='https://example.com/track.js'></script>" );
		$this->assertSame( 'https://example.com/track.js', $parsed['attrs']['src'] );
	}

	public function test_boolean_attribute_appears_as_true(): void {
		$parsed = UrlScripts::parse_script_input( '<script nomodule>fallback();</script>' );
		$this->assertTrue( $parsed['attrs']['nomodule'] );
		$this->assertSame( 'fallback();', $parsed['body'] );
	}

	public function test_empty_input_yields_empty_body(): void {
		$parsed = UrlScripts::parse_script_input( '' );
		$this->assertSame( '', $parsed['body'] );
		$this->assertSame( [], $parsed['attrs'] );
	}
}

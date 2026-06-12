<?php
/**
 * Unit tests : the conversion tracker is excluded from delay-JS optimisers.
 *
 * WP Rocket / Perfmatters "Delay JavaScript Execution" would otherwise defer the
 * tracker until the first interaction, so its click listener isn't attached when
 * the visitor first clicks — the "had to click twice" bug.
 *
 * @package Abtest\Tests\Unit
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Tracker;
use PHPUnit\Framework\TestCase;

final class TrackerDelayJsTest extends TestCase {

	public function test_adds_tracker_patterns_to_the_exclusion_list(): void {
		$out = Tracker::instance()->exclude_from_delay_js( [ 'some/other/script.js' ] );

		$this->assertContains( 'variolab-ab-testing/assets/js/tracker.js', $out, 'External tracker is excluded.' );
		$this->assertContains( 'AbtestTracker', $out, 'Inline config is excluded.' );
		$this->assertContains( 'some/other/script.js', $out, 'Existing exclusions are preserved.' );
	}

	public function test_non_array_input_is_returned_untouched(): void {
		$this->assertNull( Tracker::instance()->exclude_from_delay_js( null ) );
		$this->assertSame( '', Tracker::instance()->exclude_from_delay_js( '' ) );
	}
}

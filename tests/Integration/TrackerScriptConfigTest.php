<?php
/**
 * Integration tests : the conversion tracker is injected into Blank Canvas pages,
 * and switches to admin-preview mode for logged-in editors.
 *
 * Blank Canvas (imported-HTML) pages never run wp_enqueue_scripts, so the tracker
 * has to be injected by the template via Tracker::blank_canvas_script_tags().
 * Before v0.15.9 it wasn't injected at all, so click/URL conversion goals never
 * fired on imported landings.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Experiment;
use Abtest\Router;
use Abtest\Tracker;
use WP_UnitTestCase;

final class TrackerScriptConfigTest extends WP_UnitTestCase {

	private int $experiment_id;

	public function set_up(): void {
		parent::set_up();
		$this->experiment_id = self::factory()->post->create( [ 'post_type' => Experiment::POST_TYPE ] );
		update_post_meta( $this->experiment_id, Experiment::META_GOAL_TYPE, Experiment::GOAL_SELECTOR );
		update_post_meta( $this->experiment_id, Experiment::META_GOAL_VALUE, '.buy-btn' );
		wp_set_current_user( 0 );
	}

	private function set_router_state( bool $tracked ): void {
		$router = Router::instance();
		$ref    = new \ReflectionClass( $router );
		foreach ( [ 'current_experiment' => get_post( $this->experiment_id ), 'current_is_tracked' => $tracked ] as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $router, $value );
		}
	}

	public function test_tracked_visitor_gets_the_real_tracker(): void {
		$this->set_router_state( true );

		$tags = Tracker::instance()->blank_canvas_script_tags();

		$this->assertStringContainsString( 'assets/js/tracker.js', $tags, 'tracker.js must be injected.' );
		$this->assertStringContainsString( 'window.AbtestTracker =', $tags );
		$this->assertStringContainsString( Experiment::GOAL_SELECTOR, $tags );
		$this->assertStringContainsString( '.buy-btn', $tags, 'The configured selector must reach the page.' );
		$this->assertStringContainsString( '"preview":false', $tags, 'A real visitor is not in preview mode.' );
	}

	public function test_logged_in_editor_gets_preview_mode_when_bypassed(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$this->set_router_state( false ); // bypassed (admin), so not tracked

		$tags = Tracker::instance()->blank_canvas_script_tags();

		$this->assertStringContainsString( 'assets/js/tracker.js', $tags, 'Admins still get the tracker, for preview.' );
		$this->assertStringContainsString( '"preview":true', $tags, 'Bypassed editor gets preview mode (no real logging).' );
	}

	public function test_untracked_anonymous_visitor_gets_nothing(): void {
		wp_set_current_user( 0 );
		$this->set_router_state( false ); // out-of-target / bot / consent-blocked

		$this->assertSame(
			'',
			Tracker::instance()->blank_canvas_script_tags(),
			'A genuinely untracked anonymous visitor must get no tracker.'
		);
	}

	public function test_no_experiment_means_no_tracker(): void {
		$router = Router::instance();
		$ref    = new \ReflectionClass( $router );
		$p      = $ref->getProperty( 'current_experiment' );
		$p->setAccessible( true );
		$p->setValue( $router, null );

		$this->assertSame( '', Tracker::instance()->blank_canvas_script_tags() );
	}
}

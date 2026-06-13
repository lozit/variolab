<?php
/**
 * Integration tests : the opt-in cache-resilient redirect (cache-buster).
 *
 * CacheBypass::cache_buster_script_tag() emits a client-side redirect to a unique
 * `?_abtcb=…` URL only when the mode is on, an experiment matches the request, and
 * the visitor is anonymous (logged-in users bypass server caches).
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\CacheBypass;
use Abtest\Experiment;
use Abtest\Router;
use WP_UnitTestCase;

final class CacheBusterTest extends WP_UnitTestCase {

	private int $exp_id;

	public function set_up(): void {
		parent::set_up();
		$this->exp_id = self::factory()->post->create( [ 'post_type' => Experiment::POST_TYPE ] );
		wp_set_current_user( 0 );
	}

	private function set_mode( bool $on ): void {
		$s                    = (array) get_option( 'abtest_settings', [] );
		$s['cache_resilient'] = $on;
		update_option( 'abtest_settings', $s );
	}

	private function set_current_experiment( ?int $exp_id ): void {
		$router = Router::instance();
		$p      = ( new \ReflectionClass( $router ) )->getProperty( 'current_experiment' );
		$p->setAccessible( true );
		$p->setValue( $router, $exp_id ? get_post( $exp_id ) : null );
	}

	public function test_off_by_default_emits_nothing(): void {
		$this->set_mode( false );
		$this->set_current_experiment( $this->exp_id );
		$this->assertSame( '', CacheBypass::cache_buster_script_tag() );
	}

	public function test_no_experiment_emits_nothing(): void {
		$this->set_mode( true );
		$this->set_current_experiment( null );
		$this->assertSame( '', CacheBypass::cache_buster_script_tag() );
	}

	public function test_logged_in_user_emits_nothing(): void {
		$this->set_mode( true );
		$this->set_current_experiment( $this->exp_id );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( '', CacheBypass::cache_buster_script_tag() );
	}

	public function test_anonymous_visitor_on_a_test_page_gets_the_redirect(): void {
		$this->set_mode( true );
		$this->set_current_experiment( $this->exp_id );
		wp_set_current_user( 0 );

		$tag = CacheBypass::cache_buster_script_tag();

		$this->assertStringContainsString( '<script', $tag );
		$this->assertStringContainsString( '_abtcb', $tag, 'Uses the cache-buster param.' );
		$this->assertStringContainsString( 'location.replace', $tag, 'Redirects without a history entry.' );
		$this->assertStringContainsString( 'searchParams.has', $tag, 'No-ops once the param is present (no loop).' );
	}

	public function test_diagnostic_probe_is_never_redirected(): void {
		$this->set_mode( true );
		$this->set_current_experiment( $this->exp_id );
		wp_set_current_user( 0 );
		$_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] = '1';

		$this->assertSame(
			'',
			CacheBypass::cache_buster_script_tag(),
			'A cache-diagnostic probe must reach the origin, not be redirected.'
		);

		unset( $_SERVER['HTTP_X_ABTEST_CACHE_CHECK'] );
	}
}

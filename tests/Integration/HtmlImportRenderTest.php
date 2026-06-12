<?php
/**
 * Integration tests : Blank Canvas raw-render trust gate.
 *
 * Locks in the audit-C hardening (v0.15.7) — HtmlImport::render_html() renders
 * content raw only when the import trust flag says the importer held
 * unfiltered_html; lower-trust content is re-filtered through wp_kses_post, and
 * legacy / filesystem pages (no flag) stay trusted so nothing regresses.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Admin\HtmlImport;
use WP_UnitTestCase;

final class HtmlImportRenderTest extends WP_UnitTestCase {

	private const RAW = '<h1>Hi</h1><script>alert(1)</script>';

	private function make_page( string $content, ?string $trusted ): \WP_Post {
		global $wpdb;
		$id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		// Write post_content directly: wp_insert_post() runs kses for the
		// (logged-out) test user and would strip the <script> before we can test
		// the render gate. This mirrors what an unfiltered_html user's import
		// actually stores.
		$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		if ( null !== $trusted ) {
			update_post_meta( $id, HtmlImport::META_TRUSTED, $trusted );
		}
		return get_post( $id );
	}

	public function test_trusted_content_is_rendered_raw(): void {
		$post = $this->make_page( self::RAW, '1' );
		$this->assertSame( self::RAW, HtmlImport::render_html( $post ), 'Trusted import must render byte-for-byte raw.' );
	}

	public function test_untrusted_content_is_kses_filtered(): void {
		$post = $this->make_page( self::RAW, '0' );
		$out  = HtmlImport::render_html( $post );

		$this->assertStringNotContainsString( '<script', $out, 'Untrusted import must have <script> stripped.' );
		$this->assertStringContainsString( '<h1>Hi</h1>', $out, 'Benign markup is preserved.' );
	}

	public function test_legacy_page_without_flag_is_treated_as_trusted(): void {
		$post = $this->make_page( self::RAW, null );
		$this->assertSame(
			self::RAW,
			HtmlImport::render_html( $post ),
			'A page predating the trust flag (or a Watcher/filesystem page) must keep rendering raw.'
		);
	}
}

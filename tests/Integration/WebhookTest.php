<?php
/**
 * Integration tests : webhook intake rejects SSRF-prone targets.
 *
 * Locks in the M2 security fix (audit 2026-06-12) — Webhook::set_all() drops
 * URLs whose host is a literal loopback / link-local / private / reserved IP,
 * and keeps legitimate public HTTPS endpoints and hostnames. Request-time
 * defence (reject_unsafe_urls) lives in Webhook::send() and is exercised by WP.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Integrations\Webhook;
use WP_UnitTestCase;

final class WebhookTest extends WP_UnitTestCase {

	private function stored_urls(): array {
		return array_column( Webhook::get_all(), 'url' );
	}

	private function save_url( string $url ): void {
		Webhook::set_all( [ [ 'name' => 'h', 'url' => $url, 'enabled' => true ] ] );
	}

	/**
	 * @dataProvider blocked_urls
	 */
	public function test_set_all_drops_ssrf_targets( string $url ): void {
		$this->save_url( $url );
		$this->assertSame( [], $this->stored_urls(), "Webhook to {$url} must be rejected." );
	}

	public function blocked_urls(): array {
		return [
			'loopback v4'      => [ 'http://127.0.0.1/hook' ],
			'loopback name'    => [ 'http://127.0.0.1:9000/x' ],
			'loopback v6'      => [ 'http://[::1]/hook' ],
			'cloud metadata'   => [ 'http://169.254.169.254/latest/meta-data/' ],
			'private 10/8'     => [ 'https://10.0.0.5/hook' ],
			'private 192.168'  => [ 'http://192.168.1.10/hook' ],
			'private 172.16'   => [ 'http://172.16.4.4/hook' ],
			'all-zeros'        => [ 'http://0.0.0.0/hook' ],
			'non-http scheme'  => [ 'gopher://example.com/x' ],
			'ftp scheme'       => [ 'ftp://example.com/x' ],
		];
	}

	/**
	 * @dataProvider allowed_urls
	 */
	public function test_set_all_keeps_legitimate_endpoints( string $url ): void {
		$this->save_url( $url );
		$this->assertSame( [ $url ], $this->stored_urls(), "Webhook to {$url} must be kept." );
	}

	public function allowed_urls(): array {
		return [
			'public hostname'   => [ 'https://hooks.zapier.com/hooks/catch/123/abc' ],
			'public host http'  => [ 'http://example.com/webhook' ],
			'public IP'         => [ 'https://93.184.216.34/hook' ],
		];
	}

	public function test_mixed_batch_keeps_only_safe_entries(): void {
		Webhook::set_all(
			[
				[ 'name' => 'bad',  'url' => 'http://169.254.169.254/x', 'enabled' => true ],
				[ 'name' => 'good', 'url' => 'https://hooks.example.com/abc', 'enabled' => true ],
				[ 'name' => 'lan',  'url' => 'http://192.168.0.1/x', 'enabled' => true ],
			]
		);
		$this->assertSame( [ 'https://hooks.example.com/abc' ], $this->stored_urls() );
	}
}

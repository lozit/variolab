<?php
/**
 * Generic webhook integration — POST events to any HTTP endpoint.
 *
 * Use cases: Zapier, Make, n8n, Slack/Discord incoming webhooks, custom data
 * pipelines, Mixpanel/Segment/Amplitude via their HTTP API.
 *
 * Listens to `abtest_event_logged` and fire-and-forget POSTs each event to every
 * enabled webhook. Optional HMAC signing for endpoint authenticity.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Integrations;

use Abtest\Experiment;
use Abtest\Tracker;

defined( 'ABSPATH' ) || exit;

final class Webhook {

	public const OPTION_KEY = 'abtest_webhooks';

	public const FIRE_ALL        = 'all';
	public const FIRE_CONVERSION = 'conversion';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'abtest_event_logged', [ $this, 'on_event' ], 10, 5 );
	}

	/**
	 * @return array<int, array{name:string,url:string,secret:string,fire_on:string,enabled:bool}>
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = [
				'name'    => isset( $entry['name'] ) ? (string) $entry['name'] : '',
				'url'     => isset( $entry['url'] ) ? (string) $entry['url'] : '',
				'secret'  => isset( $entry['secret'] ) ? (string) $entry['secret'] : '',
				'fire_on' => isset( $entry['fire_on'] ) && self::FIRE_CONVERSION === $entry['fire_on'] ? self::FIRE_CONVERSION : self::FIRE_ALL,
				'enabled' => ! empty( $entry['enabled'] ),
			];
		}
		return $out;
	}

	public static function set_all( array $webhooks ): void {
		$clean = [];
		foreach ( $webhooks as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			// Refuse non-HTTP(S) schemes. esc_url_raw() lets through gopher / ftp /
			// webcal / xmpp / etc. Webhooks must POST JSON over HTTP — anything else
			// is either a config mistake or an SSRF pivot from a compromised admin.
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			// Anti-SSRF: refuse URLs whose host is a literal loopback / link-local /
			// private / reserved IP (e.g. 127.0.0.1, 169.254.169.254 cloud metadata,
			// 10.0.0.0/8). Hostnames are left to wp_remote_post()'s reject_unsafe_urls
			// at request time, which resolves them and also covers DNS rebinding.
			if ( self::host_is_blocked( $url ) ) {
				continue;
			}
			$clean[] = [
				'name'    => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
				'url'     => $url,
				'secret'  => isset( $entry['secret'] ) ? (string) $entry['secret'] : '',
				'fire_on' => ( isset( $entry['fire_on'] ) && self::FIRE_CONVERSION === $entry['fire_on'] ) ? self::FIRE_CONVERSION : self::FIRE_ALL,
				'enabled' => ! empty( $entry['enabled'] ),
			];
		}
		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Whether a webhook URL points at a literal IP we refuse to call (loopback,
	 * link-local, private, or otherwise reserved). Returns false for hostnames —
	 * those are validated at request time by wp_remote_post()'s reject_unsafe_urls,
	 * which resolves the name and rejects private/reserved targets (and covers DNS
	 * rebinding, which an intake-time check cannot).
	 */
	private static function host_is_blocked( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}
		// Strip IPv6 brackets, e.g. "[::1]" -> "::1".
		$host = trim( $host, '[]' );

		// Not a literal IP -> a hostname; allow here, validated at request time.
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// A public IP passes NO_PRIV_RANGE|NO_RES_RANGE and returns the address;
		// loopback / link-local / private / reserved fail and return false -> block.
		return false === filter_var(
			$host,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	public function on_event( int $experiment_id, string $variant, string $event_type, string $visitor, string $test_url ): void {
		$webhooks = self::get_all();
		if ( empty( $webhooks ) ) {
			return;
		}

		$payload = self::build_payload( $experiment_id, $variant, $event_type, $visitor, $test_url );

		foreach ( $webhooks as $hook ) {
			if ( ! $hook['enabled'] || '' === $hook['url'] ) {
				continue;
			}
			if ( self::FIRE_CONVERSION === $hook['fire_on'] && Tracker::EVENT_CONVERSION !== $event_type ) {
				continue;
			}

			/**
			 * Allow short-circuiting per-webhook delivery.
			 *
			 * @param bool   $should True to send.
			 * @param array  $hook   Webhook config.
			 * @param array  $payload Event payload.
			 */
			$should = apply_filters( 'abtest_webhook_should_fire', true, $hook, $payload );
			if ( ! $should ) {
				continue;
			}

			self::send( $hook, $payload );
		}
	}

	/**
	 * Build the standard event payload sent to every webhook.
	 */
	public static function build_payload( int $experiment_id, string $variant, string $event_type, string $visitor, string $test_url ): array {
		$payload = [
			'event'             => 'abtest_' . $event_type,
			'experiment_id'     => $experiment_id,
			'experiment_title'  => (string) get_the_title( $experiment_id ),
			'variant'           => $variant,
			'test_url'          => $test_url,
			'visitor_hash'      => $visitor,
			'timestamp'         => gmdate( 'c' ),
			'site_url'          => home_url(),
		];

		/**
		 * Filter the outgoing webhook payload. Useful to add custom fields or strip sensitive ones.
		 *
		 * @param array $payload
		 */
		return (array) apply_filters( 'abtest_webhook_payload', $payload );
	}

	/**
	 * Send a single POST. Fire-and-forget (timeout 1s, blocking false) so page render is unaffected.
	 *
	 * Returns the wp_remote_post result for synchronous "send test" calls.
	 *
	 * @return array|\WP_Error
	 */
	public static function send( array $hook, array $payload, bool $blocking = false ) {
		$body    = (string) wp_json_encode( $payload );
		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent'   => 'AbTesting-WordPress/' . ABTEST_VERSION,
		];
		if ( '' !== (string) $hook['secret'] ) {
			$headers['X-Abtest-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $hook['secret'] );
		}

		return wp_remote_post(
			(string) $hook['url'],
			[
				'method'    => 'POST',
				'timeout'   => $blocking ? 8 : 1,
				'blocking'  => $blocking,
				'headers'   => $headers,
				'body'      => $body,
				// Explicit so a third-party `http_request_args` filter can't silently flip
				// SSL verification off — webhook endpoints carry analytics events, not
				// secrets, but we don't want to be the channel for a downgrade attack.
				'sslverify'         => true,
				// Anti-SSRF at request time: WP resolves the host and refuses loopback /
				// link-local / private / reserved targets (incl. hostnames that resolve
				// to them, and redirects to them). Complements the intake check in
				// set_all(), which only sees literal IPs.
				'reject_unsafe_urls' => true,
			]
		);
	}

	/**
	 * Build a synthetic payload for the "Send test event" admin button.
	 */
	public static function test_payload(): array {
		return [
			'event'            => 'abtest_test',
			'experiment_id'    => 0,
			'experiment_title' => 'Webhook test',
			'variant'          => 'A',
			'test_url'         => '/test/',
			'visitor_hash'     => str_repeat( '0', \Abtest\Cookie::HASH_LENGTH ),
			'timestamp'        => gmdate( 'c' ),
			'site_url'         => home_url(),
			'note'             => 'Synthetic event sent via the Send test event button — safe to ignore.',
		];
	}
}

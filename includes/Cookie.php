<?php
/**
 * Cookie helpers for variant assignment persistence.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Cookie {

	private const PREFIX = 'abtest_';

	public static function name( int $experiment_id ): string {
		return self::PREFIX . $experiment_id;
	}

	/**
	 * Read the variant cookie. Optionally validate against an allowed list of labels
	 * — useful when an experiment was reduced from 4 variants down to 2 mid-flight,
	 * a returning visitor with an obsolete cookie should be re-assigned.
	 *
	 * @param int      $experiment_id  Experiment post ID.
	 * @param string[] $allowed_labels Uppercase labels (e.g. ['A','B','C']). Empty = no constraint, accept A/B (legacy).
	 */
	public static function get_variant( int $experiment_id, array $allowed_labels = [] ): ?string {
		$key = self::name( $experiment_id );
		if ( ! isset( $_COOKIE[ $key ] ) ) {
			return null;
		}
		$value = sanitize_key( wp_unslash( $_COOKIE[ $key ] ) );
		$upper = strtoupper( $value );

		if ( empty( $allowed_labels ) ) {
			return ( 'A' === $upper || 'B' === $upper ) ? $upper : null;
		}
		return in_array( $upper, $allowed_labels, true ) ? $upper : null;
	}

	public static function set_variant( int $experiment_id, string $variant, int $days = 30 ): void {
		if ( headers_sent() ) {
			return;
		}
		$variant = strtoupper( $variant );
		// Accept any uppercase letter A–Z (we only use A–D today, but defensive).
		if ( strlen( $variant ) !== 1 || $variant < 'A' || $variant > 'Z' ) {
			return;
		}
		setcookie(
			self::name( $experiment_id ),
			strtolower( $variant ),
			[
				'expires'  => time() + DAY_IN_SECONDS * $days,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// Make the value readable later in the same request.
		$_COOKIE[ self::name( $experiment_id ) ] = strtolower( $variant );
	}

	/**
	 * Truncated digest length stored in the events table. 16 hex chars = 64 bits =
	 * ~1.8e19 unique values — birthday collision probability stays under 3e-8 even
	 * at 1M visitors per experiment, and dedup still works perfectly. Shorter
	 * digest reduces RGPD attack surface (less data to brute-force against IP+UA
	 * rainbow tables) and shrinks the column from CHAR(64) to CHAR(16).
	 */
	public const HASH_LENGTH = 16;

	/**
	 * Stable visitor hash for dedup. SHA-256 salted with wp_salt('auth') —
	 * non-reversible across sites — then truncated to HASH_LENGTH hex chars.
	 */
	public static function visitor_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return substr( hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'auth' ) ), 0, self::HASH_LENGTH );
	}

	/**
	 * Pick uniformly at random from the supplied list of variant labels.
	 * Default list ['A','B'] preserves the original A/B 50/50 behaviour.
	 *
	 * @param string[] $labels   Allowed labels, e.g. ['A','B','C'].
	 * @param int|null $seed     Optional seed for deterministic tests.
	 */
	public static function pick_variant( array $labels = [ 'A', 'B' ], ?int $seed = null ): string {
		if ( empty( $labels ) ) {
			return 'A';
		}
		if ( null !== $seed ) {
			mt_srand( $seed );
		}
		$choice = $labels[ mt_rand( 0, count( $labels ) - 1 ) ];
		if ( null !== $seed ) {
			mt_srand();
		}
		return $choice;
	}
}

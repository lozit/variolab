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
	 * Option holding the dedicated, stable salt for {@see visitor_hash()}.
	 * See {@see hash_salt()} for why it is seeded from wp_salt('auth').
	 */
	public const HASH_SALT_OPTION = 'abtest_hash_salt';

	/**
	 * Stable visitor hash for dedup. SHA-256 salted with a dedicated stored salt —
	 * non-reversible, single-site — then truncated to HASH_LENGTH hex chars.
	 *
	 * Granularity is intentionally coarse (IP + User-Agent only): it is GDPR-minimal
	 * (no cookie, no fingerprinting beyond what the request already carries) and not
	 * meant to be an unforgeable identity. An attacker varying the UA can mint fresh
	 * hashes, but since v0.15.3 a conversion also requires a matching server-side
	 * impression ({@see Tracker::has_impression()}), so the hash is no longer the
	 * sole dedup gate — strengthening the fingerprint would only add tracking surface
	 * for no real security gain. (Audit 2026-06-12, Low-B1: accepted by design.)
	 */
	public static function visitor_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return substr( hash( 'sha256', $ip . '|' . $ua . '|' . self::hash_salt() ), 0, self::HASH_LENGTH );
	}

	/**
	 * Dedicated salt for the visitor hash, decoupled from WordPress's auth keys.
	 *
	 * Seeded once from wp_salt('auth') on first use, so every visitor_hash already
	 * stored (which used wp_salt('auth') directly) keeps matching — no one-time dedup
	 * reset on upgrade. Once stored, the salt is stable, so a later AUTH_KEY/AUTH_SALT
	 * rotation (incident response, host key rotation) no longer silently resets dedup
	 * and re-counts every visitor. (Audit 2026-06-12, Low-E1.)
	 */
	private static function hash_salt(): string {
		$salt = get_option( self::HASH_SALT_OPTION );
		if ( is_string( $salt ) && '' !== $salt ) {
			return $salt;
		}
		$salt = wp_salt( 'auth' );
		add_option( self::HASH_SALT_OPTION, $salt );
		return $salt;
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

<?php
/**
 * Targeting — gate experiments by visitor device category and/or country.
 *
 * Visitors who don't match the configured targeting fall through (no variant
 * assigned, no impression logged, no cookie set). Admins in bypass mode
 * always pass — preview is independent of their actual device/country.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Targeting {

	/**
	 * True if the current visitor matches the experiment's targeting rules
	 * (or no targeting is configured).
	 */
	public static function matches( int $experiment_id ): bool {
		$devices = Experiment::get_target_devices( $experiment_id );
		if ( ! empty( $devices ) ) {
			$current = self::current_device();
			if ( ! in_array( $current, $devices, true ) ) {
				return false;
			}
		}

		$countries = Experiment::get_target_countries( $experiment_id );
		if ( ! empty( $countries ) ) {
			$current = self::current_country();
			// Unknown country can't match a positive targeting rule — exclude.
			if ( '' === $current || ! in_array( $current, $countries, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Classify the current visitor's User-Agent into one of: mobile, tablet, desktop.
	 */
	public static function current_device(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return self::device_from_ua( $ua );
	}

	public static function device_from_ua( string $ua ): string {
		if ( '' === $ua ) {
			return 'desktop';
		}
		// Tablets first (iPad and Android-without-Mobile-token are tablets).
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk(?!.*Mobile)/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Mobile/i', $ua ) ) {
			return 'tablet';
		}
		// Mobile : iPhone, iPod, Android with Mobile, Windows Phone, anything with "Mobi".
		if ( preg_match( '/iPhone|iPod|Mobile|Mobi|Windows Phone|webOS|BlackBerry/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Best-effort country detection from common edge / CDN headers.
	 * Returns '' when unknown.
	 */
	public static function current_country(): string {
		$headers = [
			'HTTP_CF_IPCOUNTRY',     // Cloudflare (most common — Kinsta uses this)
			'HTTP_X_GEO_COUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'HTTP_X_IPCOUNTRY',
			'HTTP_GEOIP_COUNTRY_CODE', // Apache mod_geoip / nginx ngx_http_geoip_module
		];
		foreach ( $headers as $h ) {
			if ( empty( $_SERVER[ $h ] ) ) {
				continue;
			}
			$raw  = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
			$code = strtoupper( substr( $raw, 0, 2 ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code && 'T1' !== $code ) {
				return $code;
			}
		}

		/**
		 * Last resort : let an external geo plugin or custom code provide the country.
		 *
		 * @param string $country Empty by default; return an ISO 3166-1 alpha-2 code.
		 */
		$filtered = strtoupper( trim( (string) apply_filters( 'abtest_visitor_country', '' ) ) );
		return preg_match( '/^[A-Z]{2}$/', $filtered ) ? $filtered : '';
	}
}

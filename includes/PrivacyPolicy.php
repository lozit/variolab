<?php
/**
 * Privacy policy guide content — registered with WordPress's native
 * privacy guide (Settings → Privacy → Policy Guide) so admins can
 * paste a factual description of A/B testing data into their privacy policy.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class PrivacyPolicy {

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add_suggested_content' ] );
	}

	/**
	 * Append our suggested privacy text to WP's native privacy guide.
	 *
	 * Text is intentionally factual (matches what the code actually stores)
	 * and conservative (lists every cookie + every DB column). Translatable.
	 */
	public static function add_suggested_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<h3>' . esc_html__( 'What this plugin stores', 'variolab' ) . '</h3>';
		$content .= '<p>' . esc_html__(
			'A/B Testing WordPress runs split tests on your pages. To do that, it needs to remember which variant a returning visitor was shown, and to count impressions and conversions. Here is exactly what is stored.',
			'variolab'
		) . '</p>';

		$content .= '<h4>' . esc_html__( 'Cookies set on the visitor\'s browser', 'variolab' ) . '</h4>';
		$content .= '<ul>';
		$content .= '<li>' . wp_kses(
			__( '<strong>Name:</strong> <code>abtest_{ID}</code> (one cookie per running experiment, where <code>{ID}</code> is the experiment ID).', 'variolab' ),
			[ 'strong' => [], 'code' => [] ]
		) . '</li>';
		$content .= '<li>' . wp_kses(
			__( '<strong>Value:</strong> a single lowercase letter (<code>a</code>, <code>b</code>, <code>c</code>, or <code>d</code>) — the variant assigned to this visitor.', 'variolab' ),
			[ 'strong' => [], 'code' => [] ]
		) . '</li>';
		$content .= '<li>' . wp_kses(
			__( '<strong>Lifetime:</strong> 30 days by default (configurable in the plugin settings).', 'variolab' ),
			[ 'strong' => [] ]
		) . '</li>';
		$content .= '<li>' . wp_kses(
			__( '<strong>Flags:</strong> HttpOnly, SameSite=Lax, Secure (when the site is served over HTTPS).', 'variolab' ),
			[ 'strong' => [] ]
		) . '</li>';
		$content .= '<li>' . wp_kses(
			__( '<strong>Purpose:</strong> ensures returning visitors see the same variant on subsequent page loads — the test would not be statistically valid otherwise.', 'variolab' ),
			[ 'strong' => [] ]
		) . '</li>';
		$content .= '</ul>';

		$content .= '<h4>' . esc_html__( 'Data stored on this site\'s database', 'variolab' ) . '</h4>';
		$content .= '<p>' . esc_html__(
			'Each impression and conversion creates a row in the events table with the following columns:',
			'variolab'
		) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . wp_kses(
			__( '<code>experiment_id</code>, <code>variant</code> (a/b/c/d), <code>test_url</code>, <code>event_type</code> (impression or conversion), <code>created_at</code> (timestamp).', 'variolab' ),
			[ 'code' => [] ]
		) . '</li>';
		$content .= '<li>' . wp_kses(
			__( '<code>visitor_hash</code> — a 16-character truncated SHA-256 hash (64 bits) computed from the visitor\'s IP address, User-Agent string, and a site-specific secret salt. <strong>The IP and User-Agent are never stored in their raw form.</strong> The hash is non-reversible, single-site, and changes whenever the visitor switches network or browser, so it cannot be linked back to a real-world identity from the database alone. Truncation to 64 bits further reduces the rainbow-table attack surface while preserving dedup integrity.', 'variolab' ),
			[ 'code' => [], 'strong' => [] ]
		) . '</li>';
		$content .= '</ul>';

		$content .= '<p>' . wp_kses(
			__( '<strong>The plugin does not store any of the following:</strong> raw IP addresses, raw User-Agent strings, email addresses, names, WordPress user IDs, third-party cookies, cross-site tracking identifiers.', 'variolab' ),
			[ 'strong' => [] ]
		) . '</p>';

		$content .= '<h4>' . esc_html__( 'Right to erasure', 'variolab' ) . '</h4>';
		$content .= '<p>' . esc_html__(
			'Because no reversible identifier is stored, the plugin cannot resolve a request like "delete the data associated with this email address" — there is no link between events and a person. To erase all A/B testing data, an administrator can truncate the events table from the WordPress dashboard.',
			'variolab'
		) . '</p>';

		$content .= '<h4>' . esc_html__( 'Third parties', 'variolab' ) . '</h4>';
		$content .= '<p>' . esc_html__(
			'By default, no data is sent to third parties. Optional integrations (Google Analytics 4, custom webhooks) are off until an administrator configures them in the plugin settings — if you enable any, list the destination here.',
			'variolab'
		) . '</p>';

		$content .= '<h4>' . esc_html__( 'Consent', 'variolab' ) . '</h4>';
		$content .= '<p>' . wp_kses(
			__( 'A "Require consent" toggle is available in the plugin settings. When enabled, the plugin sets no cookie and logs no event until the <code>abtest_visitor_has_consent</code> filter returns <code>true</code> — wire this to your consent banner (Complianz, CookieYes, Cookiebot, or custom).', 'variolab' ),
			[ 'code' => [] ]
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Variolab – A/B Testing', 'variolab' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}

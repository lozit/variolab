<?php
/**
 * HelpTabs — registers WordPress's native contextual help on the A/B Tests
 * admin screens. Visible via the "Help" pull-down at the top-right of every
 * wp-admin page.
 *
 * Goal : non-statisticians who install the plugin should find a friendly
 * primer on p-value / α / Bonferroni / sample-size, plus a quick-start guide,
 * without leaving the admin.
 *
 * @package Abtest
 */

namespace Abtest\Admin;

defined( 'ABSPATH' ) || exit;

final class HelpTabs {

	public static function register(): void {
		add_action( 'current_screen', [ self::class, 'maybe_attach' ] );
	}

	public static function maybe_attach( \WP_Screen $screen ): void {
		if ( ! self::is_abtest_screen( $screen ) ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'      => 'abtest-quick-start',
				'title'   => __( 'Quick start', 'variolab' ),
				'content' => self::tab_quick_start(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-stats',
				'title'   => __( 'Stats explained', 'variolab' ),
				'content' => self::tab_stats(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-multi',
				'title'   => __( 'Multi-variant', 'variolab' ),
				'content' => self::tab_multi(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-privacy',
				'title'   => __( 'Privacy & GDPR', 'variolab' ),
				'content' => self::tab_privacy(),
			]
		);

		$screen->set_help_sidebar( self::sidebar() );
	}

	private static function is_abtest_screen( \WP_Screen $screen ): bool {
		// Our admin pages all live under the `ab-testing` menu slug; the screen ID
		// looks like 'toplevel_page_ab-testing' or 'a-b-tests_page_…' depending on
		// nesting. Match anything that contains 'ab-testing'.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only screen detection, no mutation
		return false !== strpos( (string) $screen->id, 'ab-testing' )
			|| ( isset( $_GET['page'] ) && 0 === strpos( (string) wp_unslash( $_GET['page'] ), 'ab-testing' ) );
		// phpcs:enable
	}

	private static function tab_quick_start(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Run your first A/B test in 3 minutes', 'variolab' ); ?></h3>
		<ol>
			<li><strong><?php esc_html_e( 'Prepare 2 WordPress pages', 'variolab' ); ?></strong> — <?php esc_html_e( 'the current version (Variant A) and the new one (Variant B). Status "Private" is fine: the plugin still serves them via the test URL.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'A/B Tests → Add new', 'variolab' ); ?></strong> — <?php esc_html_e( 'pick the test URL (e.g. /landing/), select the 2 variants, define the goal (URL reached or CSS-selector clicked).', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'Click "Save & Start"', 'variolab' ); ?></strong> — <?php esc_html_e( 'visitors are split 50/50 by persistent cookie. Live stats appear on the main list.', 'variolab' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Import an existing landing page (HTML)', 'variolab' ); ?></h3>
		<p><?php esc_html_e( 'Got a landing page authored outside WordPress? Go to Import HTML and drop your .html, .htm or .zip (with CSS/JS/image assets). The plugin imports it with a "Blank Canvas" template (byte-perfect render, zero WordPress wrapper).', 'variolab' ); ?></p>

		<h3><?php esc_html_e( 'Iterate from your IDE', 'variolab' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: code path */
			esc_html__( 'Edit your pages directly under %s — the Watch Directory feature (5-minute cron) syncs your file changes into WordPress pages without manual intervention.', 'variolab' ),
			'<code>wp-content/uploads/abtest-templates/{slug}/index.html</code>'
		);
		?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_stats(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Why does it sometimes say "No winner" even though one variant looks better?', 'variolab' ); ?></h3>
		<p><?php esc_html_e( 'The plugin does not declare a winner just because one variant has a better raw conversion rate. It runs a statistical test (two-proportion z-test) to answer: "is this difference sharp enough to not be due to chance?". Until that can be asserted, no winner is announced.', 'variolab' ); ?></p>

		<h3><?php esc_html_e( 'Vocabulary in 4 words', 'variolab' ); ?></h3>
		<dl>
			<dt><strong>p-value</strong></dt>
			<dd><?php esc_html_e( 'The probability that the observed difference between A and B is pure chance. The smaller, the more solid. p=0.02 means "there is only a 2% probability this is luck".', 'variolab' ); ?></dd>

			<dt><strong>α (alpha)</strong></dt>
			<dd><?php esc_html_e( 'Your false-positive tolerance threshold, set in advance. CRO standard: 0.05 (5%). If p < α → declare a winner. If p > α → "not enough evidence", no call.', 'variolab' ); ?></dd>

			<dt><strong>Lift</strong></dt>
			<dd><?php esc_html_e( 'The relative difference in conversion rate between a variant and the baseline. B at 7.5% vs A at 5% → lift of +50%.', 'variolab' ); ?></dd>

			<dt><strong>95% CI (Confidence Interval)</strong></dt>
			<dd><?php esc_html_e( 'The range the true lift probably sits in. Lift = +50% [10% ; 90%] means "we are 95% sure the true gain is between +10% and +90%". The narrower the range, the more precise the measurement.', 'variolab' ); ?></dd>
		</dl>

		<h3><?php esc_html_e( 'The 4 common reasons for "No winner"', 'variolab' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Too early', 'variolab' ); ?></strong> — <?php esc_html_e( 'the test has been running for only a few days. On a site doing ~1000 visits/month, expect 2 to 4 weeks before reaching significance.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'Not enough samples', 'variolab' ); ?></strong> — <?php esc_html_e( 'with 100 visitors per variant, even a +50% lift can be a fluke. Aim for 500+ per variant to reliably detect a +30% lift.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'Genuine null result', 'variolab' ); ?></strong> — <?php esc_html_e( 'A and B convert at the same rate. The change you tested has no real effect. That\'s useful info: move on.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'Borderline', 'variolab' ); ?></strong> — <?php esc_html_e( 'p just above α (e.g. 0.06). Continuing 1–2 more weeks is often enough to call it.', 'variolab' ); ?></li>
		</ul>
		<p><em><?php esc_html_e( 'Hover the "No winner (α=…)" badge on the main list to see which of these reasons applies to your test.', 'variolab' ); ?></em></p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_multi(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Testing more than 2 variants (A/B/C/D)', 'variolab' ); ?></h3>
		<p><?php esc_html_e( 'The plugin supports up to 4 simultaneous variants (A, B, C, D) on the same URL. Traffic is split equally (1/N per variant).', 'variolab' ); ?></p>

		<h3><?php esc_html_e( 'Why α is stricter in multi-variant (Bonferroni correction)', 'variolab' ); ?></h3>
		<p><?php esc_html_e( 'When you compare several variants against the baseline in parallel, you multiply the chances of getting a false positive. To stay as cautious as in plain A/B, the plugin applies the Bonferroni correction:', 'variolab' ); ?></p>
		<pre style="background:#f6f7f7;padding:8px;border-radius:4px;">corrected α = global α / number of comparisons</pre>
		<table class="widefat striped" style="max-width:500px;">
			<thead><tr><th><?php esc_html_e( 'Variants', 'variolab' ); ?></th><th><?php esc_html_e( 'Comparisons', 'variolab' ); ?></th><th><?php esc_html_e( 'Effective α', 'variolab' ); ?></th></tr></thead>
			<tbody>
				<tr><td>A/B</td><td>1</td><td>0.050</td></tr>
				<tr><td>A/B/C</td><td>2</td><td>0.025</td></tr>
				<tr><td>A/B/C/D</td><td>3</td><td>0.017</td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Practical consequence: with 3 or 4 variants you need 2 to 3 times more visitors to reach significance. Stick with plain A/B unless you have a real reason to test several variants in parallel.', 'variolab' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_privacy(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'What the plugin stores (and does not store)', 'variolab' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Cookies', 'variolab' ); ?></strong> — <?php esc_html_e( 'one cookie per experiment named `abtest_{ID}`, value = variant letter (a/b/c/d), 30-day TTL, HttpOnly + SameSite=Lax + Secure.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'visitor_hash', 'variolab' ); ?></strong> — <?php esc_html_e( 'SHA-256 hash truncated to 16 chars (64 bits) of IP + User-Agent + site salt. Non-reversible, single-site, no raw IP/UA ever stored.', 'variolab' ); ?></li>
			<li><strong><?php esc_html_e( 'None of the following', 'variolab' ); ?></strong>: <?php esc_html_e( 'email, name, user identifier, third-party cookie, cross-site tracker.', 'variolab' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Enable consent gating (GDPR)', 'variolab' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: filter name */
			esc_html__( 'Settings → "Require visitor consent" + wire your cookie banner via the %s filter. Without consent, visitors silently see the baseline (zero cookie, zero tracking). Ready-made snippets for Complianz / CookieYes / Cookiebot are in the README.', 'variolab' ),
			'<code>abtest_visitor_has_consent</code>'
		);
		?>
		</p>

		<h3><?php esc_html_e( 'Privacy policy', 'variolab' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: file name */
			esc_html__( 'Ready-to-paste text: Settings → Privacy → Policy Guide → "AB Testing WordPress" (auto-generated by the plugin). Full threat model in %s on GitHub.', 'variolab' ),
			'<code>SECURITY.md</code>'
		);
		?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	private static function sidebar(): string {
		ob_start();
		?>
		<p><strong><?php esc_html_e( 'Going further', 'variolab' ); ?></strong></p>
		<p><a href="https://github.com/lozit/variolab" target="_blank" rel="noopener">GitHub README</a></p>
		<p><a href="https://github.com/lozit/variolab/blob/main/SECURITY.md" target="_blank" rel="noopener">Security policy</a></p>
		<p><a href="https://github.com/lozit/variolab/blob/main/docs/security/latest.md" target="_blank" rel="noopener">Latest security audit</a></p>
		<?php
		return (string) ob_get_clean();
	}
}

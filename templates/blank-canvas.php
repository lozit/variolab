<?php
/**
 * Blank Canvas template — outputs $post->post_content raw, nothing else.
 *
 * No wp_head(), no wp_footer(), no theme chrome, no admin bar. The user's
 * imported HTML document is rendered byte-perfect — it's expected to be a
 * complete document with its own <!DOCTYPE>, <head>, and <body>.
 *
 * Trade-off intentionnel : the WP admin bar will not appear on these pages.
 * Reasons we don't inject it:
 *  - WP admin bar CSS is scoped to `body > #wpadminbar`. To survive bundler/SPA
 *    pages that do document.body.replaceWith(...), we'd have to move the bar
 *    outside <body> — which breaks the styling.
 *  - Injecting wp_head() also pollutes the user's page with WP scripts they
 *    don't expect (jQuery, block library, emoji, etc.).
 *
 * For previewing a specific variant as admin, append ?abtest_preview=a|b|original
 * to the URL.
 *
 * @package Abtest
 */

defined( 'ABSPATH' ) || exit;

$abtest_post = get_queried_object();
if ( ! $abtest_post instanceof WP_Post ) {
	status_header( 404 );
	return;
}

nocache_headers();
header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

// Enforce the import trust flag: content imported by an unfiltered_html user is
// rendered raw; anything lower-trust is re-filtered through wp_kses_post. Makes
// the trust boundary explicit rather than leaning only on WP's save-time kses.
$abtest_html = \Abtest\Admin\HtmlImport::render_html( $abtest_post );

// Inject per-URL tracking scripts at the configured positions inside the user's HTML.
// We call wp_body_open / wp_footer indirectly via the same helper used by themed pages.
$abtest_router_url = \Abtest\Router::instance()->get_current_test_url();
if ( '' !== $abtest_router_url ) {
	$abtest_top    = \Abtest\UrlScripts::render_for_position( $abtest_router_url, \Abtest\UrlScripts::POSITION_AFTER_BODY_OPEN );
	$abtest_bottom = \Abtest\UrlScripts::render_for_position( $abtest_router_url, \Abtest\UrlScripts::POSITION_BEFORE_BODY_CLOSE );

	if ( '' !== $abtest_top ) {
		// Insert just after the first <body...> opening tag.
		if ( preg_match( '/<body\b[^>]*>/i', $abtest_html, $abtest_match, PREG_OFFSET_CAPTURE ) ) {
			$abtest_insert_at = (int) $abtest_match[0][1] + strlen( $abtest_match[0][0] );
			$abtest_html      = substr_replace( $abtest_html, $abtest_top, $abtest_insert_at, 0 );
		} else {
			// No <body> tag — fall back to prepending so the script still loads.
			$abtest_html = $abtest_top . $abtest_html;
		}
	}

	if ( '' !== $abtest_bottom ) {
		$abtest_body_close = stripos( $abtest_html, '</body>' );
		if ( false !== $abtest_body_close ) {
			$abtest_html = substr_replace( $abtest_html, $abtest_bottom, $abtest_body_close, 0 );
		} else {
			$abtest_html .= $abtest_bottom;
		}
	}
}

// Inject the conversion tracker. Themed pages get it via wp_enqueue_scripts, but
// Blank Canvas bypasses that pipeline — without this, click/URL conversion goals
// never fire on imported landings. Returns '' for untracked visitors (bots /
// out-of-target / consent); tracked visitors get the real tracker, logged-in
// admins get it in preview mode (toast on click, nothing logged).
$abtest_tracker = \Abtest\Tracker::instance()->blank_canvas_script_tags();
if ( '' !== $abtest_tracker ) {
	$abtest_body_close = stripos( $abtest_html, '</body>' );
	if ( false !== $abtest_body_close ) {
		$abtest_html = substr_replace( $abtest_html, $abtest_tracker, $abtest_body_close, 0 );
	} else {
		$abtest_html .= $abtest_tracker;
	}
}

// Raw passthrough: no the_content filter (which would run wpautop, shortcodes, etc.).
echo $abtest_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit;

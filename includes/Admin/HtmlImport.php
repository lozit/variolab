<?php
/**
 * HTML Import sub-page — upload a complete HTML document into a WordPress page
 * with the Blank Canvas template assigned.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Template;
use Abtest\Watcher;

defined( 'ABSPATH' ) || exit;

final class HtmlImport {

	public const NONCE       = 'abtest_import_html';
	public const SCAN_NONCE  = 'abtest_watch_scan';
	public const ALLOWED_EXTS = [ 'html', 'htm', 'zip' ];

	public const ASSETS_SUBDIR = 'abtest-templates';

	/**
	 * Maximum upload size in bytes. Default 5 MiB.
	 * Filterable via 'abtest_html_import_max_bytes'. Capped by PHP's upload_max_filesize.
	 */
	public static function max_bytes(): int {
		$default = 5 * 1048576;
		$filtered = (int) apply_filters( 'abtest_html_import_max_bytes', $default );
		$php_limit = wp_max_upload_size();
		return min( $filtered, $php_limit > 0 ? $php_limit : $filtered );
	}

	public static function render(): void {
		Admin::maybe_render_notice();

		$action_url = admin_url( 'admin-post.php' );
		$pages      = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'private', 'draft' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		?>
		<div class="wrap vlab-page abtest-wrap">
			<?php Admin::render_brand_header( __( 'Import HTML', 'variolab-ab-testing' ) ); ?>

			<p class="description">
				<?php esc_html_e( 'Upload a complete HTML document (with its own DOCTYPE, head, body) and import it as a WordPress page rendered with no theme wrapper. Built for landing pages designed outside WordPress — AI-generated exports (Claude, v0, Lovable, Cursor, bolt.new), hand-coded landings, or mockup-tool extracts.', 'variolab-ab-testing' ); ?>
			</p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="abtest-form abtest-import-form">
				<?php wp_nonce_field( self::NONCE, '_abtest_import_nonce' ); ?>
				<input type="hidden" name="action" value="abtest_import_html">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abtest-html-file"><?php esc_html_e( 'HTML file', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<div class="abtest-html-dropzone" data-max-bytes="<?php echo (int) self::max_bytes(); ?>">
								<input type="file" id="abtest-html-file" name="html_file" accept=".html,.htm,.zip" required>
								<p class="abtest-html-dropzone-hint">
									<?php esc_html_e( 'Drop a .html or .zip file here, or click to browse.', 'variolab-ab-testing' ); ?>
								</p>
								<p class="abtest-html-dropzone-meta" hidden></p>
							</div>
							<p class="description">
								<?php
								printf(
									/* translators: %s: max size, human-readable */
									esc_html__( 'Max %s. Accepted: .html, .htm, or .zip (extracts to wp-content/uploads/abtest-templates/{slug}/, rewriting relative asset paths so CSS/JS/images load).', 'variolab-ab-testing' ),
									esc_html( size_format( self::max_bytes() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr class="abtest-html-preview-row" hidden>
						<th scope="row"><?php esc_html_e( 'Preview', 'variolab-ab-testing' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Rendered as it will appear on the published page (sandboxed iframe — local scripts run in isolation, but external resources are blocked).', 'variolab-ab-testing' ); ?></p>
							<iframe class="abtest-html-preview-frame" sandbox="allow-scripts" srcdoc="" loading="lazy" title="<?php esc_attr_e( 'HTML preview', 'variolab-ab-testing' ); ?>"></iframe>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-target"><?php esc_html_e( 'Target', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<select id="abtest-target" name="target_page_id">
								<option value="0"><?php esc_html_e( '— Create a new page —', 'variolab-ab-testing' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>">
										<?php echo esc_html( get_the_title( $page ) . ' (#' . $page->ID . ' · ' . $page->post_status . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pick an existing page to overwrite, or leave on "Create a new page" to make a fresh one.', 'variolab-ab-testing' ); ?></p>
						</td>
					</tr>
					<tr class="abtest-new-page-row">
						<th scope="row"><label for="abtest-new-title"><?php esc_html_e( 'Page title (when creating new)', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="text" id="abtest-new-title" name="new_title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Variant A — Landing v1', 'variolab-ab-testing' ); ?>">
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import HTML', 'variolab-ab-testing' ) ); ?>
			</form>

			<?php self::render_watcher_panel( $action_url ); ?>
		</div>
		<?php
	}

	/**
	 * Watch directory panel : explains the workflow and offers a "Scan now" button
	 * so users don't have to wait for the 5-minute cron.
	 */
	private static function render_watcher_panel( string $action_url ): void {
		$watch_dir = Watcher::watch_dir();
		$last      = Watcher::last_run();
		?>
		<hr style="margin:32px 0;">
		<h2><?php esc_html_e( 'Watch directory (auto-sync)', 'variolab-ab-testing' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: full path to wp-content/uploads/abtest-templates/ */
				esc_html__( 'Drop or edit HTML files in %1$s — every 5 minutes WP-Cron syncs changed files into pages with the Blank Canvas template. New folders create a page; edits to an existing %2$s update the matching page.', 'variolab-ab-testing' ),
				'<code>' . esc_html( $watch_dir ) . '/{slug}/</code>',
				'<code>index.html</code>'
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Tip: combine with your IDE / SFTP / cloud sync (Dropbox, iCloud Drive…) so changes propagate without leaving the editor. The watcher only adds and updates — it never deletes pages.', 'variolab-ab-testing' ); ?>
		</p>
		<?php if ( null !== $last ) : ?>
			<p>
				<strong><?php esc_html_e( 'Last scan:', 'variolab-ab-testing' ); ?></strong>
				<?php
				$ago = human_time_diff( (int) $last['time'], time() );
				printf(
					/* translators: 1: time ago, 2: created count, 3: updated count, 4: skipped count */
					esc_html__( '%1$s ago — %2$d created, %3$d updated, %4$d unchanged.', 'variolab-ab-testing' ),
					esc_html( $ago ),
					(int) ( $last['stats']['created'] ?? 0 ),
					(int) ( $last['stats']['updated'] ?? 0 ),
					(int) ( $last['stats']['skipped'] ?? 0 )
				);
				if ( ! empty( $last['stats']['errors'] ) ) {
					echo ' <span style="color:#b32d2e;">' . esc_html( implode( ' · ', (array) $last['stats']['errors'] ) ) . '</span>';
				}
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:8px;">
			<?php wp_nonce_field( self::SCAN_NONCE, '_abtest_scan_nonce' ); ?>
			<input type="hidden" name="action" value="abtest_watch_scan">
			<?php submit_button( __( 'Scan now', 'variolab-ab-testing' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function handle_scan_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab-ab-testing' ), 403 );
		}
		check_admin_referer( self::SCAN_NONCE, '_abtest_scan_nonce' );

		$stats = Watcher::scan();

		$message = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped */
			__( 'Scan complete: %1$d created, %2$d updated, %3$d unchanged.', 'variolab-ab-testing' ),
			(int) $stats['created'],
			(int) $stats['updated'],
			(int) $stats['skipped']
		);
		if ( ! empty( $stats['errors'] ) ) {
			$message .= ' ' . implode( ' · ', (array) $stats['errors'] );
			$type     = 'warning';
		} else {
			$type = 'success';
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => Admin::menu_slug(),
					'action'          => 'import',
					'abtest_notice'   => rawurlencode( $message ),
					'abtest_notice_t' => $type,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_upload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'variolab-ab-testing' ), 403 );
		}
		check_admin_referer( self::NONCE, '_abtest_import_nonce' );

		// Validate upload.
		if ( ! isset( $_FILES['html_file'] ) || ! is_array( $_FILES['html_file'] ) ) {
			self::redirect_error( __( 'No file uploaded.', 'variolab-ab-testing' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES['html_file'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::redirect_error( self::upload_error_message( (int) ( $file['error'] ?? -1 ) ) );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 ) {
			self::redirect_error( __( 'Empty file.', 'variolab-ab-testing' ) );
		}
		if ( $size > self::max_bytes() ) {
			self::redirect_error(
				sprintf(
					/* translators: 1: actual size, 2: limit */
					__( 'File too large (%1$s). Max %2$s.', 'variolab-ab-testing' ),
					size_format( $size ),
					size_format( self::max_bytes() )
				)
			);
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
			self::redirect_error(
				sprintf(
					/* translators: 1: comma-separated list of allowed extensions, 2: rejected extension */
					__( 'Only %1$s files are accepted (got: .%2$s).', 'variolab-ab-testing' ),
					'.' . implode( ', .', self::ALLOWED_EXTS ),
					'' === $ext ? '(none)' : $ext
				)
			);
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			self::redirect_error( __( 'Upload failed.', 'variolab-ab-testing' ) );
		}

		// Real MIME check (finfo magic bytes) on top of the extension allowlist.
		// For .zip this catches a PHP file renamed to .zip (magic bytes don't lie) ;
		// for .html / .htm the function falls back to extension-matching since HTML
		// has no unique magic signature, so the allowlist still gates those.
		$mime_check = wp_check_filetype_and_ext(
			$tmp_name,
			$name,
			[
				'html' => 'text/html',
				'htm'  => 'text/html',
				'zip'  => 'application/zip',
			]
		);
		if ( empty( $mime_check['ext'] ) || empty( $mime_check['type'] ) ) {
			self::redirect_error(
				sprintf(
					/* translators: %s: rejected extension */
					__( 'File MIME type does not match its .%s extension. Refused.', 'variolab-ab-testing' ),
					$ext
				)
			);
		}

		$target_id = isset( $_POST['target_page_id'] ) ? absint( wp_unslash( $_POST['target_page_id'] ) ) : 0;
		$new_title = isset( $_POST['new_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_title'] ) ) : '';

		// .zip → extract assets to uploads/, rewrite paths in HTML, then proceed as HTML import.
		$assets_slug = '';
		if ( 'zip' === $ext ) {
			$assets_slug = self::compute_assets_slug( $target_id, $new_title );
			$result      = self::extract_zip_to_uploads( $tmp_name, $assets_slug );
			if ( is_wp_error( $result ) ) {
				self::redirect_error( $result->get_error_message() );
			}
			$contents = $result; // string: the rewritten HTML
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload tmp path, wp_remote_get does not apply
			$contents = file_get_contents( $tmp_name );
			if ( false === $contents ) {
				self::redirect_error( __( 'Could not read uploaded file.', 'variolab-ab-testing' ) );
			}
		}

		if ( $target_id > 0 ) {
			$page_id = self::replace_existing( $target_id, $contents );
		} else {
			$page_id = self::create_new( $new_title, $contents );
		}

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			$msg = is_wp_error( $page_id ) ? $page_id->get_error_message() : __( 'Failed to save page.', 'variolab-ab-testing' );
			self::redirect_error( (string) $msg );
		}

		// Always assign the Blank Canvas template (idempotent).
		update_post_meta( (int) $page_id, '_wp_page_template', Template::TEMPLATE_SLUG );

		// Tag zip-imported pages with their on-disk slug so the Watcher recognizes
		// them as managed and updates this same post instead of creating a duplicate.
		if ( '' !== $assets_slug ) {
			$index_path = trailingslashit( wp_upload_dir()['basedir'] ) . self::ASSETS_SUBDIR . '/' . $assets_slug . '/index.html';
			if ( file_exists( $index_path ) ) {
				update_post_meta( (int) $page_id, '_abtest_watcher_slug', $assets_slug );
				update_post_meta( (int) $page_id, '_abtest_watcher_hash', hash_file( 'sha256', $index_path ) );
			}
		}

		self::redirect_success(
			sprintf(
				/* translators: 1: page title, 2: page ID */
				__( 'HTML imported into "%1$s" (#%2$d).', 'variolab-ab-testing' ),
				get_the_title( (int) $page_id ),
				(int) $page_id
			),
			(int) $page_id
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function create_new( string $title, string $html ) {
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %s: timestamp at upload time */
				__( 'HTML import — %s', 'variolab-ab-testing' ),
				current_time( 'Y-m-d H:i:s' )
			);
		}
		// wp_insert_post() runs wp_unslash() on inputs (it expects $_POST-style slashed data).
		// Our raw file content has no slashes, so the unslash would strip real backslashes from
		// JSON payloads / regex / Babel sources etc. We pre-slash to neutralize that.
		return wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'private',
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( $html ),
			],
			true
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function replace_existing( int $page_id, string $html ) {
		$existing = get_post( $page_id );
		if ( ! $existing instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', __( 'Target page not found.', 'variolab-ab-testing' ) );
		}
		$result = wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => wp_slash( $html ),
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $page_id;
	}

	/**
	 * Validate a zip entry path against path traversal, dotfiles, mac metadata,
	 * and directory entries. Returns false for any unsafe / non-extractable entry.
	 */
	private static function zip_entry_is_safe( $entry ): bool {
		if ( false === $entry || '' === $entry ) {
			return false;
		}
		$entry = (string) $entry;
		if (
			str_starts_with( $entry, '/' ) ||
			str_contains( $entry, '..' ) ||
			str_starts_with( $entry, '__MACOSX/' ) ||
			preg_match( '#(^|/)\.[^/]+#', $entry ) ||
			str_ends_with( $entry, '/' )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Extract a .zip archive into uploads/abtest-templates/{slug}/, rewrite relative
	 * asset URLs in the HTML (and CSS) to absolute URLs pointing at that folder, and
	 * return the rewritten HTML.
	 *
	 * Security:
	 *   - Skip dotfiles, __MACOSX/, anything starting with ../ or absolute paths.
	 *   - Only writes non-code assets to disk (css/png/jpg/jpeg/gif/svg/webp/avif/
	 *     woff/woff2/ttf/otf/ico/json/txt/map). HTML and JS are NEVER written —
	 *     wp.org Plugin Review policy forbids writing code-bearing files to the
	 *     uploads area even though uploads itself is an allowed location.
	 *   - The main index.html is read from the zip into memory (never on disk),
	 *     rewritten, and stored in `post_content` (DB) instead. Local JS the
	 *     template references via `<script src="./bundle.js">` will 404 at render
	 *     time — admins can re-inject inline JS via the per-URL tracking scripts
	 *     feature (Admin → A/B Tests → experiment edit form).
	 *
	 * @return string|\WP_Error Rewritten HTML on success, WP_Error on failure.
	 */
	private static function extract_zip_to_uploads( string $tmp_zip, string $slug ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'no_zip', __( 'PHP ZipArchive extension is required to import .zip files.', 'variolab-ab-testing' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_zip ) ) {
			return new \WP_Error( 'bad_zip', __( 'Could not open the .zip archive.', 'variolab-ab-testing' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			$zip->close();
			return new \WP_Error( 'no_uploads', __( 'Could not access the uploads directory.', 'variolab-ab-testing' ) );
		}
		$dest_dir = trailingslashit( $uploads['basedir'] ) . self::ASSETS_SUBDIR . '/' . $slug;
		$dest_url = trailingslashit( $uploads['baseurl'] ) . self::ASSETS_SUBDIR . '/' . $slug;

		if ( ! wp_mkdir_p( $dest_dir ) ) {
			$zip->close();
			return new \WP_Error( 'mkdir_failed', __( 'Could not create the assets directory.', 'variolab-ab-testing' ) );
		}

		// Asset extensions written to disk. Deliberately excludes html/htm/js per
		// wp.org policy (no code-bearing files in uploads).
		$allowed_asset_exts = [ 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'ico', 'json', 'txt', 'map' ];

		$index_relpath = '';

		// Pass 1: locate the preferred index entry (prefer "index.html" at root,
		// else the first .html/.htm encountered). Read entries metadata only.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive::numFiles is a PHP core property, camelCase by design
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( ! self::zip_entry_is_safe( $entry ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'html', 'htm' ], true ) ) {
				continue;
			}
			if ( 'index.html' === $entry || 'index.htm' === $entry ) {
				$index_relpath = $entry;
				break; // exact root index — preferred, stop searching.
			}
			if ( '' === $index_relpath ) {
				$index_relpath = $entry;
			}
		}

		if ( '' === $index_relpath ) {
			$zip->close();
			return new \WP_Error( 'no_html', __( 'No .html file found inside the archive.', 'variolab-ab-testing' ) );
		}

		// Read the index HTML into memory directly from the zip — never touches disk.
		$html_content = $zip->getFromName( $index_relpath );
		if ( false === $html_content ) {
			$zip->close();
			return new \WP_Error( 'read_html', __( 'Could not read the index HTML from the archive.', 'variolab-ab-testing' ) );
		}

		// Pass 2: extract non-code assets (CSS, images, fonts, etc.) to disk.
		// HTML and JS entries are deliberately skipped.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive::numFiles is a PHP core property, camelCase by design
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( ! self::zip_entry_is_safe( $entry ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( (string) $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_asset_exts, true ) ) {
				continue; // skips .html, .htm, .js, anything not on the allowlist.
			}

			$out_path = $dest_dir . '/' . $entry;
			wp_mkdir_p( dirname( $out_path ) );
			$stream = $zip->getStream( (string) $entry );
			if ( false === $stream ) {
				continue;
			}
			$out_handle = fopen( $out_path, 'wb' );
			if ( false === $out_handle ) {
				fclose( $stream );
				continue;
			}
			while ( ! feof( $stream ) ) {
				fwrite( $out_handle, fread( $stream, 8192 ) );
			}
			fclose( $stream );
			fclose( $out_handle );
		}
		$zip->close();

		// Determine the base URL of the index relative to the assets folder root,
		// so paths like "css/style.css" inside a nested index work. dirname() returns
		// "." when the index sits at the zip root — collapse that to nothing.
		$index_dir          = dirname( $index_relpath );
		$base_url_for_index = trailingslashit( $dest_url ) . ( '.' === $index_dir ? '' : $index_dir . '/' );

		$html = self::rewrite_relative_urls( $html_content, $base_url_for_index );

		// Also rewrite linked CSS files (image URLs inside `url(...)`). glob() with
		// `**` is shell-only — walk recursively instead so nested CSS gets rewritten.
		foreach ( self::find_files( $dest_dir, 'css' ) as $css_path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local extracted CSS path, wp_remote_get does not apply
			$css = file_get_contents( $css_path );
			if ( false === $css ) {
				continue;
			}
			$rel_dir     = dirname( substr( $css_path, strlen( $dest_dir ) + 1 ) );
			$css_dir_url = trailingslashit( $dest_url ) . ( '.' === $rel_dir ? '' : $rel_dir . '/' );
			file_put_contents( $css_path, self::rewrite_css_urls( $css, $css_dir_url ) );
		}

		return (string) $html;
	}

	/**
	 * Rewrite href/src/srcset attributes in HTML to absolute URLs when relative.
	 * Skips http(s)://, //, /, #, data:, mailto:, tel:.
	 *
	 * Public so the directory watcher can reuse it on hand-edited HTML.
	 */
	public static function rewrite_relative_urls( string $html, string $base_url ): string {
		// href|src on tags that load resources.
		$html = preg_replace_callback(
			'/(\b(?:href|src|poster|action)\s*=\s*)(["\'])(?!https?:|\/\/|\/|#|data:|mailto:|tel:|javascript:)([^"\']+)\2/i',
			static function ( $m ) use ( $base_url ) {
				return $m[1] . $m[2] . $base_url . ltrim( $m[3], './' ) . $m[2];
			},
			$html
		);
		// srcset = "img1.png 1x, img2.png 2x" — rewrite each candidate.
		$html = preg_replace_callback(
			'/(\bsrcset\s*=\s*)(["\'])([^"\']+)\2/i',
			static function ( $m ) use ( $base_url ) {
				$candidates = array_map( 'trim', explode( ',', $m[3] ) );
				$rewritten  = array_map(
					static function ( $c ) use ( $base_url ) {
						$parts = preg_split( '/\s+/', $c, 2 );
						$url   = $parts[0];
						if ( '' === $url || preg_match( '#^(?:https?:|//|/|data:)#', $url ) ) {
							return $c;
						}
						return $base_url . ltrim( $url, './' ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
					},
					$candidates
				);
				return $m[1] . $m[2] . implode( ', ', $rewritten ) . $m[2];
			},
			$html
		);
		// Inline <style> blocks : rewrite url(...) inside them.
		$html = preg_replace_callback(
			'/<style[^>]*>(.*?)<\/style>/is',
			static function ( $m ) use ( $base_url ) {
				return str_replace( $m[1], self::rewrite_css_urls( $m[1], $base_url ), $m[0] );
			},
			$html
		);
		return $html;
	}

	/**
	 * Recursively find files with the given extension under $dir.
	 *
	 * @return string[] Absolute file paths.
	 */
	private static function find_files( string $dir, string $ext ): array {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$out      = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$ext      = strtolower( $ext );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && strtolower( $file->getExtension() ) === $ext ) {
				$out[] = $file->getPathname();
			}
		}
		return $out;
	}

	private static function rewrite_css_urls( string $css, string $base_url ): string {
		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)(?!https?:|\/\/|\/|data:|#)([^)"\']+)\1\s*\)/i',
			static function ( $m ) use ( $base_url ) {
				return 'url(' . $m[1] . $base_url . ltrim( $m[2], './' ) . $m[1] . ')';
			},
			$css
		);
	}

	private static function compute_assets_slug( int $target_id, string $new_title ): string {
		// When updating an existing page, reuse its slug so re-imports replace
		// the assets folder. New pages get a unique slug based on title + timestamp.
		if ( $target_id > 0 ) {
			$slug = (string) get_post_field( 'post_name', $target_id );
			if ( '' !== $slug ) {
				return sanitize_title( $slug );
			}
		}
		$base = '' !== $new_title ? sanitize_title( $new_title ) : 'imported';
		return $base . '-' . gmdate( 'YmdHis' );
	}

	private static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'File too large (server upload limit).', 'variolab-ab-testing' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Upload was interrupted.', 'variolab-ab-testing' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file selected.', 'variolab-ab-testing' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not save the upload.', 'variolab-ab-testing' );
			default:
				return __( 'Upload error.', 'variolab-ab-testing' );
		}
	}

	private static function redirect_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => Admin::menu_slug(),
					'action'          => 'import',
					'abtest_notice'   => rawurlencode( $message ),
					'abtest_notice_t' => 'error',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function redirect_success( string $message, int $page_id ): void {
		$args = [
			'page'              => Admin::menu_slug(),
			'action'            => 'import',
			'abtest_notice'     => rawurlencode( $message ),
			'abtest_notice_t'   => 'success',
			'abtest_imported'   => $page_id,
		];
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

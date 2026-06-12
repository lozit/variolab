<?php
/**
 * Watcher — sync HTML files dropped into wp-content/uploads/abtest-templates/
 * to WordPress pages with the Blank Canvas template.
 *
 * Workflow :
 *   1. Edit your landing page on disk (Cursor / VSCode / SFTP / cloud sync)
 *      under wp-content/uploads/abtest-templates/{slug}/index.html
 *   2. WP-Cron runs every 5 minutes and detects content hash changes
 *   3. Page is created on first detection, updated on subsequent edits
 *
 * The watcher is additive only — never deletes pages, even if the folder
 * disappears. Use the WP admin to delete experiments / pages explicitly.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

use Abtest\Admin\HtmlImport;

defined( 'ABSPATH' ) || exit;

final class Watcher {

	public const CRON_HOOK     = 'abtest_watch_dir_scan';
	public const CRON_INTERVAL = 'abtest_5min';

	private const META_SLUG     = '_abtest_watcher_slug';
	private const META_HASH     = '_abtest_watcher_hash';
	private const LAST_RUN_OPT  = 'abtest_watcher_last_run';

	public static function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- intentional 5-min interval registered in register_interval() for IDE-sync UX
		add_filter( 'cron_schedules', [ self::class, 'register_interval' ] );
		add_action( self::CRON_HOOK, [ self::class, 'scan' ] );

		// Self-heal : schedule the event if it's missing (fresh install or
		// admin purged WP-Cron).
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- intentional 5-min interval for IDE-sync UX
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- intentional 5-min interval for IDE-sync UX
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Register the 5-minute cron interval (WP only ships hourly / twicedaily / daily).
	 *
	 * The `display` label is intentionally NOT wrapped in __() : the `cron_schedules`
	 * filter fires from wp_schedule_event() at `plugins_loaded` (and on every
	 * wp-cron.php run), i.e. before `init`, so translating here would trigger WP 6.7+'s
	 * `_load_textdomain_just_in_time` "translation triggered too early" notice. The
	 * label only ever surfaces in cron-management tooling (WP Crontrol / Site Health),
	 * so a plain English string is the right trade-off.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function register_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 minutes (A/B Testing)',
			];
		}
		return $schedules;
	}

	/**
	 * Scan the watch directory for changes and sync to pages.
	 *
	 * @return array{created:int, updated:int, skipped:int, errors:string[]}
	 */
	public static function scan(): array {
		$stats = [
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => [],
		];

		if ( ! apply_filters( 'abtest_watcher_enabled', true ) ) {
			return $stats;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			$stats['errors'][] = (string) $uploads['error'];
			return $stats;
		}

		$base_dir = trailingslashit( $uploads['basedir'] ) . HtmlImport::ASSETS_SUBDIR;
		$base_url = trailingslashit( $uploads['baseurl'] ) . HtmlImport::ASSETS_SUBDIR;

		if ( ! is_dir( $base_dir ) ) {
			update_option( self::LAST_RUN_OPT, [ 'time' => time(), 'stats' => $stats ], false );
			return $stats;
		}

		$folders = glob( $base_dir . '/*', GLOB_ONLYDIR );
		if ( false === $folders || empty( $folders ) ) {
			update_option( self::LAST_RUN_OPT, [ 'time' => time(), 'stats' => $stats ], false );
			return $stats;
		}

		foreach ( $folders as $folder ) {
			$slug  = basename( $folder );
			$index = self::find_index_html( $folder );
			if ( '' === $index ) {
				++$stats['skipped'];
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file path, wp_remote_get does not apply
			$html = file_get_contents( $index );
			if ( false === $html ) {
				$stats['errors'][] = sprintf( 'read failed: %s', $slug );
				continue;
			}

			$hash    = hash( 'sha256', $html );
			$page_id = self::find_page_for_slug( $slug );

			if ( $page_id > 0 ) {
				$stored = (string) get_post_meta( $page_id, self::META_HASH, true );
				if ( $stored === $hash ) {
					++$stats['skipped'];
					continue;
				}
			}

			// Build the base URL the relative asset paths should resolve against.
			// dirname() returns "." when index.html sits at the slug root — collapse to nothing.
			$rel       = substr( $index, strlen( $folder ) + 1 );
			$index_dir = dirname( $rel );
			$base_url_for_index = trailingslashit( $base_url ) . $slug . '/' . ( '.' === $index_dir ? '' : $index_dir . '/' );
			$rewritten = HtmlImport::rewrite_relative_urls( $html, $base_url_for_index );

			if ( $page_id > 0 ) {
				$result = wp_update_post(
					[
						'ID'           => $page_id,
						'post_content' => wp_slash( $rewritten ),
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					$stats['errors'][] = sprintf( 'update failed (%s): %s', $slug, $result->get_error_message() );
					continue;
				}
				++$stats['updated'];
			} else {
				$page_id = wp_insert_post(
					[
						'post_type'    => 'page',
						'post_status'  => 'private',
						'post_title'   => wp_slash( self::title_from_slug( $slug ) ),
						'post_name'    => $slug,
						'post_content' => wp_slash( $rewritten ),
					],
					true
				);
				if ( is_wp_error( $page_id ) ) {
					$stats['errors'][] = sprintf( 'create failed (%s): %s', $slug, $page_id->get_error_message() );
					continue;
				}
				update_post_meta( (int) $page_id, '_wp_page_template', Template::TEMPLATE_SLUG );
				update_post_meta( (int) $page_id, self::META_SLUG, $slug );
				++$stats['created'];
			}

			update_post_meta( (int) $page_id, self::META_HASH, $hash );
		}

		update_option( self::LAST_RUN_OPT, [ 'time' => time(), 'stats' => $stats ], false );
		return $stats;
	}

	/**
	 * @return array{time:int, stats:array{created:int, updated:int, skipped:int, errors:string[]}}|null
	 */
	public static function last_run(): ?array {
		$value = get_option( self::LAST_RUN_OPT );
		return is_array( $value ) ? $value : null;
	}

	public static function watch_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ?? '' ) . HtmlImport::ASSETS_SUBDIR;
	}

	private static function find_index_html( string $folder ): string {
		// Prefer index.html at the slug root, then index.htm, then any .html anywhere.
		foreach ( [ '/index.html', '/index.htm' ] as $candidate ) {
			if ( file_exists( $folder . $candidate ) ) {
				return $folder . $candidate;
			}
		}
		if ( ! is_dir( $folder ) ) {
			return '';
		}
		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( 'html' === $ext || 'htm' === $ext ) {
				return $file->getPathname();
			}
		}
		return '';
	}

	private static function find_page_for_slug( string $slug ): int {
		$ids = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => self::META_SLUG,
				'meta_value'     => $slug,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	private static function title_from_slug( string $slug ): string {
		$title = trim( str_replace( [ '-', '_' ], ' ', $slug ) );
		// Strip trailing -YYYYMMDDHHMMSS suffix added by the zip importer for new pages.
		$title = (string) preg_replace( '/\s+\d{14}$/', '', $title );
		return ucfirst( $title );
	}
}

<?php
/**
 * Experiments list — branded redesign (v0.15.0).
 *
 * The page is grouped by Test URL, but the layout is no longer a
 * wp-list-table. Each URL renders as a "card" with its own header,
 * KPI line, experiment rows (CSS grid, not a table), and inline SVG
 * sparkline. The page top has a 5-card KPI strip (Active / Impressions
 * / Conversions / Overall rate / Winners shipped) and a chip-based
 * status filter (All / Draft / Running / Paused / Ended) alongside a
 * date-range picker with preset shortcuts (7d / 30d / All time).
 *
 * Class names: every selector is prefixed `vlab-` so it never collides
 * with WordPress admin / other plugins / the legacy `abtest-*` classes
 * still used on the Edit / Settings / HtmlImport screens (see admin.css,
 * untouched). See assets/css/admin-list.css.
 *
 * Reused helpers (no change to their semantics):
 *   - Experiment::get_status / get_variants / META_TEST_URL etc.
 *   - Stats::raw_counts_for_experiments / compute_multi / daily_breakdown_for_url
 *   - Stats::overview_kpis (NEW, drives the KPI strip)
 *   - StatsExplain::no_winner_reason (No-winner tooltip)
 *   - CsvExport::download_url (header button)
 *   - Admin::maybe_render_notice (top-of-page admin notices)
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Experiment;
use Abtest\Stats;

defined( 'ABSPATH' ) || exit;

final class ExperimentsList {

	private const STATUS_ALL = 'all';

	/** @return string[] Status keys for the filter chips, in display order. */
	private static function chip_statuses(): array {
		return [
			self::STATUS_ALL,
			Experiment::STATUS_DRAFT,
			Experiment::STATUS_RUNNING,
			Experiment::STATUS_PAUSED,
			Experiment::STATUS_ENDED,
		];
	}

	public static function render(): void {
		Admin::maybe_render_notice();

		$experiments = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// Read filters from query string (validated downstream).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view, no mutation
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$status_filter = isset( $_GET['status_filter'] )
			? sanitize_key( wp_unslash( $_GET['status_filter'] ) )
			: self::STATUS_ALL;

		// Back-compat with the v0.14 `?show=` param. Translated silently for one
		// release; old bookmarks keep working.
		if ( ! isset( $_GET['status_filter'] ) && isset( $_GET['show'] ) ) {
			$legacy = sanitize_key( wp_unslash( $_GET['show'] ) );
			if ( 'running' === $legacy ) {
				$status_filter = Experiment::STATUS_RUNNING;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $status_filter, self::chip_statuses(), true ) ) {
			$status_filter = self::STATUS_ALL;
		}

		$exp_ids = wp_list_pluck( $experiments, 'ID' );
		$kpis    = Stats::overview_kpis( $exp_ids, $from, $to );
		$counts  = Stats::raw_counts_for_experiments( $exp_ids, $from, $to );

		$csv_url = CsvExport::download_url( $from, $to, $status_filter );
		$new_url = add_query_arg(
			[ 'page' => Admin::menu_slug(), 'action' => 'new' ],
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap vlab-page">
			<?php
			self::render_page_header( $new_url, $csv_url );
			self::render_kpi_strip( $kpis );
			self::render_toolbar( $status_filter, $kpis, $from, $to );

			if ( empty( $experiments ) ) :
				?>
				<div class="vlab-empty">
					<p><?php esc_html_e( 'No experiments yet. Create your first A/B test.', 'variolab-ab-testing' ); ?></p>
				</div>
				<?php
				echo '</div>'; // .vlab-page
				return;
			endif;

			$grouped        = self::group_by_url( $experiments );
			$running_by_url = self::running_by_url( $experiments );

			foreach ( $grouped as $url => $exps_in_group ) {
				self::render_url_block( $url, $exps_in_group, $counts, $running_by_url, $status_filter, $from, $to );
			}
			?>
		</div>
		<?php
	}

	private static function render_page_header( string $new_url, string $csv_url ): void {
		ob_start();
		?>
		<a href="<?php echo esc_url( $csv_url ); ?>" class="vlab-btn" title="<?php esc_attr_e( 'Download all visible experiments as CSV (respects current filters).', 'variolab-ab-testing' ); ?>">
			<svg class="vlab-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v8m0 0l-3-3m3 3l3-3M2 13h12"/></svg>
			<?php esc_html_e( 'Export CSV', 'variolab-ab-testing' ); ?>
		</a>
		<a href="<?php echo esc_url( $new_url ); ?>" class="vlab-btn vlab-btn--primary">
			<svg class="vlab-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>
			<?php esc_html_e( 'Add new test', 'variolab-ab-testing' ); ?>
		</a>
		<?php
		$actions = (string) ob_get_clean();

		Admin::render_brand_header( __( 'A/B Tests', 'variolab-ab-testing' ), $actions );
	}

	private static function render_kpi_strip( array $kpis ): void {
		$dash = '—';
		?>
		<section class="vlab-kpi-strip">
			<div class="vlab-kpi">
				<div class="vlab-kpi-label"><?php esc_html_e( 'Active tests', 'variolab-ab-testing' ); ?></div>
				<div class="vlab-kpi-value"><?php echo esc_html( number_format_i18n( (int) $kpis['active'] ) ); ?></div>
				<div class="vlab-kpi-sub">
					<?php if ( $kpis['running'] > 0 ) : ?>
						<span class="vlab-kpi-running">
							<?php
							printf(
								/* translators: %d: number of running experiments */
								esc_html( _n( '%d running', '%d running', (int) $kpis['running'], 'variolab-ab-testing' ) ),
								(int) $kpis['running']
							);
							?>
						</span>
					<?php else : ?>
						<?php esc_html_e( 'No live test', 'variolab-ab-testing' ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="vlab-kpi">
				<div class="vlab-kpi-label"><?php esc_html_e( 'Impressions', 'variolab-ab-testing' ); ?></div>
				<div class="vlab-kpi-value">
					<?php
					echo $kpis['impressions'] > 0
						? esc_html( number_format_i18n( (int) $kpis['impressions'] ) )
						: esc_html( $dash );
					?>
				</div>
				<div class="vlab-kpi-sub"><?php esc_html_e( 'across all variants', 'variolab-ab-testing' ); ?></div>
			</div>
			<div class="vlab-kpi">
				<div class="vlab-kpi-label"><?php esc_html_e( 'Conversions', 'variolab-ab-testing' ); ?></div>
				<div class="vlab-kpi-value">
					<?php
					echo $kpis['conversions'] > 0
						? esc_html( number_format_i18n( (int) $kpis['conversions'] ) )
						: esc_html( $dash );
					?>
				</div>
				<div class="vlab-kpi-sub"><?php esc_html_e( 'across all variants', 'variolab-ab-testing' ); ?></div>
			</div>
			<div class="vlab-kpi">
				<div class="vlab-kpi-label"><?php esc_html_e( 'Overall rate', 'variolab-ab-testing' ); ?></div>
				<div class="vlab-kpi-value">
					<?php
					if ( null === $kpis['rate'] ) {
						echo esc_html( $dash );
					} else {
						echo esc_html( self::pct( (float) $kpis['rate'] ) );
					}
					?>
				</div>
				<div class="vlab-kpi-sub"><?php esc_html_e( 'conversions / impressions', 'variolab-ab-testing' ); ?></div>
			</div>
			<div class="vlab-kpi">
				<div class="vlab-kpi-label"><?php esc_html_e( 'Winners shipped', 'variolab-ab-testing' ); ?></div>
				<div class="vlab-kpi-value"><?php echo esc_html( number_format_i18n( (int) $kpis['winners'] ) ); ?></div>
				<div class="vlab-kpi-sub">
					<?php
					if ( $kpis['ended'] > 0 ) {
						printf(
							/* translators: %d: number of ended experiments */
							esc_html( _n( 'out of %d ended test', 'out of %d ended tests', (int) $kpis['ended'], 'variolab-ab-testing' ) ),
							(int) $kpis['ended']
						);
					} else {
						esc_html_e( 'No completed test yet', 'variolab-ab-testing' );
					}
					?>
				</div>
			</div>
		</section>
		<?php
	}

	private static function render_toolbar( string $current_filter, array $kpis, string $from, string $to ): void {
		$today      = current_time( 'Y-m-d' );
		$preset_7   = gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) );
		$preset_30  = gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );

		$preset_url = static function ( array $args ) {
			$base = [ 'page' => Admin::menu_slug() ];
			return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
		};

		// Chip count source = the unfiltered counts from overview_kpis (which counted
		// every experiment status before applying the filter).
		$chip_counts = [
			self::STATUS_ALL              => (int) $kpis['exp_count'],
			Experiment::STATUS_DRAFT      => (int) $kpis['draft'],
			Experiment::STATUS_RUNNING    => (int) $kpis['running'],
			Experiment::STATUS_PAUSED     => (int) $kpis['paused'],
			Experiment::STATUS_ENDED      => (int) $kpis['ended'],
		];
		$chip_labels = [
			self::STATUS_ALL              => __( 'All', 'variolab-ab-testing' ),
			Experiment::STATUS_DRAFT      => __( 'Draft', 'variolab-ab-testing' ),
			Experiment::STATUS_RUNNING    => __( 'Running', 'variolab-ab-testing' ),
			Experiment::STATUS_PAUSED     => __( 'Paused', 'variolab-ab-testing' ),
			Experiment::STATUS_ENDED      => __( 'Ended', 'variolab-ab-testing' ),
		];

		$preset_active = '';
		if ( $to === $today && $from === $preset_7 ) {
			$preset_active = '7d';
		} elseif ( $to === $today && $from === $preset_30 ) {
			$preset_active = '30d';
		} elseif ( '' === $from && '' === $to ) {
			$preset_active = 'all';
		}
		?>
		<div class="vlab-toolbar">
			<nav class="vlab-filter-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter experiments by status', 'variolab-ab-testing' ); ?>">
				<?php foreach ( self::chip_statuses() as $status_key ) :
					$chip_url = $preset_url(
						array_filter(
							[
								'status_filter' => $status_key,
								'from'          => $from,
								'to'            => $to,
							],
							static fn( $v ) => '' !== $v
						)
					);
					$is_active = ( $status_key === $current_filter );
					?>
					<a href="<?php echo esc_url( $chip_url ); ?>"
					   class="vlab-chip<?php echo $is_active ? ' is-active' : ''; ?>"
					   role="tab"
					   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					>
						<?php echo esc_html( $chip_labels[ $status_key ] ); ?>
						<span class="vlab-chip-count"><?php echo esc_html( number_format_i18n( $chip_counts[ $status_key ] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="get" class="vlab-toolbar-r">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::menu_slug() ); ?>">
				<input type="hidden" name="status_filter" value="<?php echo esc_attr( $current_filter ); ?>">
				<label class="vlab-date-range">
					<span class="screen-reader-text"><?php esc_html_e( 'From date', 'variolab-ab-testing' ); ?></span>
					<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" max="<?php echo esc_attr( $today ); ?>" aria-label="<?php esc_attr_e( 'From', 'variolab-ab-testing' ); ?>">
					<span class="vlab-sep">→</span>
					<span class="screen-reader-text"><?php esc_html_e( 'To date', 'variolab-ab-testing' ); ?></span>
					<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" max="<?php echo esc_attr( $today ); ?>" aria-label="<?php esc_attr_e( 'To', 'variolab-ab-testing' ); ?>">
				</label>
				<button type="submit" class="vlab-btn vlab-btn--ghost"><?php esc_html_e( 'Apply', 'variolab-ab-testing' ); ?></button>
				<span class="vlab-preset-chips">
					<a href="<?php echo esc_url( $preset_url( [ 'status_filter' => $current_filter, 'from' => $preset_7, 'to' => $today ] ) ); ?>"
					   class="vlab-preset<?php echo '7d' === $preset_active ? ' is-active' : ''; ?>"><?php esc_html_e( '7d', 'variolab-ab-testing' ); ?></a>
					<a href="<?php echo esc_url( $preset_url( [ 'status_filter' => $current_filter, 'from' => $preset_30, 'to' => $today ] ) ); ?>"
					   class="vlab-preset<?php echo '30d' === $preset_active ? ' is-active' : ''; ?>"><?php esc_html_e( '30d', 'variolab-ab-testing' ); ?></a>
					<a href="<?php echo esc_url( $preset_url( [ 'status_filter' => $current_filter ] ) ); ?>"
					   class="vlab-preset<?php echo 'all' === $preset_active ? ' is-active' : ''; ?>"><?php esc_html_e( 'All time', 'variolab-ab-testing' ); ?></a>
				</span>
			</form>
		</div>
		<?php
	}

	/**
	 * Palette of distinct line / tag colors. One color per (experiment,variant)
	 * pair within a URL block, assigned in iteration order. Cycles if the
	 * block has more than count($palette) pairs (rare — 12 pairs = 6 A/B
	 * experiments or 3 quad-variant experiments on the same URL).
	 *
	 * @return string[]
	 */
	private static function color_palette(): array {
		return [
			'#F87018', // brand orange
			'#2271b1', // WP blue
			'#00a32a', // green
			'#dba617', // amber
			'#956eff', // violet
			'#dc3545', // red
			'#0a4b78', // deep blue
			'#e24a90', // pink
			'#155724', // forest green
			'#856404', // brown
			'#1993fd', // sky
			'#50575e', // slate
		];
	}

	/**
	 * Build a stable color map for one URL block. Keyed by `"$experiment_id|$variant"`,
	 * value = hex string. Used both by the variant-tag inline `background-color`
	 * style and injected into the sparkline JSON so the chart lines match.
	 *
	 * @param \WP_Post[] $exps Experiments grouped on this URL.
	 * @return array<string,string>
	 */
	private static function build_color_map( array $exps ): array {
		$palette = self::color_palette();
		$count   = count( $palette );
		$map     = [];
		$index   = 0;
		foreach ( $exps as $exp ) {
			$exp_id   = (int) $exp->ID;
			$variants = Experiment::get_variants( $exp_id );
			foreach ( $variants as $v ) {
				$label = (string) $v['label'];
				$map[ $exp_id . '|' . $label ] = $palette[ $index % $count ];
				++$index;
			}
		}
		return $map;
	}

	private static function render_url_block( string $url, array $exps, array $counts, array $running_by_url, string $status_filter, string $from, string $to ): void {
		// Partition experiments by status family for the current filter view.
		$inline  = []; // Running / Paused / Draft (shown inline)
		$ended   = []; // Ended (hidden in <details> unless filter == ended)
		foreach ( $exps as $exp ) {
			$status = Experiment::get_status( (int) $exp->ID );
			if ( Experiment::STATUS_ENDED === $status ) {
				$ended[] = $exp;
			} else {
				$inline[] = $exp;
			}
		}

		// Apply the status_filter chip.
		if ( self::STATUS_ALL !== $status_filter ) {
			$keep = static function ( array $pool ) use ( $status_filter ) {
				return array_values(
					array_filter(
						$pool,
						static fn( \WP_Post $p ) => Experiment::get_status( (int) $p->ID ) === $status_filter
					)
				);
			};
			$inline = $keep( $inline );
			$ended  = $keep( $ended );
		}

		// Skip URL blocks that have nothing to show under the current filter.
		if ( empty( $inline ) && empty( $ended ) ) {
			return;
		}

		$totals      = self::totals_for_group( $exps, $counts );
		$exp_count   = count( $exps );
		$color_map   = self::build_color_map( $exps );
		$full_url    = '' !== $url ? home_url( $url ) : '';
		$add_to_url  = add_query_arg(
			array_filter(
				[
					'page'     => Admin::menu_slug(),
					'action'   => 'new',
					'test_url' => '' !== $url ? $url : null,
				],
				static fn( $v ) => null !== $v
			),
			admin_url( 'admin.php' )
		);
		?>
		<section class="vlab-url-block">
			<header class="vlab-url-header">
				<?php if ( '' !== $url ) : ?>
					<a href="<?php echo esc_url( $full_url ); ?>" class="vlab-url-name" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a>
				<?php else : ?>
					<span class="vlab-url-name"><?php esc_html_e( '(no URL set)', 'variolab-ab-testing' ); ?></span>
				<?php endif; ?>
				<div class="vlab-url-meta">
					<span class="vlab-num"><?php echo esc_html( number_format_i18n( $exp_count ) ); ?></span>
					<?php echo esc_html( _n( 'experiment', 'experiments', $exp_count, 'variolab-ab-testing' ) ); ?>
					<span class="vlab-sep">·</span>
					<span class="vlab-num"><?php echo esc_html( number_format_i18n( (float) $totals['impressions'] ) ); ?></span>
					<?php esc_html_e( 'impressions', 'variolab-ab-testing' ); ?>
					<span class="vlab-sep">·</span>
					<span class="vlab-num"><?php echo esc_html( number_format_i18n( (float) $totals['conversions'] ) ); ?></span>
					<?php esc_html_e( 'conversions', 'variolab-ab-testing' ); ?>
					<span class="vlab-sep">·</span>
					<span class="vlab-num"><?php echo esc_html( self::pct( (float) $totals['rate'] ) ); ?></span>
					<?php esc_html_e( 'overall', 'variolab-ab-testing' ); ?>
				</div>
				<span class="vlab-url-actions">
					<a href="<?php echo esc_url( $add_to_url ); ?>" class="vlab-btn vlab-btn--ghost">
						+ <?php esc_html_e( 'Add experiment', 'variolab-ab-testing' ); ?>
					</a>
				</span>
			</header>

			<div class="vlab-exp-list">
				<?php foreach ( $inline as $experiment ) : ?>
					<?php
					$exp_url       = (string) get_post_meta( (int) $experiment->ID, Experiment::META_TEST_URL, true );
					$running_other = $running_by_url[ $exp_url ] ?? null;
					if ( $running_other && (int) $running_other->ID === (int) $experiment->ID ) {
						$running_other = null;
					}
					self::render_experiment_row( $experiment, $counts, $running_other, $color_map );
					?>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $ended ) && Experiment::STATUS_ENDED !== $status_filter ) : ?>
				<details class="vlab-archived">
					<summary>
						<?php esc_html_e( 'Archived (ended)', 'variolab-ab-testing' ); ?>
						<span class="vlab-archived-count"><?php echo esc_html( number_format_i18n( count( $ended ) ) ); ?></span>
					</summary>
					<div class="vlab-archived-body vlab-exp-list">
						<?php foreach ( $ended as $experiment ) : ?>
							<?php self::render_experiment_row( $experiment, $counts, null, $color_map ); ?>
						<?php endforeach; ?>
					</div>
				</details>
			<?php elseif ( ! empty( $ended ) ) : ?>
				<?php foreach ( $ended as $experiment ) : ?>
					<div class="vlab-exp-list">
						<?php self::render_experiment_row( $experiment, $counts, null, $color_map ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( '' !== $url ) : ?>
				<?php self::render_url_sparkline( $url, $exps, $color_map, $from, $to ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_experiment_row( \WP_Post $experiment, array $counts, ?\WP_Post $running_other = null, array $color_map = [] ): void {
		$exp_id        = (int) $experiment->ID;
		$status        = Experiment::get_status( $exp_id );
		$variant_specs = Experiment::get_variants( $exp_id );
		$labels        = array_map( static fn( $v ) => (string) $v['label'], $variant_specs );
		$started_at    = (string) get_post_meta( $exp_id, Experiment::META_STARTED_AT, true );
		$ended_at      = (string) get_post_meta( $exp_id, Experiment::META_ENDED_AT, true );
		$row_counts    = $counts[ $exp_id ] ?? [];
		foreach ( $labels as $lbl ) {
			if ( ! isset( $row_counts[ $lbl ] ) ) {
				$row_counts[ $lbl ] = [ 'impressions' => 0, 'conversions' => 0 ];
			}
		}
		$multi = Stats::compute_multi( $row_counts, ! empty( $labels ) ? $labels : [ 'A' ] );

		$edit_url = add_query_arg(
			[ 'page' => Admin::menu_slug(), 'action' => 'edit', 'experiment' => $exp_id ],
			admin_url( 'admin.php' )
		);
		?>
		<article class="vlab-exp-row" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="vlab-exp-row-l">
				<h3 class="vlab-exp-title"><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $experiment ) ); ?></a></h3>
				<?php
				$duration = self::run_duration( $started_at, $ended_at );
				if ( $started_at || $ended_at ) :
					?>
					<div class="vlab-exp-meta">
						<?php
						if ( $started_at && $ended_at ) {
							printf(
								/* translators: 1: start date, 2: end date, 3: duration like "3 days" */
								esc_html__( '%1$s → %2$s · %3$s', 'variolab-ab-testing' ),
								esc_html( mysql2date( get_option( 'date_format' ), $started_at ) ),
								esc_html( mysql2date( get_option( 'date_format' ), $ended_at ) ),
								esc_html( $duration )
							);
						} elseif ( $started_at ) {
							printf(
								/* translators: 1: start date, 2: duration */
								esc_html__( 'Since %1$s · %2$s', 'variolab-ab-testing' ),
								esc_html( mysql2date( get_option( 'date_format' ), $started_at ) ),
								esc_html( $duration )
							);
						}
						?>
					</div>
				<?php endif; ?>
				<div class="vlab-exp-status-row">
					<span class="vlab-status vlab-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
				</div>
			</div>

			<div>
				<?php self::render_variants_stack( $variant_specs, $multi, $color_map, $exp_id ); ?>
			</div>

			<div class="vlab-exp-right">
				<div class="vlab-best-result">
					<?php self::render_best_badge( $labels, $multi, $status, $started_at ); ?>
				</div>
				<div class="vlab-actions">
					<?php self::render_row_actions( $exp_id, $status, $running_other, $experiment, $edit_url ); ?>
				</div>
			</div>
		</article>
		<?php
	}

	private static function render_variants_stack( array $variant_specs, array $multi, array $color_map = [], int $exp_id = 0 ): void {
		if ( empty( $variant_specs ) ) {
			echo '<em class="vlab-exp-meta">—</em>';
			return;
		}
		$baseline = (string) ( $multi['baseline'] ?? 'A' );
		?>
		<div class="vlab-variants">
			<?php
			foreach ( $variant_specs as $v ) :
				$label = (string) $v['label'];
				$pid   = (int) $v['post_id'];
				$row   = $multi['variants'][ $label ] ?? [ 'impressions' => 0, 'conversions' => 0, 'rate' => 0 ];
				$cmp   = $multi['comparisons'][ $label ] ?? null;
				$color = $color_map[ $exp_id . '|' . $label ] ?? '';
				$style = '' !== $color ? ' style="background-color:' . esc_attr( $color ) . ';"' : '';
				?>
				<div class="vlab-variant">
					<span class="vlab-vtag vlab-vtag--<?php echo esc_attr( strtolower( $label ) ); ?>"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style is pre-built from esc_attr'd hex color ?>><?php echo esc_html( $label ); ?></span>
					<span class="vlab-variant-title" title="<?php echo esc_attr( (string) get_the_title( $pid ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ?: '—' ); ?></span>
					<span class="vlab-variant-counts">
						<?php
						printf(
							/* translators: 1: conversions, 2: impressions, 3: conversion rate */
							esc_html__( '%1$d / %2$d %3$s', 'variolab-ab-testing' ),
							(int) $row['conversions'],
							(int) $row['impressions'],
							'<span class="vlab-rate">' . esc_html( self::pct( (float) $row['rate'] ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside the span
						);
						?>
					</span>
					<span class="vlab-variant-right">
						<?php if ( $label === $baseline ) : ?>
							<span class="vlab-baseline-tag"><?php esc_html_e( 'baseline', 'variolab-ab-testing' ); ?></span>
						<?php elseif ( $cmp ) : ?>
							<span class="vlab-lift vlab-lift--<?php echo $cmp['lift'] >= 0 ? 'pos' : 'neg'; ?>">
								<?php echo esc_html( self::pct( (float) $cmp['lift'], true ) ); ?>
							</span>
							<?php if ( $cmp['significant'] ) : ?>
								<span class="vlab-sig-badge"><?php esc_html_e( 'sig', 'variolab-ab-testing' ); ?></span>
							<?php else : ?>
								<span class="vlab-ci-line">
									<?php
									printf(
										/* translators: 1: low bound, 2: high bound */
										esc_html__( 'CI [%1$s ; %2$s]', 'variolab-ab-testing' ),
										esc_html( self::pct( (float) $cmp['lift_ci_low'], true ) ),
										esc_html( self::pct( (float) $cmp['lift_ci_high'], true ) )
									);
									?>
								</span>
							<?php endif; ?>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_best_badge( array $labels, array $multi, string $status, string $started_at ): void {
		if ( count( $labels ) <= 1 ) {
			?>
			<span class="vlab-baseline-badge"><?php esc_html_e( 'Baseline only', 'variolab-ab-testing' ); ?></span>
			<?php
			return;
		}
		if ( null !== $multi['best'] ) {
			$best = (string) $multi['best'];
			?>
			<span class="vlab-winner-badge">
				<span class="vlab-vtag vlab-vtag--<?php echo esc_attr( strtolower( $best ) ); ?>"><?php echo esc_html( $best ); ?></span>
				<?php esc_html_e( 'wins', 'variolab-ab-testing' ); ?>
			</span>
			<?php
			return;
		}
		$reason = StatsExplain::no_winner_reason( $multi, $status, $started_at );
		?>
		<span class="vlab-no-winner" title="<?php echo esc_attr( $reason ); ?>">
			<?php
			/* translators: %s: alpha threshold */
			printf( esc_html__( 'No winner (α=%s)', 'variolab-ab-testing' ), esc_html( number_format_i18n( (float) $multi['alpha'], 3 ) ) );
			?>
			<span class="vlab-help" aria-hidden="true">?</span>
			<span class="screen-reader-text"><?php echo esc_html( $reason ); ?></span>
		</span>
		<?php
	}

	private static function render_row_actions( int $exp_id, string $status, ?\WP_Post $running_other, \WP_Post $experiment, string $edit_url ): void {
		?>
		<a href="<?php echo esc_url( $edit_url ); ?>" class="vlab-action-btn"><?php esc_html_e( 'Edit', 'variolab-ab-testing' ); ?></a>
		<?php
		if ( Experiment::STATUS_DRAFT === $status ) :
			?>
			<a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_RUNNING ) ); ?>" class="vlab-action-btn vlab-action-btn--primary">
				<?php esc_html_e( 'Start', 'variolab-ab-testing' ); ?>
			</a>
			<?php
		elseif ( Experiment::STATUS_RUNNING === $status ) :
			?>
			<a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_PAUSED ) ); ?>" class="vlab-action-btn"><?php esc_html_e( 'Pause', 'variolab-ab-testing' ); ?></a>
			<a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_ENDED ) ); ?>" class="vlab-action-btn"><?php esc_html_e( 'End', 'variolab-ab-testing' ); ?></a>
			<?php
		elseif ( Experiment::STATUS_PAUSED === $status ) :
			?>
			<a href="<?php echo esc_url( self::resume_url( $exp_id ) ); ?>"
				class="vlab-action-btn vlab-action-btn--resume"
				title="<?php esc_attr_e( 'Create a new experiment from this one and start it. The original keeps its locked dates.', 'variolab-ab-testing' ); ?>"
				onclick="return confirm('<?php echo esc_js( __( 'Resume by creating a new experiment with fresh dates? The original stays paused with its current period locked.', 'variolab-ab-testing' ) ); ?>');">
				<?php esc_html_e( 'Resume', 'variolab-ab-testing' ); ?>
			</a>
			<a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_ENDED ) ); ?>" class="vlab-action-btn"><?php esc_html_e( 'End', 'variolab-ab-testing' ); ?></a>
			<?php
		endif;

		if ( Experiment::STATUS_RUNNING !== $status && Experiment::STATUS_ENDED !== $status && $running_other instanceof \WP_Post ) :
			?>
			<a href="<?php echo esc_url( self::replace_running_url( $exp_id ) ); ?>"
				class="vlab-action-btn"
				title="<?php echo esc_attr( sprintf( /* translators: %s: title of the experiment that will be paused */ __( 'Pause "%s" and start this one in a single action.', 'variolab-ab-testing' ), get_the_title( $running_other ) ) ); ?>"
				onclick="return confirm('<?php echo esc_js( sprintf( /* translators: 1: running experiment title, 2: new experiment title */ __( 'Replace "%1$s" (running) with "%2$s"? The current one will be paused.', 'variolab-ab-testing' ), get_the_title( $running_other ), get_the_title( $experiment ) ) ); ?>');">
				<?php esc_html_e( 'Replace running', 'variolab-ab-testing' ); ?>
			</a>
			<?php
		endif;
		?>
		<a href="<?php echo esc_url( self::delete_url( $exp_id ) ); ?>" class="vlab-action-btn vlab-action-btn--danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this experiment? Events will be lost.', 'variolab-ab-testing' ) ); ?>');">
			<?php esc_html_e( 'Delete', 'variolab-ab-testing' ); ?>
		</a>
		<?php
	}

	private static function render_url_sparkline( string $url, array $exps, array $color_map = [], string $from = '', string $to = '' ): void {
		$breakdown = Stats::daily_breakdown_for_url( $url, $from, $to );
		if ( empty( $breakdown['days'] ) || empty( $breakdown['series'] ) ) {
			return;
		}
		$titles = [];
		foreach ( $exps as $exp ) {
			$titles[ (int) $exp->ID ] = (string) get_the_title( $exp );
		}
		$breakdown['titles'] = $titles;

		// Inject the (experiment, variant) color into each series so the chart
		// line matches the variant tag rendered above it in the experiment row.
		foreach ( $breakdown['series'] as $key => $series_data ) {
			$map_key = (int) $series_data['experiment_id'] . '|' . (string) $series_data['variant'];
			if ( isset( $color_map[ $map_key ] ) ) {
				$breakdown['series'][ $key ]['color'] = $color_map[ $map_key ];
			}
		}

		// Vertical change markers — one per experiment start / end date so the
		// admin can see at a glance when a test rolled in or out on this URL.
		// Dates outside the chart range silently drop (no DOM noise).
		$markers = [];
		foreach ( $exps as $exp ) {
			$exp_id  = (int) $exp->ID;
			$title   = (string) get_the_title( $exp );
			$started = (string) get_post_meta( $exp_id, Experiment::META_STARTED_AT, true );
			$ended   = (string) get_post_meta( $exp_id, Experiment::META_ENDED_AT, true );
			if ( '' !== $started ) {
				$markers[] = [
					'date'  => substr( $started, 0, 10 ),
					'kind'  => 'start',
					'title' => $title,
				];
			}
			if ( '' !== $ended ) {
				$markers[] = [
					'date'  => substr( $ended, 0, 10 ),
					'kind'  => 'end',
					'title' => $title,
				];
			}
		}
		$breakdown['markers'] = $markers;

		$shell_id = 'vlab-spark-' . md5( $url );
		?>
		<div class="vlab-chart-section">
			<h4 class="vlab-chart-title"><?php esc_html_e( 'Daily conversion rate', 'variolab-ab-testing' ); ?></h4>
			<div class="vlab-chart-wrap">
				<svg class="vlab-sparkline" id="<?php echo esc_attr( $shell_id ); ?>" viewBox="0 0 800 220" preserveAspectRatio="none" role="img" aria-label="<?php esc_attr_e( 'Daily conversion rate sparkline', 'variolab-ab-testing' ); ?>">
					<title><?php esc_html_e( 'Daily conversion rate per variant', 'variolab-ab-testing' ); ?></title>
				</svg>
				<script type="application/json" class="vlab-sparkline-data" data-target="<?php echo esc_attr( $shell_id ); ?>"><?php echo wp_json_encode( $breakdown ); ?></script>
			</div>
		</div>
		<?php
	}

	// Pure helpers (unchanged from the previous render — preserved for action URL builders and group/totals math).

	/**
	 * @param \WP_Post[] $experiments
	 * @return array<string, \WP_Post[]> URL path => experiments[], sorted by URL ASC, empty URL last.
	 */
	private static function group_by_url( array $experiments ): array {
		$grouped = [];
		foreach ( $experiments as $exp ) {
			$url = (string) get_post_meta( (int) $exp->ID, Experiment::META_TEST_URL, true );
			if ( ! isset( $grouped[ $url ] ) ) {
				$grouped[ $url ] = [];
			}
			$grouped[ $url ][] = $exp;
		}
		uksort(
			$grouped,
			static function ( $a, $b ) {
				if ( '' === $a ) { return 1; }
				if ( '' === $b ) { return -1; }
				return strcmp( $a, $b );
			}
		);
		return $grouped;
	}

	private static function totals_for_group( array $exps, array $counts ): array {
		$imp = 0;
		$cv  = 0;
		foreach ( $exps as $exp ) {
			$row = $counts[ (int) $exp->ID ] ?? [];
			foreach ( [ 'A', 'B', 'C', 'D' ] as $label ) {
				$imp += (int) ( $row[ $label ]['impressions'] ?? 0 );
				$cv  += (int) ( $row[ $label ]['conversions'] ?? 0 );
			}
		}
		return [
			'impressions' => $imp,
			'conversions' => $cv,
			'rate'        => $imp > 0 ? $cv / $imp : 0.0,
		];
	}

	/**
	 * Map url => running experiment, used to decide whether to show the "Replace running" action.
	 *
	 * @param \WP_Post[] $experiments
	 * @return array<string, \WP_Post>
	 */
	private static function running_by_url( array $experiments ): array {
		$out = [];
		foreach ( $experiments as $exp ) {
			$status = Experiment::get_status( (int) $exp->ID );
			if ( Experiment::STATUS_RUNNING !== $status ) {
				continue;
			}
			$url = (string) get_post_meta( (int) $exp->ID, Experiment::META_TEST_URL, true );
			if ( '' === $url ) {
				continue;
			}
			$out[ $url ] = $exp;
		}
		return $out;
	}

	private static function status_url( int $experiment_id, string $status ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'     => 'abtest_set_status',
					'experiment' => $experiment_id,
					'status'     => $status,
				],
				admin_url( 'admin-post.php' )
			),
			'abtest_status_change'
		);
	}

	private static function resume_url( int $experiment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'     => 'abtest_resume',
					'experiment' => $experiment_id,
				],
				admin_url( 'admin-post.php' )
			),
			'abtest_resume'
		);
	}

	private static function replace_running_url( int $experiment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'     => 'abtest_replace_running',
					'experiment' => $experiment_id,
				],
				admin_url( 'admin-post.php' )
			),
			'abtest_replace_running'
		);
	}

	private static function delete_url( int $experiment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'     => 'abtest_delete_experiment',
					'experiment' => $experiment_id,
				],
				admin_url( 'admin-post.php' )
			),
			'abtest_delete_experiment'
		);
	}

	/**
	 * Human-readable run duration. Returns e.g. "3 days", "2 weeks", "47 minutes".
	 * For a still-running experiment (no end), measures from start to now.
	 */
	private static function run_duration( string $started_at, string $ended_at ): string {
		if ( '' === $started_at ) {
			return '';
		}
		$start_ts = strtotime( $started_at );
		if ( false === $start_ts ) {
			return '';
		}
		$end_ts = '' !== $ended_at ? strtotime( $ended_at ) : time();
		if ( false === $end_ts || $end_ts <= $start_ts ) {
			return '';
		}
		return human_time_diff( $start_ts, $end_ts );
	}

	private static function pct( float $ratio, bool $signed = false ): string {
		$pct  = $ratio * 100;
		$sign = ( $signed && $pct > 0 ) ? '+' : '';
		return $sign . number_format_i18n( $pct, 2 ) . ' %';
	}
}

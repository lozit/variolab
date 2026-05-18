<?php
/**
 * Experiments listing — grouped by Test URL, with per-experiment stats and config actions.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Experiment;
use Abtest\Stats;

defined( 'ABSPATH' ) || exit;

final class ExperimentsList {

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

		$new_url = add_query_arg(
			[ 'page' => Admin::menu_slug(), 'action' => 'new' ],
			admin_url( 'admin.php' )
		);

		// Read filters from query string (validated downstream).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'running';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$show_all = ( 'all' === $show );

		$csv_url = CsvExport::download_url( $from, $to, $show );
		?>
		<div class="wrap abtest-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'A/B Tests', 'variolab-ab-testing' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'variolab-ab-testing' ); ?></a>
			<a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action" title="<?php esc_attr_e( 'Download all visible experiments as CSV (respects current filters).', 'variolab-ab-testing' ); ?>">⬇ <?php esc_html_e( 'Export CSV', 'variolab-ab-testing' ); ?></a>

			<?php self::render_date_filter( $from, $to ); ?>

			<?php if ( empty( $experiments ) ) : ?>
				<p><?php esc_html_e( 'No experiments yet. Create your first A/B test.', 'variolab-ab-testing' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$grouped        = self::group_by_url( $experiments );
			$counts         = Stats::raw_counts_for_experiments( wp_list_pluck( $experiments, 'ID' ), $from, $to );
			$running_by_url = self::running_by_url( $experiments );

			$hidden_count = 0;
			if ( ! $show_all ) {
				// Default view: keep only URLs with at least one running experiment.
				foreach ( $grouped as $url => $exps_in_group ) {
					if ( ! isset( $running_by_url[ $url ] ) ) {
						unset( $grouped[ $url ] );
						++$hidden_count;
					}
				}
			}
			self::render_visibility_toggle( $show_all, $hidden_count, $from, $to );
			?>

			<?php if ( empty( $grouped ) ) : ?>
				<p>
					<?php
					if ( ! $show_all && $hidden_count > 0 ) {
						printf(
							/* translators: %s: link to show all */
							esc_html__( 'No URLs with a running experiment. %s to see archived URLs.', 'variolab-ab-testing' ),
							'<a href="' . esc_url( add_query_arg( [ 'page' => Admin::menu_slug(), 'show' => 'all' ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Show all', 'variolab-ab-testing' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
					} else {
						esc_html_e( 'No experiments to display.', 'variolab-ab-testing' );
					}
					?>
				</p>
				<?php return; ?>
			<?php endif; ?>

			<?php foreach ( $grouped as $url => $exps ) : ?>
				<?php
				$totals = self::totals_for_group( $exps, $counts );
				$exp_count = count( $exps );
				$test_url_full = '' !== $url ? home_url( $url ) : '';
				?>
				<?php
				$add_to_url_args = [ 'page' => Admin::menu_slug(), 'action' => 'new' ];
				if ( '' !== $url ) {
					$add_to_url_args['test_url'] = $url;
				}
				$add_to_url = add_query_arg( $add_to_url_args, admin_url( 'admin.php' ) );
				?>
				<div class="abtest-url-block">
					<h2 class="abtest-url-heading">
						<?php if ( '' !== $url ) : ?>
							<a href="<?php echo esc_url( $test_url_full ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $url ); ?></code></a>
						<?php else : ?>
							<code class="abtest-no-url"><?php esc_html_e( '(no URL set)', 'variolab-ab-testing' ); ?></code>
						<?php endif; ?>
						<small class="abtest-url-meta">
							<?php
							printf(
								esc_html(
									/* translators: 1: number of experiments, 2: total impressions, 3: total conversions, 4: overall rate */
									_n(
										'%1$d experiment · %2$s impressions · %3$s conversions · %4$s overall',
										'%1$d experiments · %2$s impressions · %3$s conversions · %4$s overall',
										$exp_count,
										'variolab-ab-testing'
									)
								),
								(int) $exp_count,
								esc_html( number_format_i18n( (float) $totals['impressions'] ) ),
								esc_html( number_format_i18n( (float) $totals['conversions'] ) ),
								esc_html( self::pct( (float) $totals['rate'] ) )
							);
							?>
						</small>
						<a href="<?php echo esc_url( $add_to_url ); ?>" class="page-title-action abtest-add-to-url">+ <?php esc_html_e( 'Add experiment to this URL', 'variolab-ab-testing' ); ?></a>
					</h2>

					<table class="wp-list-table widefat striped abtest-list">
						<thead>
							<tr>
								<th style="width:24%;"><?php esc_html_e( 'Experiment', 'variolab-ab-testing' ); ?></th>
								<th style="width:10%;"><?php esc_html_e( 'Status', 'variolab-ab-testing' ); ?></th>
								<th style="width:46%;"><?php esc_html_e( 'Variants', 'variolab-ab-testing' ); ?></th>
								<th style="width:8%;"><?php esc_html_e( 'Best', 'variolab-ab-testing' ); ?></th>
								<th style="width:12%;"><?php esc_html_e( 'Actions', 'variolab-ab-testing' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $exps as $experiment ) : ?>
							<?php
							$exp_url       = (string) get_post_meta( (int) $experiment->ID, Experiment::META_TEST_URL, true );
							$running_other = $running_by_url[ $exp_url ] ?? null;
							if ( $running_other && (int) $running_other->ID === (int) $experiment->ID ) {
								$running_other = null;
							}
							?>
							<?php self::render_experiment_row( $experiment, $counts, $running_other ); ?>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php
					if ( '' !== $url ) {
						self::render_url_chart( $url, $exps, $from, $to );
					}
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_experiment_row( \WP_Post $experiment, array $counts, ?\WP_Post $running_other = null ): void {
		$exp_id        = (int) $experiment->ID;
		$status        = Experiment::get_status( $exp_id );
		$variant_specs = Experiment::get_variants( $exp_id );
		$labels        = array_map( static fn( $v ) => (string) $v['label'], $variant_specs );
		$started_at    = (string) get_post_meta( $exp_id, Experiment::META_STARTED_AT, true );
		$ended_at      = (string) get_post_meta( $exp_id, Experiment::META_ENDED_AT, true );
		$row_counts    = $counts[ $exp_id ] ?? [];
		// Ensure all configured labels have entries in counts (zero-fill missing).
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
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $experiment ) ); ?></a></strong>
				<?php
				$duration = self::run_duration( $started_at, $ended_at );
				if ( $started_at || $ended_at ) :
					?>
					<br><small class="abtest-muted">
						<?php
						if ( $started_at && $ended_at ) {
							printf(
								/* translators: 1: start date, 2: end date, 3: duration like "3 days" */
								esc_html__( '%1$s → %2$s (%3$s)', 'variolab-ab-testing' ),
								esc_html( mysql2date( get_option( 'date_format' ), $started_at ) ),
								esc_html( mysql2date( get_option( 'date_format' ), $ended_at ) ),
								esc_html( $duration )
							);
						} elseif ( $started_at ) {
							printf(
								/* translators: 1: start date, 2: duration */
								esc_html__( 'Since %1$s (%2$s)', 'variolab-ab-testing' ),
								esc_html( mysql2date( get_option( 'date_format' ), $started_at ) ),
								esc_html( $duration )
							);
						}
						?>
					</small>
				<?php endif; ?>
			</td>
			<td><span class="abtest-status abtest-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			<td class="abtest-variants-cell">
				<?php self::render_variants_cell( $variant_specs, $multi ); ?>
			</td>
			<td>
				<?php if ( count( $labels ) <= 1 ) : ?>
					<span class="abtest-badge abtest-badge-baseline"><?php esc_html_e( 'Baseline', 'variolab-ab-testing' ); ?></span>
				<?php elseif ( null !== $multi['best'] ) : ?>
					<span class="abtest-badge abtest-badge-sig">
						<?php
						printf(
							/* translators: %s: variant label */
							esc_html__( '%s wins', 'variolab-ab-testing' ),
							esc_html( (string) $multi['best'] )
						);
						?>
					</span>
					<?php
				else :
					$reason = StatsExplain::no_winner_reason( $multi, $status, $started_at );
					?>
					<span class="abtest-muted abtest-ci abtest-no-winner" title="<?php echo esc_attr( $reason ); ?>">
						<?php
						/* translators: %s: alpha threshold */
						printf( esc_html__( 'No winner (α=%s)', 'variolab-ab-testing' ), esc_html( number_format_i18n( (float) $multi['alpha'], 3 ) ) );
						?>
						<span class="abtest-help-icon" aria-hidden="true">?</span>
						<span class="screen-reader-text"><?php echo esc_html( $reason ); ?></span>
					</span>
				<?php endif; ?>
			</td>
			<td class="abtest-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'variolab-ab-testing' ); ?></a>

				<?php if ( Experiment::STATUS_DRAFT === $status ) : ?>
					| <a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_RUNNING ) ); ?>"><?php esc_html_e( 'Start', 'variolab-ab-testing' ); ?></a>
				<?php elseif ( Experiment::STATUS_RUNNING === $status ) : ?>
					| <a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_PAUSED ) ); ?>"><?php esc_html_e( 'Pause', 'variolab-ab-testing' ); ?></a>
					| <a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_ENDED ) ); ?>"><?php esc_html_e( 'End', 'variolab-ab-testing' ); ?></a>
				<?php elseif ( Experiment::STATUS_PAUSED === $status ) : ?>
					| <a href="<?php echo esc_url( self::resume_url( $exp_id ) ); ?>"
						 class="abtest-resume"
						 title="<?php esc_attr_e( 'Create a new experiment from this one and start it. The original keeps its locked dates.', 'variolab-ab-testing' ); ?>"
						 onclick="return confirm('<?php echo esc_js( __( 'Resume by creating a new experiment with fresh dates? The original stays paused with its current period locked.', 'variolab-ab-testing' ) ); ?>');">
						<?php esc_html_e( 'Resume', 'variolab-ab-testing' ); ?>
					</a>
					| <a href="<?php echo esc_url( self::status_url( $exp_id, Experiment::STATUS_ENDED ) ); ?>"><?php esc_html_e( 'End', 'variolab-ab-testing' ); ?></a>
				<?php endif; ?>

				<?php if ( Experiment::STATUS_RUNNING !== $status && Experiment::STATUS_ENDED !== $status && $running_other instanceof \WP_Post ) : ?>
					|
					<a href="<?php echo esc_url( self::replace_running_url( $exp_id ) ); ?>"
					   class="abtest-replace"
					   title="<?php echo esc_attr( sprintf( /* translators: %s: title of the experiment that will be paused */ __( 'Pause "%s" and start this one in a single action.', 'variolab-ab-testing' ), get_the_title( $running_other ) ) ); ?>"
					   onclick="return confirm('<?php echo esc_js( sprintf( /* translators: 1: running experiment title, 2: new experiment title */ __( 'Replace "%1$s" (running) with "%2$s"? The current one will be paused.', 'variolab-ab-testing' ), get_the_title( $running_other ), get_the_title( $experiment ) ) ); ?>');">
						<?php esc_html_e( 'Replace running', 'variolab-ab-testing' ); ?>
					</a>
				<?php endif; ?>

				| <a href="<?php echo esc_url( self::delete_url( $exp_id ) ); ?>" class="abtest-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this experiment? Events will be lost.', 'variolab-ab-testing' ) ); ?>');"><?php esc_html_e( 'Delete', 'variolab-ab-testing' ); ?></a>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param \WP_Post[] $experiments
	 * @return array<string, \WP_Post[]> URL path => experiments[], sorted by URL ASC
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
		// Sort URLs alphabetically; empty URL bucket goes last.
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
			$imp += (int) ( $row['A']['impressions'] ?? 0 ) + (int) ( $row['B']['impressions'] ?? 0 );
			$cv  += (int) ( $row['A']['conversions'] ?? 0 ) + (int) ( $row['B']['conversions'] ?? 0 );
		}
		return [
			'impressions' => $imp,
			'conversions' => $cv,
			'rate'        => $imp > 0 ? $cv / $imp : 0.0,
		];
	}

	/**
	 * Render a Chart.js timeline canvas + JSON payload for one URL block.
	 *
	 * @param string     $url  Test URL the chart is plotting.
	 * @param \WP_Post[] $exps Experiments running (or that ran) on this URL.
	 * @param string     $from Optional from-date filter (YYYY-MM-DD).
	 * @param string     $to   Optional to-date filter (YYYY-MM-DD).
	 */
	private static function render_url_chart( string $url, array $exps, string $from = '', string $to = '' ): void {
		$breakdown = Stats::daily_breakdown_for_url( $url, $from, $to );
		if ( empty( $breakdown['days'] ) || empty( $breakdown['series'] ) ) {
			return;
		}

		// Inline experiment titles so the JS can label legends nicely.
		$titles = [];
		foreach ( $exps as $exp ) {
			$titles[ (int) $exp->ID ] = (string) get_the_title( $exp );
		}
		$breakdown['titles'] = $titles;

		$canvas_id = 'abtest-chart-' . md5( $url );
		?>
		<div class="abtest-chart-wrap">
			<h3 class="abtest-chart-title"><?php esc_html_e( 'Daily conversion rate', 'variolab-ab-testing' ); ?></h3>
			<div class="abtest-chart-canvas-wrap">
				<canvas id="<?php echo esc_attr( $canvas_id ); ?>" class="abtest-url-chart"></canvas>
				<script type="application/json" class="abtest-chart-data"><?php echo wp_json_encode( $breakdown ); ?></script>
			</div>
		</div>
		<?php
	}

	/**
	 * Show "Showing X URLs · Y archived hidden" with a toggle link to flip the show=all param.
	 */
	private static function render_visibility_toggle( bool $show_all, int $hidden_count, string $from, string $to ): void {
		$base = [ 'page' => Admin::menu_slug() ];
		if ( '' !== $from ) { $base['from'] = $from; }
		if ( '' !== $to ) { $base['to'] = $to; }
		?>
		<p class="abtest-visibility-toggle">
			<?php if ( $show_all ) : ?>
				<?php esc_html_e( 'Showing all URLs (including those without a running experiment).', 'variolab-ab-testing' ); ?>
				<a href="<?php echo esc_url( add_query_arg( $base, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Show only running', 'variolab-ab-testing' ); ?></a>
			<?php elseif ( $hidden_count > 0 ) : ?>
				<?php
				printf(
					esc_html(
						/* translators: %d: number of hidden URLs */
						_n(
							'%d archived URL hidden (no running experiment).',
							'%d archived URLs hidden (no running experiment).',
							$hidden_count,
							'variolab-ab-testing'
						)
					),
					(int) $hidden_count
				);
				?>
				<a href="<?php echo esc_url( add_query_arg( array_merge( $base, [ 'show' => 'all' ] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Show all', 'variolab-ab-testing' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Stack each variant in the cell with its own page title, counts, and (for non-baseline)
	 * lift + 95% CI vs the baseline. Significant comparisons get the green badge.
	 *
	 * @param array<int, array{label:string, post_id:int}> $variant_specs
	 * @param array                                        $multi   Output of Stats::compute_multi()
	 */
	private static function render_variants_cell( array $variant_specs, array $multi ): void {
		if ( empty( $variant_specs ) ) {
			echo '<em class="abtest-muted">—</em>';
			return;
		}
		$baseline = (string) ( $multi['baseline'] ?? 'A' );
		?>
		<div class="abtest-variants-stack">
			<?php
			foreach ( $variant_specs as $v ) :
				$label = (string) $v['label'];
				$pid   = (int) $v['post_id'];
				$row   = $multi['variants'][ $label ] ?? [ 'impressions' => 0, 'conversions' => 0, 'rate' => 0 ];
				$cmp   = $multi['comparisons'][ $label ] ?? null; // null for baseline
				?>
				<div class="abtest-variant-line">
					<span class="abtest-variant-tag abtest-variant-tag-<?php echo esc_attr( strtolower( $label ) ); ?>"><?php echo esc_html( $label ); ?></span>
					<span class="abtest-variant-title"><?php echo esc_html( get_the_title( $pid ) ?: '—' ); ?></span>
					<span class="abtest-variant-counts">
						<?php
						printf(
							/* translators: 1: conversions, 2: impressions, 3: conversion rate */
							esc_html__( '%1$d / %2$d (%3$s)', 'variolab-ab-testing' ),
							(int) $row['conversions'],
							(int) $row['impressions'],
							esc_html( self::pct( (float) $row['rate'] ) )
						);
						?>
					</span>
					<?php if ( $label === $baseline ) : ?>
						<span class="abtest-variant-baseline"><?php esc_html_e( 'baseline', 'variolab-ab-testing' ); ?></span>
					<?php elseif ( $cmp ) : ?>
						<span class="abtest-lift abtest-lift-<?php echo $cmp['lift'] >= 0 ? 'pos' : 'neg'; ?>">
							<?php echo esc_html( self::pct( (float) $cmp['lift'], true ) ); ?>
						</span>
						<?php if ( $cmp['significant'] ) : ?>
							<span class="abtest-badge abtest-badge-sig"><?php esc_html_e( 'sig', 'variolab-ab-testing' ); ?></span>
						<?php else : ?>
							<span class="abtest-muted abtest-ci">
								<?php
								printf(
									/* translators: 1: low bound, 2: high bound */
									esc_html__( '95%% CI [%1$s ; %2$s]', 'variolab-ab-testing' ),
									esc_html( self::pct( (float) $cmp['lift_ci_low'], true ) ),
									esc_html( self::pct( (float) $cmp['lift_ci_high'], true ) )
								);
								?>
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_date_filter( string $from, string $to ): void {
		$today    = current_time( 'Y-m-d' );
		$preset_7 = gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) );
		$preset_30 = gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );

		$base = [ 'page' => Admin::menu_slug() ];
		$preset_url = static function ( array $args ) use ( $base ) {
			return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
		};
		?>
		<form method="get" class="abtest-date-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::menu_slug() ); ?>">
			<label>
				<?php esc_html_e( 'From', 'variolab-ab-testing' ); ?>
				<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" max="<?php echo esc_attr( $today ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'To', 'variolab-ab-testing' ); ?>
				<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" max="<?php echo esc_attr( $today ); ?>">
			</label>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'variolab-ab-testing' ); ?></button>
			<span class="abtest-date-presets">
				<a href="<?php echo esc_url( $preset_url( [ 'from' => $preset_7, 'to' => $today ] ) ); ?>"><?php esc_html_e( 'Last 7 days', 'variolab-ab-testing' ); ?></a>
				·
				<a href="<?php echo esc_url( $preset_url( [ 'from' => $preset_30, 'to' => $today ] ) ); ?>"><?php esc_html_e( 'Last 30 days', 'variolab-ab-testing' ); ?></a>
				·
				<a href="<?php echo esc_url( $preset_url( [] ) ); ?>"><?php esc_html_e( 'All time', 'variolab-ab-testing' ); ?></a>
			</span>
			<?php if ( '' !== $from || '' !== $to ) : ?>
				<span class="abtest-date-active">
					<?php
					if ( '' !== $from && '' !== $to ) {
						/* translators: 1: from date (YYYY-MM-DD), 2: to date (YYYY-MM-DD) */
						printf( esc_html__( 'Showing events from %1$s to %2$s', 'variolab-ab-testing' ), esc_html( $from ), esc_html( $to ) );
					} elseif ( '' !== $from ) {
						/* translators: %s: from date (YYYY-MM-DD) */
						printf( esc_html__( 'Showing events since %s', 'variolab-ab-testing' ), esc_html( $from ) );
					} else {
						/* translators: %s: to date (YYYY-MM-DD) */
						printf( esc_html__( 'Showing events up to %s', 'variolab-ab-testing' ), esc_html( $to ) );
					}
					?>
				</span>
			<?php endif; ?>
		</form>
		<?php
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

<?php
/**
 * Experiment create/edit screen.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Experiment;

defined( 'ABSPATH' ) || exit;

final class ExperimentEdit {

	/**
	 * Render a single variant row : label badge + page select + remove button.
	 * The label is positional (A for index 0, B for 1, etc.) and re-numbered by JS on add/remove.
	 *
	 * @param int        $index            Position in the variants list (0 = A, 1 = B, ...).
	 * @param int        $selected_post_id Currently selected page post ID, or 0 for "no selection".
	 * @param \WP_Post[] $pages            Pages to populate the dropdown with.
	 */
	public static function render_variant_row( int $index, int $selected_post_id, array $pages ): void {
		$label = Experiment::VARIANT_LABELS[ $index ] ?? '?';
		?>
		<div class="abtest-variant-row" data-index="<?php echo (int) $index; ?>">
			<span class="abtest-variant-label"><?php echo esc_html( $label ); ?></span>
			<select name="variants[<?php echo (int) $index; ?>][post_id]" class="abtest-variant-select">
				<option value="0"><?php echo 0 === $index ? esc_html__( '— Select page —', 'variolab-ab-testing' ) : esc_html__( '— None / remove —', 'variolab-ab-testing' ); ?></option>
				<?php foreach ( $pages as $page ) : ?>
					<option value="<?php echo (int) $page->ID; ?>" <?php selected( (int) $page->ID, $selected_post_id ); ?>>
						<?php echo esc_html( get_the_title( $page ) . ' (#' . $page->ID . ' · ' . $page->post_status . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $index > 0 ) : ?>
				<button type="button" class="button-link abtest-variant-remove" aria-label="<?php esc_attr_e( 'Remove this variant', 'variolab-ab-testing' ); ?>">
					<?php esc_html_e( 'Remove', 'variolab-ab-testing' ); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single row of the URL-scripts editor. Used by both the initial render
	 * and (as a JS template via data-attribute) by the "Add script" button.
	 */
	public static function render_script_row( int $index, string $position, string $code ): void {
		$positions = [
			\Abtest\UrlScripts::POSITION_AFTER_BODY_OPEN   => __( 'After <body> opening tag', 'variolab-ab-testing' ),
			\Abtest\UrlScripts::POSITION_BEFORE_BODY_CLOSE => __( 'Before </body> closing tag', 'variolab-ab-testing' ),
		];
		?>
		<div class="abtest-url-script-row">
			<div class="abtest-url-script-head">
				<label>
					<?php esc_html_e( 'Position:', 'variolab-ab-testing' ); ?>
					<select name="url_scripts[<?php echo (int) $index; ?>][position]">
						<?php foreach ( $positions as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $position, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link abtest-url-script-remove" aria-label="<?php esc_attr_e( 'Remove this script', 'variolab-ab-testing' ); ?>">
					<?php esc_html_e( 'Remove', 'variolab-ab-testing' ); ?>
				</button>
			</div>
			<textarea name="url_scripts[<?php echo (int) $index; ?>][code]" rows="6" class="large-text code" placeholder="<?php /* phpcs:ignore WordPress.WP.I18n.NoHtmlWrappedStrings -- example snippet shown in placeholder, intentional */ esc_attr_e( '<script>gtag(\'event\', \'page_view\')</script>', 'variolab-ab-testing' ); ?>"><?php echo esc_textarea( $code ); ?></textarea>
		</div>
		<?php
	}

	public static function render( int $experiment_id ): void {
		Admin::maybe_render_notice();

		$is_new       = $experiment_id <= 0;
		$post         = $is_new ? null : get_post( $experiment_id );
		$title        = $post ? $post->post_title : '';
		$test_path    = $is_new ? '' : Experiment::get_test_url( $experiment_id );
		$url_noindex  = '' !== $test_path ? \Abtest\UrlSettings::is_noindex( $test_path ) : false;

		// Pre-fill test_url from query string when creating a new experiment via the
		// per-URL "+ Add experiment to this URL" button.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_new && isset( $_GET['test_url'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$test_path = Experiment::normalize_path( sanitize_text_field( wp_unslash( $_GET['test_url'] ) ) );
		}

		$variants     = $is_new ? [] : Experiment::get_variants( $experiment_id );
		$control_id   = $is_new ? 0 : Experiment::get_control_id( $experiment_id );
		$variant_id   = $is_new ? 0 : Experiment::get_variant_id( $experiment_id );
		$goal         = $is_new ? [ 'type' => Experiment::GOAL_URL, 'value' => '' ] : Experiment::get_goal( $experiment_id );
		$status       = $is_new ? Experiment::STATUS_DRAFT : Experiment::get_status( $experiment_id );

		// Sticky form: hydrate from the transient stash set by Admin::handle_save()
		// when validation failed on the previous submit. One-shot read — the stash
		// is consumed and deleted by pop_form_state(). Stash values OVERRIDE the
		// DB/query-string defaults above (the user's last typed values win).
		$stash = Admin::pop_form_state( $experiment_id );
		if ( null !== $stash ) {
			if ( isset( $stash['title'] ) ) {
				$title = (string) $stash['title'];
			}
			if ( isset( $stash['test_url'] ) ) {
				$test_path = Experiment::normalize_path( (string) $stash['test_url'] );
			}
			if ( isset( $stash['goal_type'] ) || isset( $stash['goal_value'] ) ) {
				$goal = [
					'type'  => isset( $stash['goal_type'] ) ? (string) $stash['goal_type'] : $goal['type'],
					'value' => isset( $stash['goal_value'] ) ? (string) $stash['goal_value'] : $goal['value'],
				];
			}
			if ( isset( $stash['status'] ) ) {
				$status = (string) $stash['status'];
			}
			if ( isset( $stash['variants'] ) && is_array( $stash['variants'] ) ) {
				$variants = $stash['variants'];
				// Recompute control/variant IDs from the stashed array for downstream code.
				$control_id = isset( $variants[0]['post_id'] ) ? (int) $variants[0]['post_id'] : 0;
				$variant_id = isset( $variants[1]['post_id'] ) ? (int) $variants[1]['post_id'] : 0;
			}
			if ( array_key_exists( 'url_noindex', $stash ) ) {
				$url_noindex = (bool) $stash['url_noindex'];
			}
		}

		// Variant pages may be in `private` status — list those too so admins can pick / re-pick them.
		$pages = get_posts(
			[
				'post_type'      => [ 'page', 'post' ],
				'post_status'    => [ 'publish', 'private', 'draft' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		$action_url = admin_url( 'admin-post.php' );
		$full_url   = '' !== $test_path ? home_url( $test_path ) : '';

		// Detect conflict: an existing post that already lives at this path.
		$conflict_post = null;
		if ( '' !== $test_path ) {
			$slug = trim( $test_path, '/' );
			$conflict_post = get_page_by_path( $slug, OBJECT, [ 'page', 'post' ] );
			if ( $conflict_post && in_array( (int) $conflict_post->ID, [ $control_id, $variant_id ], true ) ) {
				$conflict_post = null; // It's our own variant — not a conflict.
			}
		}
		?>
		<div class="wrap abtest-wrap">
			<h1><?php echo esc_html( $is_new ? __( 'New A/B Test', 'variolab-ab-testing' ) : __( 'Edit A/B Test', 'variolab-ab-testing' ) ); ?></h1>

			<?php if ( $full_url ) : ?>
				<div class="notice notice-info inline abtest-test-url-banner">
					<p>
						<strong><?php esc_html_e( 'Test URL — share this with your visitors:', 'variolab-ab-testing' ); ?></strong><br>
						<a href="<?php echo esc_url( $full_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $full_url ); ?></code></a>
						<br>
						<em><?php esc_html_e( 'Visitors are split 50/50 between the two variants via a cookie. The URL stays the same for everyone.', 'variolab-ab-testing' ); ?></em>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $conflict_post ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: 1: existing post title, 2: post ID */
							esc_html__( 'Heads up: a published post already lives at this URL — "%1$s" (#%2$d). The A/B test will hide it while running, and restore it when the test is paused or ended.', 'variolab-ab-testing' ),
							esc_html( get_the_title( $conflict_post ) ),
							(int) $conflict_post->ID
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="abtest-form">
				<?php wp_nonce_field( Admin::nonce_action(), '_abtest_nonce' ); ?>
				<input type="hidden" name="action" value="abtest_save_experiment">
				<input type="hidden" name="experiment_id" value="<?php echo (int) $experiment_id; ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abtest-title"><?php esc_html_e( 'Title', 'variolab-ab-testing' ); ?></label></th>
						<td><input type="text" id="abtest-title" name="title" class="regular-text" value="<?php echo esc_attr( $title ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-test-url"><?php esc_html_e( 'Test URL', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="text" id="abtest-test-url" name="test_url" class="regular-text code" placeholder="/promo/" value="<?php echo esc_attr( $test_path ); ?>" required>
							<p class="description">
								<?php esc_html_e( 'Public URL where visitors will see the A/B test. Examples: /promo/, /landing-2026/, /pricing/new/, /promotion-été/, /中文/. Optional query params target campaigns: /promo/?campaign=fb (visitor URL must include campaign=fb; extra params like utm_source or fbclid are tolerated).', 'variolab-ab-testing' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SEO', 'variolab-ab-testing' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="abtest-url-noindex" name="url_noindex" value="1" <?php checked( $url_noindex ); ?>>
								<?php esc_html_e( 'Mark this URL as no-index (block search engines)', 'variolab-ab-testing' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Sends `<meta name="robots" content="noindex,nofollow">` and the matching `X-Robots-Tag` HTTP header on every visit to this URL — regardless of which experiment is running. Recommended for landing pages dedicated to paid traffic, or any URL where you don\'t want both A/B variants to compete in search results. The setting is per-URL: every experiment that lands on the same URL inherits it.', 'variolab-ab-testing' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Variants', 'variolab-ab-testing' ); ?></th>
						<td>
							<?php
							// Build the rendered list: at least 1 row (Variant A), up to MAX_VARIANTS.
							$rendered = ! empty( $variants ) ? $variants : [ [ 'label' => 'A', 'post_id' => 0 ] ];
							?>
							<div class="abtest-variants" data-max="<?php echo (int) Experiment::MAX_VARIANTS; ?>">
								<?php foreach ( $rendered as $i => $v ) : ?>
									<?php self::render_variant_row( $i, (int) ( $v['post_id'] ?? 0 ), $pages ); ?>
								<?php endforeach; ?>
							</div>
							<p>
								<button type="button" class="button button-secondary abtest-variant-add"
										<?php echo count( $rendered ) >= Experiment::MAX_VARIANTS ? 'disabled' : ''; ?>>
									+ <?php esc_html_e( 'Add variant', 'variolab-ab-testing' ); ?>
								</button>
								<span class="abtest-variant-help description">
									<?php
									printf(
										/* translators: %d: max variants */
										esc_html__( 'Up to %d variants. Visitors are split equally (1/N each). Variant A is the baseline — others are compared against it. Leave only A to run in baseline mode.', 'variolab-ab-testing' ),
										(int) Experiment::MAX_VARIANTS
									);
									?>
								</span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Goal', 'variolab-ab-testing' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="goal_type" value="<?php echo esc_attr( Experiment::GOAL_URL ); ?>" <?php checked( $goal['type'], Experiment::GOAL_URL ); ?>>
									<?php esc_html_e( 'URL visited (e.g. /thank-you)', 'variolab-ab-testing' ); ?>
								</label><br>
								<label>
									<input type="radio" name="goal_type" value="<?php echo esc_attr( Experiment::GOAL_SELECTOR ); ?>" <?php checked( $goal['type'], Experiment::GOAL_SELECTOR ); ?>>
									<?php esc_html_e( 'CSS selector clicked (e.g. .cta-buy)', 'variolab-ab-testing' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-goal-value"><?php esc_html_e( 'Goal value', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="text" id="abtest-goal-value" name="goal_value" class="regular-text" value="<?php echo esc_attr( $goal['value'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'URL path or CSS selector matching the conversion event.', 'variolab-ab-testing' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current status', 'variolab-ab-testing' ); ?></th>
						<td>
							<?php if ( $is_new ) : ?>
								<span class="abtest-status abtest-status-draft"><?php esc_html_e( 'New', 'variolab-ab-testing' ); ?></span>
							<?php else : ?>
								<span class="abtest-status abtest-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
							<?php endif; ?>
							<?php if ( Experiment::STATUS_PAUSED === $status ) : ?>
								<p class="description"><?php esc_html_e( 'To run this paused experiment again, use the Resume action from the experiments list — it creates a new experiment with fresh dates and ends this one cleanly.', 'variolab-ab-testing' ); ?></p>
							<?php elseif ( Experiment::STATUS_ENDED === $status ) : ?>
								<p class="description"><?php esc_html_e( 'This experiment is ended (terminal state). Its dates are locked.', 'variolab-ab-testing' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php
					$schedule_start = $is_new ? '' : (string) get_post_meta( $experiment_id, Experiment::META_SCHEDULE_START_AT, true );
					$schedule_end   = $is_new ? '' : (string) get_post_meta( $experiment_id, Experiment::META_SCHEDULE_END_AT, true );
					// datetime-local needs YYYY-MM-DDTHH:MM (no seconds, no timezone).
					$start_input    = '' !== $schedule_start ? str_replace( ' ', 'T', substr( $schedule_start, 0, 16 ) ) : '';
					$end_input      = '' !== $schedule_end ? str_replace( ' ', 'T', substr( $schedule_end, 0, 16 ) ) : '';
					// Sticky-form override (stash format is already datetime-local).
					if ( isset( $stash['schedule_start_at'] ) ) {
						$start_input = (string) $stash['schedule_start_at'];
					}
					if ( isset( $stash['schedule_end_at'] ) ) {
						$end_input = (string) $stash['schedule_end_at'];
					}
					?>
					<tr>
						<th scope="row"><label for="abtest-schedule-start"><?php esc_html_e( 'Auto-start at (optional)', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="datetime-local" id="abtest-schedule-start" name="schedule_start_at" value="<?php echo esc_attr( $start_input ); ?>">
							<p class="description"><?php esc_html_e( 'When this date passes, a Draft experiment auto-transitions to Running (via WP-Cron, hourly check). Cleared after firing. Skipped if another experiment is already running on the same URL.', 'variolab-ab-testing' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-schedule-end"><?php esc_html_e( 'Auto-end at (optional)', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="datetime-local" id="abtest-schedule-end" name="schedule_end_at" value="<?php echo esc_attr( $end_input ); ?>">
							<p class="description"><?php esc_html_e( 'When this date passes, a Running experiment auto-transitions to Ended.', 'variolab-ab-testing' ); ?></p>
						</td>
					</tr>
					<?php
					$target_devices   = $is_new ? [] : Experiment::get_target_devices( $experiment_id );
					$target_countries = $is_new ? [] : Experiment::get_target_countries( $experiment_id );
					// Sticky-form override.
					if ( isset( $stash['target_devices'] ) && is_array( $stash['target_devices'] ) ) {
						$target_devices = $stash['target_devices'];
					}
					if ( isset( $stash['target_countries'] ) ) {
						// Stash holds the raw comma-separated string; expand to the canonical
						// uppercase-ISO array shape so the rendering code below sees a uniform input.
						$codes = preg_split( '/[\s,;]+/', (string) $stash['target_countries'] ) ?: [];
						$target_countries = [];
						foreach ( $codes as $code ) {
							$norm = strtoupper( trim( $code ) );
							if ( preg_match( '/^[A-Z]{2}$/', $norm ) ) {
								$target_countries[] = $norm;
							}
						}
						$target_countries = array_values( array_unique( $target_countries ) );
					}
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Device targeting (optional)', 'variolab-ab-testing' ); ?></th>
						<td>
							<fieldset class="abtest-target-devices">
								<?php
								$device_labels = [
									'mobile'  => __( 'Mobile', 'variolab-ab-testing' ),
									'tablet'  => __( 'Tablet', 'variolab-ab-testing' ),
									'desktop' => __( 'Desktop', 'variolab-ab-testing' ),
								];
								foreach ( $device_labels as $value => $label ) :
									?>
									<label>
										<input type="checkbox" name="target_devices[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $target_devices, true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Leave all unchecked to include every device. Detection uses the visitor\'s User-Agent.', 'variolab-ab-testing' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-target-countries"><?php esc_html_e( 'Country targeting (optional)', 'variolab-ab-testing' ); ?></label></th>
						<td>
							<input type="text" id="abtest-target-countries" name="target_countries" class="regular-text code" value="<?php echo esc_attr( implode( ', ', $target_countries ) ); ?>" placeholder="FR, BE, CH">
							<p class="description">
								<?php esc_html_e( 'Comma-separated ISO 3166-1 alpha-2 codes (e.g. FR, BE, CH). Leave empty to target everyone. Requires a country header from your CDN/host (Cloudflare, Kinsta) or the `abtest_visitor_country` filter — visitors with unknown country are excluded when targeting is set.', 'variolab-ab-testing' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php
				// Tracking scripts editor for the URL of this experiment.
				if ( '' !== $test_path ) {
					$url_scripts = \Abtest\UrlScripts::get( $test_path );
				} else {
					$url_scripts = [];
				}
				// Sticky-form override (admin-typed entries beat the DB snapshot).
				if ( isset( $stash['url_scripts'] ) && is_array( $stash['url_scripts'] ) ) {
					$url_scripts = $stash['url_scripts'];
				}
				?>
				<h2 class="abtest-section-title"><?php esc_html_e( 'Tracking scripts', 'variolab-ab-testing' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL path */
						esc_html__( 'Shared with every experiment on %s. Injected as-is into the rendered page (Blank Canvas templates and themed pages alike). Save the form to persist changes.', 'variolab-ab-testing' ),
						'<code>' . esc_html( $test_path ?: '/your-url/' ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
					?>
				</p>

				<div class="abtest-url-scripts" data-empty-msg="<?php esc_attr_e( 'No scripts yet — click + Add script.', 'variolab-ab-testing' ); ?>">
					<?php if ( empty( $url_scripts ) ) : ?>
						<p class="abtest-url-scripts-empty"><?php esc_html_e( 'No scripts yet — click + Add script.', 'variolab-ab-testing' ); ?></p>
					<?php else : ?>
						<?php foreach ( $url_scripts as $i => $entry ) : ?>
							<?php self::render_script_row( $i, (string) $entry['position'], (string) $entry['code'] ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<p>
					<button type="button" class="button button-secondary abtest-url-script-add">+ <?php esc_html_e( 'Add script', 'variolab-ab-testing' ); ?></button>
				</p>

				<?php
				// New experiments start from DRAFT for transition purposes.
				$transition_baseline = $is_new ? Experiment::STATUS_DRAFT : $status;
				$allowed_statuses    = Experiment::allowed_next_statuses( $transition_baseline );

				// Map each allowed target status to a button label (kept-vs-transitioned).
				$button_specs = [
					Experiment::STATUS_DRAFT   => [
						'keep' => __( 'Save as Draft', 'variolab-ab-testing' ),
						'to'   => __( 'Save as Draft', 'variolab-ab-testing' ),
					],
					Experiment::STATUS_RUNNING => [
						'keep' => __( 'Save (still running)', 'variolab-ab-testing' ),
						'to'   => __( 'Save & Start', 'variolab-ab-testing' ),
					],
					Experiment::STATUS_PAUSED  => [
						'keep' => __( 'Save (still paused)', 'variolab-ab-testing' ),
						'to'   => __( 'Save & Pause', 'variolab-ab-testing' ),
					],
					Experiment::STATUS_ENDED   => [
						'keep' => __( 'Save (still ended)', 'variolab-ab-testing' ),
						'to'   => __( 'Save & End', 'variolab-ab-testing' ),
					],
				];

				// Primary button = the most common next action.
				if ( $is_new || Experiment::STATUS_DRAFT === $status ) {
					$primary = Experiment::STATUS_RUNNING;
				} else {
					$primary = $status; // existing experiment → encourage staying
				}
				?>
				<p class="submit abtest-status-buttons">
					<?php
					foreach ( $allowed_statuses as $option ) :
						$is_keep    = ( $option === $status && ! $is_new );
						$label      = $is_keep ? $button_specs[ $option ]['keep'] : $button_specs[ $option ]['to'];
						$is_primary = ( $option === $primary );
						?>
						<button type="submit" name="status" value="<?php echo esc_attr( $option ); ?>" class="button <?php echo $is_primary ? 'button-primary' : 'button-secondary'; ?> abtest-btn-<?php echo esc_attr( $option ); ?>">
							<?php echo esc_html( $label ); ?>
						</button>
					<?php endforeach; ?>
				</p>
			</form>
		</div>
		<?php
	}
}

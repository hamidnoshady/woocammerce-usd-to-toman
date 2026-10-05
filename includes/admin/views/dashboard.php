<?php
/**
 * Dashboard tab: rate, scope, preview and the current job.
 *
 * @package USDTF
 *
 * @var array $state   Dashboard state.
 * @var array $history Rate history rows.
 * @var array $page    Page slug.
 */

use USDTF\Calculator;

defined( 'ABSPATH' ) || exit;

$usdtf_suffix    = (string) $settings->get( 'toman_suffix' );
$usdtf_persian   = (bool) $settings->get( 'persian_digits' );
$usdtf_rate      = (float) $state['rate'];
$usdtf_pending   = $state['pending_rate'];
$usdtf_summary   = $state['summary'];
$usdtf_active    = $state['active_job'];
$usdtf_last      = $state['last_job'];
$usdtf_preview   = $state['last_preview_job'];
$usdtf_categories = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'number'     => 200,
	)
);

if ( is_wp_error( $usdtf_categories ) ) {
	$usdtf_categories = array();
}

$usdtf_types = array(
	'simple'        => __( 'Simple products', 'usd-to-toman-price-sync-for-woocommerce' ),
	'variable'      => __( 'Variable products', 'usd-to-toman-price-sync-for-woocommerce' ),
	'grouped'       => __( 'Grouped products', 'usd-to-toman-price-sync-for-woocommerce' ),
	'external'      => __( 'External products', 'usd-to-toman-price-sync-for-woocommerce' ),
);
?>
<div class="usdtf-grid">

	<section class="usdtf-card usdtf-card--rate">
		<h2><?php esc_html_e( 'Manual exchange rate', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

		<p class="usdtf-rate-current">
			<span class="usdtf-rate-value" id="usdtf-rate-value">
				<?php echo esc_html( $usdtf_rate > 0 ? Calculator::format_toman( $usdtf_rate, array( 'persian_digits' => $usdtf_persian ) ) : '—' ); ?>
			</span>
			<span class="usdtf-rate-unit"><?php esc_html_e( 'Toman per 1 USD', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
		</p>

		<p class="usdtf-meta">
			<?php if ( (float) $state['previous_rate'] > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: previous rate. */
					esc_html__( 'Previous rate: %s', 'usd-to-toman-price-sync-for-woocommerce' ),
					esc_html( Calculator::format_toman( (float) $state['previous_rate'], array( 'persian_digits' => $usdtf_persian ) ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No previous rate stored yet.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			<?php endif; ?>
		</p>

		<form id="usdtf-rate-form" class="usdtf-form">
			<label class="usdtf-field">
				<span><?php esc_html_e( 'New rate (Toman per 1 USD)', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
				<input
					type="text"
					id="usdtf-rate-input"
					name="usdtf_rate"
					class="regular-text"
					inputmode="decimal"
					autocomplete="off"
					placeholder="<?php echo esc_attr( $usdtf_rate > 0 ? (string) (int) $usdtf_rate : '270000' ); ?>"
				/>
			</label>

			<p class="description">
				<?php
				printf(
					/* translators: %s: percentage threshold. */
					esc_html__( 'A change larger than %s%% must be confirmed explicitly. Saving a rate never rewrites product prices by itself.', 'usd-to-toman-price-sync-for-woocommerce' ),
					esc_html( number_format_i18n( (float) $settings->get( 'rate_change_threshold' ), 2 ) )
				);
				?>
			</p>

			<p class="usdtf-actions">
				<button type="submit" class="button button-primary" id="usdtf-save-rate">
					<?php esc_html_e( 'Save rate', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</button>
				<button type="button" class="button" id="usdtf-preview-rate">
					<?php esc_html_e( 'Preview changes', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</button>
			</p>

			<div class="usdtf-confirm" id="usdtf-confirm" hidden>
				<p class="usdtf-confirm__text"></p>
				<label class="usdtf-field">
					<span>
						<?php
						printf(
							/* translators: %s: confirmation word. */
							esc_html__( 'Type %s to confirm this large rate change', 'usd-to-toman-price-sync-for-woocommerce' ),
							'<code>UPDATE</code>'
						);
						?>
					</span>
					<input type="text" id="usdtf-confirm-input" autocomplete="off" />
				</label>
				<p>
					<button type="button" class="button button-primary" id="usdtf-confirm-rate">
						<?php esc_html_e( 'Confirm and save', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					</button>
					<button type="button" class="button-link" id="usdtf-cancel-confirm">
						<?php esc_html_e( 'Cancel', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					</button>
				</p>
			</div>
		</form>

		<?php if ( $usdtf_pending ) : ?>
			<div class="usdtf-pending">
				<p>
					<strong><?php esc_html_e( 'A rate is waiting for confirmation:', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong>
					<?php
					printf(
						/* translators: 1: rate, 2: percentage change, 3: user name. */
						esc_html__( '%1$s Toman (%2$s%% change) submitted by %3$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
						esc_html( Calculator::format_toman( (float) $usdtf_pending['rate'], array( 'persian_digits' => $usdtf_persian ) ) ),
						esc_html( null === $usdtf_pending['change_percent'] ? '—' : number_format_i18n( (float) $usdtf_pending['change_percent'], 2 ) ),
						esc_html( $usdtf_pending['user'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<p class="usdtf-actions usdtf-actions--secondary">
			<button type="button" class="button button-primary" id="usdtf-start-update">
				<?php esc_html_e( 'Update prices now', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</button>
			<button type="button" class="button" id="usdtf-start-preview">
				<?php esc_html_e( 'Dry run', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</button>
			<?php if ( ! empty( $state['can']['rollback'] ) ) : ?>
				<button type="button" class="button" id="usdtf-rollback">
					<?php esc_html_e( 'Roll back last rate change', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</button>
			<?php endif; ?>
		</p>
	</section>

	<section class="usdtf-card usdtf-card--summary">
		<h2><?php esc_html_e( 'Catalog overview', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

		<ul class="usdtf-stats">
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['managed'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'Managed products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['managed_variations'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'Managed variations', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['candidates'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'May need an update', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['native'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'Native USD products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['excluded'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'Excluded products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
			<li>
				<span class="usdtf-stat-value"><?php echo esc_html( number_format_i18n( (int) $usdtf_summary['unset'] ) ); ?></span>
				<span class="usdtf-stat-label"><?php esc_html_e( 'No mode chosen yet', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			</li>
		</ul>

		<p class="description">
			<?php esc_html_e( '“May need an update” counts managed products whose stored rate is not the current rate. The exact number of price changes is calculated by the dry run.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
		</p>

		<p class="usdtf-meta">
			<?php
			printf(
				/* translators: 1: transaction currency mode, 2: product count. */
				esc_html__( 'Transaction currency mode: %1$s. Products in the catalog: %2$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html( 'toman' === $state['currency_mode'] ? __( 'Toman (mode B)', 'usd-to-toman-price-sync-for-woocommerce' ) : __( 'USD with Toman display (mode A)', 'usd-to-toman-price-sync-for-woocommerce' ) ),
				esc_html( number_format_i18n( (int) $usdtf_summary['products'] ) )
			);
			?>
		</p>
	</section>
</div>

<section class="usdtf-card usdtf-card--scope">
	<h2><?php esc_html_e( 'What should be updated?', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<div class="usdtf-scope" id="usdtf-scope">
		<label class="usdtf-field">
			<span><?php esc_html_e( 'Scope', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			<select id="usdtf-scope-mode">
				<option value="all"><?php esc_html_e( 'Every managed product', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				<option value="outdated"><?php esc_html_e( 'Only products whose price may be outdated', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				<option value="category"><?php esc_html_e( 'Selected categories', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				<option value="type"><?php esc_html_e( 'Selected product types', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				<option value="selected"><?php esc_html_e( 'Selected products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
			</select>
		</label>

		<div class="usdtf-scope-row" data-scope="category" hidden>
			<span class="usdtf-label"><?php esc_html_e( 'Categories', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			<select id="usdtf-scope-categories" multiple size="6">
				<?php foreach ( $usdtf_categories as $usdtf_category ) : ?>
					<option value="<?php echo esc_attr( (int) $usdtf_category->term_id ); ?>">
						<?php echo esc_html( $usdtf_category->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="usdtf-scope-row" data-scope="type" hidden>
			<span class="usdtf-label"><?php esc_html_e( 'Product types', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			<?php foreach ( $usdtf_types as $usdtf_type => $usdtf_type_label ) : ?>
				<label class="usdtf-checkbox">
					<input type="checkbox" class="usdtf-scope-type" value="<?php echo esc_attr( $usdtf_type ); ?>" />
					<?php echo esc_html( $usdtf_type_label ); ?>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="usdtf-scope-row" data-scope="selected" hidden>
			<span class="usdtf-label"><?php esc_html_e( 'Products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
			<input type="search" id="usdtf-product-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name…', 'usd-to-toman-price-sync-for-woocommerce' ); ?>" />
			<ul class="usdtf-chips" id="usdtf-selected-products"></ul>
			<ul class="usdtf-search-results" id="usdtf-search-results"></ul>
		</div>

		<label class="usdtf-checkbox">
			<input type="checkbox" id="usdtf-include-variations" checked />
			<?php esc_html_e( 'Include variations (recommended)', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
		</label>
	</div>
</section>

<section class="usdtf-card usdtf-card--progress" id="usdtf-job-panel">
	<h2><?php esc_html_e( 'Current job', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<div id="usdtf-job-live"
		data-job-id="<?php echo esc_attr( $usdtf_active ? (int) $usdtf_active['id'] : 0 ); ?>">

		<?php if ( $usdtf_active ) : ?>
			<div class="usdtf-progress">
				<div class="usdtf-progress__bar">
					<span style="width: <?php echo esc_attr( (float) $usdtf_active['progress'] ); ?>%"></span>
				</div>
				<p class="usdtf-progress__numbers">
					<?php
					printf(
						/* translators: 1: processed items, 2: total items, 3: percentage. */
						esc_html__( '%1$s / %2$s items (%3$s%%)', 'usd-to-toman-price-sync-for-woocommerce' ),
						esc_html( number_format_i18n( (int) $usdtf_active['counters']['processed'] ) ),
						esc_html( number_format_i18n( (int) $usdtf_active['counters']['total'] ) ),
						esc_html( number_format_i18n( (float) $usdtf_active['progress'], 1 ) )
					);
					?>
				</p>
				<ul class="usdtf-counters">
					<li><?php esc_html_e( 'Changed', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['changed'] ) ); ?></strong></li>
					<li><?php esc_html_e( 'Unchanged', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['unchanged'] ) ); ?></strong></li>
					<li><?php esc_html_e( 'Skipped', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['skipped'] ) ); ?></strong></li>
					<li><?php esc_html_e( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['failed'] ) ); ?></strong></li>
					<li><?php esc_html_e( 'Conflicts', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['conflicts'] ) ); ?></strong></li>
					<li><?php esc_html_e( 'Variations', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_active['counters']['variations_processed'] ) ); ?></strong></li>
				</ul>

				<p class="usdtf-job-status">
					<?php echo esc_html( $usdtf_active['status'] ); ?>
					&middot;
					<?php echo esc_html( $usdtf_active['type'] ); ?>
					&middot;
					<?php
					printf(
						/* translators: %s: user name. */
						esc_html__( 'started by %s', 'usd-to-toman-price-sync-for-woocommerce' ),
						esc_html( $usdtf_active['user'] )
					);
					?>
				</p>

				<p class="usdtf-actions">
					<?php if ( ! empty( $usdtf_active['can_pause'] ) ) : ?>
						<button type="button" class="button usdtf-job-action" data-action="pause" data-job-id="<?php echo esc_attr( (int) $usdtf_active['id'] ); ?>">
							<?php esc_html_e( 'Pause', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $usdtf_active['can_resume'] ) ) : ?>
						<button type="button" class="button button-primary usdtf-job-action" data-action="resume" data-job-id="<?php echo esc_attr( (int) $usdtf_active['id'] ); ?>">
							<?php esc_html_e( 'Resume', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $usdtf_active['can_cancel'] ) ) : ?>
						<button type="button" class="button usdtf-job-action" data-action="cancel" data-job-id="<?php echo esc_attr( (int) $usdtf_active['id'] ); ?>">
							<?php esc_html_e( 'Cancel', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
						</button>
					<?php endif; ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . (int) $usdtf_active['id'] ) ); ?>">
						<?php esc_html_e( 'View details', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No price update is running right now.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php if ( $usdtf_preview && \USDTF\Job::TYPE_PREVIEW === $usdtf_preview['type'] ) : ?>
	<section class="usdtf-card usdtf-card--preview">
		<h2><?php esc_html_e( 'Last dry run', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
		<?php
		$usdtf_preview_items = $jobs->items(
			(int) $usdtf_preview['id'],
			array(
				'status' => \USDTF\Job_Repository::ITEM_CHANGED,
				'limit'  => 20,
			)
		);
		?>
		<p>
			<?php
			printf(
				/* translators: 1: changed, 2: unchanged, 3: skipped, 4: failed, 5: conflicts. */
				esc_html__( 'Would change: %1$s · unchanged: %2$s · skipped: %3$s · failed: %4$s · conflicts: %5$s', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html( number_format_i18n( (int) $usdtf_preview['counters']['changed'] ) ),
				esc_html( number_format_i18n( (int) $usdtf_preview['counters']['unchanged'] ) ),
				esc_html( number_format_i18n( (int) $usdtf_preview['counters']['skipped'] ) ),
				esc_html( number_format_i18n( (int) $usdtf_preview['counters']['failed'] ) ),
				esc_html( number_format_i18n( (int) $usdtf_preview['counters']['conflicts'] ) )
			);
			?>
		</p>

		<?php if ( $usdtf_preview_items ) : ?>
			<table class="widefat striped usdtf-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Toman source', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Current price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'New price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Note', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $usdtf_preview_items as $usdtf_item ) : ?>
						<?php $usdtf_product = wc_get_product( (int) $usdtf_item['object_id'] ); ?>
						<tr>
							<td>
								<?php if ( $usdtf_product ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $usdtf_item['object_id'], 'raw' ) ); ?>">
										<?php echo esc_html( $usdtf_product->get_name() ); ?>
									</a>
								<?php else : ?>
									<?php
									printf(
										/* translators: %d: product ID. */
										esc_html__( 'Product #%d', 'usd-to-toman-price-sync-for-woocommerce' ),
										(int) $usdtf_item['object_id']
									);
									?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Calculator::format_toman( (float) $usdtf_item['toman_regular'], array( 'with_suffix' => false, 'persian_digits' => $usdtf_persian ) ) ); ?></td>
							<td><?php echo esc_html( (string) $usdtf_item['old_regular'] ); ?></td>
							<td><strong><?php echo esc_html( (string) $usdtf_item['new_regular'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $usdtf_item['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Showing up to 20 products. The full report is available in the job details, including a CSV download.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . (int) $usdtf_preview['id'] ) ); ?>">
				<?php esc_html_e( 'Open the dry run report', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</a>
		</p>
	</section>
<?php endif; ?>

<div class="usdtf-grid">
	<section class="usdtf-card">
		<h2><?php esc_html_e( 'Recent jobs', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
		<?php
		$usdtf_jobs_list = $jobs->query( array( 'limit' => 5 ) );
		?>
		<?php if ( ! $usdtf_jobs_list ) : ?>
			<p class="description"><?php esc_html_e( 'No jobs have run yet.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped usdtf-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Type', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Changed', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Started', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $usdtf_jobs_list as $usdtf_job ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . $usdtf_job->id() ) ); ?>">#<?php echo esc_html( (string) $usdtf_job->id() ); ?></a></td>
							<td><?php echo esc_html( $usdtf_job->type_label() ); ?></td>
							<td><?php echo esc_html( $usdtf_job->status_label() ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $usdtf_job->data['changed'] ) ); ?></td>
							<td><?php echo esc_html( (string) $usdtf_job->data['started_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="usdtf-card">
		<h2><?php esc_html_e( 'Rate history', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
		<?php if ( ! $history ) : ?>
			<p class="description"><?php esc_html_e( 'No rate has been saved yet.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped usdtf-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Rate', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Change', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'By', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Changed', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $history, 0, 5 ) as $usdtf_row ) : ?>
						<tr>
							<td><?php echo esc_html( Calculator::format_toman( (float) $usdtf_row['rate'], array( 'persian_digits' => $usdtf_persian ) ) ); ?></td>
							<td>
								<?php
								echo esc_html(
									null === $usdtf_row['change_percent']
										? '—'
										: number_format_i18n( (float) $usdtf_row['change_percent'], 2 ) . '%'
								);
								?>
							</td>
							<td><?php echo esc_html( \USDTF\Job::user_display_name( (int) $usdtf_row['user_id'] ) ); ?></td>
							<td><?php echo esc_html( (string) $usdtf_row['created_at'] ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: checked, 2: changed. */
									esc_html__( '%1$s checked / %2$s changed', 'usd-to-toman-price-sync-for-woocommerce' ),
									esc_html( number_format_i18n( (int) $usdtf_row['products_checked'] ) ),
									esc_html( number_format_i18n( (int) $usdtf_row['products_changed'] ) )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs' ) ); ?>"><?php esc_html_e( 'Full history and CSV export', 'usd-to-toman-price-sync-for-woocommerce' ); ?></a></p>
	</section>
</div>

<div class="usdtf-toast" id="usdtf-toast" hidden></div>

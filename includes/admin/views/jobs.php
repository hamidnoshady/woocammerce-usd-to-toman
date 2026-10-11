<?php
/**
 * Jobs, job detail, conflicts and history.
 *
 * @package USDTF
 *
 * @var string              $page        Page slug.
 * @var array               $state       Dashboard state.
 * @var array               $history     Rate history rows.
 * @var \USDTF\Job|null     $current_job Job being inspected.
 */

use USDTF\Admin\Admin;
use USDTF\Calculator;
use USDTF\Job;

defined( 'ABSPATH' ) || exit;
?>
<?php if ( $current_job ) : ?>
	<?php
	$usdtf_id          = $current_job->id();
	$usdtf_status_tabs = Admin::item_status_tabs();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only display filter.
	$usdtf_filter = isset( $_GET['items_status'] ) ? sanitize_key( wp_unslash( $_GET['items_status'] ) ) : '';
	$usdtf_filter = isset( $usdtf_status_tabs[ $usdtf_filter ] ) ? $usdtf_filter : '';
	$usdtf_items  = $jobs->items(
		$usdtf_id,
		array(
			'limit'  => 50,
			'page'   => 1,
			'status' => $usdtf_filter,
		)
	);
	$usdtf_totals = $jobs->item_totals( $usdtf_id );
	$usdtf_data   = $current_job->to_array();
	?>
	<section class="usdtf-card">
		<h2>
			<?php
			printf(
				/* translators: 1: job ID, 2: job type. */
				esc_html__( 'Job #%1$d — %2$s', 'usd-to-toman-price-sync-for-woocommerce' ),
				(int) $usdtf_id,
				esc_html( $current_job->type_label() )
			);
			?>
		</h2>

		<p class="usdtf-meta">
			<?php
			printf(
				/* translators: 1: status, 2: rate with its unit, 3: user, 4: scope label. */
				esc_html__( 'Status: %1$s · Rate: %2$s · Started by %3$s · Scope: %4$s', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html( $current_job->status_label() ),
				esc_html( Calculator::format_price( $current_job->rate() ) . ' ' . Admin::rate_label() ),
				esc_html( $usdtf_data['user'] ),
				esc_html( $products->scope_label( $current_job->scope() ) )
			);
			?>
		</p>

		<?php if ( ! empty( $usdtf_data['current_item'] ) && ! empty( $usdtf_data['is_active'] ) ) : ?>
			<p class="usdtf-current-item">
				<?php esc_html_e( 'Currently processing:', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				<strong><?php echo esc_html( $usdtf_data['current_item'] ); ?></strong>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $usdtf_data['message'] ) ) : ?>
			<p class="usdtf-message"><?php echo esc_html( $usdtf_data['message'] ); ?></p>
		<?php endif; ?>

		<div class="usdtf-progress">
			<div class="usdtf-progress__bar">
				<span style="width: <?php echo esc_attr( (float) $usdtf_data['progress'] ); ?>%"></span>
			</div>
			<ul class="usdtf-counters">
				<li><?php esc_html_e( 'Total', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_data['counters']['total'] ) ); ?></strong></li>
				<li><?php esc_html_e( 'Processed', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_data['counters']['processed'] ) ); ?></strong></li>
				<?php foreach ( Admin::counter_labels() as $usdtf_counter => $usdtf_counter_label ) : ?>
					<li><?php echo esc_html( $usdtf_counter_label ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_data['counters'][ $usdtf_counter ] ) ); ?></strong></li>
				<?php endforeach; ?>
				<li><?php esc_html_e( 'Variations processed', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_data['counters']['variations_processed'] ) ); ?></strong></li>
				<li><?php esc_html_e( 'Variations changed', 'usd-to-toman-price-sync-for-woocommerce' ); ?>: <strong><?php echo esc_html( number_format_i18n( (int) $usdtf_data['counters']['variations_changed'] ) ); ?></strong></li>
			</ul>
		</div>

		<p class="usdtf-actions">
			<?php if ( ! empty( $usdtf_data['can_pause'] ) ) : ?>
				<button type="button" class="button usdtf-job-action" data-action="pause" data-job-id="<?php echo esc_attr( (int) $usdtf_id ); ?>"><?php esc_html_e( 'Pause', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $usdtf_data['can_resume'] ) ) : ?>
				<button type="button" class="button button-primary usdtf-job-action" data-action="resume" data-job-id="<?php echo esc_attr( (int) $usdtf_id ); ?>"><?php esc_html_e( 'Resume', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $usdtf_data['can_cancel'] ) ) : ?>
				<button type="button" class="button usdtf-job-action" data-action="cancel" data-job-id="<?php echo esc_attr( (int) $usdtf_id ); ?>"><?php esc_html_e( 'Cancel', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( (int) $usdtf_data['counters']['failed'] > 0 ) : ?>
				<button type="button" class="button usdtf-job-action" data-action="retry-failed" data-job-id="<?php echo esc_attr( (int) $usdtf_id ); ?>"><?php esc_html_e( 'Retry failed items', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<?php if ( (int) $usdtf_data['counters']['conflicts'] > 0 ) : ?>
				<button type="button" class="button usdtf-job-action" data-action="recalculate-conflicts" data-job-id="<?php echo esc_attr( (int) $usdtf_id ); ?>"><?php esc_html_e( 'Recalculate conflicts', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usdtf_export&type=items&job_id=' . (int) $usdtf_id ), 'usdtf_export' ) ); ?>">
				<?php esc_html_e( 'Download CSV', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</a>
			<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs' ) ); ?>">
				<?php esc_html_e( 'Back to all jobs', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</a>
		</p>

		<ul class="usdtf-filters usdtf-status-tabs" data-job-id="<?php echo esc_attr( (string) (int) $usdtf_id ); ?>">
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . $usdtf_id ) ); ?>" data-status="" class="<?php echo '' === $usdtf_filter ? 'is-current' : ''; ?>">
					<?php esc_html_e( 'All', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					(<?php echo esc_html( number_format_i18n( array_sum( $usdtf_totals ) ) ); ?>)
				</a>
			</li>
			<?php foreach ( $usdtf_status_tabs as $usdtf_status => $usdtf_label ) : ?>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . $usdtf_id . '&items_status=' . $usdtf_status ) ); ?>" data-status="<?php echo esc_attr( $usdtf_status ); ?>" class="<?php echo $usdtf_status === $usdtf_filter ? 'is-current' : ''; ?>">
						<?php echo esc_html( $usdtf_label ); ?>
						(<?php echo esc_html( number_format_i18n( isset( $usdtf_totals[ $usdtf_status ] ) ? (int) $usdtf_totals[ $usdtf_status ] : 0 ) ); ?>)
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php
		$usdtf_table_items = $usdtf_items;
		$usdtf_table_job   = $usdtf_id;
		include USDTF_DIR . 'includes/admin/views/items-table.php';
		?>
		<p class="description"><?php esc_html_e( 'Showing up to 50 items. Use the CSV download for the complete report.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
	</section>
<?php endif; ?>

<section class="usdtf-card">
	<h2><?php esc_html_e( 'All jobs', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
	<?php $usdtf_list = $jobs->query( array( 'limit' => 50 ) ); ?>

	<?php if ( ! $usdtf_list ) : ?>
		<p class="description"><?php esc_html_e( 'No jobs have been created yet.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="widefat striped usdtf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Type', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Failed / conflicts', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Started', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'By', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $usdtf_list as $usdtf_job ) : ?>
					<?php $usdtf_job_data = $usdtf_job->to_array(); ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . $usdtf_job->id() ) ); ?>">#<?php echo esc_html( (string) $usdtf_job->id() ); ?></a></td>
						<td><?php echo esc_html( $usdtf_job->type_label() ); ?></td>
						<td>
							<?php echo esc_html( $usdtf_job->status_label() ); ?>
							<?php if ( ! empty( $usdtf_job_data['is_stale'] ) ) : ?>
								<span class="usdtf-flag usdtf-flag--conflict"><?php esc_html_e( 'worker lost', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Calculator::format_price( $usdtf_job->rate() ) ); ?></td>
						<td>
							<?php echo esc_html( number_format_i18n( (float) $usdtf_job_data['progress'], 1 ) ); ?>%
							<?php if ( ! empty( $usdtf_job_data['current_item'] ) && $usdtf_job_data['is_active'] ) : ?>
								<span class="usdtf-current-item"><?php echo esc_html( $usdtf_job_data['current_item'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $usdtf_job_data['counters']['changed'] ) ); ?></td>
						<td>
							<?php
							printf(
								/* translators: 1: failed, 2: conflicts. */
								esc_html__( '%1$s / %2$s', 'usd-to-toman-price-sync-for-woocommerce' ),
								esc_html( number_format_i18n( (int) $usdtf_job_data['counters']['failed'] ) ),
								esc_html( number_format_i18n( (int) $usdtf_job_data['counters']['conflicts'] ) )
							);
							?>
						</td>
						<td><?php echo esc_html( (string) $usdtf_job_data['started_at'] ); ?></td>
						<td><?php echo esc_html( $usdtf_job_data['user'] ); ?></td>
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
					<th><?php esc_html_e( 'Previous', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Change', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Source', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'By', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Checked', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Changed', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'When', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Job', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $usdtf_row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( Calculator::format_price( (float) $usdtf_row['rate'] ) ); ?></strong></td>
						<td><?php echo esc_html( null === $usdtf_row['previous_rate'] ? '—' : Calculator::format_price( (float) $usdtf_row['previous_rate'] ) ); ?></td>
						<td><?php echo esc_html( null === $usdtf_row['change_percent'] ? '—' : number_format_i18n( (float) $usdtf_row['change_percent'], 2 ) . '%' ); ?></td>
						<td><?php echo esc_html( (string) $usdtf_row['source'] ); ?></td>
						<td><?php echo esc_html( Job::user_display_name( (int) $usdtf_row['user_id'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $usdtf_row['products_checked'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $usdtf_row['products_changed'] ) ); ?></td>
						<td><?php echo esc_html( (string) $usdtf_row['status'] ); ?></td>
						<td><?php echo esc_html( (string) $usdtf_row['created_at'] ); ?></td>
						<td>
							<?php if ( (int) $usdtf_row['job_id'] > 0 ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=jobs&job=' . (int) $usdtf_row['job_id'] ) ); ?>">#<?php echo esc_html( (string) (int) $usdtf_row['job_id'] ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=usdtf_export&type=history' ), 'usdtf_export' ) ); ?>">
				<?php esc_html_e( 'Download rate history CSV', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</a>
		</p>
	<?php endif; ?>
</section>

<section class="usdtf-card">
	<h2><?php esc_html_e( 'Recent log entries', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
	<?php $usdtf_logs = $logger->recent( 20 ); ?>
	<?php if ( ! $usdtf_logs ) : ?>
		<p class="description"><?php esc_html_e( 'Nothing logged yet.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
	<?php else : ?>
		<table class="widefat striped usdtf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Level', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Job', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Message', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $usdtf_logs as $usdtf_log ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $usdtf_log['created_at'] ); ?></td>
						<td><span class="usdtf-status usdtf-status--<?php echo esc_attr( (string) $usdtf_log['level'] ); ?>"><?php echo esc_html( (string) $usdtf_log['level'] ); ?></span></td>
						<td><?php echo (int) $usdtf_log['job_id'] > 0 ? esc_html( '#' . (int) $usdtf_log['job_id'] ) : '—'; ?></td>
						<td><?php echo esc_html( (string) $usdtf_log['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<div class="usdtf-toast" id="usdtf-toast" hidden></div>

<?php
/**
 * Diagnostics tab.
 *
 * @package USDTF
 *
 * @var \USDTF\Health $health    Diagnostics.
 * @var \USDTF\Scheduler $scheduler Scheduler.
 */

defined( 'ABSPATH' ) || exit;

$usdtf_checks  = $health->checks();
$usdtf_queue   = $scheduler->health();
$usdtf_lock    = usdtf_plugin()->lock()->status();
$usdtf_stale   = $health->stale_jobs();
?>
<section class="usdtf-card">
	<h2><?php esc_html_e( 'Background queue', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="widefat striped usdtf-table">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Scheduling backend', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><code><?php echo esc_html( (string) $usdtf_queue['backend'] ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Action Scheduler available', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo $usdtf_queue['action_scheduler'] ? esc_html__( 'Yes', 'usd-to-toman-price-sync-for-woocommerce' ) : esc_html__( 'No', 'usd-to-toman-price-sync-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Pending worker actions', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) $usdtf_queue['pending'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Failed worker actions', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) $usdtf_queue['failed'] ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Oldest pending action age', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) $usdtf_queue['oldest_pending'] ) ); ?>s</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'WP-Cron disabled', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo $usdtf_queue['cron_disabled'] ? esc_html__( 'Yes', 'usd-to-toman-price-sync-for-woocommerce' ) : esc_html__( 'No', 'usd-to-toman-price-sync-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Next maintenance event', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( $usdtf_queue['next_tick'] ? (string) wp_date( 'Y-m-d H:i:s', (int) $usdtf_queue['next_tick'] ) : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Job lock', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td>
					<?php if ( $usdtf_lock['active'] ) : ?>
						<?php
						printf(
							/* translators: 1: job ID, 2: age in seconds. */
							esc_html__( 'Held by job #%1$d, heartbeat %2$ds ago', 'usd-to-toman-price-sync-for-woocommerce' ),
							(int) $usdtf_lock['job_id'],
							(int) $usdtf_lock['age']
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Free', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Interrupted jobs', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td>
					<?php if ( ! $usdtf_stale ) : ?>
						<?php esc_html_e( 'None', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
					<?php else : ?>
						<?php
						$usdtf_stale_ids = array();

						foreach ( $usdtf_stale as $usdtf_stale_job ) {
							$usdtf_stale_ids[] = sprintf(
								'<a href="%s">#%d</a>',
								esc_url( admin_url( 'admin.php?page=usdtf&tab=jobs&job=' . $usdtf_stale_job->id() ) ),
								$usdtf_stale_job->id()
							);
						}

						echo wp_kses_post( implode( ', ', $usdtf_stale_ids ) );
						?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="usdtf-actions">
		<button type="button" class="button" id="usdtf-loopback-test"><?php esc_html_e( 'Test background trigger', 'usd-to-toman-price-sync-for-woocommerce' ); ?></button>
		<span id="usdtf-loopback-result" class="usdtf-loopback-result"></span>
	</p>
</section>

<section class="usdtf-card">
	<h2><?php esc_html_e( 'Diagnostics', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="widefat striped usdtf-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Check', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Status', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Details', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $usdtf_checks as $usdtf_check ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $usdtf_check['label'] ); ?></strong></td>
					<td><span class="usdtf-status usdtf-status--<?php echo esc_attr( $usdtf_check['status'] ); ?>"><?php echo esc_html( $usdtf_check['status'] ); ?></span></td>
					<td>
						<?php echo esc_html( $usdtf_check['description'] ); ?>
						<?php if ( '' !== (string) $usdtf_check['value'] ) : ?>
							<br /><code><?php echo esc_html( substr( (string) $usdtf_check['value'], 0, 120 ) ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description">
		<?php esc_html_e( 'The same checks are reported in Tools → Site Health. Rate history is kept forever; detailed product logs follow the retention setting.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
	</p>
</section>

<section class="usdtf-card">
	<h2><?php esc_html_e( 'Plugin data', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>
	<table class="widefat striped usdtf-table">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Plugin version', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( USDTF_VERSION ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Database schema', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( (string) get_option( \USDTF\Installer::VERSION_OPTION, '—' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'WooCommerce version', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'High-Performance Order Storage', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td>
					<?php
					echo esc_html(
						class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
							? __( 'Enabled', 'usd-to-toman-price-sync-for-woocommerce' )
							: __( 'Disabled', 'usd-to-toman-price-sync-for-woocommerce' )
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Last error', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td>
					<?php
					$usdtf_error = usdtf_plugin()->logger()->last_error();
					echo $usdtf_error ? esc_html( (string) $usdtf_error['message'] . ' (' . $usdtf_error['created_at'] . ')' ) : esc_html__( 'None', 'usd-to-toman-price-sync-for-woocommerce' );
					?>
				</td>
			</tr>
		</tbody>
	</table>
</section>

<div class="usdtf-toast" id="usdtf-toast" hidden></div>

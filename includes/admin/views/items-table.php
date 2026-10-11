<?php
/**
 * Items of a job: the table shared by the dry-run card and the job detail.
 *
 * The admin script re-renders the body of this table with the same columns
 * when a status tab is chosen (see assets/js/admin.js, renderItems()).
 *
 * @package USDTF
 *
 * @var array[] $usdtf_table_items Job item rows.
 * @var int     $usdtf_table_job   Job ID.
 */

use USDTF\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$usdtf_table_decimals = wc_get_price_decimals();
?>
<table class="widefat striped usdtf-table usdtf-items-table" data-job-id="<?php echo esc_attr( (string) (int) $usdtf_table_job ); ?>">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Product', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Status', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Current Woo price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'USD price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'New Woo price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<th><?php esc_html_e( 'Details', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( ! $usdtf_table_items ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No items match this filter.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $usdtf_table_items as $usdtf_item ) : ?>
			<?php $usdtf_product = wc_get_product( (int) $usdtf_item['object_id'] ); ?>
			<tr>
				<td>
					<?php if ( $usdtf_product ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $usdtf_item['object_id'], 'raw' ) ); ?>"><?php echo esc_html( $usdtf_product->get_name() ); ?></a>
						<?php if ( (int) $usdtf_item['parent_id'] > 0 ) : ?>
							<span class="usdtf-badge"><?php esc_html_e( 'variation', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
						<?php endif; ?>
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
				<td><span class="usdtf-status usdtf-status--<?php echo esc_attr( (string) $usdtf_item['status'] ); ?>"><?php echo esc_html( Admin::item_status_label( (string) $usdtf_item['status'] ) ); ?></span></td>
				<td><?php echo esc_html( Admin::format_price_pair( $usdtf_item['old_regular'], $usdtf_item['old_sale'], $usdtf_table_decimals ) ); ?></td>
				<td><?php echo esc_html( Admin::format_price_pair( $usdtf_item['usd_regular'], $usdtf_item['usd_sale'], 2, '$' ) ); ?></td>
				<td><strong><?php echo esc_html( Admin::format_price_pair( $usdtf_item['new_regular'], $usdtf_item['new_sale'], $usdtf_table_decimals ) ); ?></strong></td>
				<td>
					<?php echo esc_html( (string) $usdtf_item['message'] ); ?>
					<?php if ( (int) $usdtf_item['child_total'] > 0 ) : ?>
						<br /><span class="description">
							<?php
							printf(
								/* translators: 1: processed variations, 2: total variations. */
								esc_html__( 'Variations processed: %1$d / %2$d', 'usd-to-toman-price-sync-for-woocommerce' ),
								(int) $usdtf_item['child_cursor'],
								(int) $usdtf_item['child_total']
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( (int) $usdtf_item['attempts'] > 0 ) : ?>
						<br /><span class="description">
							<?php
							printf(
								/* translators: %d: attempts. */
								esc_html__( 'Attempts: %d', 'usd-to-toman-price-sync-for-woocommerce' ),
								(int) $usdtf_item['attempts']
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
/**
 * Product edit screen panel.
 *
 * @package USDTF
 *
 * @var array                  $snapshot        Product snapshot.
 * @var array[]                $variations      Variation snapshots.
 * @var \USDTF\Settings        $settings        Settings.
 * @var \USDTF\Product_Pricing $pricing         Pricing model.
 * @var string                 $recalculate_url Recalculate URL.
 * @var \WP_Post               $post            Post object.
 */

use USDTF\Admin\Admin;
use USDTF\Admin\Product_Panel;
use USDTF\Calculator;

defined( 'ABSPATH' ) || exit;

$usdtf_error       = get_transient( 'usdtf_panel_error_' . get_current_user_id() );
$usdtf_decimals    = wc_get_price_decimals();
$usdtf_panel_modes = array(
	\USDTF\Product_Pricing::MODE_MANAGED  => __( 'Managed — the WooCommerce price follows the USD price and the exchange rate', 'usd-to-toman-price-sync-for-woocommerce' ),
	\USDTF\Product_Pricing::MODE_EXCLUDED => __( 'Excluded — never changed by this plugin', 'usd-to-toman-price-sync-for-woocommerce' ),
);
$usdtf_mode_labels = Product_Panel::mode_labels();

if ( $usdtf_error ) {
	delete_transient( 'usdtf_panel_error_' . get_current_user_id() );
}
?>
<?php wp_nonce_field( Product_Panel::NONCE, 'usdtf_panel_nonce' ); ?>

<?php if ( $usdtf_error ) : ?>
	<div class="notice notice-error inline"><p><?php echo esc_html( (string) $usdtf_error ); ?></p></div>
<?php endif; ?>

<p class="usdtf-panel-intro">
	<?php esc_html_e( 'The WooCommerce price fields hold the store price. The first price update records a USD price from it; after that, a new exchange rate rewrites the store price from the USD price. Editing the store price by hand re-bases the USD price on the next update.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
</p>

<p class="usdtf-field">
	<label for="usdtf-mode"><strong><?php esc_html_e( 'Pricing mode', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong></label>
	<select name="usdtf_mode" id="usdtf-mode">
		<?php foreach ( $usdtf_panel_modes as $usdtf_mode => $usdtf_mode_label ) : ?>
			<option value="<?php echo esc_attr( $usdtf_mode ); ?>" <?php selected( $snapshot['mode'], $usdtf_mode ); ?>>
				<?php echo esc_html( $usdtf_mode_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</p>

<table class="widefat striped usdtf-table usdtf-panel-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'Woo price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td><?php echo esc_html( Admin::format_price_pair( $snapshot['current_regular'], $snapshot['current_sale'], $usdtf_decimals ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'USD price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					'' === $snapshot['usd_regular']
						? __( 'Not recorded yet', 'usd-to-toman-price-sync-for-woocommerce' )
						: Admin::format_price_pair( $snapshot['usd_regular'], $snapshot['usd_sale'], 2, '$' )
				);
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Rate used', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					$snapshot['rate'] > 0
						? Calculator::format_price( $snapshot['rate'] ) . ' ' . Admin::rate_label()
						: '—'
				);
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Last sync', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					$snapshot['synced_at'] > 0
						? (string) wp_date( 'Y-m-d H:i:s', (int) $snapshot['synced_at'] )
						: __( 'Never', 'usd-to-toman-price-sync-for-woocommerce' )
				);
				?>
			</td>
		</tr>
		<?php if ( $snapshot['conflict'] ) : ?>
			<tr>
				<th><?php esc_html_e( 'Conflict', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td class="usdtf-flag usdtf-flag--conflict"><?php echo esc_html( (string) $snapshot['conflict'] ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( $snapshot['last_error'] ) : ?>
			<tr>
				<th><?php esc_html_e( 'Last error', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<td class="usdtf-flag usdtf-flag--error"><?php echo esc_html( (string) $snapshot['last_error'] ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( $variations ) : ?>
	<h4><?php esc_html_e( 'Variations', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h4>
	<p class="description"><?php esc_html_e( 'Each variation keeps its own USD price. The parent price range is recalculated by WooCommerce after each update.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>

	<table class="widefat striped usdtf-table usdtf-panel-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Variation', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Woo price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'USD price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Rate used', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $variations as $usdtf_variation ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( (string) $usdtf_variation['edit_link'] ); ?>">
							<?php echo esc_html( $usdtf_variation['name'] ? $usdtf_variation['name'] : '#' . $usdtf_variation['id'] ); ?>
						</a>
						<?php if ( $usdtf_variation['conflict'] ) : ?>
							<span class="usdtf-flag usdtf-flag--conflict"><?php esc_html_e( 'conflict', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<select name="usdtf_variation[<?php echo esc_attr( (string) $usdtf_variation['id'] ); ?>][mode]" class="usdtf-variation-mode">
							<?php foreach ( $usdtf_mode_labels as $usdtf_mode => $usdtf_mode_label ) : ?>
								<option value="<?php echo esc_attr( $usdtf_mode ); ?>" <?php selected( $usdtf_variation['mode'], $usdtf_mode ); ?>><?php echo esc_html( $usdtf_mode_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><?php echo esc_html( Admin::format_price_pair( $usdtf_variation['current_regular'], $usdtf_variation['current_sale'], $usdtf_decimals ) ); ?></td>
					<td><?php echo esc_html( '' === $usdtf_variation['usd_regular'] ? '—' : Admin::format_price_pair( $usdtf_variation['usd_regular'], $usdtf_variation['usd_sale'], 2, '$' ) ); ?></td>
					<td><?php echo esc_html( $usdtf_variation['rate'] > 0 ? Calculator::format_price( $usdtf_variation['rate'] ) : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<p class="usdtf-actions">
	<a class="button" href="<?php echo esc_url( $recalculate_url ); ?>">
		<?php esc_html_e( 'Recalculate this product', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
	</a>
	<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=usdtf&tab=jobs' ) ); ?>">
		<?php esc_html_e( 'Open jobs and history', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
	</a>
</p>

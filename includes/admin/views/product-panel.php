<?php
/**
 * Product edit screen panel.
 *
 * @package USDTF
 *
 * @var array               $snapshot        Product snapshot.
 * @var array[]             $variations      Variation snapshots.
 * @var \USDTF\Settings     $settings        Settings.
 * @var \USDTF\Product_Pricing $pricing      Pricing model.
 * @var string              $recalculate_url Recalculate URL.
 * @var \WP_Post            $post            Post object.
 */

use USDTF\Calculator;
use USDTF\Product_Pricing;
use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

$usdtf_persian   = (bool) $settings->get( 'persian_digits' );
$usdtf_suffix    = (string) $settings->get( 'toman_suffix' );
$usdtf_currency  = (string) $settings->get( 'currency_mode' );
$usdtf_error     = get_transient( 'usdtf_panel_error_' . get_current_user_id() );
$usdtf_is_toman  = Settings::MODE_TOMAN === $usdtf_currency;
$usdtf_panel_modes = array(
	Product_Pricing::MODE_MANAGED  => __( 'Toman managed — WooCommerce price is calculated from the Toman price', 'usd-to-toman-price-sync-for-woocommerce' ),
	Product_Pricing::MODE_NATIVE   => __( 'Native USD — never synchronized by this plugin', 'usd-to-toman-price-sync-for-woocommerce' ),
	Product_Pricing::MODE_EXCLUDED => __( 'Excluded — do not synchronize', 'usd-to-toman-price-sync-for-woocommerce' ),
);

if ( $usdtf_error ) {
	delete_transient( 'usdtf_panel_error_' . get_current_user_id() );
}
?>
<?php wp_nonce_field( \USDTF\Admin\Product_Panel::NONCE, 'usdtf_panel_nonce' ); ?>

<?php if ( $usdtf_error ) : ?>
	<div class="notice notice-error inline"><p><?php echo esc_html( (string) $usdtf_error ); ?></p></div>
<?php endif; ?>

<p class="usdtf-panel-intro">
	<?php
	if ( $usdtf_is_toman ) {
		esc_html_e( 'In Toman transaction mode the normal WooCommerce price fields hold the canonical Toman price. Enter the Toman price below (or in the standard price fields) and the plugin keeps both in sync.', 'usd-to-toman-price-sync-for-woocommerce' );
	} else {
		esc_html_e( 'In USD transaction mode the normal WooCommerce price fields hold the derived USD price and are calculated by the plugin. Enter the source price in Toman below.', 'usd-to-toman-price-sync-for-woocommerce' );
	}
	?>
</p>

<div class="usdtf-panel-grid">
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

	<p class="usdtf-field">
		<label for="usdtf-source-regular"><strong><?php esc_html_e( 'Canonical Toman regular price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong></label>
		<input
			type="text"
			id="usdtf-source-regular"
			name="usdtf_source_regular"
			class="regular-text"
			inputmode="decimal"
			value="<?php echo esc_attr( null === $snapshot['source_regular'] ? '' : (string) (int) $snapshot['source_regular'] ); ?>"
			<?php echo Product_Pricing::MODE_MANAGED === $snapshot['mode'] ? '' : 'disabled'; ?>
		/>
		</p>

	<p class="usdtf-field">
		<label for="usdtf-source-sale"><strong><?php esc_html_e( 'Canonical Toman sale price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong></label>
		<input
			type="text"
			id="usdtf-source-sale"
			name="usdtf_source_sale"
			class="regular-text"
			inputmode="decimal"
			value="<?php echo esc_attr( null === $snapshot['source_sale'] ? '' : (string) (int) $snapshot['source_sale'] ); ?>"
			<?php echo Product_Pricing::MODE_MANAGED === $snapshot['mode'] ? '' : 'disabled'; ?>
		/>
		<span class="description"><?php esc_html_e( 'Leave empty if the product has no sale price.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
	</p>
</div>

<table class="widefat striped usdtf-table usdtf-panel-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'Stored Toman price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					null === $snapshot['source_regular']
						? '—'
						: Calculator::format_toman( $snapshot['source_regular'], array( 'persian_digits' => $usdtf_persian, 'with_suffix' => true, 'suffix' => $usdtf_suffix ) )
				);
				?>
				<?php if ( null !== $snapshot['source_sale'] ) : ?>
					&nbsp;·&nbsp;
					<?php
					printf(
						/* translators: %s: sale price. */
						esc_html__( 'Sale: %s', 'usd-to-toman-price-sync-for-woocommerce' ),
						esc_html( Calculator::format_toman( $snapshot['source_sale'], array( 'persian_digits' => $usdtf_persian, 'with_suffix' => true, 'suffix' => $usdtf_suffix ) ) )
					);
					?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Derived USD price', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					null === $snapshot['derived_regular']
						? __( 'Not calculated yet', 'usd-to-toman-price-sync-for-woocommerce' )
						: '$' . Calculator::to_price_string( $snapshot['derived_regular'], (int) $settings->get( 'decimals' ) )
				);
				?>
				<?php if ( null !== $snapshot['derived_sale'] ) : ?>
					&nbsp;·&nbsp;
					<?php
					printf(
						/* translators: %s: derived sale price. */
						esc_html__( 'Sale: $%s', 'usd-to-toman-price-sync-for-woocommerce' ),
						esc_html( Calculator::to_price_string( $snapshot['derived_sale'], (int) $settings->get( 'decimals' ) ) )
					);
					?>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Rate used', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php
				echo esc_html(
					null === $snapshot['rate']
						? '—'
						: Calculator::format_toman( $snapshot['rate'], array( 'persian_digits' => $usdtf_persian, 'with_suffix' => true, 'suffix' => $usdtf_suffix ) )
				);
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Last synchronized', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
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
		<tr>
			<th><?php esc_html_e( 'Source revision', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<?php echo esc_html( number_format_i18n( (int) $snapshot['revision'] ) ); ?>
				<span class="description"><?php esc_html_e( 'Editing the price during a running update marks the product as a conflict instead of overwriting the edit.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></span>
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
	<h4><?php esc_html_e( 'Variation Toman prices', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h4>
	<p class="description"><?php esc_html_e( 'Variations are the source of truth for variable products. The parent price range is recalculated by WooCommerce after each update.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>

	<table class="widefat striped usdtf-table usdtf-panel-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Variation', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Toman regular', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Toman sale', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Derived USD', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
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
						<select name="usdtf_variation_source[<?php echo esc_attr( (string) $usdtf_variation['id'] ); ?>][mode]" class="usdtf-variation-mode">
							<?php foreach ( array( Product_Pricing::MODE_MANAGED, Product_Pricing::MODE_NATIVE, Product_Pricing::MODE_EXCLUDED ) as $usdtf_mode ) : ?>
								<option value="<?php echo esc_attr( $usdtf_mode ); ?>" <?php selected( $usdtf_variation['mode'], $usdtf_mode ); ?>><?php echo esc_html( $usdtf_mode ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<input
							type="text"
							inputmode="decimal"
							name="usdtf_variation_source[<?php echo esc_attr( (string) $usdtf_variation['id'] ); ?>][regular]"
							value="<?php echo esc_attr( null === $usdtf_variation['source_regular'] ? '' : (string) (int) $usdtf_variation['source_regular'] ); ?>"
						/>
					</td>
					<td>
						<input
							type="text"
							inputmode="decimal"
							name="usdtf_variation_source[<?php echo esc_attr( (string) $usdtf_variation['id'] ); ?>][sale]"
							value="<?php echo esc_attr( null === $usdtf_variation['source_sale'] ? '' : (string) (int) $usdtf_variation['source_sale'] ); ?>"
						/>
					</td>
					<td>
						<?php
						echo esc_html(
							null === $usdtf_variation['derived_regular']
								? '—'
								: '$' . Calculator::to_price_string( $usdtf_variation['derived_regular'], (int) $settings->get( 'decimals' ) )
						);
						?>
					</td>
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

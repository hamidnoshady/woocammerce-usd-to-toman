<?php
/**
 * Settings tab.
 *
 * @package USDTF
 *
 * @var \USDTF\Settings $settings Settings.
 */

use USDTF\Calculator;
use USDTF\Capabilities;
use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

$usdtf_options = $settings->all();
?>
<form method="post" action="options.php" class="usdtf-card usdtf-settings">
	<?php settings_fields( \USDTF\Admin\Admin::OPTION_GROUP ); ?>

	<h2><?php esc_html_e( 'Checkout and transaction currency', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<fieldset class="usdtf-fieldset">
		<legend class="screen-reader-text"><?php esc_html_e( 'Transaction currency mode', 'usd-to-toman-price-sync-for-woocommerce' ); ?></legend>

		<label class="usdtf-radio">
			<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[currency_mode]" value="<?php echo esc_attr( Settings::MODE_USD ); ?>" <?php checked( $usdtf_options['currency_mode'], Settings::MODE_USD ); ?> />
			<span>
				<strong><?php esc_html_e( 'Mode A — USD transaction, Toman display', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong><br />
				<?php esc_html_e( 'WooCommerce stores and charges the derived USD price. The storefront shows the canonical Toman price from this plugin.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</span>
		</label>

		<label class="usdtf-radio">
			<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[currency_mode]" value="<?php echo esc_attr( Settings::MODE_TOMAN ); ?>" <?php checked( $usdtf_options['currency_mode'], Settings::MODE_TOMAN ); ?> />
			<span>
				<strong><?php esc_html_e( 'Mode B — Toman transaction, USD reference', 'usd-to-toman-price-sync-for-woocommerce' ); ?></strong><br />
				<?php esc_html_e( 'WooCommerce stores and charges the canonical Toman price. The derived USD price is kept as an internal reference for reporting. This is usually the safer choice for Iranian payment gateways.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
			</span>
		</label>

		<p class="description">
			<?php esc_html_e( 'Switching the mode changes the currency the price fields hold. After a switch, run a full price update: the plugin blocks partial updates until the whole catalog is rewritten.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
		</p>
	</fieldset>

	<h2><?php esc_html_e( 'Conversion', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="usdtf-rounding"><?php esc_html_e( 'Rounding', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Settings::OPTION ); ?>[rounding]" id="usdtf-rounding">
					<option value="<?php echo esc_attr( Calculator::ROUND_UP ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_UP ); ?>><?php esc_html_e( 'Always round up (recommended)', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
					<option value="<?php echo esc_attr( Calculator::ROUND_NEAREST ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_NEAREST ); ?>><?php esc_html_e( 'Round to nearest', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
					<option value="<?php echo esc_attr( Calculator::ROUND_DOWN ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_DOWN ); ?>><?php esc_html_e( 'Round down', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'The formula is USD = Toman ÷ rate, then rounded. Rounding up protects your margin and never loses cents.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-increment"><?php esc_html_e( 'Increment', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Settings::OPTION ); ?>[increment]" id="usdtf-increment">
					<?php foreach ( Settings::allowed_increments() as $usdtf_increment ) : ?>
						<option value="<?php echo esc_attr( (string) $usdtf_increment ); ?>" <?php selected( (float) $usdtf_options['increment'], $usdtf_increment ); ?>>
							<?php
							printf(
								/* translators: %s: increment value. */
								esc_html__( '%s USD', 'usd-to-toman-price-sync-for-woocommerce' ),
								esc_html( number_format_i18n( $usdtf_increment ) )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-decimals"><?php esc_html_e( 'Stored decimals', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" min="0" max="6" step="1" id="usdtf-decimals" name="<?php echo esc_attr( Settings::OPTION ); ?>[decimals]" value="<?php echo esc_attr( (string) (int) $usdtf_options['decimals'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'The safe default is 0: whole USD prices. Keep in mind that the WooCommerce currency still controls its own decimal setting.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Safety', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="usdtf-threshold"><?php esc_html_e( 'Rate change warning', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" min="0" max="90" step="0.5" id="usdtf-threshold" name="<?php echo esc_attr( Settings::OPTION ); ?>[rate_change_threshold]" value="<?php echo esc_attr( (string) (float) $usdtf_options['rate_change_threshold'] ); ?>" class="small-text" /> %
				<p class="description"><?php esc_html_e( 'Rate moves above this percentage need explicit confirmation before they are stored.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-batch"><?php esc_html_e( 'Batch size', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select id="usdtf-batch" name="<?php echo esc_attr( Settings::OPTION ); ?>[batch_size]">
					<?php foreach ( Settings::allowed_batch_sizes() as $usdtf_batch ) : ?>
						<option value="<?php echo esc_attr( (string) $usdtf_batch ); ?>" <?php selected( (int) $usdtf_options['batch_size'], $usdtf_batch ); ?>>
							<?php
							printf(
								/* translators: %d: number of products. */
								esc_html( _n( '%d product per run', '%d products per run', $usdtf_batch, 'usd-to-toman-price-sync-for-woocommerce' ) ),
								(int) $usdtf_batch
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Deliberately capped at 25 products per run. Larger batches are the fastest way to hit PHP time or memory limits on shared hosting.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-time-budget"><?php esc_html_e( 'Seconds per worker run', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" min="5" max="55" step="1" id="usdtf-time-budget" name="<?php echo esc_attr( Settings::OPTION ); ?>[time_budget]" value="<?php echo esc_attr( (string) (int) $usdtf_options['time_budget'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'The worker stops before the PHP execution limit and lets the next queued run continue where it stopped.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-retries"><?php esc_html_e( 'Retries per product', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" min="0" max="10" step="1" id="usdtf-retries" name="<?php echo esc_attr( Settings::OPTION ); ?>[retry_limit]" value="<?php echo esc_attr( (string) (int) $usdtf_options['retry_limit'] ); ?>" class="small-text" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-retention"><?php esc_html_e( 'Job detail retention', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="number" min="0" max="3650" step="1" id="usdtf-retention" name="<?php echo esc_attr( Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) (int) $usdtf_options['retention_days'] ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				<p class="description"><?php esc_html_e( 'Detailed per-product job logs are deleted after this period; the rate history is kept forever. Set 0 to keep everything.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'New products', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[auto_manage_new_products]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[auto_manage_new_products]" value="1" <?php checked( ! empty( $usdtf_options['auto_manage_new_products'] ) ); ?> />
					<?php esc_html_e( 'Automatically manage new products and import their first price as the Toman source', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background fallback', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[loopback_fallback]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[loopback_fallback]" value="1" <?php checked( ! empty( $usdtf_options['loopback_fallback'] ) ); ?> />
					<?php esc_html_e( 'Trigger queued worker runs with a non blocking loopback request', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-capability"><?php esc_html_e( 'Required capability', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select id="usdtf-capability" name="<?php echo esc_attr( Settings::OPTION ); ?>[required_capability]">
					<?php foreach ( Capabilities::allowed() as $usdtf_capability ) : ?>
						<option value="<?php echo esc_attr( $usdtf_capability ); ?>" <?php selected( (string) $usdtf_options['required_capability'], $usdtf_capability ); ?>>
							<?php echo esc_html( $usdtf_capability ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Default: manage_woocommerce. Everything this plugin does — changing the rate, running updates, editing Toman prices — requires this capability plus, for products, the normal WordPress edit permission. Only these two capabilities are offered; weaker setups are possible for developers through the usdtf_required_capability filter.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Dry run first', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_preview]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_preview]" value="1" <?php checked( ! empty( $usdtf_options['require_preview'] ) ); ?> />
					<?php esc_html_e( 'Only start a price update after a completed dry run with the same exchange rate, transaction currency, rounding settings and scope', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Recommended. When enabled, the update button refuses to run until the dry run on the dashboard has previewed exactly the same change, so nothing can rewrite the catalog unseen.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Storefront display', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Toman prices', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[display_toman]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[display_toman]" value="1" <?php checked( ! empty( $usdtf_options['display_toman'] ) ); ?> />
					<?php esc_html_e( 'Show the canonical Toman price on shop, category, single product, related and cross-sell prices', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<br />
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[display_toman_cart]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[display_toman_cart]" value="1" <?php checked( ! empty( $usdtf_options['display_toman_cart'] ) ); ?> />
					<?php esc_html_e( 'Show Toman prices for cart, checkout and mini-cart line items (mode A only, totals stay in the transaction currency)', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<br />
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[persian_digits]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[persian_digits]" value="1" <?php checked( ! empty( $usdtf_options['persian_digits'] ) ); ?> />
					<?php esc_html_e( 'Use Persian digits', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'The displayed value is always the stored Toman source price, never a value reconstructed from the rounded USD price.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-suffix"><?php esc_html_e( 'Currency label', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<input type="text" id="usdtf-suffix" name="<?php echo esc_attr( Settings::OPTION ); ?>[toman_suffix]" value="<?php echo esc_attr( (string) $usdtf_options['toman_suffix'] ); ?>" class="regular-text" />
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Uninstall', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Data removal', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_data_on_uninstall]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $usdtf_options['delete_data_on_uninstall'] ) ); ?> />
					<?php esc_html_e( 'Remove all plugin data on uninstall, including the Toman source prices stored on products', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Left unchecked, uninstalling keeps your Toman prices so a reinstall can pick them up again. Settings and job tables are always removed.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save settings', 'usd-to-toman-price-sync-for-woocommerce' ) ); ?>
</form>

<?php
/**
 * Settings tab.
 *
 * @package USDTF
 *
 * @var \USDTF\Settings $settings Settings.
 */

use USDTF\Admin\Admin;
use USDTF\Calculator;
use USDTF\Capabilities;
use USDTF\Settings;
use USDTF\Store_Currency;

defined( 'ABSPATH' ) || exit;

$usdtf_options = $settings->all();
$usdtf_unit    = Store_Currency::unit_label();
?>
<form method="post" action="options.php" class="usdtf-card usdtf-settings">
	<?php settings_fields( \USDTF\Admin\Admin::OPTION_GROUP ); ?>

	<h2><?php esc_html_e( 'Conversion', 'usd-to-toman-price-sync-for-woocommerce' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Store currency', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<strong class="usdtf-store-currency"><?php echo esc_html( Admin::currency_label() ); ?></strong>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings' ) ); ?>"><?php esc_html_e( 'Change it in WooCommerce → Settings → General', 'usd-to-toman-price-sync-for-woocommerce' ); ?></a>
				<?php if ( ! Store_Currency::is_supported() ) : ?>
					<p class="description usdtf-flag--error"><?php esc_html_e( 'Set the store currency to Iranian Toman or Rial', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Read from WooCommerce. Store prices, the exchange rate and the rounding increment use this unit.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-rounding"><?php esc_html_e( 'Rounding', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Settings::OPTION ); ?>[rounding]" id="usdtf-rounding">
					<option value="<?php echo esc_attr( Calculator::ROUND_UP ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_UP ); ?>><?php esc_html_e( 'Always round up (recommended)', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
					<option value="<?php echo esc_attr( Calculator::ROUND_NEAREST ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_NEAREST ); ?>><?php esc_html_e( 'Round to nearest', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
					<option value="<?php echo esc_attr( Calculator::ROUND_DOWN ); ?>" <?php selected( $usdtf_options['rounding'], Calculator::ROUND_DOWN ); ?>><?php esc_html_e( 'Round down', 'usd-to-toman-price-sync-for-woocommerce' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'A new store price is the USD price × the exchange rate, rounded to the increment below. Rounding up protects your margin.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="usdtf-increment"><?php esc_html_e( 'Increment', 'usd-to-toman-price-sync-for-woocommerce' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( Settings::OPTION ); ?>[increment]" id="usdtf-increment">
					<?php foreach ( Settings::allowed_increments() as $usdtf_increment ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $usdtf_increment ); ?>" <?php selected( (float) $usdtf_options['increment'], $usdtf_increment ); ?>>
							<?php
							printf(
								/* translators: 1: increment value, 2: unit name (Toman or Rial). */
								esc_html__( '%1$s %2$s', 'usd-to-toman-price-sync-for-woocommerce' ),
								esc_html( number_format_i18n( $usdtf_increment ) ),
								esc_html( $usdtf_unit )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Store prices are rounded to a multiple of this amount. The WooCommerce decimals setting controls the decimals of the price fields.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
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
				<p class="description"><?php esc_html_e( 'Default: manage_woocommerce. Everything this plugin does — changing the rate, running updates, setting product pricing modes — requires this capability plus, for products, the normal WordPress edit permission. Only these two capabilities are offered; weaker setups are possible for developers through the usdtf_required_capability filter.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Dry run first', 'usd-to-toman-price-sync-for-woocommerce' ); ?></th>
			<td>
				<label class="usdtf-checkbox">
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_preview]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_preview]" value="1" <?php checked( ! empty( $usdtf_options['require_preview'] ) ); ?> />
					<?php esc_html_e( 'Only start a price update after a completed dry run with the same exchange rate, rounding settings and scope', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Recommended. When enabled, the update button refuses to run until the dry run on the dashboard has previewed exactly the same change, so nothing can rewrite the catalog unseen.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
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
					<?php esc_html_e( 'Remove all plugin data on uninstall, including the USD prices stored on products', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Left unchecked, uninstalling keeps the USD prices so a reinstall can pick them up again. Settings and job tables are always removed. WooCommerce prices are never deleted.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save settings', 'usd-to-toman-price-sync-for-woocommerce' ) ); ?>
</form>

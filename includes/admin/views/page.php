<?php
/**
 * Admin page wrapper with the tab navigation.
 *
 * @package USDTF
 *
 * @var string $page       Page slug.
 * @var string $tab        Active tab.
 * @var array  $usdtf_tabs Tabs.
 * @var array  $state      Dashboard state.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap usdtf-wrap">
	<h1 class="usdtf-title">
		<?php esc_html_e( 'USD / Toman Pricing', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
		<span class="usdtf-version"><?php echo esc_html( 'v' . USDTF_VERSION ); ?></span>
	</h1>

	<p class="usdtf-lead">
		<?php esc_html_e( 'Source prices are entered in Toman. WooCommerce prices are derived from the manual USD/Toman rate, and every update runs as a safe background job that can be paused, resumed and reviewed.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
	</p>

	<?php if ( ! empty( $state['currency_mode_stale'] ) ) : ?>
		<div class="notice notice-warning inline usdtf-inline-notice">
			<p><?php esc_html_e( 'The transaction currency changed after the last full update. Run a full price update so every price field holds the configured currency.', 'usd-to-toman-price-sync-for-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper usdtf-tabs">
		<?php foreach ( $usdtf_tabs as $usdtf_slug => $usdtf_label ) : ?>
			<a
				class="nav-tab <?php echo $usdtf_slug === $tab ? 'nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page . '&tab=' . $usdtf_slug ) ); ?>"
			>
				<?php echo esc_html( $usdtf_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="usdtf-tab-content" id="usdtf-tab-<?php echo esc_attr( $tab ); ?>">
		<?php
		switch ( $tab ) {
			case 'jobs':
				include USDTF_DIR . 'includes/admin/views/jobs.php';
				break;
			case 'settings':
				include USDTF_DIR . 'includes/admin/views/settings.php';
				break;
			case 'health':
				include USDTF_DIR . 'includes/admin/views/health.php';
				break;
			case 'dashboard':
			default:
				include USDTF_DIR . 'includes/admin/views/dashboard.php';
				break;
		}
		?>
	</div>
</div>

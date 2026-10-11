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
		<?php esc_html_e( 'Every managed product keeps a USD price, recorded from its WooCommerce price. When you change the exchange rate, the WooCommerce price is recalculated from the USD price, in a safe background job that can be previewed, paused, resumed and reviewed.', 'usd-to-toman-price-sync-for-woocommerce' ); ?>
	</p>

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

<?php
/**
 * Plugin Name: USD / Toman Pricing for WooCommerce
 * Description: Provides the foundation for safely managing Toman source prices and derived USD prices.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Hamid Noshady
 * License: GPL-2.0-or-later
 * Text Domain: woocammerce-usd-to-toman
 */

defined( 'ABSPATH' ) || exit;

define( 'WUT_VERSION', '0.1.0' );
define( 'WUT_FILE', __FILE__ );
define( 'WUT_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Load the plugin only when WooCommerce is available.
 */
function wut_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wut_missing_woocommerce_notice' );
		return;
	}

	// Feature modules are loaded here as they are added. Keeping the entrypoint
	// small makes it safe for WordPress to activate and deactivate.
}
add_action( 'plugins_loaded', 'wut_bootstrap' );

function wut_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'USD / Toman Pricing requires WooCommerce to be active.', 'woocammerce-usd-to-toman' )
	);
}

register_activation_hook( __FILE__, 'wut_activate' );
function wut_activate() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'USD / Toman Pricing requires PHP 7.4 or newer.', 'woocammerce-usd-to-toman' ) );
	}
}

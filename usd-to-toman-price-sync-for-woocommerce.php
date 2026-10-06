<?php
/**
 * Plugin Name:       USD to Toman Price Sync for WooCommerce
 * Plugin URI:        https://github.com/hamidnoshady/woocammerce-usd-to-toman
 * Description:       Keep canonical Toman prices and derive WooCommerce USD prices from a manual USD/Toman rate, with dry-run preview and safe queued background synchronization.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Hamid Noshady
 * Author URI:        https://github.com/hamidnoshady
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       usd-to-toman-price-sync-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * Requires Plugins:  woocommerce
 *
 * @package USDTF
 */

defined( 'ABSPATH' ) || exit;

define( 'USDTF_VERSION', '1.1.1' );
define( 'USDTF_FILE', __FILE__ );
define( 'USDTF_DIR', plugin_dir_path( __FILE__ ) );
define( 'USDTF_URL', plugin_dir_url( __FILE__ ) );
define( 'USDTF_BASENAME', plugin_basename( __FILE__ ) );
define( 'USDTF_SLUG', 'usd-to-toman-price-sync-for-woocommerce' );

require_once USDTF_DIR . 'includes/class-autoloader.php';

\USDTF\Autoloader::register();

register_activation_hook( __FILE__, array( '\USDTF\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\USDTF\Installer', 'deactivate' ) );

/**
 * Main plugin accessor.
 *
 * @return \USDTF\Plugin
 */
function usdtf_plugin() {
	return \USDTF\Plugin::instance();
}

usdtf_plugin();

<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's options, cron events and custom tables. The canonical
 * Toman prices stored on products are only removed when the store owner
 * explicitly opted in, because they are real price data.
 *
 * @package USDTF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'USDTF_DIR' ) ) {
	define( 'USDTF_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'USDTF_FILE' ) ) {
	define( 'USDTF_FILE', __FILE__ );
}

if ( ! defined( 'USDTF_VERSION' ) ) {
	define( 'USDTF_VERSION', '1.1.0' );
}

require_once USDTF_DIR . 'includes/class-autoloader.php';

\USDTF\Autoloader::register();

$usdtf_settings = get_option( 'usdtf_settings', array() );
$usdtf_purge    = is_array( $usdtf_settings ) && ! empty( $usdtf_settings['delete_data_on_uninstall'] );

\USDTF\Installer::uninstall( (bool) $usdtf_purge );

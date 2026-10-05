<?php
/**
 * Prepares a WordPress installation for the integration suite.
 *
 * Installs WordPress when needed, activates WooCommerce and the plugin under
 * test. Safe to run more than once.
 *
 * Usage: USDTF_WP_PATH=/path/to/wordpress php tests/integration/prepare.php
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.

define( 'WP_INSTALLING', true );

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT']    = '80';

$usdtf_wp_path = getenv( 'USDTF_WP_PATH' );

if ( ! $usdtf_wp_path && ! empty( $argv[1] ) ) {
	$usdtf_wp_path = $argv[1];
}

$usdtf_plugin_dir = dirname( dirname( __DIR__ ) );

if ( ! $usdtf_wp_path ) {
	fwrite( STDERR, "Set USDTF_WP_PATH or pass the WordPress directory as the first argument.\n" );
	exit( 1 );
}

$usdtf_wp_path = rtrim( $usdtf_wp_path, '/' );

if ( ! file_exists( $usdtf_wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress was not found at {$usdtf_wp_path}.\n" );
	exit( 1 );
}

// Make the plugin available to WordPress (symlink when possible).
$usdtf_target = $usdtf_wp_path . '/wp-content/plugins/usd-to-toman-price-sync-for-woocommerce';

if ( ! is_dir( $usdtf_target ) && ! is_link( $usdtf_target ) ) {
	if ( ! @symlink( $usdtf_plugin_dir, $usdtf_target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		echo "Symlink failed, copying the plugin instead.\n";
		mkdir( $usdtf_target, 0777, true );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $usdtf_plugin_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( $usdtf_plugin_dir ) + 1 );

			if ( preg_match( '#^(\.git|tests|\.github|node_modules|bin)/#', $relative ) ) {
				continue;
			}

			$destination = $usdtf_target . '/' . $relative;

			if ( $file->isDir() ) {
				mkdir( $destination, 0777, true );
			} else {
				copy( $file->getPathname(), $destination );
			}
		}
	}
}

$GLOBALS['_wp_die_handler'] = static function ( $message ) {
	fwrite( STDERR, 'WP_DIE: ' . ( is_string( $message ) ? $message : wp_json_encode( $message ) ) . "\n" );
	exit( 1 );
};

require_once $usdtf_wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	$result = wp_install( 'USDTF Integration', 'admin', 'admin@example.com', true, '', 'password123' );

	echo 'Installed WordPress, admin user #' . (int) $result['user_id'] . "\n";
}

$active = (array) get_option( 'active_plugins', array() );

foreach ( array( 'woocommerce/woocommerce.php', 'usd-to-toman-price-sync-for-woocommerce/usd-to-toman-price-sync-for-woocommerce.php' ) as $plugin ) {
	if ( in_array( $plugin, $active, true ) ) {
		echo "Already active: {$plugin}\n";

		continue;
	}

	$result = activate_plugin( $plugin );

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "Could not activate {$plugin}: " . $result->get_error_message() . "\n" );
		exit( 1 );
	}

	echo "Activated {$plugin}\n";
}

echo 'WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ', WordPress ' . get_bloginfo( 'version' ) . "\n";
echo 'Active plugins: ' . implode( ', ', (array) get_option( 'active_plugins', array() ) ) . "\n";

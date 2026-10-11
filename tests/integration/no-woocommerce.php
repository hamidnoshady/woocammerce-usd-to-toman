<?php
/**
 * Boot WordPress with WooCommerce switched off and run the paths that read
 * the store currency, the way an activation on WordPress 6.2 to 6.4 (which do
 * not enforce "Requires Plugins") can run them.
 *
 * Usage: php tests/integration/no-woocommerce.php /path/to/wordpress
 *
 * WooCommerce is taken out of the active plugins of this process only, through
 * a preinitialized filter, so nothing is written to the site to switch it off.
 * Prints one JSON object on success; a fatal error ends the process without it.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

$usdtf_wp_path = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/' ) : '';

if ( '' === $usdtf_wp_path || ! file_exists( $usdtf_wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php no-woocommerce.php /path/to/wordpress\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

/**
 * Drop WooCommerce from the active plugins.
 *
 * @param mixed $plugins Active plugins.
 * @return mixed
 */
function usdtf_it_without_woocommerce( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	return array_values(
		array_filter(
			$plugins,
			static function ( $plugin ) {
				return 0 !== strpos( (string) $plugin, 'woocommerce/' );
			}
		)
	);
}

// WordPress turns this array into hooks before any plugin loads.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Preinitialized hooks are the documented way to filter before plugins load.
$GLOBALS['wp_filter'] = array(
	'option_active_plugins' => array(
		10 => array(
			array(
				'function'      => 'usdtf_it_without_woocommerce',
				'accepted_args' => 1,
			),
		),
	),
);

require_once $usdtf_wp_path . '/wp-load.php';

if ( ! class_exists( '\USDTF\Store_Currency' ) ) {
	fwrite( STDERR, "The plugin under test is not active.\n" );
	exit( 2 );
}

$usdtf_result = array(
	'woocommerce' => function_exists( 'get_woocommerce_currency' ) || class_exists( 'WooCommerce' ),
	'option'      => (string) get_option( 'woocommerce_currency', '' ),
	'code'        => \USDTF\Store_Currency::code(),
	'unit'        => \USDTF\Store_Currency::unit(),
	'increment'   => \USDTF\Settings::defaults()['increment'],
);

\USDTF\Installer::activate();

$usdtf_result['activated'] = true;

echo wp_json_encode( $usdtf_result ) . "\n";

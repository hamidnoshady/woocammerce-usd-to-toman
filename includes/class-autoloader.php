<?php
/**
 * PSR-4 style autoloader for the plugin classes.
 *
 * Class names live in the `USDTF` namespace and map to files inside `includes/`,
 * e.g. `USDTF\Admin\Rest_Controller` => `includes/admin/class-rest-controller.php`.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin autoloader.
 */
final class Autoloader {

	/**
	 * Namespace prefix owned by this plugin.
	 *
	 * @var string
	 */
	const PREFIX = 'USDTF\\';

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a class name to a file and include it.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$sub_dir  = '';

		if ( $parts ) {
			$sub_dir = strtolower( implode( '/', $parts ) ) . '/';
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path = USDTF_DIR . 'includes/' . $sub_dir . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

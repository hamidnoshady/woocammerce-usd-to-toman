<?php
/**
 * Router for PHP's built-in web server in the integration suite.
 *
 * Using WordPress' index.php as the router sends every request, including
 * POST /wp-admin/admin-ajax.php and /wp-cron.php, to the front end. The
 * plugin's worker loopback and Action Scheduler's async runner then never
 * reach their handlers, so background jobs started over REST cannot run.
 * Existing files are served directly, like Apache or nginx would; everything
 * else (pretty permalinks, /wp-json/) goes through index.php.
 *
 * This router is used by the canonical server lifecycle script
 * tests/integration/server.sh and is the only file that the built-in server
 * executes directly. It must be robust to missing DOCUMENT_ROOT, to an
 * absolute REQUEST_URI with query string, and to a missing index.php: an
 * empty reply (curl 52) hides the real error, so failures are logged and
 * answered with a 500 instead of closing the connection.
 *
 * Usage:
 *   php -S 127.0.0.1:8888 -t /path/to/wordpress tests/integration/router.php
 *
 * @package USDTF
 */

// DOCUMENT_ROOT is set by php -S -t, but a direct php invocation or a
// different SAPI may leave it empty. Fall back to the current working
// directory and to the directory that contains this router's WordPress.
$usdtf_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
$usdtf_root = rtrim( $usdtf_root, '/' );

if ( '' === $usdtf_root || ! is_dir( $usdtf_root ) ) {
	$usdtf_root = rtrim( (string) getcwd(), '/' );
}

if ( '' === $usdtf_root ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo "Router error: DOCUMENT_ROOT is empty and getcwd() failed.\n";
	error_log( 'usdtf router: DOCUMENT_ROOT empty, cannot resolve docroot' );
	exit( 1 );
}

$usdtf_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
$usdtf_path = (string) parse_url( $usdtf_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet.

if ( '' === $usdtf_path ) {
	$usdtf_path = '/';
}

$usdtf_file = $usdtf_root . $usdtf_path;

if ( '/' !== $usdtf_path && is_file( $usdtf_file ) ) {
	return false;
}

if ( is_dir( $usdtf_file ) && is_file( rtrim( $usdtf_file, '/' ) . '/index.php' ) && '/' !== $usdtf_path ) {
	return false;
}

if ( ! is_readable( $usdtf_root . '/index.php' ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	echo "Router error: index.php not readable at {$usdtf_root}/index.php\n";
	error_log( "usdtf router: index.php not readable at {$usdtf_root}/index.php (REQUEST_URI={$usdtf_uri})" );
	exit( 1 );
}

// Some plugins check getcwd() to locate files; WordPress itself does not,
// but the suite's prepare and the plugin's health checks do.
if ( ! @chdir( $usdtf_root ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback logged below.
	error_log( "usdtf router: chdir to {$usdtf_root} failed" );
}

require $usdtf_root . '/index.php';

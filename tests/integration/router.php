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
 * Usage:
 *   php -S 127.0.0.1:8888 -t /path/to/wordpress tests/integration/router.php
 *
 * @package USDTF
 */

$usdtf_root = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/' );
$usdtf_path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet.
$usdtf_file = $usdtf_root . $usdtf_path;

if ( '/' !== $usdtf_path && is_file( $usdtf_file ) ) {
	return false;
}

if ( is_dir( $usdtf_file ) && is_file( rtrim( $usdtf_file, '/' ) . '/index.php' ) && '/' !== $usdtf_path ) {
	return false;
}

chdir( $usdtf_root );
require $usdtf_root . '/index.php';

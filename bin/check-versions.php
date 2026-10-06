<?php
/**
 * Checks that every shipped version marker agrees on one version.
 *
 * A release once shipped with an uninstall.php fallback that still declared
 * the previous version, and a mismatch like that breaks upgrade bookkeeping.
 * This gate fails when the plugin header, the USDTF_VERSION constant, the
 * uninstall fallback and the readme stable tag do not match.
 *
 * Usage:
 *   php bin/check-versions.php [--path=/path/to/plugin] [--quiet]
 *
 *   --path  Check another copy of the plugin (for example an extracted
 *           release ZIP) instead of the working copy.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

$usdtf_root  = dirname( __DIR__ );
$usdtf_quiet = in_array( '--quiet', array_slice( $argv, 1 ), true );
$usdtf_path  = $usdtf_root;

foreach ( array_slice( $argv, 1 ) as $usdtf_argument ) {
	if ( 0 === strpos( $usdtf_argument, '--path=' ) ) {
		$usdtf_path = (string) substr( $usdtf_argument, strlen( '--path=' ) );
	}
}

/**
 * First regex capture group of a file, or an error message.
 *
 * @param string $file   Absolute file path.
 * @param string $pattern Regex with one capture group.
 * @return string
 */
function usdtf_version_capture( $file, $pattern ) {
	if ( ! is_readable( $file ) ) {
		return 'missing file: ' . $file;
	}

	$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( ! preg_match( $pattern, $source, $matches ) ) {
		return 'pattern not found: ' . $pattern;
	}

	return trim( $matches[1] );
}

$usdtf_main    = $usdtf_path . '/usd-to-toman-price-sync-for-woocommerce.php';
$usdtf_headers = array(
	'plugin header Version' => usdtf_version_capture( $usdtf_main, '/^[ \t\/*#@]*Version:\s*(.+)$/mi' ),
	'USDTF_VERSION define'  => usdtf_version_capture( $usdtf_main, "/define\(\\s*'USDTF_VERSION',\\s*'([^']+)'/" ),
	'uninstall.php fallback' => usdtf_version_capture( $usdtf_path . '/uninstall.php', "/define\(\\s*'USDTF_VERSION',\\s*'([^']+)'/s" ),
	'readme.txt stable tag' => usdtf_version_capture( $usdtf_path . '/readme.txt', '/^Stable tag:\s*(.+)$/mi' ),
);

$usdtf_bad = array();

foreach ( $usdtf_headers as $usdtf_label => $usdtf_version ) {
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $usdtf_version ) ) {
		$usdtf_bad[] = $usdtf_label . ': expected a version number, found "' . $usdtf_version . '"';
	}
}

$usdtf_versions = array_unique( array_values( $usdtf_headers ) );

if ( count( $usdtf_versions ) > 1 ) {
	foreach ( $usdtf_headers as $usdtf_label => $usdtf_version ) {
		$usdtf_bad[] = $usdtf_label . ': ' . $usdtf_version;
	}
}

if ( $usdtf_bad ) {
	foreach ( $usdtf_bad as $usdtf_problem ) {
		fwrite( STDERR, 'error: version mismatch — ' . $usdtf_problem . "\n" );
	}

	exit( 1 );
}

if ( ! $usdtf_quiet ) {
	fwrite( STDOUT, sprintf( "ok: every version marker agrees on %s.\n", reset( $usdtf_versions ) ) );
}

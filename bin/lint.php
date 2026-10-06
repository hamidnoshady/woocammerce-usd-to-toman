<?php
/**
 * Parses every PHP file of the plugin and fails on a syntax error.
 *
 * This is a fast, dependency free gate that runs before the coding standards
 * check: it catches broken files on the oldest supported PHP version, and it
 * does not depend on the `php -l` binary being reachable from the test runner.
 *
 * Usage:
 *   php bin/lint.php [--quiet]
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

$usdtf_root  = dirname( __DIR__ );
$usdtf_quiet = in_array( '--quiet', array_slice( $argv, 1 ), true );
$usdtf_files = array();
$usdtf_bad   = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $usdtf_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $item ) {
	$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $usdtf_root ) + 1 ) );

	if ( ! $item->isFile() || 'php' !== strtolower( $item->getExtension() ) ) {
		continue;
	}

	if ( preg_match( '#^(\.git|node_modules|dist|vendor)/#', $relative ) ) {
		continue;
	}

	$usdtf_files[] = $relative;
}

sort( $usdtf_files );

foreach ( $usdtf_files as $usdtf_relative ) {
	$source = file_get_contents( $usdtf_root . '/' . $usdtf_relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( false === $source ) {
		$usdtf_bad[] = $usdtf_relative . ': the file could not be read';

		continue;
	}

	try {
		// Throwing on a parse error is the only reliable way to detect one.
		token_get_all( $source, TOKEN_PARSE );
	} catch ( ParseError $error ) {
		$usdtf_bad[] = $usdtf_relative . ': ' . $error->getMessage();
	} catch ( Throwable $error ) {
		$usdtf_bad[] = $usdtf_relative . ': ' . $error->getMessage();
	}
}

if ( $usdtf_bad ) {
	foreach ( $usdtf_bad as $usdtf_problem ) {
		fwrite( STDERR, 'error: ' . $usdtf_problem . "\n" );
	}

	exit( 1 );
}

if ( ! $usdtf_quiet ) {
	fwrite( STDOUT, sprintf( "ok: %d PHP files parsed without syntax errors (PHP %s).\n", count( $usdtf_files ), PHP_VERSION ) );
}

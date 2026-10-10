<?php
/**
 * phpcs:ignoreFile is removed; violations are fixed below with narrow
 * phpcs:disable comments only where CLI context justifies it.
 */
/**
 * Canonical archive verification.
 *
 * Consolidates the three slightly different "does the ZIP look clean?" checks
 * that lived in ci.yml, release.yml and verify-release.yml. The ZIP itself
 * is built by bin/build-dist.php; this tool is the single source of truth
 * for whether a built archive is shippable.
 *
 * Checks:
 *   - Exactly one top-level directory named after the plugin slug.
 *   - No development files (tests, bin, .github, wordpress-org, node_modules,
 *     phpcs, composer, .distignore, .git).
 *   - Required runtime files present.
 *   - Languages present.
 *   - Version header matches expected version.
 *   - Admin JS addresses the usdtf/v1 REST namespace.
 *
 * Usage:
 *   php bin/verify-archive.php [--zip=dist/*.zip] [--version=1.2.3] [--slug=usd-to-toman-price-sync-for-woocommerce] [--quiet]
 *
 * Exits non-zero on any failure.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI tool, no WP filesystem in bin scripts.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- CLI tool, bin scripts use exec.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- CLI tool, standalone verifier.

require_once __DIR__ . '/lib-archive-contract.php';

$usdtf_root = dirname( __DIR__ );
$usdtf_args = array(
	'zip'     => '',
	'version' => '',
	'slug'    => 'usd-to-toman-price-sync-for-woocommerce',
	'quiet'   => false,
);

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--quiet' === $arg ) {
		$usdtf_args['quiet'] = true;
		continue;
	}
	if ( 0 === strpos( $arg, '--zip=' ) ) {
		$usdtf_args['zip'] = trim( substr( $arg, 6 ) );
		continue;
	}
	if ( 0 === strpos( $arg, '--version=' ) ) {
		$usdtf_args['version'] = trim( substr( $arg, 10 ) );
		continue;
	}
	if ( 0 === strpos( $arg, '--slug=' ) ) {
		$usdtf_args['slug'] = trim( substr( $arg, 7 ) );
		continue;
	}
	// Positional fallback: first positional is zip.
	if ( '' === $usdtf_args['zip'] && 0 !== strpos( $arg, '--' ) ) {
		$usdtf_args['zip'] = $arg;
		continue;
	}
	fwrite( STDERR, "error: unknown argument: {$arg}\n" );
	exit( 1 );
}

function usdtf_verify_say( $usdtf_msg ) {
	global $usdtf_args;
	if ( empty( $usdtf_args['quiet'] ) ) {
		fwrite( STDOUT, $usdtf_msg . "\n" );
	}
}

function usdtf_verify_fail( $usdtf_msg ) {
	fwrite( STDERR, 'error: ' . $usdtf_msg . "\n" );
}

$usdtf_slug = $usdtf_args['slug'];
$usdtf_zip  = $usdtf_args['zip'];

// Resolve zip pattern or default to newest in dist.
if ( '' === $usdtf_zip ) {
	$zips = glob( $usdtf_root . '/dist/*.zip' );
	if ( ! $zips ) {
		usdtf_verify_fail( 'no archive found at dist/*.zip (pass --zip=...)' );
		exit( 1 );
	}
	// Pick newest.
	usort( $zips, function ( $a, $b ) { return filemtime( $b ) - filemtime( $a ); } );
	$usdtf_zip = $zips[0];
} elseif ( false !== strpos( $usdtf_zip, '*' ) ) {
	$matches = glob( $usdtf_zip );
	if ( ! $matches ) {
		usdtf_verify_fail( "no archive matches pattern: {$usdtf_zip}" );
		exit( 1 );
	}
	if ( count( $matches ) > 1 ) {
		usdtf_verify_fail( "pattern matches multiple archives: " . implode( ', ', $matches ) );
		exit( 1 );
	}
	$usdtf_zip = $matches[0];
}

if ( ! is_readable( $usdtf_zip ) ) {
	usdtf_verify_fail( "archive not readable: {$usdtf_zip}" );
	exit( 1 );
}

// Determine expected version if not given.
$usdtf_version = $usdtf_args['version'];
if ( '' === $usdtf_version ) {
	// Try to read from plugin header.
	$main = $usdtf_root . '/usd-to-toman-price-sync-for-woocommerce.php';
	if ( is_readable( $main ) ) {
		$src = (string) file_get_contents( $main ); // phpcs:ignore
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $src, $m ) ) {
			$usdtf_version = trim( $m[1] );
		}
	}
	// Fallback: extract from zip filename.
	if ( '' === $usdtf_version && preg_match( '/\.(\d+\.\d+\.\d+)\.zip$/', $usdtf_zip, $m ) ) {
		$usdtf_version = $m[1];
	}
}

if ( '' === $usdtf_version ) {
	usdtf_verify_fail( 'expected version not given and could not be detected (pass --version=...)' );
	exit( 1 );
}

usdtf_verify_say( "Verifying {$usdtf_zip} (expected version {$usdtf_version}, slug {$usdtf_slug})..." );

$usdtf_problems = usdtf_archive_verify( $usdtf_zip, $usdtf_slug, $usdtf_version );
if ( $usdtf_problems ) {
	foreach ( $usdtf_problems as $p ) {
		usdtf_verify_fail( $p );
	}
	exit( 1 );
}
usdtf_verify_say( '  contract checks: top-level, forbidden, required, languages, version, stable tag, JS namespace ok' );


usdtf_verify_say( sprintf( 'ok: %s is a clean distribution archive (version %s)', basename( $usdtf_zip ), $usdtf_version ) );

// Summary for GitHub.
if ( getenv( 'GITHUB_STEP_SUMMARY' ) ) {
	$usdtf_summary = getenv( 'GITHUB_STEP_SUMMARY' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.
	$usdtf_msg  = "### Archive verification\n\n";
	$usdtf_msg .= '- **Archive**: `' . basename( $usdtf_zip ) . "`\n";
	$usdtf_msg .= "- **Version**: `{$usdtf_version}`\n";
	$usdtf_msg .= '- **Size**: ' . round( (int) filesize( $usdtf_zip ) / 1024, 1 ) . " KB\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- CLI tool.
	$usdtf_msg .= "- **Top level**: `{$usdtf_slug}/`\n";
	$usdtf_msg .= "- **Checks**: forbidden files, required runtime files, languages, version header, JS namespace (single contract)\n";
	$usdtf_msg .= "- **Result**: \u2705 clean\n";
	file_put_contents( $usdtf_summary, $usdtf_msg, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tool.
}

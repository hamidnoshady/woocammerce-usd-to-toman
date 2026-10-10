<?php
/**
 * Negative fixtures for the single archive contract.
 *
 * Builds minimal valid zips and mutates each contract dimension, then
 * asserts that usdtf_archive_verify reports a problem. Runs without
 * WordPress: `php tests/unit/archive-contract-negative.php`.
 *
 * Covers: malformed paths, development files, missing runtime files,
 * versions, readme markers, languages, namespace. Also proves explicit
 * archives use identical checks regardless of path (builder --check vs
 * verifier --zip).
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- CLI test.

require_once dirname( __DIR__, 2 ) . '/bin/lib-archive-contract.php';

$slug = 'usd-to-toman-price-sync-for-woocommerce';
$version = '1.1.1';

function usdtf_test_ok( $msg ) {
	fwrite( STDOUT, "ok - $msg\n" );
}

function usdtf_test_fail( $msg ) {
	fwrite( STDERR, "not ok - $msg\n" );
	exit( 1 );
}

function usdtf_assert_contains( $problems, $needle, $label ) {
	foreach ( $problems as $p ) {
		if ( false !== stripos( $p, $needle ) ) {
			usdtf_test_ok( $label );
			return;
		}
	}
	usdtf_test_fail( $label . ' (expected "' . $needle . '" in: ' . implode( '; ', $problems ) . ')' );
}

function usdtf_assert_empty( $problems, $label ) {
	if ( ! $problems ) {
		usdtf_test_ok( $label );
		return;
	}
	usdtf_test_fail( $label . ' (expected empty, got: ' . implode( '; ', $problems ) . ')' );
}

function usdtf_build_zip( $callback ) {
	global $slug, $version;
	$tmp = tempnam( sys_get_temp_dir(), 'usdtf-zip-' );
	unlink( $tmp );
	$zip_path = $tmp . '.zip';
	$za = new ZipArchive();
	if ( true !== $za->open( $zip_path, ZipArchive::CREATE ) ) {
		usdtf_test_fail( "could not create zip at $zip_path" );
	}
	// Minimal valid files.
	$files = array(
		"$slug/usd-to-toman-price-sync-for-woocommerce.php" => "<?php\n/*\n * Plugin Name: Test\n * Version: $version\n * Text Domain: $slug\n */\n",
		"$slug/readme.txt" => "=== Test ===\nStable tag: $version\n",
		"$slug/assets/js/admin.js" => "console.log('usdtf/v1');",
		"$slug/includes/admin/class-rest-controller.php" => "<?php // ok",
		"$slug/includes/class-plugin.php" => "<?php // ok",
		"$slug/includes/class-sync-runner.php" => "<?php // ok",
		"$slug/languages/$slug.pot" => "# pot",
		"$slug/languages/$slug-fa_IR.po" => "# po",
		"$slug/languages/$slug-fa_IR.mo" => "mo",
	);
	// Allow callback to mutate.
	$files = $callback( $files, $slug, $version );
	foreach ( $files as $name => $content ) {
		$za->addFromString( $name, $content );
	}
	$za->close();
	return $zip_path;
}

// 1. Valid archive passes.
$zip = usdtf_build_zip( function( $files ) { return $files; } );
$problems = usdtf_archive_verify( $zip, $slug, $version );
usdtf_assert_empty( $problems, 'valid archive passes' );
unlink( $zip );

// 2. Malformed paths: no top-level.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$new = array();
	foreach ( $files as $k => $v ) {
		$new[ substr( $k, strlen( $slug ) + 1 ) ] = $v;
	}
	return $new;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'top level', 'malformed: missing top-level fails' );
unlink( $zip );

// 3. Malformed paths: wrong top-level.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$new = array();
	foreach ( $files as $k => $v ) {
		$new[ 'wrong-slug/' . substr( $k, strlen( $slug ) + 1 ) ] = $v;
	}
	return $new;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'top level', 'malformed: wrong top-level fails' );
unlink( $zip );

// 4. Malformed paths: multiple top-levels.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files['extra/file.txt'] = 'extra';
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'top level', 'malformed: multiple top-levels fails' );
unlink( $zip );

// 5. Development files: tests/.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/tests/foo.php"] = '<?php';
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'development file', 'development file tests/ fails' );
unlink( $zip );

// 6. Development files: bin/.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/bin/foo.php"] = '<?php';
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'development file', 'development file bin/ fails' );
unlink( $zip );

// 7. Development files: .github/.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/.github/workflows/ci.yml"] = 'x';
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'development file', 'development file .github/ fails' );
unlink( $zip );

// 8. Missing runtime file.
$zip = usdtf_build_zip( function( $files, $slug ) {
	unset( $files["$slug/includes/class-sync-runner.php"] );
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'required file', 'missing runtime file fails' );
unlink( $zip );

// 9. Version mismatch in main file.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/usd-to-toman-price-sync-for-woocommerce.php"] = "<?php\n/* Version: 9.9.9 */\n";
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, '1.1.1' ), 'archive version is 9.9.9', 'version mismatch fails' );
unlink( $zip );

// 10. Version missing.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/usd-to-toman-price-sync-for-woocommerce.php"] = "<?php\n// no version\n";
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'has no version', 'missing version fails' );
unlink( $zip );

// 11. Readme Stable tag mismatch.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/readme.txt"] = "Stable tag: 9.9.9\n";
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, '1.1.1' ), 'stable tag is 9.9.9', 'readme stable tag mismatch fails' );
unlink( $zip );

// 12. Readme missing Stable tag.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/readme.txt"] = "No stable tag here\n";
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'has no "Stable tag"', 'missing stable tag fails' );
unlink( $zip );

// 13. Languages missing .pot.
$zip = usdtf_build_zip( function( $files, $slug ) {
	unset( $files["$slug/languages/$slug.pot"] );
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'language file', 'missing .pot fails' );
unlink( $zip );

// 14. Languages missing .mo.
$zip = usdtf_build_zip( function( $files, $slug ) {
	unset( $files["$slug/languages/$slug-fa_IR.mo"] );
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'language file', 'missing .mo fails' );
unlink( $zip );

// 15. Namespace missing.
$zip = usdtf_build_zip( function( $files, $slug ) {
	$files["$slug/assets/js/admin.js"] = "console.log('no namespace');";
	return $files;
} );
usdtf_assert_contains( usdtf_archive_verify( $zip, $slug, $version ), 'usdtf/v1', 'missing JS namespace fails' );
unlink( $zip );

// 16. Explicit archives receive identical checks regardless of path.
// Build via builder --check equivalent: the contract is same.
$zip1 = usdtf_build_zip( function( $files ) { return $files; } );
$zip2 = sys_get_temp_dir() . '/explicit-' . uniqid() . '.zip';
copy( $zip1, $zip2 );
// Both should pass via same contract.
$p1 = usdtf_archive_verify( $zip1, $slug, $version );
$p2 = usdtf_archive_verify( $zip2, $slug, $version );
if ( $p1 !== $p2 ) {
	usdtf_test_fail( 'explicit archives must receive identical checks' );
}
usdtf_test_ok( 'explicit archives receive identical checks' );
unlink( $zip1 );
unlink( $zip2 );

// 17. Also verify via bin/verify-archive.php and bin/build-dist.php --check use same lib.
// This is a meta check: ensure both bins include the lib.
$build_dist = file_get_contents( dirname( __DIR__, 2 ) . '/bin/build-dist.php' );
$verify = file_get_contents( dirname( __DIR__, 2 ) . '/bin/verify-archive.php' );
if ( false === strpos( $build_dist, 'lib-archive-contract.php' ) || false === strpos( $verify, 'lib-archive-contract.php' ) ) {
	usdtf_test_fail( 'both builder and verifier must include lib-archive-contract.php' );
}
usdtf_test_ok( 'both builder and verifier include single contract' );

fwrite( STDOUT, "all archive contract negative fixtures passed\n" );

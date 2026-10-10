<?php
/**
 * Single source of truth for the clean distribution archive.
 *
 * Both bin/build-dist.php (builder + --check) and bin/verify-archive.php
 * (verifier for any explicit --zip) must enforce identical checks, so the
 * contract lives here. Workflows, release and verify-release all call the
 * verifier, so a change here updates every consumer.
 *
 * Checks:
 *   - Exactly one top-level directory named $slug.
 *   - No forbidden development files (tests, bin, .github, wordpress-org,
 *     node_modules, .git, phpcs, composer, .distignore, .gitignore, etc).
 *   - Required runtime files present.
 *   - Language catalogues present (.pot + fa_IR .po/.mo).
 *   - Version header in main file matches $version.
 *   - Stable tag in readme.txt matches $version.
 *   - Admin JS addresses usdtf/v1 REST namespace.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

/**
 * Required runtime files (inside slug/).
 *
 * @return string[]
 */
function usdtf_archive_required_files() {
	return array(
		'includes/admin/class-rest-controller.php',
		'includes/class-plugin.php',
		'includes/class-sync-runner.php',
		'assets/js/admin.js',
		'usd-to-toman-price-sync-for-woocommerce.php',
		'readme.txt',
	);
}

/**
 * Language files required inside slug/languages/.
 *
 * @param string $slug Plugin slug.
 * @return string[]
 */
function usdtf_archive_languages( $slug ) {
	return array(
		"languages/{$slug}-fa_IR.po",
		"languages/{$slug}-fa_IR.mo",
		"languages/{$slug}.pot",
	);
}

/**
 * Forbidden development patterns (matched against path inside slug/).
 *
 * @return string[]
 */
function usdtf_archive_forbidden_patterns() {
	return array(
		'^(tests|bin|\.github|wordpress-org|node_modules)/',
		'^(phpcs\.xml(\.dist)?|composer\.(json|lock)|package(-lock)?\.json|\.distignore|\.gitignore|\.gitattributes|\.editorconfig|phpunit\.xml(\.dist)?)$',
		'\.git/',
	);
}

/**
 * Whether a relative path inside the archive is forbidden.
 *
 * @param string $inside Path inside slug/ (e.g. "tests/foo.php").
 * @return bool
 */
function usdtf_archive_is_forbidden( $inside ) {
	if ( '' === $inside ) {
		return false;
	}
	foreach ( usdtf_archive_forbidden_patterns() as $pat ) {
		if ( preg_match( '#' . $pat . '#', $inside ) ) {
			return true;
		}
	}
	if ( preg_match( '#/\.git#', $inside ) ) {
		return true;
	}
	if ( in_array( basename( $inside ), array( '.distignore', '.gitignore', '.gitattributes', '.editorconfig' ), true ) ) {
		return true;
	}
	return false;
}

/**
 * Verify an archive against the contract.
 *
 * @param string $zip_path Absolute path to zip.
 * @param string $slug     Plugin slug.
 * @param string $version  Expected version (e.g. 1.1.1).
 * @return string[] Problems (empty = clean).
 */
function usdtf_archive_verify( $zip_path, $slug, $version ) {
	$problems = array();

	if ( ! class_exists( 'ZipArchive' ) ) {
		return array( 'the PHP zip extension is required.' );
	}

	$za = new ZipArchive();
	if ( true !== $za->open( $zip_path ) ) {
		return array( 'the archive could not be opened: ' . $zip_path );
	}

	$contents = array();
	$listing  = array();
	$top_level = array();
	$main_content = '';
	$readme_content = '';
	$admin_js = '';

	for ( $i = 0; $i < $za->numFiles; $i++ ) {
		$name = str_replace( '\\', '/', (string) $za->getNameIndex( $i ) );
		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			continue;
		}
		$listing[] = $name;
		$contents[] = $name;
		$parts = explode( '/', $name );
		if ( count( $parts ) < 2 || $parts[0] !== $slug ) {
			$problems[] = 'unexpected top level entry: ' . $name;
			continue;
		}
		$top_level[ $parts[0] ] = true;
		$inside = implode( '/', array_slice( $parts, 1 ) );
		if ( usdtf_archive_is_forbidden( $inside ) ) {
			$problems[] = 'development file inside the archive: ' . $name;
		}
		if ( $slug . '/usd-to-toman-price-sync-for-woocommerce.php' === $name ) {
			$main_content = (string) $za->getFromIndex( $i );
		}
		if ( $slug . '/readme.txt' === $name ) {
			$readme_content = (string) $za->getFromIndex( $i );
		}
		if ( $slug . '/assets/js/admin.js' === $name ) {
			$admin_js = (string) $za->getFromIndex( $i );
		}
	}
	$za->close();

	if ( ! $listing ) {
		$problems[] = 'the archive is empty.';
		return $problems;
	}

	// Deduplicate problems already collected, then add structural checks.
	$unique_top = array_keys( $top_level );
	if ( count( $unique_top ) !== 1 || $unique_top[0] !== $slug ) {
		$problems[] = sprintf( 'the archive must contain exactly one top level directory named "%s".', $slug );
	}

	$required = usdtf_archive_required_files();
	foreach ( $required as $req ) {
		if ( ! in_array( $slug . '/' . $req, $listing, true ) ) {
			$problems[] = 'the plugin main file is missing from the archive.' === $req ? 'the plugin main file is missing from the archive.' : 'required file missing from the archive: ' . $slug . '/' . $req;
			// Normalize message for required files check:
			$problems[count($problems)-1] = 'required file missing from the archive: ' . $slug . '/' . $req;
		}
	}

	$langs = usdtf_archive_languages( $slug );
	foreach ( $langs as $lang ) {
		if ( ! in_array( $slug . '/' . $lang, $listing, true ) ) {
			$problems[] = 'language file missing from the archive: ' . $slug . '/' . $lang;
		}
	}

	if ( '' === $main_content ) {
		$problems[] = 'the plugin main file is missing from the archive.';
	} elseif ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $main_content, $m ) ) {
		$problems[] = 'the plugin main file inside the archive has no version.';
	} elseif ( '' !== $version && trim( $m[1] ) !== $version ) {
		$problems[] = sprintf( 'the archive version is %s, expected %s.', trim( $m[1] ), $version );
	}

	if ( '' === $readme_content ) {
		$problems[] = 'readme.txt is missing from the archive.';
	} elseif ( ! preg_match( '/^Stable tag:\s*(.+)$/mi', $readme_content, $m ) ) {
		$problems[] = 'readme.txt inside the archive has no "Stable tag" header.';
	} elseif ( '' !== $version && trim( $m[1] ) !== $version ) {
		$problems[] = sprintf( 'the readme stable tag is %s, expected %s.', trim( $m[1] ), $version );
	}

	// JS namespace check only if file present; if required file missing, that problem already reported.
	if ( '' !== $admin_js && false === strpos( $admin_js, 'usdtf/v1' ) ) {
		$problems[] = 'shipped admin script does not address the usdtf/v1 REST namespace';
	} elseif ( '' === $admin_js && in_array( $slug . '/assets/js/admin.js', array_map(function($f){return $f;}, $required), true ) ) {
		// Already reported as missing above.
	}

	// Ensure language template specifically.
	if ( ! in_array( $slug . '/languages/usd-to-toman-price-sync-for-woocommerce.pot', $listing, true ) ) {
		// Already reported via languages loop, but keep explicit message for builder parity.
		if ( ! in_array( 'language file missing from the archive: ' . $slug . '/languages/usd-to-toman-price-sync-for-woocommerce.pot', $problems, true ) ) {
			$problems[] = 'the translation template is missing from the archive.';
		}
	}

	return array_values( array_unique( $problems ) );
}

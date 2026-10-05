<?php
/**
 * Builds the clean, WordPress.org ready distribution zip.
 *
 * The archive contains one top level directory named after the plugin slug and
 * only the files a store owner needs: no tests, no CI configuration, no
 * documentation for developers and no dotfiles. The file list comes from
 * .distignore, so what is excluded is reviewable in one place.
 *
 * Usage:
 *   php bin/build-dist.php [--output=dist] [--version=1.2.3] [--check] [--list] [--quiet]
 *
 *   --output=DIR   Directory for the archive (default: dist).
 *   --version=X    Override the version read from the plugin header.
 *   --source-date=DATE
 *                  Timestamp recorded in the archive comment: a unix epoch or
 *                  anything strtotime() understands (default: SOURCE_DATE_EPOCH,
 *                  then the current time). Together with the fixed 1980 entry
 *                  timestamps this removes the build time from the archive, so
 *                  rebuilding the same sources on the same PHP and zlib version
 *                  produces the same bytes. Compressors may still differ between
 *                  environments, which is why releases are verified by checking
 *                  the published checksum.
 *   --check        Do not build; validate the newest existing archive.
 *   --list         Print the files that would be packaged and exit.
 *   --quiet        Only print errors.
 *
 * Exits non-zero on any failure, which makes it usable as a CI gate.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

$usdtf_root = dirname( __DIR__ );

$usdtf_args    = array(
	'output'      => 'dist',
	'version'     => '',
	'source-date' => '',
	'check'       => false,
	'list'        => false,
	'quiet'       => false,
);
$usdtf_unknown = array();

foreach ( array_slice( $argv, 1 ) as $usdtf_arg ) {
	if ( '--check' === $usdtf_arg ) {
		$usdtf_args['check'] = true;

		continue;
	}

	if ( '--list' === $usdtf_arg ) {
		$usdtf_args['list'] = true;

		continue;
	}

	if ( '--quiet' === $usdtf_arg ) {
		$usdtf_args['quiet'] = true;

		continue;
	}

	if ( 0 === strpos( $usdtf_arg, '--output=' ) ) {
		$usdtf_args['output'] = trim( substr( $usdtf_arg, 9 ) );

		continue;
	}

	if ( 0 === strpos( $usdtf_arg, '--version=' ) ) {
		$usdtf_args['version'] = trim( substr( $usdtf_arg, 10 ) );

		continue;
	}

	if ( 0 === strpos( $usdtf_arg, '--source-date=' ) ) {
		$usdtf_args['source-date'] = trim( substr( $usdtf_arg, 14 ) );

		continue;
	}

	$usdtf_unknown[] = $usdtf_arg;
}

/**
 * Print a line unless the tool runs in quiet mode.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_say( $message ) {
	global $usdtf_args;

	if ( empty( $usdtf_args['quiet'] ) ) {
		fwrite( STDOUT, $message . "\n" );
	}
}

/**
 * Fail the build.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_fail( $message ) {
	fwrite( STDERR, 'error: ' . $message . "\n" );
	exit( 1 );
}

if ( $usdtf_unknown ) {
	usdtf_fail( 'unknown argument(s): ' . implode( ', ', $usdtf_unknown ) );
}

/**
 * Format a byte count without WordPress.
 *
 * @param int $bytes     Bytes.
 * @param int $precision Decimals.
 * @return string
 */
function usdtf_size_format( $bytes, $precision = 1 ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$bytes = max( (int) $bytes, 0 );
	$power = $bytes > 0 ? (int) floor( log( $bytes, 1024 ) ) : 0;
	$power = min( $power, count( $units ) - 1 );

	return round( $bytes / pow( 1024, $power ), $precision ) . ' ' . $units[ $power ];
}

/**
 * Build the timestamp that is written into the archive comment.
 *
 * @param string $source Explicit --source-date value, empty to fall back.
 * @return string
 */
function usdtf_build_stamp( $source ) {
	$source = trim( (string) $source );

	if ( '' === $source ) {
		$environment = getenv( 'SOURCE_DATE_EPOCH' );

		if ( false !== $environment && '' !== trim( $environment ) ) {
			$source = trim( $environment );
		}
	}

	if ( '' === $source ) {
		return gmdate( 'Y-m-d H:i:s' ) . ' UTC';
	}

	$timestamp = ctype_digit( $source ) ? (int) $source : strtotime( $source );

	if ( false === $timestamp ) {
		usdtf_fail( '--source-date must be a unix timestamp or a date strtotime() understands.' );
	}

	return gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
}

/**
 * Read a value from the plugin main file header.
 *
 * @param string $root Root directory.
 * @param string $key  Header key (for example "Version").
 * @return string
 */
function usdtf_header( $root, $key ) {
	$main = $root . '/usd-to-toman-price-sync-for-woocommerce.php';

	if ( ! is_readable( $main ) ) {
		usdtf_fail( 'the plugin main file is missing: ' . $main );
	}

	$source = (string) file_get_contents( $main ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $source, $matches ) ) {
		usdtf_fail( sprintf( 'the "%s" header is missing from the plugin main file.', $key ) );
	}

	return trim( $matches[1] );
}

/**
 * Read the "Stable tag" of readme.txt.
 *
 * @param string $root Root directory.
 * @return string
 */
function usdtf_stable_tag( $root ) {
	$readme = $root . '/readme.txt';

	if ( ! is_readable( $readme ) ) {
		usdtf_fail( 'readme.txt is missing.' );
	}

	$source = (string) file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( ! preg_match( '/^Stable tag:\s*(.+)$/mi', $source, $matches ) ) {
		usdtf_fail( 'readme.txt has no "Stable tag" header.' );
	}

	return trim( $matches[1] );
}

/**
 * Parse .distignore into include/exclude rules.
 *
 * @param string $root Root directory.
 * @return array{exclude: string[], include: string[]}
 */
function usdtf_ignore_rules( $root ) {
	$file = $root . '/.distignore';

	if ( ! is_readable( $file ) ) {
		usdtf_fail( '.distignore is missing.' );
	}

	$rules = array(
		'exclude' => array(),
		'include' => array(),
	);

	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- CLI tool.

	foreach ( (array) $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		$bucket = ( '!' === $line[0] ) ? 'include' : 'exclude';

		$rules[ $bucket ][] = trim( ltrim( $line, '!' ), '/' );
	}

	return $rules;
}

/**
 * Whether a relative path matches a pattern (supports *, ? and **).
 *
 * @param string $relative Relative path with forward slashes.
 * @param string $pattern  Pattern.
 * @return bool
 */
function usdtf_matches( $relative, $pattern ) {
	if ( '' === $pattern ) {
		return false;
	}

	$regex = preg_quote( $pattern, '#' );
	$regex = str_replace( array( '\*\*', '\*', '\?' ), array( '.*', '[^/]*', '[^/]' ), $regex );

	// A bare directory name (or directory pattern) also matches everything inside it.
	if ( false === strpos( $pattern, '*' ) && false === strpos( $pattern, '.' ) ) {
		return (bool) preg_match( '#^' . $regex . '(/|$)#i', $relative );
	}

	return (bool) preg_match( '#^' . $regex . '$#i', $relative ) || (bool) preg_match( '#^' . $regex . '/#i', $relative );
}

/**
 * Whether a relative path is part of the distribution.
 *
 * @param string $relative Relative path.
 * @param array  $rules    Ignore rules.
 * @return bool
 */
function usdtf_is_included( $relative, array $rules ) {
	foreach ( $rules['include'] as $pattern ) {
		if ( usdtf_matches( $relative, $pattern ) ) {
			return true;
		}
	}

	foreach ( $rules['exclude'] as $pattern ) {
		if ( usdtf_matches( $relative, $pattern ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Collect the files of the distribution.
 *
 * @param string $root  Root directory.
 * @param array  $rules Ignore rules.
 * @return string[] Relative paths, sorted.
 */
function usdtf_collect( $root, array $rules ) {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );

		if ( '' === $relative || 0 === strpos( $relative, '.' ) || false !== strpos( $relative, '/.' ) ) {
			continue;
		}

		if ( ! usdtf_is_included( $relative, $rules ) ) {
			continue;
		}

		if ( $item->isDir() ) {
			continue;
		}

		$files[] = $relative;
	}

	sort( $files );

	return $files;
}

/**
 * Development paths that must never appear inside the archive.
 *
 * @return string[]
 */
function usdtf_forbidden_paths() {
	return array(
		'tests/',
		'bin/',
		'.github/',
		'.git/',
		'phpcs.xml.dist',
		'composer.json',
		'composer.lock',
		'package.json',
		'node_modules/',
		'phpunit.xml.dist',
	);
}

/**
 * Whether a relative path is forbidden inside the archive.
 *
 * @param string $relative Relative path.
 * @return bool
 */
function usdtf_is_forbidden( $relative ) {
	foreach ( usdtf_forbidden_paths() as $forbidden ) {
		if ( 0 === strpos( $relative, $forbidden ) ) {
			return true;
		}
	}

	if ( '.distignore' === basename( $relative ) || '.gitignore' === basename( $relative ) || '.gitattributes' === basename( $relative ) ) {
		return true;
	}

	// Translation catalogues (.pot, .po, .mo) ship with the plugin, so they are
	// deliberately absent from this list; only the types that never ship are here.
	if ( preg_match( '#\.(md|zip|log|sh|neon|dist)$#i', $relative ) ) {
		return true;
	}

	return false;
}

/**
 * Check an archive: entry list, plugin header and readme.
 *
 * @param string $zip_path Archive path.
 * @param string $slug     Plugin slug.
 * @param string $version  Expected version.
 * @return string[] Problems found (empty when the archive is good).
 */
function usdtf_verify_zip( $zip_path, $slug, $version ) {
	$problems = array();

	if ( ! class_exists( 'ZipArchive' ) ) {
		usdtf_fail( 'the PHP zip extension is required.' );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path ) ) {
		usdtf_fail( 'the archive could not be opened: ' . $zip_path );
	}

	$main      = $slug . '/usd-to-toman-price-sync-for-woocommerce.php';
	$readme    = $slug . '/readme.txt';
	$contents  = array();
	$zip_main  = '';
	$zip_read  = '';
	$top_level = array();

	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $index ) );

		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			continue;
		}

		$contents[] = $name;

		$relative = $name;
		$parts    = explode( '/', $name );

		if ( count( $parts ) < 2 || $parts[0] !== $slug ) {
			$problems[] = 'unexpected top level entry: ' . $name;

			continue;
		}

		$top_level[ $parts[0] ] = true;

		$inside = implode( '/', array_slice( $parts, 1 ) );

		if ( usdtf_is_forbidden( $inside ) ) {
			$problems[] = 'development file inside the archive: ' . $name;
		}

		if ( $main === $name ) {
			$zip_main = (string) $zip->getFromIndex( $index );
		}

		if ( $readme === $name ) {
			$zip_read = (string) $zip->getFromIndex( $index );
		}
	}

	$zip->close();

	if ( ! $contents ) {
		$problems[] = 'the archive is empty.';
	}

	if ( array_keys( $top_level ) !== array( $slug ) ) {
		$problems[] = sprintf( 'the archive must contain exactly one top level directory named "%s".', $slug );
	}

	if ( '' === $zip_main ) {
		$problems[] = 'the plugin main file is missing from the archive.';
	} elseif ( ! preg_match( '/^\s*\*?\s*Plugin Name:\s*(.+)$/mi', $zip_main ) ) {
		$problems[] = 'the plugin main file inside the archive has no plugin header.';
	} elseif ( ! preg_match( '/^\s*\*?\s*Version:\s*(.+)$/mi', $zip_main, $matches ) ) {
		$problems[] = 'the plugin main file inside the archive has no version.';
	} elseif ( '' !== $version && trim( $matches[1] ) !== $version ) {
		$problems[] = sprintf( 'the archive version is %s, expected %s.', trim( $matches[1] ), $version );
	}

	if ( '' === $zip_read ) {
		$problems[] = 'readme.txt is missing from the archive.';
	} elseif ( ! preg_match( '/^\s*Stable tag:\s*(.+)$/mi', $zip_read, $matches ) ) {
		$problems[] = 'readme.txt inside the archive has no "Stable tag" header.';
	} elseif ( '' !== $version && trim( $matches[1] ) !== $version ) {
		$problems[] = sprintf( 'the readme stable tag is %s, expected %s.', trim( $matches[1] ), $version );
	}

	if ( ! in_array( $slug . '/languages/usd-to-toman-price-sync-for-woocommerce.pot', $contents, true ) ) {
		$problems[] = 'the translation template is missing from the archive.';
	}

	return $problems;
}

// ---------------------------------------------------------------------------

$usdtf_slug = 'usd-to-toman-price-sync-for-woocommerce';

// The slug above is duplicated in many places; make sure it is the real one.
$usdtf_text_domain = usdtf_header( $usdtf_root, 'Text Domain' );

if ( $usdtf_text_domain !== $usdtf_slug ) {
	usdtf_fail( sprintf( 'the plugin text domain is "%s" but the builder expects "%s".', $usdtf_text_domain, $usdtf_slug ) );
}

$usdtf_version = '' !== $usdtf_args['version'] ? $usdtf_args['version'] : usdtf_header( $usdtf_root, 'Version' );
$usdtf_tag     = usdtf_stable_tag( $usdtf_root );

if ( $usdtf_version !== $usdtf_tag ) {
	usdtf_fail( sprintf( 'readme.txt stable tag (%s) does not match the plugin version (%s).', $usdtf_tag, $usdtf_version ) );
}

$usdtf_output = $usdtf_root . '/' . trim( $usdtf_args['output'], '/' );
$usdtf_files  = usdtf_collect( $usdtf_root, usdtf_ignore_rules( $usdtf_root ) );

usdtf_say( sprintf( 'version %s — %d files to package', $usdtf_version, count( $usdtf_files ) ) );

if ( $usdtf_args['list'] ) {
	foreach ( $usdtf_files as $usdtf_relative ) {
		fwrite( STDOUT, $usdtf_relative . "\n" );
	}

	exit( 0 );
}

$usdtf_zip_path = $usdtf_output . '/' . $usdtf_slug . '.' . $usdtf_version . '.zip';

if ( $usdtf_args['check'] ) {
	if ( ! is_readable( $usdtf_zip_path ) ) {
		usdtf_fail( 'no archive to check at ' . $usdtf_zip_path );
	}

	$usdtf_problems = usdtf_verify_zip( $usdtf_zip_path, $usdtf_slug, $usdtf_version );

	if ( $usdtf_problems ) {
		foreach ( $usdtf_problems as $usdtf_problem ) {
			fwrite( STDERR, 'error: ' . $usdtf_problem . "\n" );
		}

		exit( 1 );
	}

	usdtf_say( 'ok: ' . $usdtf_zip_path . ' is a clean distribution archive.' );

	exit( 0 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	usdtf_fail( 'the PHP zip extension is required (install php-zip).' );
}

if ( ! is_dir( $usdtf_output ) && ! mkdir( $usdtf_output, 0777, true ) && ! is_dir( $usdtf_output ) ) {
	usdtf_fail( 'could not create the output directory: ' . $usdtf_output );
}

if ( file_exists( $usdtf_zip_path ) && ! unlink( $usdtf_zip_path ) ) {
	usdtf_fail( 'could not replace the existing archive: ' . $usdtf_zip_path );
}

$usdtf_zip = new ZipArchive();

if ( true !== $usdtf_zip->open( $usdtf_zip_path, ZipArchive::CREATE ) ) {
	usdtf_fail( 'could not create the archive: ' . $usdtf_zip_path );
}

$usdtf_stamp = usdtf_build_stamp( $usdtf_args['source-date'] );

foreach ( $usdtf_files as $usdtf_relative ) {
	$usdtf_absolute = $usdtf_root . '/' . $usdtf_relative;
	$usdtf_entry    = $usdtf_slug . '/' . $usdtf_relative;

	if ( ! $usdtf_zip->addFile( $usdtf_absolute, $usdtf_entry ) ) {
		usdtf_fail( 'could not add ' . $usdtf_relative . ' to the archive.' );
	}

	// Reproducible metadata: a fixed timestamp for every entry, so the archive
	// only depends on the file contents and --source-date.
	if ( method_exists( $usdtf_zip, 'setMtimeName' ) ) {
		$usdtf_zip->setMtimeName( $usdtf_entry, 315532800 ); // 1980-01-01, the zip epoch.
	}
}

$usdtf_comment = sprintf(
	"%s %s\nBuilt from the tagged source on %s.\nDevelopment files (tests, CI configuration, build tools) are not included.\n",
	usdtf_header( $usdtf_root, 'Plugin Name' ),
	$usdtf_version,
	$usdtf_stamp
);

$usdtf_zip->setArchiveComment( $usdtf_comment );
$usdtf_zip->close();

$usdtf_hash = hash_file( 'sha256', $usdtf_zip_path );

if ( false === $usdtf_hash ) {
	usdtf_fail( 'could not hash the archive.' );
}

$usdtf_checksum_path = $usdtf_zip_path . '.sha256';

if ( false === file_put_contents( $usdtf_checksum_path, $usdtf_hash . '  ' . basename( $usdtf_zip_path ) . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tool.
	usdtf_fail( 'could not write the checksum file.' );
}

$usdtf_problems = usdtf_verify_zip( $usdtf_zip_path, $usdtf_slug, $usdtf_version );

if ( $usdtf_problems ) {
	foreach ( $usdtf_problems as $usdtf_problem ) {
		fwrite( STDERR, 'error: ' . $usdtf_problem . "\n" );
	}

	unlink( $usdtf_zip_path );
	unlink( $usdtf_checksum_path );

	exit( 1 );
}

$usdtf_size = filesize( $usdtf_zip_path );

usdtf_say(
	sprintf(
		'ok: %s (%s, %d files, sha256 %s)',
		$usdtf_zip_path,
		usdtf_size_format( (int) $usdtf_size, 1 ),
		count( $usdtf_files ),
		substr( (string) $usdtf_hash, 0, 16 ) . '…'
	)
);

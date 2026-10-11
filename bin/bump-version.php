<?php
/**
 * Raises the plugin version in every place that carries it.
 *
 * The automatic release workflow runs this on main after a merge. It moves the
 * plugin header, the USDTF_VERSION define, the uninstall fallback and the
 * readme stable tag to the next version together (so bin/check-versions.php
 * keeps passing) and records the change in CHANGELOG.md and in the readme.txt
 * changelog and upgrade notice.
 *
 * Usage:
 *   php bin/bump-version.php [--current] [--set=X.Y.Z] [--note=TEXT] [--date=YYYY-MM-DD] [--dry-run]
 *
 *   --current   Print the current version and exit.
 *   --set       Use this version instead of the next patch version.
 *   --note      One line describing the change. Falls back to the
 *               USDTF_RELEASE_NOTE environment variable, which is the safe way
 *               to pass text that came from a pull request title.
 *   --date      Release date for the changelog heading (default: today, UTC).
 *   --dry-run   Report the new version without writing any file.
 *
 * Prints the new version on standard output and exits non-zero on any failure.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI tool.

$usdtf_root = dirname( __DIR__ );

$usdtf_options = array(
	'current' => false,
	'set'     => '',
	'note'    => (string) getenv( 'USDTF_RELEASE_NOTE' ),
	'date'    => gmdate( 'Y-m-d' ),
	'dry-run' => false,
);

foreach ( array_slice( $argv, 1 ) as $usdtf_argument ) {
	if ( '--current' === $usdtf_argument ) {
		$usdtf_options['current'] = true;
	} elseif ( '--dry-run' === $usdtf_argument ) {
		$usdtf_options['dry-run'] = true;
	} elseif ( preg_match( '/^--(set|note|date)=(.*)$/s', $usdtf_argument, $usdtf_matches ) ) {
		$usdtf_options[ $usdtf_matches[1] ] = $usdtf_matches[2];
	} else {
		fwrite( STDERR, "error: unknown argument {$usdtf_argument}\n" );
		exit( 2 );
	}
}

/**
 * Fail the run.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_bump_fail( $message ) {
	fwrite( STDERR, 'error: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Read a file that has to exist.
 *
 * @param string $path File path.
 * @return string
 */
function usdtf_bump_read( $path ) {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;

	if ( false === $contents ) {
		usdtf_bump_fail( 'cannot read ' . $path );
	}

	return $contents;
}

/**
 * Replace a pattern exactly once, or fail.
 *
 * @param string   $source   File contents.
 * @param string   $pattern  Regex.
 * @param string   $label    What is being replaced, for the error message.
 * @param callable $callback Replacement callback.
 * @return string
 */
function usdtf_bump_replace_once( $source, $pattern, $label, $callback ) {
	$count  = 0;
	$result = preg_replace_callback( $pattern, $callback, $source, 1, $count );

	if ( 1 !== $count || null === $result ) {
		usdtf_bump_fail( 'could not find ' . $label );
	}

	return $result;
}

/**
 * Reduce free text to a single safe changelog line.
 *
 * @param string $note Raw text, for example a pull request title.
 * @return string
 */
function usdtf_bump_clean_note( $note ) {
	$note = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $note );
	$note = trim( (string) preg_replace( '/\s+/', ' ', $note ) );
	// Strip a conventional commit prefix such as "fix(scope): ".
	$note = (string) preg_replace( '/^(?:fix|feat|chore|docs|ci|test|refactor|perf|style|build)(?:\([^)]*\))?!?:\s*/i', '', $note );
	$note = trim( str_replace( array( '<', '>' ), '', (string) preg_replace( '/<[^>]*>/', '', $note ) ) );

	if ( function_exists( 'mb_substr' ) ) {
		$note = mb_substr( $note, 0, 200 );
	} else {
		$note = substr( $note, 0, 200 );
	}

	$note = trim( $note );

	if ( '' === $note ) {
		return 'Maintenance release.';
	}

	return ucfirst( $note );
}

$usdtf_main      = $usdtf_root . '/usd-to-toman-price-sync-for-woocommerce.php';
$usdtf_uninstall = $usdtf_root . '/uninstall.php';
$usdtf_readme    = $usdtf_root . '/readme.txt';
$usdtf_changelog = $usdtf_root . '/CHANGELOG.md';

$usdtf_main_source = usdtf_bump_read( $usdtf_main );

if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(\d+)\.(\d+)\.(\d+)\s*$/mi', $usdtf_main_source, $usdtf_parts ) ) {
	usdtf_bump_fail( 'the plugin header carries no X.Y.Z version' );
}

$usdtf_current = $usdtf_parts[1] . '.' . $usdtf_parts[2] . '.' . $usdtf_parts[3];

if ( $usdtf_options['current'] ) {
	fwrite( STDOUT, $usdtf_current . "\n" );
	exit( 0 );
}

if ( '' !== $usdtf_options['set'] ) {
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $usdtf_options['set'] ) ) {
		usdtf_bump_fail( '--set expects X.Y.Z, found "' . $usdtf_options['set'] . '"' );
	}

	$usdtf_next = $usdtf_options['set'];
} else {
	$usdtf_next = $usdtf_parts[1] . '.' . $usdtf_parts[2] . '.' . ( (int) $usdtf_parts[3] + 1 );
}

if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $usdtf_options['date'] ) ) {
	usdtf_bump_fail( '--date expects YYYY-MM-DD' );
}

if ( $usdtf_options['dry-run'] ) {
	fwrite( STDOUT, $usdtf_next . "\n" );
	exit( 0 );
}

$usdtf_note = usdtf_bump_clean_note( $usdtf_options['note'] );

// The three source files that declare the version.
$usdtf_main_source = usdtf_bump_replace_once(
	$usdtf_main_source,
	'/^([ \t\/*#@]*Version:\s*)\d+\.\d+\.\d+/mi',
	'the plugin header version',
	static function ( $matches ) use ( $usdtf_next ) {
		return $matches[1] . $usdtf_next;
	}
);

$usdtf_define_pattern = "/(define\(\s*'USDTF_VERSION',\s*')\d+\.\d+\.\d+(')/";
$usdtf_define_replace = static function ( $matches ) use ( $usdtf_next ) {
	return $matches[1] . $usdtf_next . $matches[2];
};

$usdtf_main_source = usdtf_bump_replace_once( $usdtf_main_source, $usdtf_define_pattern, 'the USDTF_VERSION define', $usdtf_define_replace );
$usdtf_uninstall_source = usdtf_bump_replace_once( usdtf_bump_read( $usdtf_uninstall ), $usdtf_define_pattern, 'the uninstall.php USDTF_VERSION fallback', $usdtf_define_replace );

// readme.txt: stable tag, changelog and upgrade notice.
$usdtf_readme_source = usdtf_bump_replace_once(
	usdtf_bump_read( $usdtf_readme ),
	'/^(Stable tag:\s*)\d+\.\d+\.\d+/mi',
	'the readme Stable tag',
	static function ( $matches ) use ( $usdtf_next ) {
		return $matches[1] . $usdtf_next;
	}
);

$usdtf_readme_source = usdtf_bump_replace_once(
	$usdtf_readme_source,
	'/^(== Changelog ==\R\R)/m',
	'the readme Changelog heading',
	static function ( $matches ) use ( $usdtf_next, $usdtf_note ) {
		return $matches[1] . "= {$usdtf_next} =\n* Fixed: {$usdtf_note}\n\n";
	}
);

$usdtf_readme_source = usdtf_bump_replace_once(
	$usdtf_readme_source,
	'/^(== Upgrade Notice ==\R\R)/m',
	'the readme Upgrade Notice heading',
	static function ( $matches ) use ( $usdtf_next, $usdtf_note ) {
		return $matches[1] . "= {$usdtf_next} =\n{$usdtf_note}\n\n";
	}
);

// CHANGELOG.md: a new section above the newest one.
$usdtf_changelog_source = usdtf_bump_replace_once(
	usdtf_bump_read( $usdtf_changelog ),
	'/^## \[/m',
	'the first CHANGELOG.md release heading',
	static function ( $matches ) use ( $usdtf_next, $usdtf_options, $usdtf_note ) {
		return "## [{$usdtf_next}] - {$usdtf_options['date']}\n\n### Fixed\n\n- {$usdtf_note}\n\n" . $matches[0];
	}
);

file_put_contents( $usdtf_main, $usdtf_main_source );
file_put_contents( $usdtf_uninstall, $usdtf_uninstall_source );
file_put_contents( $usdtf_readme, $usdtf_readme_source );
file_put_contents( $usdtf_changelog, $usdtf_changelog_source );

fwrite( STDOUT, $usdtf_next . "\n" );

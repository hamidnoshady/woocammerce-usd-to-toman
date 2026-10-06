<?php
/**
 * Regenerates the translation template (languages/*.pot).
 *
 * WordPress.org extracts translations from the source, so the template only has
 * to exist and be current. This tool scans the plugin with the PHP tokenizer so
 * it never mistakes a string inside a comment or an HTML attribute for a
 * translatable string.
 *
 * Usage:
 *   php bin/make-pot.php [--check] [--quiet]
 *
 *   --check  Do not write; fail when the committed template is out of date.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

$usdtf_root     = dirname( __DIR__ );
$usdtf_check    = in_array( '--check', array_slice( $argv, 1 ), true );
$usdtf_quiet    = in_array( '--quiet', array_slice( $argv, 1 ), true );
$usdtf_domain   = 'usd-to-toman-price-sync-for-woocommerce';
$usdtf_entries  = array();
$usdtf_version  = '0.0.0';
$usdtf_files    = array();
$usdtf_js_files = array();

$usdtf_main = $usdtf_root . '/' . $usdtf_domain . '.php';

if ( is_readable( $usdtf_main ) ) {
	$usdtf_source = (string) file_get_contents( $usdtf_main ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $usdtf_source, $usdtf_matches ) ) {
		$usdtf_version = trim( $usdtf_matches[1] );
	}
}

/**
 * Print a line unless quiet.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_pot_say( $message ) {
	global $usdtf_quiet;

	if ( ! $usdtf_quiet ) {
		fwrite( STDOUT, $message . "\n" );
	}
}

/**
 * Collect translatable strings of one file.
 *
 * @param string $path    Absolute file path.
 * @param string $domain  Text domain.
 * @param array  $entries Entries, keyed by the msgid signature.
 * @return void
 */
function usdtf_pot_scan( $path, $domain, array &$entries ) {
	$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	// Functions with one translatable argument first, then the plural/comment ones.
	$single_functions  = array( '__', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_e' );
	$context_functions = array( '_x', 'esc_html_x', 'esc_attr_x' );
	$plural_functions  = array( '_n', '_nx', 'ngettext' );

	for ( $index = 0; $index < $count; $index++ ) {
		$token = $tokens[ $index ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$name = $token[1];

		if ( ! in_array( $name, array_merge( $single_functions, $context_functions, $plural_functions ), true ) ) {
			continue;
		}

		// Collect the arguments of the call.
		$args    = array();
		$depth   = 0;
		$started = false;
		$current = '';

		for ( $cursor = $index + 1; $cursor < $count; $cursor++ ) {
			$piece = $tokens[ $cursor ];

			if ( is_array( $piece ) ) {
				if ( T_WHITESPACE === $piece[0] && '' === $current ) {
					continue;
				}

				$current .= $piece[1];

				continue;
			}

			if ( '(' === $piece ) {
				if ( $started && 0 === $depth ) {
					$current = '';
				}

				++$depth;
				$started = true;

				continue;
			}

			if ( ')' === $piece ) {
				--$depth;

				if ( $depth <= 0 ) {
					if ( '' !== trim( $current ) ) {
						$args[] = trim( $current );
					}

					break;
				}

				$current .= $piece;

				continue;
			}

			if ( ',' === $piece && 1 === $depth ) {
				$args[]  = trim( $current );
				$current = '';

				continue;
			}

			if ( $started ) {
				$current .= $piece;
			}
		}

		$args = array_map(
			static function ( $argument ) {
				$argument = trim( $argument );

				// Only literal strings are translatable.
				if ( preg_match( "/^'(\\\\.|[^'\\\\])*'$/s", $argument ) || preg_match( '/^"([^"\\\\]|\\\\.)*"$/s', $argument ) ) {
					return usdtf_pot_unescape( substr( $argument, 1, -1 ) );
				}

				return null;
			},
			$args
		);

		$singular = isset( $args[0] ) ? $args[0] : null;

		if ( null === $singular || '' === $singular ) {
			continue;
		}

		$plural  = null;
		$context = null;

		if ( in_array( $name, $plural_functions, true ) ) {
			$plural = isset( $args[1] ) ? $args[1] : null;
		}

		if ( in_array( $name, $context_functions, true ) ) {
			// Context functions take the context as their second argument.
			$context = isset( $args[1] ) ? $args[1] : null;
		}

		$key = ( null === $context ? '' : $context . "\4" ) . $singular . "\0" . ( null === $plural ? '' : $plural );

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'        => $singular,
				'msgid_plural' => null === $plural ? '' : $plural,
				'context'      => null === $context ? '' : $context,
				'references'   => array(),
				'comment'      => '',
			);
		}

		$entries[ $key ]['references'][] = usdtf_pot_relative( $path ) . ':' . $token[2];

		$comment = usdtf_pot_translator_comment( $tokens, $index );

		if ( '' !== $comment && '' === $entries[ $key ]['comment'] ) {
			$entries[ $key ]['comment'] = $comment;
		}
	}
}

/**
 * Collect translatable strings of one JavaScript file.
 *
 * The admin script uses wp.i18n with the same text domain as PHP. JavaScript
 * has no tokenizer here, so the scanner matches the plain call shapes the
 * code style uses: __( 'literal', 'domain' ) and _n( 'single', 'plural', n,
 * 'domain' ), each optionally preceded by a /* translators: * / comment.
 *
 * @param string $path    Absolute file path.
 * @param string $domain  Text domain.
 * @param array  $entries Entries, keyed by the msgid signature.
 * @return void
 */
function usdtf_pot_scan_js( $path, $domain, array &$entries ) {
	$source         = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.
	$domain_pattern = preg_quote( $domain, '/' );

	$patterns = array(
		// Matches a __() call carrying the text domain.
		'/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])' . $domain_pattern . '\3\s*\)/s',
		// Matches an _n() call carrying the text domain.
		'/_n\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,\s*[^,]+?\s*,\s*([\'"])' . $domain_pattern . '\5\s*\)/s',
	);

	foreach ( $patterns as $pattern_index => $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches as $match ) {
			$singular = usdtf_pot_unescape( $match[2][0] );
			$plural   = 1 === $pattern_index ? usdtf_pot_unescape( $match[4][0] ) : null;

			if ( '' === $singular ) {
				continue;
			}

			$key = $singular . "\0" . ( null === $plural ? '' : $plural );

			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array(
					'msgid'        => $singular,
					'msgid_plural' => null === $plural ? '' : $plural,
					'context'      => '',
					'references'   => array(),
					'comment'      => '',
				);
			}

			// Line number of the match.
			$line = 1 + substr_count( substr( $source, 0, (int) $match[0][1] ), "\n" );

			$entries[ $key ]['references'][] = usdtf_pot_relative( $path ) . ':' . $line;

			if ( '' === $entries[ $key ]['comment'] ) {
				// A /* translators: ... */ comment on the lines above the call.
				$before = substr( $source, 0, (int) $match[0][1] );

				if ( preg_match_all( '#/\*\s*translators:([^*]*)\*/\s*$#is', $before, $comments ) ) {
					$text = trim( preg_replace( '/\s+/', ' ', end( $comments[1] ) ) );

					if ( '' !== $text ) {
						$entries[ $key ]['comment'] = $text;
					}
				}
			}
		}
	}
}

/**
 * Extract a "translators:" comment that documents a call.
 *
 * @param array $tokens Token stream.
 * @param int   $index  Index of the function name token.
 * @return string
 */
function usdtf_pot_translator_comment( array $tokens, $index ) {
	for ( $cursor = $index - 1; $cursor >= 0; $cursor-- ) {
		$token = $tokens[ $cursor ];

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( ! is_array( $token ) || ( T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0] ) ) {
			return '';
		}

		$text = trim( preg_replace( '#^/\*+|\*+/$|^\s*\*\s?#m', '', $token[1] ) );

		if ( 0 !== stripos( $text, 'translators:' ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', substr( $text, strlen( 'translators:' ) ) ) );
	}

	return '';
}

/**
 * Turn a PHP escaped string literal into its raw text.
 *
 * @param string $value Escaped value.
 * @return string
 */
function usdtf_pot_unescape( $value ) {
	$map = array(
		'\\n'  => "\n",
		'\\t'  => "\t",
		'\\r'  => "\r",
		'\\\\' => '\\',
		"\\'"  => "'",
		'\\"'  => '"',
		'\\$'  => '$',
	);

	return strtr( $value, $map );
}

/**
 * Path relative to the plugin root.
 *
 * @param string $path Absolute path.
 * @return string
 */
function usdtf_pot_relative( $path ) {
	global $usdtf_root;

	return str_replace( '\\', '/', substr( $path, strlen( $usdtf_root ) + 1 ) );
}

/**
 * Escape a value for a PO file.
 *
 * @param string $value Value.
 * @return string
 */
function usdtf_pot_escape( $value ) {
	$value = str_replace( '\\', '\\\\', $value );
	$value = str_replace( '"', '\\"', $value );
	$value = str_replace( "\n", '\\n', $value );
	$value = str_replace( "\t", '\\t', $value );
	$value = str_replace( "\r", '\\r', $value );

	return $value;
}

// ---------------------------------------------------------------------------

$usdtf_files = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $usdtf_root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $iterator as $item ) {
	$relative = usdtf_pot_relative( $item->getPathname() );

	if ( ! $item->isFile() ) {
		continue;
	}

	if ( preg_match( '#^(\.git|tests|bin|dist)/#', $relative ) ) {
		continue;
	}

	$extension = strtolower( $item->getExtension() );

	if ( 'php' === $extension ) {
		$usdtf_files[] = $item->getPathname();
	} elseif ( 'js' === $extension && 0 === strpos( $relative, 'assets/' ) ) {
		$usdtf_js_files[] = $item->getPathname();
	}
}

sort( $usdtf_files );
sort( $usdtf_js_files );

foreach ( $usdtf_files as $usdtf_file ) {
	usdtf_pot_scan( $usdtf_file, $usdtf_domain, $usdtf_entries );
}

foreach ( $usdtf_js_files as $usdtf_file ) {
	usdtf_pot_scan_js( $usdtf_file, $usdtf_domain, $usdtf_entries );
}

ksort( $usdtf_entries );

$headers = array(
	'Project-Id-Version: USD to Toman Price Sync for WooCommerce ' . $usdtf_version,
	'Report-Msgid-Bugs-To: https://github.com/hamidnoshady/woocammerce-usd-to-toman/issues',
	'Last-Translator: FULL NAME <EMAIL@ADDRESS>',
	'Language-Team: LANGUAGE <LL@li.org>',
	'MIME-Version: 1.0',
	'Content-Type: text/plain; charset=UTF-8',
	'Content-Transfer-Encoding: 8bit',
	'POT-Creation-Date: ' . gmdate( 'Y-m-d H:iO' ),
	'PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE',
	'X-Generator: bin/make-pot.php',
	'X-Domain: ' . $usdtf_domain,
);

$pot  = "# Copyright (C) 2026 Hamid Noshady\n";
$pot .= "# This file is distributed under the GPL-2.0-or-later license.\n";
$pot .= 'msgid ""' . "\n" . 'msgstr ""' . "\n";

foreach ( $headers as $header ) {
	$pot .= '"' . usdtf_pot_escape( $header ) . '\n"' . "\n";
}

$pot .= "\n";

foreach ( $usdtf_entries as $entry ) {
	$references = array_values( array_unique( $entry['references'] ) );

	$pot .= '#: ' . implode( ' ', $references ) . "\n";

	if ( '' !== $entry['comment'] ) {
		$pot .= '#. ' . $entry['comment'] . "\n";
	}

	if ( '' !== $entry['context'] ) {
		$pot .= 'msgctxt "' . usdtf_pot_escape( $entry['context'] ) . "\"\n";
	}

	$pot .= 'msgid "' . usdtf_pot_escape( $entry['msgid'] ) . "\"\n";

	if ( '' !== $entry['msgid_plural'] ) {
		$pot .= 'msgid_plural "' . usdtf_pot_escape( $entry['msgid_plural'] ) . "\"\n";
		$pot .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n";

		continue;
	}

	$pot .= "msgstr \"\"\n\n";
}

$destination = $usdtf_root . '/languages/' . $usdtf_domain . '.pot';

if ( $usdtf_check ) {
	$current = is_readable( $destination ) ? (string) file_get_contents( $destination ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	// Ignore the creation date, which changes on every run by design.
	$strip = static function ( $value ) {
		return (string) preg_replace( '/^"POT-Creation-Date:.*$/m', '', $value );
	};

	if ( $strip( $current ) === $strip( $pot ) ) {
		usdtf_pot_say( 'ok: the translation template is up to date.' );

		exit( 0 );
	}

	fwrite( STDERR, "error: languages/{$usdtf_domain}.pot is out of date. Run: php bin/make-pot.php\n" );
	exit( 1 );
}

if ( ! is_dir( dirname( $destination ) ) && ! mkdir( dirname( $destination ), 0777, true ) && ! is_dir( dirname( $destination ) ) ) {
	fwrite( STDERR, "error: could not create the languages directory.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $destination, $pot ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tool.
	fwrite( STDERR, "error: could not write {$destination}.\n" );
	exit( 1 );
}

usdtf_pot_say( sprintf( 'wrote languages/%s.pot (%d strings)', $usdtf_domain, count( $usdtf_entries ) ) );

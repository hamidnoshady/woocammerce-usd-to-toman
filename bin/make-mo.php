<?php
/**
 * Compiles the shipped .po translations into the .mo files WordPress reads.
 *
 * WordPress loads binary .mo files from the plugin's languages/ directory, so a
 * translation that is meant to ship with the plugin has to exist in both forms:
 * the .po file people edit and the .mo file the runtime reads. This tool keeps
 * them in step and can verify the pair in CI.
 *
 * Usage:
 *   php bin/make-mo.php [--check] [--quiet]
 *
 *   --check   Do not write; fail when a .mo file is missing or out of date.
 *   --quiet   Only print errors.
 *
 * Exits non-zero on any failure, which makes it usable as a CI gate.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool.

$usdtf_root  = dirname( __DIR__ );
$usdtf_check = in_array( '--check', array_slice( $argv, 1 ), true );
$usdtf_quiet = in_array( '--quiet', array_slice( $argv, 1 ), true );

/**
 * Print a line unless quiet mode is on.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_mo_say( $message ) {
	global $usdtf_quiet;

	if ( ! $usdtf_quiet ) {
		fwrite( STDOUT, $message . "\n" );
	}
}

/**
 * Fail the run.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_mo_fail( $message ) {
	fwrite( STDERR, 'error: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Read a .po file into entries.
 *
 * Handles the subset of the format these translations use: comments, the header
 * entry, multi line msgid/msgstr pairs and escaped strings.
 *
 * @param string $path File path.
 * @return array{headers: array<string,string>, entries: array<string,string>}
 */
function usdtf_mo_parse_po( $path ) {
	$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

	if ( false === $source ) {
		usdtf_mo_fail( 'could not read ' . $path );
	}

	$source  = str_replace( "\r\n", "\n", $source );
	$lines   = explode( "\n", $source );
	$headers = array();
	$entries = array();
	$current = null;
	$mode    = '';

	$finish = static function () use ( &$current, &$entries, &$headers ) {
		if ( null === $current ) {
			return;
		}

		if ( '' === $current['msgid'] ) {
			foreach ( explode( "\n", $current['msgstr'] ) as $line ) {
				$position = strpos( $line, ':' );

				if ( false !== $position ) {
					$headers[ substr( $line, 0, $position ) ] = trim( substr( $line, $position + 1 ) );
				}
			}
		} elseif ( '' !== $current['msgstr'] ) {
			$entries[ $current['msgid'] ] = $current['msgstr'];
		}

		$current = null;
	};

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		if ( '#' === substr( $line, 0, 1 ) ) {
			continue;
		}

		if ( 0 === strpos( $line, 'msgid_plural ' ) || 0 === strpos( $line, 'msgctxt ' ) ) {
			// Plural forms and contexts are not used by this plugin's catalogue.
			continue;
		}

		if ( 0 === strpos( $line, 'msgid ' ) ) {
			$finish();

			$current = array(
				'msgid'  => usdtf_mo_unescape( trim( substr( $line, 6 ) ) ),
				'msgstr' => '',
			);
			$mode    = 'id';

			continue;
		}

		if ( 0 === strpos( $line, 'msgstr ' ) ) {
			if ( null === $current ) {
				$current = array(
					'msgid'  => '',
					'msgstr' => '',
				);
			}

			$current['msgstr'] = usdtf_mo_unescape( trim( substr( $line, 7 ) ) );
			$mode              = 'str';

			continue;
		}

		if ( null === $current ) {
			continue;
		}

		if ( '"' === substr( $line, 0, 1 ) ) {
			$chunk = usdtf_mo_unescape( trim( $line ) );

			if ( $mode === 'id' ) {
				$current['msgid'] .= $chunk;
			} else {
				$current['msgstr'] .= $chunk;
			}
		}
	}

	$finish();

	return array(
		'headers' => $headers,
		'entries' => $entries,
	);
}

/**
 * Decode a quoted .po string.
 *
 * @param string $value Quoted value, possibly with surrounding quotes.
 * @return string
 */
function usdtf_mo_unescape( $value ) {
	$value = trim( $value );

	if ( strlen( $value ) > 1 && '"' === substr( $value, 0, 1 ) && '"' === substr( $value, -1 ) ) {
		$value = substr( $value, 1, -1 );
	}

	return stripcslashes( $value );
}

/**
 * Build the binary .mo contents.
 *
 * @param array<string,string> $headers Header fields.
 * @param array<string,string> $entries Translated entries.
 * @return string
 */
function usdtf_mo_build( array $headers, array $entries ) {
	$header_block = '';

	foreach ( $headers as $name => $value ) {
		$header_block .= $name . ': ' . $value . "\n";
	}

	$all = array( '' => $header_block ) + $entries;
	ksort( $all, SORT_STRING );

	$ids      = array_keys( $all );
	$messages = array_values( $all );
	$count    = count( $ids );

	// Header: magic, revision, count, offset of the id table, offset of the
	// string table, size of the (unused) hash table, offset of the hash table.
	// The hash table fields are mandatory: without them every offset in the
	// file is read eight bytes late.
	$output = pack( 'Iiiiiii', 0x950412de, 0, $count, 28, 28 + ( $count * 8 ), 0, 0 );

	// The two lookup tables are followed by the ids and then by the messages.
	// The message offsets only start after the last id, so the size of the id
	// block has to be known before the tables are written.
	$id_block_start     = 28 + ( $count * 16 );
	$string_block_start = $id_block_start;

	foreach ( $ids as $id ) {
		$string_block_start += strlen( $id ) + 1;
	}

	$id_offset     = $id_block_start;
	$string_offset = $string_block_start;

	foreach ( $ids as $id ) {
		$output    .= pack( 'ii', strlen( $id ), $id_offset );
		$id_offset += strlen( $id ) + 1;
	}

	foreach ( $messages as $message ) {
		$output        .= pack( 'ii', strlen( $message ), $string_offset );
		$string_offset += strlen( $message ) + 1;
	}

	foreach ( $ids as $id ) {
		$output .= $id . "\0";
	}

	foreach ( $messages as $message ) {
		$output .= $message . "\0";
	}

	return $output;
}

/**
 * List the printf placeholders of a string, sorted.
 *
 * A translation that drops, renames or invents a placeholder crashes at
 * runtime or prints a literal %s, so a mismatch is refused here.
 *
 * @param string $text Text to inspect.
 * @return array
 */
function usdtf_mo_placeholders( $text ) {
	$matches = array();

	preg_match_all( '/%(?:\d+\$)?[-+]?\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $text, $matches );

	$placeholders = $matches[0];
	sort( $placeholders, SORT_STRING );

	return $placeholders;
}

/**
 * Read a compiled catalogue back and compare it with the source entries.
 *
 * A .mo file that cannot be read back is worthless, and an offset mistake is
 * invisible in a byte comparison, so every build is verified before it is
 * written to disk.
 *
 * @param string $binary  Compiled catalogue.
 * @param array  $entries Map of msgid => msgstr that must be readable.
 * @return string Empty when the catalogue is valid, the problem otherwise.
 */
function usdtf_mo_verify( $binary, array $entries ) {
	if ( strlen( $binary ) < 28 ) {
		return 'the catalogue is too short to contain a header';
	}

	$header = unpack( 'Vmagic/Vrevision/Vcount/Vid_table/Vstring_table', substr( $binary, 0, 20 ) );

	if ( 0x950412de !== $header['magic'] ) {
		return 'the catalogue does not start with the GNU MO magic number';
	}

	$expected = array( '' => '' ) + $entries;

	if ( count( $expected ) !== $header['count'] ) {
		return sprintf( 'the catalogue holds %d entries, expected %d', $header['count'], count( $expected ) );
	}

	$found = 0;

	for ( $i = 0; $i < $header['count']; $i++ ) {
		$id_entry     = unpack( 'Vlength/Voffset', substr( $binary, $header['id_table'] + ( $i * 8 ), 8 ) );
		$string_entry = unpack( 'Vlength/Voffset', substr( $binary, $header['string_table'] + ( $i * 8 ), 8 ) );

		if ( $id_entry['offset'] + $id_entry['length'] > strlen( $binary ) || $string_entry['offset'] + $string_entry['length'] > strlen( $binary ) ) {
			return sprintf( 'entry %d points outside of the catalogue', $i );
		}

		$id      = substr( $binary, $id_entry['offset'], $id_entry['length'] );
		$message = substr( $binary, $string_entry['offset'], $string_entry['length'] );

		if ( ! array_key_exists( $id, $expected ) ) {
			return sprintf( 'entry %d ("%s") is not in the source catalogue', $i, substr( $id, 0, 40 ) );
		}

		if ( '' !== $expected[ $id ] && $message !== $expected[ $id ] ) {
			return sprintf( 'entry %d ("%s") does not round trip', $i, substr( $id, 0, 40 ) );
		}

		++$found;
	}

	if ( $found !== $header['count'] ) {
		return 'the catalogue does not contain every entry';
	}

	return '';
}

$usdtf_po_files = glob( $usdtf_root . '/languages/*.po' );

if ( ! $usdtf_po_files ) {
	usdtf_mo_say( 'No .po files to compile.' );

	exit( 0 );
}

$usdtf_failed = 0;

foreach ( $usdtf_po_files as $usdtf_po ) {
	$usdtf_mo     = preg_replace( '/\.po$/', '.mo', $usdtf_po );
	$usdtf_parsed = usdtf_mo_parse_po( $usdtf_po );

	if ( '' === $usdtf_parsed['entries'] ) {
		usdtf_mo_fail( basename( $usdtf_po ) . ' has no translated entries.' );
	}

	foreach ( $usdtf_parsed['entries'] as $usdtf_id => $usdtf_message ) {
		$usdtf_source = usdtf_mo_placeholders( $usdtf_id );
		$usdtf_target = usdtf_mo_placeholders( $usdtf_message );

		if ( $usdtf_source !== $usdtf_target ) {
			usdtf_mo_fail(
				sprintf(
					basename( $usdtf_po ) . ': "%s" uses [%s] but the translation uses [%s].',
					substr( $usdtf_id, 0, 60 ),
					implode( ', ', $usdtf_source ),
					implode( ', ', $usdtf_target )
				)
			);
		}
	}

	$usdtf_binary = usdtf_mo_build( $usdtf_parsed['headers'], $usdtf_parsed['entries'] );
	$usdtf_broken = usdtf_mo_verify( $usdtf_binary, $usdtf_parsed['entries'] );

	if ( '' !== $usdtf_broken ) {
		usdtf_mo_fail( basename( $usdtf_mo ) . ' would not be readable: ' . $usdtf_broken );
	}

	if ( $usdtf_check ) {
		$usdtf_existing = is_readable( $usdtf_mo ) ? file_get_contents( $usdtf_mo ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI tool.

		if ( $usdtf_existing !== $usdtf_binary ) {
			fwrite( STDERR, 'error: ' . basename( $usdtf_mo ) . " is missing or out of date. Run: php bin/make-mo.php\n" );
			++$usdtf_failed;
		}

		continue;
	}

	if ( false === file_put_contents( $usdtf_mo, $usdtf_binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tool.
		usdtf_mo_fail( 'could not write ' . $usdtf_mo );
	}

	usdtf_mo_say(
		sprintf(
			'wrote languages/%s (%d strings, %d bytes)',
			basename( $usdtf_mo ),
			count( $usdtf_parsed['entries'] ),
			strlen( $usdtf_binary )
		)
	);
}

if ( $usdtf_failed > 0 ) {
	exit( 1 );
}

if ( $usdtf_check ) {
	usdtf_mo_say( 'ok: the compiled translations are up to date.' );
}

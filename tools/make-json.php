<?php
/**
 * Convert a gettext PO file into a WordPress JED JSON translation file.
 *
 * WordPress loads JavaScript translations through wp_set_script_translations(),
 * which only reads JSON files named "<domain>-<locale>-<handle>.json". The PO and
 * MO files used by the PHP side are never consulted for script translations, so
 * this converter produces the JSON payload from the same PO source of truth.
 *
 * Usage: php tools/make-json.php <input.po> <output.json> <locale> <source>
 */

if ( $argc < 5 ) {
	fwrite( STDERR, "Usage: php tools/make-json.php <input.po> <output.json> <locale> <source>\n" );
	exit( 2 );
}

list( , $input, $output, $locale, $source ) = $argv;

$contents = file_get_contents( $input );
if ( false === $contents ) {
	fwrite( STDERR, "Unable to read {$input}\n" );
	exit( 1 );
}

/**
 * Decode the escape sequences allowed inside PO string literals.
 *
 * @param string $value Raw PO string body without the surrounding quotes.
 * @return string
 */
function htbd_json_unescape( $value ) {
	return preg_replace_callback(
		'/\\\\(.)/s',
		static function ( $matches ) {
			switch ( $matches[1] ) {
				case 'n':
					return "\n";
				case 't':
					return "\t";
				case 'r':
					return "\r";
				case '"':
					return '"';
				case '\\':
					return '\\';
				default:
					return $matches[0];
			}
		},
		$value
	);
}

$entries = array();
$current = null;
$fuzzy   = false;

/**
 * Flush the entry currently being parsed into the collected list.
 */
function htbd_json_flush( &$entries, &$current, &$fuzzy ) {
	if ( null === $current ) {
		return;
	}

	$current['fuzzy'] = $fuzzy;

	// The header entry carries no translation and is rebuilt from scratch below.
	if ( '' !== $current['msgid'] ) {
		$entries[] = $current;
	}

	$current = null;
	$fuzzy   = false;
}

foreach ( preg_split( '/\r\n|\n|\r/', $contents ) as $line ) {
	if ( '' === trim( $line ) ) {
		htbd_json_flush( $entries, $current, $fuzzy );
		continue;
	}

	// Obsolete entries and translator comments never reach the JSON payload.
	if ( 0 === strpos( $line, '#~' ) ) {
		continue;
	}

	if ( 0 === strpos( $line, '#,' ) ) {
		$fuzzy = ( false !== strpos( $line, 'fuzzy' ) );
		continue;
	}

	if ( 0 === strpos( $line, '#' ) ) {
		continue;
	}

	if ( 0 === strpos( $line, 'msgctxt ' ) ) {
		htbd_json_flush( $entries, $current, $fuzzy );
		$current = array(
			'msgctxt' => htbd_json_unescape( substr( $line, 9, -1 ) ),
			'msgid'   => '',
			'plural'  => null,
			'msgstr'  => array(),
			'key'     => null,
		);
		continue;
	}

	if ( 0 === strpos( $line, 'msgid_plural ' ) ) {
		$current['plural'] = htbd_json_unescape( substr( $line, 13, -1 ) );
		continue;
	}

	if ( 0 === strpos( $line, 'msgid ' ) ) {
		htbd_json_flush( $entries, $current, $fuzzy );
		$current = array(
			'msgctxt' => null,
			'msgid'   => htbd_json_unescape( substr( $line, 7, -1 ) ),
			'plural'  => null,
			'msgstr'  => array(),
			'key'     => 'msgid',
		);
		continue;
	}

	if ( 0 === strpos( $line, 'msgstr[' ) ) {
		$closing = strpos( $line, ']' );
		$index   = (int) substr( $line, 7, $closing - 7 );
		$value   = htbd_json_unescape( substr( $line, $closing + 3, -1 ) );

		$current['msgstr'][ $index ] = $value;
		$current['key']              = 'msgstr[' . $index . ']';
		continue;
	}

	if ( 0 === strpos( $line, 'msgstr ' ) ) {
		$current['msgstr'][0] = htbd_json_unescape( substr( $line, 8, -1 ) );
		$current['key']       = 'msgstr';
		continue;
	}

	if ( '"' === $line[0] && null !== $current ) {
		$fragment = htbd_json_unescape( substr( $line, 1, -1 ) );

		if ( 'msgid' === $current['key'] ) {
			$current['msgid'] .= $fragment;
		} elseif ( 'msgstr' === $current['key'] ) {
			$current['msgstr'][0] .= $fragment;
		} elseif ( null !== $current['key'] && 0 === strpos( $current['key'], 'msgstr[' ) ) {
			$index                              = (int) substr( $current['key'], 7, -1 );
			$current['msgstr'][ $index ]       .= $fragment;
		}
	}
}

htbd_json_flush( $entries, $current, $fuzzy );

$messages = array(
	'' => array(
		'domain'       => 'messages',
		'lang'         => $locale,
		'plural-forms' => 'nplurals=1; plural=0;',
	),
);

foreach ( $entries as $entry ) {
	if ( $entry['fuzzy'] ) {
		continue;
	}

	$translation = array_values(
		array_filter(
			$entry['msgstr'],
			static function ( $value ) {
				return '' !== $value;
			}
		)
	);

	if ( array() === $translation ) {
		continue;
	}

	$key = $entry['msgid'];
	if ( null !== $entry['msgctxt'] && '' !== $entry['msgctxt'] ) {
		$key = $entry['msgctxt'] . "\u{0004}" . $key;
	}

	$messages[ $key ] = $translation;
}

$payload = array(
	'translation-revision-date' => gmdate( 'Y-m-d H:iO' ),
	'generator'                 => 'tools/make-json.php',
	'source'                    => $source,
	'domain'                    => 'messages',
	'locale_data'               => array(
		'messages' => $messages,
	),
);

$json = wp_json_encode_compat( $payload );
if ( false === file_put_contents( $output, $json ) ) {
	fwrite( STDERR, "Unable to write {$output}\n" );
	exit( 1 );
}

printf( "Generated %s (%d strings).\n", $output, count( $messages ) - 1 );

/**
 * Encode JSON without relying on WordPress being loaded.
 *
 * @param array $payload Translation payload.
 * @return string
 */
function wp_json_encode_compat( array $payload ) {
	$json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json ) {
		fwrite( STDERR, "Unable to encode translation payload: " . json_last_error_msg() . "\n" );
		exit( 1 );
	}

	return $json;
}

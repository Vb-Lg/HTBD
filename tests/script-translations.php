<?php
/**
 * Standalone checks for the PO to JED JSON converter used for script translations.
 *
 * Run with: php tests/script-translations.php
 *
 * The converter is executed as a separate process with a temporary PO file, so
 * this test needs no WordPress installation, no network access, and no API
 * credentials.
 *
 * @package HTBD
 */

$GLOBALS['htbd_test_checks'] = 0;
$GLOBALS['htbd_test_failed'] = false;

/**
 * Record the outcome of a single check.
 *
 * @param bool   $condition Condition that must hold.
 * @param string $message   Description used in the output.
 */
function htbd_test_assert( $condition, $message ) {
	++$GLOBALS['htbd_test_checks'];

	if ( ! $condition ) {
		$GLOBALS['htbd_test_failed'] = true;
		printf( "FAIL: %s\n", $message );
		return;
	}

	printf( "ok: %s\n", $message );
}

$converter  = dirname( __DIR__ ) . '/tools/make-json.php';
$source_po  = dirname( __DIR__ ) . '/languages/hyx-translator-for-baidu-translate-zh_CN.po';
$fixture_po = tempnam( sys_get_temp_dir(), 'htbd-po-' );
$output     = tempnam( sys_get_temp_dir(), 'htbd-json-' );

$fixture = <<<'PO'
msgid ""
msgstr ""
"Language: zh_CN\n"
"Content-Type: text/plain; charset=UTF-8\n"

msgid "Simple"
msgstr "简单"

#. translators: %s: database table name.
#, php-format
msgid "The table %s exists."
msgstr "数据表 %s 已存在。"

msgid ""
"A message split "
"across lines."
msgstr "跨行拼接的消息。"

msgid "Quotes \"inside\" the string"
msgstr "字符串包含引号"

#, fuzzy
msgid "Unreviewed"
msgstr "未复核"

msgid "Untranslated"
msgstr ""

#~ msgid "Obsolete"
#~ msgstr "已废弃"

msgid "%d task is queued."
msgid_plural "%d tasks are queued."
msgstr[0] "队列中有 %d 个任务。"

PO;

file_put_contents( $fixture_po, $fixture );

$command = sprintf(
	'%s %s %s %s %s %s 2>&1',
	escapeshellarg( PHP_BINARY ),
	escapeshellarg( $converter ),
	escapeshellarg( $fixture_po ),
	escapeshellarg( $output ),
	escapeshellarg( 'zh_CN' ),
	escapeshellarg( 'assets/js/language-switcher-block.js' )
);

exec( $command, $output_lines, $status );

htbd_test_assert( 0 === $status, 'the converter exits successfully' );

$payload = json_decode( (string) file_get_contents( $output ), true );

htbd_test_assert( is_array( $payload ), 'the converter writes valid JSON' );
htbd_test_assert( isset( $payload['locale_data']['messages'] ), 'the payload exposes the messages locale data' );

$messages = isset( $payload['locale_data']['messages'] ) ? $payload['locale_data']['messages'] : array();

htbd_test_assert( 'zh_CN' === ( $messages['']['lang'] ?? null ), 'the header carries the requested locale' );
htbd_test_assert( 'nplurals=1; plural=0;' === ( $messages['']['plural-forms'] ?? null ), 'the header carries Chinese plural forms' );

htbd_test_assert( array( '简单' ) === ( $messages['Simple'] ?? null ), 'a single line entry is converted' );
htbd_test_assert( array( '数据表 %s 已存在。' ) === ( $messages['The table %s exists.'] ?? null ), 'a formatted entry keeps its placeholder' );
htbd_test_assert( array( '跨行拼接的消息。' ) === ( $messages['A message split across lines.'] ?? null ), 'multi line msgids are joined without inserting separators' );
htbd_test_assert( array( '字符串包含引号' ) === ( $messages['Quotes "inside" the string'] ?? null ), 'escaped quotes are decoded' );
htbd_test_assert( array( '队列中有 %d 个任务。' ) === ( $messages['%d task is queued.'] ?? null ), 'plural entries are keyed by the singular msgid' );

htbd_test_assert( ! isset( $messages['Unreviewed'] ), 'fuzzy entries are excluded from the payload' );
htbd_test_assert( ! isset( $messages['Untranslated'] ), 'entries without a translation are excluded from the payload' );
htbd_test_assert( ! isset( $messages['Obsolete'] ), 'obsolete entries are excluded from the payload' );

/*
 * The shipped PO file must cover every string the block editor asks for,
 * otherwise those labels silently fall back to English.
 */
$editor_script  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/language-switcher-block.js' );
$shipped_output = tempnam( sys_get_temp_dir(), 'htbd-json-shipped-' );

exec(
	sprintf(
		'%s %s %s %s %s %s 2>&1',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( $converter ),
		escapeshellarg( $source_po ),
		escapeshellarg( $shipped_output ),
		escapeshellarg( 'zh_CN' ),
		escapeshellarg( 'assets/js/language-switcher-block.js' )
	),
	$ignored,
	$shipped_status
);

htbd_test_assert( 0 === $shipped_status, 'the shipped PO file converts successfully' );

$shipped = json_decode( (string) file_get_contents( $shipped_output ), true );

preg_match_all( "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $editor_script, $matches );

$missing = array();
foreach ( $matches[1] as $string ) {
	$string = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $string );

	if ( ! isset( $shipped['locale_data']['messages'][ $string ] ) ) {
		$missing[] = $string;
	}
}

htbd_test_assert( array() === $missing, 'every block editor string has a JED translation: ' . implode( ' | ', $missing ) );

unlink( $fixture_po );
unlink( $output );
unlink( $shipped_output );

if ( $GLOBALS['htbd_test_failed'] ) {
	printf( "\n%d check(s) failed.\n", $GLOBALS['htbd_test_checks'] );
	exit( 1 );
}

printf( "\nAll %d checks passed.\n", $GLOBALS['htbd_test_checks'] );

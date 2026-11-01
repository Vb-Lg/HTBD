<?php
/**
 * Standalone checks for the browser language suggestion.
 *
 * Run with: php tests/language-prompt.php
 *
 * WordPress and the language router are stubbed, so this file needs no
 * installation, database, network access, or API credentials. The browser side
 * of the matching is exercised by the same aliases asserted here.
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HTBD_FILE', dirname( __DIR__ ) . '/hyx-translator-for-baidu-translate.php' );
define( 'HTBD_VERSION', '0.0.0' );

$GLOBALS['htbd_test_settings'] = array();
$GLOBALS['htbd_test_urls']     = array();

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function is_admin() {
	return false;
}

function esc_attr__( $text, $domain = '' ) {
	return $text;
}

function __( $text, $domain = '' ) {
	return $text;
}

class HTBD_Settings {
	public static function get() {
		return $GLOBALS['htbd_test_settings'];
	}

	public static function languages() {
		return array_values( $GLOBALS['htbd_test_settings']['languages'] );
	}

	public static function language_label( $language ) {
		$labels = array(
			'zh'  => '简体中文',
			'en'  => 'English',
			'cht' => '繁體中文',
			'jp'  => '日本語',
		);

		return isset( $labels[ $language ] ) ? $labels[ $language ] : strtoupper( $language );
	}

	public static function hreflang_code( $language ) {
		$map = array(
			'zh'  => 'zh',
			'cht' => 'zh-Hant',
			'en'  => 'en',
			'jp'  => 'ja',
		);

		return isset( $map[ $language ] ) ? $map[ $language ] : $language;
	}
}

class HTBD_Language_Router {
	public function request_url() {
		return 'https://example.com/about/';
	}

	public function current_language() {
		return $GLOBALS['htbd_test_settings']['current'];
	}

	public function url_for_language( $url, $language ) {
		return isset( $GLOBALS['htbd_test_urls'][ $language ] ) ? $GLOBALS['htbd_test_urls'][ $language ] : $url;
	}
}

require dirname( __DIR__ ) . '/includes/class-language-prompt.php';

$failures = 0;

function htbd_test_assert( $condition, $label ) {
	global $failures;

	if ( $condition ) {
		printf( "PASS %s\n", $label );

		return;
	}

	++$failures;
	printf( "FAIL %s\n", $label );
}

function htbd_test_configure( array $settings ) {
	$GLOBALS['htbd_test_settings'] = array_merge(
		array(
			'browser_language_prompt' => true,
			'languages'               => array( 'zh', 'en', 'cht' ),
			'current'                 => 'zh',
		),
		$settings
	);
	$GLOBALS['htbd_test_urls'] = array(
		'en'  => 'https://example.com/en/about/',
		'cht' => 'https://example.com/cht/about/',
		'zh'  => 'https://example.com/about/',
	);
}

function htbd_test_links() {
	return ( new HTBD_Language_Prompt( new HTBD_Language_Router() ) )->links();
}

// Browser tags that select a language.
htbd_test_assert( array( 'en' ) === HTBD_Language_Prompt::browser_aliases( 'en' ), 'an English alias is the hreflang code' );
htbd_test_assert( array( 'ja' ) === HTBD_Language_Prompt::browser_aliases( 'jp' ), 'a Baidu code is aliased to its BCP 47 code' );
htbd_test_assert(
	array( 'zh', 'zh-hans', 'zh-cn', 'zh-sg', 'zh-my' ) === HTBD_Language_Prompt::browser_aliases( 'zh' ),
	'simplified Chinese keeps its region and script variants'
);
htbd_test_assert(
	array( 'zh-hant', 'zh-tw', 'zh-hk', 'zh-mo' ) === HTBD_Language_Prompt::browser_aliases( 'cht' ),
	'traditional Chinese does not reuse the simplified alias'
);

// Candidate links.
htbd_test_configure( array() );
$links = htbd_test_links();

htbd_test_assert( 2 === count( $links ), 'the current language is not offered as a suggestion' );
htbd_test_assert( 'en' === $links[0]['code'], 'links keep the configured language order' );
htbd_test_assert( 'English' === $links[0]['label'], 'links carry the language label' );
htbd_test_assert( 'https://example.com/en/about/' === $links[0]['url'], 'links carry the URL of the same page in that language' );
htbd_test_assert( 'zh-Hant' === $links[1]['hreflang'], 'links carry the hreflang code for the lang attribute' );

// Nothing to suggest.
htbd_test_configure( array( 'browser_language_prompt' => false ) );
htbd_test_assert( array() === htbd_test_links(), 'no suggestion is built while the setting is off' );

htbd_test_configure( array( 'languages' => array( 'zh' ) ) );
htbd_test_assert( array() === htbd_test_links(), 'no suggestion is built for a single language site' );

// A language the router cannot reach has no usable URL.
htbd_test_configure( array() );
$GLOBALS['htbd_test_urls']['cht'] = 'https://example.com/about/';
$links = htbd_test_links();

htbd_test_assert( 1 === count( $links ), 'a language without a distinct URL is skipped' );
htbd_test_assert( 'en' === $links[0]['code'], 'the reachable language is still offered' );

// The notice shell is rendered hidden, so it is invisible without JavaScript.
htbd_test_configure( array() );
ob_start();
( new HTBD_Language_Prompt( new HTBD_Language_Router() ) )->render();
$markup = (string) ob_get_clean();

htbd_test_assert( false !== strpos( $markup, 'hidden' ), 'the notice is hidden until the script decides to show it' );
htbd_test_assert( false !== strpos( $markup, 'id="htbd-language-prompt"' ), 'the notice has the id the script looks for' );
htbd_test_assert( false !== strpos( $markup, 'htbd-language-prompt__dismiss' ), 'the notice provides a dismiss button' );
htbd_test_assert( false === strpos( $markup, 'href=' ), 'the notice ships no URL before the browser language is known' );

ob_start();
htbd_test_configure( array( 'browser_language_prompt' => false ) );
( new HTBD_Language_Prompt( new HTBD_Language_Router() ) )->render();
htbd_test_assert( '' === (string) ob_get_clean(), 'nothing is rendered while the setting is off' );

// Configuration handed to the script.
htbd_test_configure( array() );
$config = ( new HTBD_Language_Prompt( new HTBD_Language_Router() ) )->config();

htbd_test_assert( 'zh' === $config['current'], 'the script receives the current language' );
htbd_test_assert( 2 === count( $config['links'] ), 'the script receives every candidate link' );
htbd_test_assert( 'htbd-language-prompt-dismissed' === $config['storageKey'], 'the dismissal key is stable' );
htbd_test_assert( isset( $config['i18n']['message'] ) && isset( $config['i18n']['action'] ), 'the notice strings are localized on the server' );
htbd_test_assert( false !== strpos( $config['i18n']['message'], '%s' ), 'the message keeps its language name placeholder' );

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

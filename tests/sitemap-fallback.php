<?php
/**
 * Standalone checks for the minimal sitemap served before the cached source
 * sitemap exists.
 *
 * Run with: php tests/sitemap-fallback.php
 *
 * Only the XML builder is exercised here; the WordPress and router calls it
 * needs are stubbed, so no installation and no network access are required.
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'MB_IN_BYTES', 1048576 );

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}

function esc_url_raw( $url ) {
	return $url;
}

class HTBD_Settings {
	public static function languages() {
		return array( 'zh', 'en', 'jp' );
	}

	public static function hreflang_code( $language ) {
		$map = array(
			'zh' => 'zh',
			'en' => 'en',
			'jp' => 'ja',
		);

		return $map[ $language ];
	}

	public static function target_languages() {
		return array( 'en', 'jp' );
	}

	public static function is_routing_mode_enabled( $mode ) {
		return 'subdirectory' === $mode;
	}
}

class HTBD_Language_Router {
	public function url_for_language( $url, $language ) {
		if ( 'zh' === $language ) {
			return $url;
		}

		return 'https://example.com/' . $language . '/';
	}
}

class HTBD_Cron {
	const SITEMAP_REFRESH = 'htbd_refresh_source_sitemap';
}

require dirname( __DIR__ ) . '/includes/class-sitemap-controller.php';

$controller = new HTBD_Sitemap_Controller( new HTBD_Language_Router() );
$method     = new ReflectionMethod( $controller, 'fallback_sitemap_xml' );
$method->setAccessible( true );
$xml = $method->invoke( $controller );

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

htbd_test_assert( is_string( $xml ) && '' !== $xml, 'a fallback sitemap is produced' );

$document = new DOMDocument();
htbd_test_assert( false !== $document->loadXML( $xml, LIBXML_NONET ), 'the fallback is well formed XML' );
htbd_test_assert( 'urlset' === $document->documentElement->localName, 'the root element is urlset' );
htbd_test_assert(
	'http://www.sitemaps.org/schemas/sitemap/0.9' === $document->documentElement->namespaceURI,
	'the sitemap namespace is declared'
);

$xpath = new DOMXPath( $document );
$xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
$xpath->registerNamespace( 'xhtml', 'http://www.w3.org/1999/xhtml' );

htbd_test_assert( 3 === $xpath->query( '//sm:url' )->length, 'one entry per configured language' );
htbd_test_assert( 9 === $xpath->query( '//xhtml:link' )->length, 'each entry lists every language as an alternate' );
htbd_test_assert(
	3 === $xpath->query( '//xhtml:link[@hreflang="ja"]' )->length && 0 === $xpath->query( '//xhtml:link[@hreflang="jp"]' )->length,
	'Baidu language codes are mapped to hreflang codes'
);
htbd_test_assert(
	'https://example.com/en/' === trim( $xpath->query( '//sm:url[2]/sm:loc' )->item( 0 )->textContent ),
	'the localized home URL is used for each entry'
);

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

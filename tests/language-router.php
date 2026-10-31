<?php
/**
 * Standalone checks for HTBD_Language_Router URL rewriting.
 *
 * Run with: php tests/language-router.php
 *
 * WordPress lookups are stubbed and HTBD_Settings is replaced by a fixed
 * configuration, so this file needs no installation, database, or network.
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['htbd_test_options']  = array();
$GLOBALS['htbd_test_query_var'] = array();
$GLOBALS['htbd_test_settings']  = array();

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['htbd_test_options'] ) ? $GLOBALS['htbd_test_options'][ $name ] : $default;
}

function get_query_var( $name ) {
	return isset( $GLOBALS['htbd_test_query_var'][ $name ] ) ? $GLOBALS['htbd_test_query_var'][ $name ] : '';
}

function esc_url_raw( $url ) {
	return $url;
}

function is_ssl() {
	return true;
}

class HTBD_Settings {
	public static function get() {
		return $GLOBALS['htbd_test_settings'];
	}

	public static function is_routing_mode_enabled( $mode ) {
		return in_array( $mode, (array) $GLOBALS['htbd_test_settings']['routing_mode'], true );
	}

	public static function target_languages() {
		return array_values( $GLOBALS['htbd_test_settings']['target_languages'] );
	}

	public static function subdirectory_languages() {
		return self::target_languages();
	}

	public static function languages() {
		$settings = $GLOBALS['htbd_test_settings'];

		if ( 'auto' === $settings['source_language'] ) {
			return self::target_languages();
		}

		return array_merge( array( $settings['source_language'] ), self::target_languages() );
	}

	public static function is_supported_language( $language ) {
		return in_array( sanitize_key( $language ), self::languages(), true );
	}

	public static function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = explode( '/', $domain, 2 )[0];
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return '' === $domain ? '' : $domain;
	}
}

function htbd_test_configure( array $settings, $home = 'https://example.com' ) {
	$GLOBALS['htbd_test_settings'] = array_merge(
		array(
			'source_language' => 'zh',
			'target_languages' => array( 'en', 'jp' ),
			'routing_mode'    => array( 'subdirectory' ),
			'domain_bindings' => array(),
		),
		$settings
	);
	$GLOBALS['htbd_test_options']['home'] = $home;
}

require dirname( __DIR__ ) . '/includes/class-language-router.php';

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

function htbd_test_request( $uri, $host = 'example.com' ) {
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['HTTP_HOST']   = $host;
}

function htbd_test_redirect( $uri, $host = 'example.com', $home = 'https://example.com' ) {
	$GLOBALS['htbd_test_options']['home'] = $home;

	$router = new HTBD_Language_Router();
	htbd_test_request( $uri, $host );

	return $router->admin_redirect_url();
}

function htbd_test_strip( $uri, $home = 'https://example.com' ) {
	$router = new HTBD_Language_Router();
	htbd_test_request( $uri );
	$router->strip_language_prefix( true, new stdClass(), array() );

	return $_SERVER['REQUEST_URI'];
}

// Subdirectory prefix removal.
htbd_test_configure( array() );
htbd_test_assert( '/about/' === htbd_test_strip( '/en/about/' ), 'strips the language prefix from the request URI' );
htbd_test_assert( '/search/?s=test' === htbd_test_strip( '/en/search/?s=test' ), 'keeps the query string when stripping the prefix' );
htbd_test_assert( '/about/' === htbd_test_strip( '/about/' ), 'leaves a source language request untouched' );
htbd_test_assert( '/enquiry/' === htbd_test_strip( '/enquiry/' ), 'does not treat a similar slug as a language prefix' );

htbd_test_configure( array(), 'https://example.com/blog' );
htbd_test_assert( '/blog/about/' === htbd_test_strip( '/blog/en/about/', 'https://example.com/blog' ), 'handles a site installed in a subdirectory' );

htbd_test_configure( array( 'routing_mode' => array( 'domain' ) ) );
htbd_test_assert( '/en/about/' === htbd_test_strip( '/en/about/' ), 'ignores prefixes when subdirectory routing is off' );

// Subdirectory URL building.
htbd_test_configure( array() );
$router = new HTBD_Language_Router();
htbd_test_assert(
	'https://example.com/en/about/' === $router->localized_url( 'https://example.com/about/', 'en' ),
	'prefixes a source URL for a target language'
);
htbd_test_assert(
	'https://example.com/jp/about/' === $router->localized_url( 'https://example.com/en/about/', 'jp' ),
	'replaces an existing language prefix'
);
htbd_test_assert(
	'https://example.com/about/' === $router->localized_url( 'https://example.com/about/', 'zh' ),
	'leaves the source language URL untouched'
);
htbd_test_assert(
	'https://example.com/en/search/?s=test#top' === $router->localized_url( 'https://example.com/search/?s=test#top', 'en' ),
	'preserves the query string and fragment'
);

// Reversing a localized URL back to the source language.
htbd_test_assert(
	'https://example.com/about/' === $router->url_for_language( 'https://example.com/en/about/', 'zh' ),
	'url_for_language() strips the prefix for the source language'
);
htbd_test_assert(
	'https://example.com/en/about/' === $router->url_for_language( 'https://example.com/about/', 'en' ),
	'url_for_language() builds a target language URL'
);

// Domain bindings.
htbd_test_configure(
	array(
		'routing_mode'    => array( 'domain' ),
		'domain_bindings' => array( 'en.example.com' => 'en' ),
	)
);
$router = new HTBD_Language_Router();
htbd_test_assert(
	'https://en.example.com/about/' === $router->url_for_language( 'https://example.com/about/', 'en' ),
	'url_for_language() uses the bound domain even in domain only mode'
);
htbd_test_assert(
	'https://example.com/about/' === $router->url_for_language( 'https://en.example.com/about/', 'zh' ),
	'url_for_language() maps a bound domain back to the source site'
);
htbd_test_assert(
	'https://example.com/about/' === $router->source_url( 'https://en.example.com/en/about/' ),
	'source_url() removes both the domain and the path prefix'
);

// Login and admin requests arriving through a language route.
htbd_test_configure( array() );
htbd_test_assert(
	'https://example.com/wp-login.php' === htbd_test_redirect( '/en/wp-login.php' ),
	'redirects a prefixed login request to the source site'
);
htbd_test_assert(
	'https://example.com/wp-login.php?action=login' === htbd_test_redirect( '/en/wp-login.php?action=login' ),
	'keeps the query string when redirecting a login request'
);
htbd_test_assert(
	'https://example.com/wp-admin/edit.php?post_type=post' === htbd_test_redirect( '/en/wp-admin/edit.php?post_type=post' ),
	'redirects a prefixed admin request to the source site'
);
htbd_test_assert(
	'https://example.com/wp-admin' === htbd_test_redirect( '/en/wp-admin' ),
	'redirects an admin request without a trailing slash'
);
htbd_test_assert( '' === htbd_test_redirect( '/en/about/' ), 'does not redirect front-end content' );
htbd_test_assert( '' === htbd_test_redirect( '/wp-login.php' ), 'does not redirect a source language login request' );
htbd_test_assert(
	'https://example.com/blog/wp-admin/' === htbd_test_redirect( '/blog/en/wp-admin/', 'example.com', 'https://example.com/blog' ),
	'keeps the subdirectory install path in the redirect target'
);

htbd_test_configure(
	array(
		'routing_mode'    => array( 'domain' ),
		'domain_bindings' => array( 'en.example.com' => 'en' ),
	)
);
htbd_test_assert(
	'https://example.com/wp-admin/' === htbd_test_redirect( '/wp-admin/', 'en.example.com' ),
	'redirects an admin request that arrived on a bound domain'
);
htbd_test_assert( '' === htbd_test_redirect( '/wp-admin/', 'example.com' ), 'does not redirect on the source domain' );

// Language resolution from the query variable.
$GLOBALS['htbd_test_query_var'] = array( 'htbd_language' => 'jp' );
htbd_test_assert( 'jp' === $router->current_language(), 'current_language() reads the resolved query variable' );

$GLOBALS['htbd_test_query_var'] = array( 'htbd_language' => 'de' );
htbd_test_assert( 'zh' === $router->current_language(), 'current_language() falls back to the source language for unknown codes' );

$GLOBALS['htbd_test_query_var'] = array( 'htbd_language' => 'auto' );
htbd_test_assert( 'zh' === $router->current_language(), 'current_language() never returns the auto pseudo language' );

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

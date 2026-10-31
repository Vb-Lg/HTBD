<?php
/**
 * Standalone checks for HTBD_Settings::normalize_domain().
 *
 * Run with: php tests/domain-normalize.php
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

require dirname( __DIR__ ) . '/includes/class-settings.php';

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

$accepted = array(
	'en.example.com'            => 'en.example.com',
	'https://en.example.com/'   => 'en.example.com',
	'en.example.com:8443'       => 'en.example.com',
	'  EN.Example.COM  '        => 'en.example.com',
	'localhost'                 => 'localhost',
	'localhost:8080'            => 'localhost',
	'127.0.0.1'                 => '127.0.0.1',
	'127.0.0.1:8080'            => '127.0.0.1',
	'my-local-host'             => 'my-local-host',
	'example.com/path?x=1'      => 'example.com',
);

foreach ( $accepted as $input => $expected ) {
	$actual = HTBD_Settings::normalize_domain( $input );
	htbd_test_assert( $expected === $actual, sprintf( 'normalizes "%s" to "%s"', $input, $expected ) );
}

$rejected = array( '', '   ', 'not a domain', '-broken.example.com', 'http://' );

foreach ( $rejected as $input ) {
	htbd_test_assert( '' === HTBD_Settings::normalize_domain( $input ), sprintf( 'rejects "%s"', $input ) );
}

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

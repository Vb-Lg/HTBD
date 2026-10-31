<?php
/**
 * Standalone checks for HTBD_Translation_Store::split_context().
 *
 * Run with: php tests/store-context.php
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/includes/class-translation-store.php';

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

$cases = array(
	'post:12:post_title'                 => array( 'post', 12 ),
	'post:12:post_content:text:3'        => array( 'post', 12 ),
	'term:7:name'                        => array( 'term', 7 ),
	'attachment:9:alt'                   => array( 'attachment', 9 ),
	'post:abc:post_title'                => array( 'post', 0 ),
	'no-separator'                       => array( 'no-separator', 0 ),
	'post:12'                            => array( 'post', 12 ),
);

foreach ( $cases as $context => $expected ) {
	list( $type, $id ) = HTBD_Translation_Store::split_context( $context );
	htbd_test_assert( $type === $expected[0] && $id === $expected[1], sprintf( '%s -> %s:%d', $context, $expected[0], $expected[1] ) );
}

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

<?php
/**
 * Standalone checks for HTBD_Baidu_Provider batching, retries, and fallbacks.
 *
 * Run with: php tests/provider-batching.php
 *
 * The WordPress functions used by the provider are stubbed here, so this file
 * needs no WordPress installation, no credentials, and no network access.
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['htbd_test_http_calls'] = array();
$GLOBALS['htbd_test_http_queue'] = array();
$GLOBALS['htbd_test_failures']   = 0;

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

function wp_rand( $min, $max ) {
	return 123456;
}

function do_action( $hook, $payload = null ) {
	return null;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['htbd_test_http_calls'][] = $args['body'];

	if ( empty( $GLOBALS['htbd_test_http_queue'] ) ) {
		return new WP_Error( 'http', 'no stubbed response' );
	}

	return array_shift( $GLOBALS['htbd_test_http_queue'] );
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $row ) use ( $field ) {
			return $row[ $field ];
		},
		$list
	);
}

function current_time( $type, $gmt = false ) {
	return gmdate( 'Y-m-d H:i:s' );
}

class WP_Error {
	private $message;

	public function __construct( $code, $message ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class HTBD_Settings {
	public static function get() {
		return array(
			'request_interval' => 0,
			'log_requests'     => false,
		);
	}
}

require dirname( __DIR__ ) . '/includes/interface-translation-provider.php';
require dirname( __DIR__ ) . '/includes/class-translation-result.php';
require dirname( __DIR__ ) . '/includes/class-translation-identity.php';
require dirname( __DIR__ ) . '/includes/class-baidu-provider.php';

function htbd_test_assert( $condition, $label ) {
	if ( $condition ) {
		printf( "PASS %s\n", $label );

		return;
	}

	++$GLOBALS['htbd_test_failures'];
	printf( "FAIL %s\n", $label );
}

function htbd_test_translated( array $lines ) {
	return array(
		'body' => json_encode(
			array(
				'trans_result' => array_map(
					static function ( $line ) {
						return array( 'dst' => $line );
					},
					$lines
				),
			)
		),
	);
}

function htbd_test_error( $code, $message = 'error' ) {
	return array(
		'body' => json_encode(
			array(
				'error_code' => $code,
				'error_msg'  => $message,
			)
		),
	);
}

function htbd_test_reset( array $queue ) {
	$GLOBALS['htbd_test_http_calls'] = array();
	$GLOBALS['htbd_test_http_queue'] = $queue;
}

$provider = new HTBD_Baidu_Provider( 'app-id', 'secret' );

htbd_test_reset( array( htbd_test_translated( array( 'A', 'B', 'C' ) ) ) );
$results = $provider->translate_batch( array( 'one', 'two', 'three' ), 'zh', 'en', 'post:1:post_content' );
htbd_test_assert( 1 === count( $GLOBALS['htbd_test_http_calls'] ), 'three short texts share one HTTP request' );
htbd_test_assert( "one\ntwo\nthree" === $GLOBALS['htbd_test_http_calls'][0]['q'], 'texts are joined with newlines' );
htbd_test_assert( 'A' === $results[0]->value && 'B' === $results[1]->value && 'C' === $results[2]->value, 'translated lines map back to the input keys' );

htbd_test_reset( array( htbd_test_translated( array( 'X' ) ), htbd_test_translated( array( 'Y' ) ) ) );
$results = $provider->translate_batch( array( "a\nb", 'c' ), 'zh', 'en', 'ctx' );
htbd_test_assert( 2 === count( $GLOBALS['htbd_test_http_calls'] ), 'a text containing a line break is sent alone' );
htbd_test_assert( $results[0]->success && $results[1]->success, 'both results succeed' );

htbd_test_reset( array( htbd_test_error( 54003 ), htbd_test_translated( array( 'OK' ) ) ) );
$results = $provider->translate_batch( array( 'hello' ), 'zh', 'en', 'ctx' );
htbd_test_assert( 2 === count( $GLOBALS['htbd_test_http_calls'] ), 'a quota error is retried' );
htbd_test_assert( $results[0]->success && 'OK' === $results[0]->value, 'the retry result is used' );

htbd_test_reset( array( htbd_test_error( 52003 ) ) );
$results = $provider->translate_batch( array( 'hello' ), 'zh', 'en', 'ctx' );
htbd_test_assert( 1 === count( $GLOBALS['htbd_test_http_calls'] ), 'an authentication error is not retried' );
htbd_test_assert( ! $results[0]->success && '52003' === $results[0]->error_code, 'the provider error code is reported' );

htbd_test_reset(
	array(
		htbd_test_translated( array( 'only-one' ) ),
		htbd_test_translated( array( 'L1' ) ),
		htbd_test_translated( array( 'L2' ) ),
	)
);
$results = $provider->translate_batch( array( 'a', 'b' ), 'zh', 'en', 'ctx' );
htbd_test_assert( 3 === count( $GLOBALS['htbd_test_http_calls'] ), 'a line count mismatch falls back to one request per text' );
htbd_test_assert( 'L1' === $results[0]->value && 'L2' === $results[1]->value, 'the fallback recovers both translations' );

htbd_test_reset( array() );
$without_credentials = new HTBD_Baidu_Provider( '', '' );
$results             = $without_credentials->translate_batch( array( 'x' ), 'zh', 'en', 'ctx' );
htbd_test_assert( 0 === count( $GLOBALS['htbd_test_http_calls'] ), 'missing credentials skip the HTTP request' );
htbd_test_assert( 'missing_credentials' === $results[0]->error_code, 'missing credentials are reported' );

if ( $GLOBALS['htbd_test_failures'] > 0 ) {
	printf( "\n%d check(s) failed.\n", $GLOBALS['htbd_test_failures'] );
	exit( 1 );
}

printf( "\nAll checks passed.\n" );

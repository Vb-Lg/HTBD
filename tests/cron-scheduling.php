<?php
/**
 * Standalone checks for HTBD_Cron argument normalization.
 *
 * Run with: php tests/cron-scheduling.php
 *
 * WordPress cron functions are stubbed with an in-memory event list, so this
 * file needs no WordPress installation and no database.
 *
 * @package HTBD
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['htbd_test_events'] = array();
$GLOBALS['htbd_test_checks'] = 0;

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['htbd_test_events'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);

	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['htbd_test_events'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return $event['timestamp'];
		}
	}

	return false;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	foreach ( $GLOBALS['htbd_test_events'] as $index => $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args && $event['timestamp'] === $timestamp ) {
			unset( $GLOBALS['htbd_test_events'][ $index ] );
			$GLOBALS['htbd_test_events'] = array_values( $GLOBALS['htbd_test_events'] );

			return true;
		}
	}

	return false;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['htbd_test_events'] = array_values(
		array_filter(
			$GLOBALS['htbd_test_events'],
			static function ( $event ) use ( $hook ) {
				return $event['hook'] !== $hook;
			}
		)
	);

	return true;
}

function _get_cron_array() {
	$cron = array();

	foreach ( $GLOBALS['htbd_test_events'] as $event ) {
		$cron[ $event['timestamp'] ][ $event['hook'] ][] = array( 'args' => $event['args'] );
	}

	return $cron;
}

require dirname( __DIR__ ) . '/includes/class-cron.php';

function htbd_test_assert( $condition, $label ) {
	++$GLOBALS['htbd_test_checks'];

	if ( $condition ) {
		printf( "PASS %s\n", $label );

		return;
	}

	printf( "FAIL %s\n", $label );
	$GLOBALS['htbd_test_failed'] = true;
}

$GLOBALS['htbd_test_events'] = array();

HTBD_Cron::schedule_post( 12, 'en' );
htbd_test_assert( 1 === count( $GLOBALS['htbd_test_events'] ), 'scheduling a post translation queues one event' );
htbd_test_assert( array( 12, 'en', false ) === $GLOBALS['htbd_test_events'][0]['args'], 'the queued event uses the normalized argument list' );
htbd_test_assert( HTBD_Cron::is_post_scheduled( 12, 'en' ), 'the queued job is reported as scheduled' );

// An event queued by an older version with two arguments must also be seen.
$GLOBALS['htbd_test_events'][] = array(
	'timestamp' => time() + 30,
	'hook'      => HTBD_Cron::POST_TRANSLATION,
	'args'      => array( 99, 'jp' ),
);
htbd_test_assert( HTBD_Cron::is_post_scheduled( 99, 'jp' ), 'a legacy two argument event is still detected' );

$GLOBALS['htbd_test_events'][] = array(
	'timestamp' => time() + 40,
	'hook'      => HTBD_Cron::POST_TRANSLATION,
	'args'      => array( 99, 'jp', true ),
);
HTBD_Cron::clear_post( 99, 'jp' );
htbd_test_assert( 1 === count( $GLOBALS['htbd_test_events'] ), 'clearing a post job removes every queued variant' );
htbd_test_assert( ! HTBD_Cron::is_post_scheduled( 99, 'jp' ), 'the cleared job is no longer scheduled' );

HTBD_Cron::schedule_term( 7, 'fr', true, time() + 5 );
htbd_test_assert( array( 7, 'fr', true ) === end( $GLOBALS['htbd_test_events'] )['args'], 'a forced term job keeps the force flag' );

$backlog = HTBD_Cron::backlog();
htbd_test_assert( 2 === $backlog['count'], 'the backlog counts every queued plugin event' );

HTBD_Cron::clear_hooks();
htbd_test_assert( 0 === count( $GLOBALS['htbd_test_events'] ), 'clearing all hooks empties the queue' );

if ( ! empty( $GLOBALS['htbd_test_failed'] ) ) {
	printf( "\n%d check(s) failed.\n", $GLOBALS['htbd_test_checks'] );
	exit( 1 );
}

printf( "\nAll %d checks passed.\n", $GLOBALS['htbd_test_checks'] );

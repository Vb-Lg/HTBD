<?php

defined( 'ABSPATH' ) || exit;

/**
 * Owns the cron hook names and the argument list used for each translation job.
 *
 * WordPress identifies a scheduled event by hook name plus a hash of its
 * arguments. Scheduling the same job with two arguments in one place and three
 * arguments in another creates two independent events, so every caller has to
 * go through this class.
 */
class HTBD_Cron {
	const POST_TRANSLATION = 'htbd_process_translation';
	const TERM_TRANSLATION = 'htbd_process_term_translation';
	const SEO_OUTPUT       = 'htbd_process_seo_output_translation';

	/**
	 * Hooks registered by the plugin, including the legacy names that earlier
	 * versions of the plugin may still have queued on this site.
	 *
	 * @return string[]
	 */
	public static function hooks() {
		return array(
			self::POST_TRANSLATION,
			self::TERM_TRANSLATION,
			self::SEO_OUTPUT,
			'btranslate_process_translation',
			'btranslate_process_term_translation',
			'btranslate_process_seo_output_translation',
		);
	}

	/**
	 * Argument lists used for the same job by current and earlier versions.
	 *
	 * @param int    $item_id        Post or term ID.
	 * @param string $target_language Target language code.
	 * @return array[]
	 */
	public static function signatures( $item_id, $target_language ) {
		$item_id         = (int) $item_id;
		$target_language = sanitize_key( $target_language );

		return array(
			array( $item_id, $target_language ),
			array( $item_id, $target_language, false ),
			array( $item_id, $target_language, true ),
		);
	}

	/**
	 * @param int    $post_id         Post ID.
	 * @param string $target_language Target language code.
	 * @param bool   $force_refresh   Whether to bypass the stored translation.
	 * @param int    $timestamp       Unix timestamp, defaults to ten seconds from now.
	 * @return bool
	 */
	public static function schedule_post( $post_id, $target_language, $force_refresh = false, $timestamp = 0 ) {
		return self::schedule( self::POST_TRANSLATION, $post_id, $target_language, $force_refresh, $timestamp );
	}

	/**
	 * @param int    $term_id         Term ID.
	 * @param string $target_language Target language code.
	 * @param bool   $force_refresh   Whether to bypass the stored translation.
	 * @param int    $timestamp       Unix timestamp, defaults to ten seconds from now.
	 * @return bool
	 */
	public static function schedule_term( $term_id, $target_language, $force_refresh = false, $timestamp = 0 ) {
		return self::schedule( self::TERM_TRANSLATION, $term_id, $target_language, $force_refresh, $timestamp );
	}

	/**
	 * @param int    $post_id         Post ID.
	 * @param string $target_language Target language code.
	 * @return bool
	 */
	public static function is_post_scheduled( $post_id, $target_language ) {
		return self::is_scheduled( self::POST_TRANSLATION, $post_id, $target_language );
	}

	/**
	 * @param int    $term_id         Term ID.
	 * @param string $target_language Target language code.
	 * @return bool
	 */
	public static function is_term_scheduled( $term_id, $target_language ) {
		return self::is_scheduled( self::TERM_TRANSLATION, $term_id, $target_language );
	}

	/**
	 * Removes every queued variant of one post translation job.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $target_language Target language code.
	 * @return void
	 */
	public static function clear_post( $post_id, $target_language ) {
		self::clear( self::POST_TRANSLATION, $post_id, $target_language );
	}

	/**
	 * Removes every queued variant of one term translation job.
	 *
	 * @param int    $term_id         Term ID.
	 * @param string $target_language Target language code.
	 * @return void
	 */
	public static function clear_term( $term_id, $target_language ) {
		self::clear( self::TERM_TRANSLATION, $term_id, $target_language );
	}

	/**
	 * Removes all queued events for the given hooks.
	 *
	 * @param string[] $hooks Hook names, defaults to the hooks used by this plugin.
	 * @return void
	 */
	public static function clear_hooks( $hooks = array() ) {
		foreach ( empty( $hooks ) ? self::hooks() : (array) $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Counts queued plugin events, including overdue ones.
	 *
	 * @return array{count: int, next: int, seen: array<string, int>, overdue: bool}
	 */
	public static function backlog() {
		$cron   = _get_cron_array();
		$hooks  = self::hooks();
		$count  = 0;
		$next   = 0;
		$seen   = array();
		$now    = time();

		if ( ! is_array( $cron ) ) {
			return array(
				'count'   => 0,
				'next'    => 0,
				'seen'    => array(),
				'overdue' => false,
			);
		}

		foreach ( $cron as $timestamp => $entries ) {
			foreach ( (array) $entries as $hook => $callbacks ) {
				if ( ! in_array( $hook, $hooks, true ) ) {
					continue;
				}

				$count += count( (array) $callbacks );
				$seen[ $hook ] = ( $seen[ $hook ] ?? 0 ) + count( (array) $callbacks );

				if ( 0 === $next || $timestamp < $next ) {
					$next = (int) $timestamp;
				}
			}
		}

		return array(
			'count' => $count,
			'next'  => $next,
			'seen'  => $seen,
			'overdue' => $next && $next < $now,
		);
	}

	private static function schedule( $hook, $item_id, $target_language, $force_refresh, $timestamp ) {
		$item_id = (int) $item_id;

		if ( ! $item_id || '' === (string) $target_language ) {
			return false;
		}

		$timestamp = $timestamp ? (int) $timestamp : time() + 10;

		return wp_schedule_single_event(
			$timestamp,
			$hook,
			array( $item_id, sanitize_key( $target_language ), (bool) $force_refresh )
		);
	}

	private static function is_scheduled( $hook, $item_id, $target_language ) {
		foreach ( self::signatures( $item_id, $target_language ) as $args ) {
			if ( false !== wp_next_scheduled( $hook, $args ) ) {
				return true;
			}
		}

		return false;
	}

	private static function clear( $hook, $item_id, $target_language ) {
		foreach ( self::signatures( $item_id, $target_language ) as $args ) {
			while ( false !== ( $timestamp = wp_next_scheduled( $hook, $args ) ) ) {
				wp_unschedule_event( $timestamp, $hook, $args );
			}
		}
	}
}

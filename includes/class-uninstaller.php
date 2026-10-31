<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Uninstaller {
	/**
	 * Options this plugin owns. Options that belong to the original plugin are
	 * only removed when this plugin took them over during a migration.
	 */
	private const OPTION_NAMES = array(
		'htbd_settings',
		'htbd_db_version',
		'htbd_flush_rewrite_rules',
		'htbd_translation_task',
		'htbd_retranslation_batch',
	);

	private const LEGACY_OPTION_NAMES = array(
		'btranslate_settings',
		'btranslate_flush_rewrite_rules',
		'btranslate_translation_task',
		'btranslate_retranslation_batch',
	);

	public static function uninstall() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::delete_site_data();
				restore_current_blog();
			}
		} else {
			self::delete_site_data();
		}

		foreach ( self::OPTION_NAMES as $option_name ) {
			delete_site_option( $option_name );
		}

		delete_site_option( HTBD_Translation_Store::LEGACY_MIGRATION_OPTION );
	}

	private static function delete_site_data() {
		global $wpdb;

		HTBD_Cron::clear_hooks();

		foreach ( self::OPTION_NAMES as $option_name ) {
			delete_option( $option_name );
		}

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', HTBD_Translation_Store::table_name() ) );

		// The legacy plugin may still be installed and using its own settings
		// and table. Only touch them when this plugin renamed or adopted them.
		if ( get_option( HTBD_Translation_Store::LEGACY_MIGRATION_OPTION, false ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . HTBD_Translation_Store::LEGACY_TABLE_SUFFIX ) );

			foreach ( self::LEGACY_OPTION_NAMES as $option_name ) {
				delete_option( $option_name );
			}

			delete_option( HTBD_Translation_Store::LEGACY_MIGRATION_OPTION );
		}
	}
}
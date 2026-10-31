<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Translation_Store {
	const DB_VERSION              = 2;
	const DB_VERSION_OPTION       = 'htbd_db_version';
	const LEGACY_MIGRATION_OPTION = 'htbd_migrated_from_btranslate';
	const LEGACY_TABLE_SUFFIX     = 'btranslate_translations';

	/**
	 * Request-level cache of find_valid() lookups. A single page render asks
	 * for the same title, term, and permalink translations many times.
	 *
	 * @var array<string, array|null>
	 */
	private $cache = array();

	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'hyx_bd_translations';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$legacy_table    = $wpdb->prefix . self::LEGACY_TABLE_SUFFIX;
		$table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the plugin-owned table during activation.
		$legacy_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks for the legacy plugin-owned table during activation.
		if ( $legacy_exists === $legacy_table && $table_exists !== $table_name ) {
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy_table, $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migrates persisted translations to the renamed table.
			update_option( self::LEGACY_MIGRATION_OPTION, 1, false );
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			identity_key char(64) NOT NULL,
			source_language varchar(20) NOT NULL,
			target_language varchar(20) NOT NULL,
			field_context varchar(191) NOT NULL,
			item_type varchar(20) NOT NULL DEFAULT '',
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_fingerprint char(64) NOT NULL,
			translated_value longtext NULL,
			status varchar(20) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY identity_key (identity_key),
			KEY target_language (target_language),
			KEY source_fingerprint (source_fingerprint),
			KEY item_lookup (item_type, item_id, target_language, status)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Runs the schema upgrade once per version, including on sites that were
	 * activated before the columns existed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		self::install();
		self::backfill_item_columns();
	}

	/**
	 * Derives the indexed item columns from the legacy field_context values.
	 *
	 * @return void
	 */
	private static function backfill_item_columns() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema backfill of the plugin-owned table.
			$wpdb->prepare(
				"UPDATE %i
				SET item_type = SUBSTRING_INDEX(field_context, ':', 1),
					item_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(field_context, ':', 2), ':', -1) AS UNSIGNED)
				WHERE item_type = '' OR item_id = 0",
				self::table_name()
			)
		);
	}

	/**
	 * Splits a field context such as post:12:post_content into its indexed
	 * parts. Contexts without a numeric ID keep an empty type and a zero ID.
	 *
	 * @param string $field_context Field context.
	 * @return array{0: string, 1: int}
	 */
	public static function split_context( $field_context ) {
		$parts = explode( ':', (string) $field_context );
		$type  = isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
		$id    = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

		if ( ! in_array( $type, array( 'post', 'term', 'attachment' ), true ) ) {
			$id = 0;
		}

		return array( $type, (int) $id );
	}

	public function find_valid( $identity_key ) {
		global $wpdb;

		if ( array_key_exists( $identity_key, $this->cache ) ) {
			return $this->cache[ $identity_key ];
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom translation table is the plugin's persistent cache, cached per request below.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE identity_key = %s AND status IN ('complete', 'manual') LIMIT 1",
				self::table_name(),
				$identity_key
			),
			ARRAY_A
		);

		$this->cache[ $identity_key ] = $row;

		return $row;
	}

	public function save( $identity_key, $source_language, $target_language, $field_context, $source_fingerprint, $translated_value, $status ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		list( $item_type, $item_id ) = self::split_context( $field_context );

		// INSERT ... ON DUPLICATE KEY UPDATE instead of REPLACE so created_at
		// keeps the first write time and the auto increment value is stable.
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes to the plugin-owned persistent translation cache.
			$wpdb->prepare(
				"INSERT INTO %i (identity_key, source_language, target_language, field_context, item_type, item_id, source_fingerprint, translated_value, status, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					source_language = VALUES(source_language),
					target_language = VALUES(target_language),
					field_context = VALUES(field_context),
					item_type = VALUES(item_type),
					item_id = VALUES(item_id),
					source_fingerprint = VALUES(source_fingerprint),
					translated_value = VALUES(translated_value),
					status = VALUES(status),
					updated_at = VALUES(updated_at)",
				self::table_name(),
				$identity_key,
				$source_language,
				$target_language,
				$field_context,
				$item_type,
				$item_id,
				$source_fingerprint,
				$translated_value,
				$status,
				$now,
				$now
			)
		);

		unset( $this->cache[ $identity_key ] );

		return $result;
	}

	public function save_manual( $identity_key, $source_language, $target_language, $field_context, $source_fingerprint, $translated_value ) {
		return $this->save( $identity_key, $source_language, $target_language, $field_context, $source_fingerprint, $translated_value, 'manual' );
	}

	public function clear( $target_language = '' ) {
		global $wpdb;

		$this->cache = array();

		if ( '' !== $target_language ) {
			return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This explicitly clears one language from the plugin's persistent translation cache.
				$wpdb->prepare(
					'DELETE FROM %i WHERE target_language = %s',
					self::table_name(),
					sanitize_key( $target_language )
				)
			);
		}

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This explicitly clears the plugin's persistent translation cache.
			$wpdb->prepare( 'DELETE FROM %i', self::table_name() )
		);
	}

	public function get_post_language_status( $post_id, $target_language ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom translation table is the plugin's persistent cache.
			$wpdb->prepare(
				"SELECT COUNT(*) AS translated_fields, MAX(updated_at) AS last_translated_at
				FROM %i
				WHERE target_language = %s
				AND status IN ('complete', 'manual')
				AND item_type = 'post'
				AND item_id = %d",
				self::table_name(),
				$target_language,
				absint( $post_id )
			),
			ARRAY_A
		);
	}

	public function get_completed_item_counts( $target_languages, $post_ids, $term_ids, $since = '' ) {
		$target_languages = array_values( array_filter( array_map( 'sanitize_key', (array) $target_languages ) ) );
		$post_ids         = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
		$term_ids         = array_values( array_filter( array_map( 'absint', (array) $term_ids ) ) );
		if ( empty( $target_languages ) ) {
			return array(
				'posts' => 0,
				'terms' => 0,
			);
		}

		$language_placeholders = implode( ',', array_fill( 0, count( $target_languages ), '%s' ) );

		return array(
			'posts' => $this->get_completed_item_count( 'post', $post_ids, $target_languages, $language_placeholders, $since ),
			'terms' => $this->get_completed_item_count( 'term', $term_ids, $target_languages, $language_placeholders, $since ),
		);
	}

	public function get_failed_items( $target_languages, $post_ids, $term_ids, $since = '' ) {
		$target_languages = array_values( array_filter( array_map( 'sanitize_key', (array) $target_languages ) ) );
		$failures         = array_merge(
			$this->get_failed_items_for( 'post', $post_ids, $target_languages, $since ),
			$this->get_failed_items_for( 'term', $term_ids, $target_languages, $since )
		);

		usort(
			$failures,
			static function ( $left, $right ) {
				return strcmp( (string) $right['failed_at'], (string) $left['failed_at'] );
			}
		);

		return $failures;
	}

	/**
	 * @param string   $item_type        Post or term.
	 * @param int[]    $item_ids         Item IDs to look at.
	 * @param string[] $target_languages Target languages.
	 * @param string   $since            Optional UTC timestamp lower bound.
	 * @return array<int, array<string, string>>
	 */
	private function get_failed_items_for( $item_type, $item_ids, $target_languages, $since ) {
		global $wpdb;

		$item_ids = array_values( array_filter( array_map( 'absint', (array) $item_ids ) ) );

		if ( empty( $target_languages ) || empty( $item_ids ) ) {
			return array();
		}

		$language_placeholders = implode( ',', array_fill( 0, count( $target_languages ), '%s' ) );
		$id_placeholders       = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$query_args            = array_merge( array( self::table_name(), sanitize_key( $item_type ) ), $target_languages, $item_ids );

		if ( '' !== $since ) {
			$query_args[] = $since;
		}

		$since_clause = '' === $since ? '' : ' AND updated_at >= %s';

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads task failures from the plugin-owned translation table.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN clauses contain only generated placeholders; the optional clause is fixed SQL.
				"SELECT target_language, CONCAT(item_type, ':', item_id) AS item_context, MAX(updated_at) AS failed_at
				FROM %i
				WHERE status = 'failed'
					AND item_type = %s
					AND target_language IN ({$language_placeholders})
					AND item_id IN ({$id_placeholders}){$since_clause}
				GROUP BY target_language, item_id
				ORDER BY failed_at DESC",
				$query_args
			),
			ARRAY_A
		);
	}

	/**
	 * Row counts grouped by language and status, used by the CLI status
	 * command and the diagnostics screen.
	 *
	 * @return array<int, array{target_language: string, status: string, total: int}>
	 */
	public function get_status_counts() {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate read of the plugin-owned table for reporting.
			$wpdb->prepare( 'SELECT target_language, status, COUNT(*) AS total FROM %i GROUP BY target_language, status ORDER BY target_language, status', self::table_name() ),
			ARRAY_A
		);
	}

	/**
	 * Returns stored translations for export.
	 *
	 * @param string $target_language Optional target language filter.
	 * @param int    $limit           Maximum rows, 0 for no limit.
	 * @return array<int, array<string, string>>
	 */
	public function get_translations( $target_language = '', $limit = 0 ) {
		global $wpdb;

		$table_name = self::table_name();
		$columns    = 'identity_key, source_language, target_language, field_context, source_fingerprint, translated_value, status';
		$limit_sql  = $limit > 0 ? ' LIMIT %d' : '';

		if ( '' !== $target_language ) {
			$args = array( $table_name, sanitize_key( $target_language ) );

			if ( $limit > 0 ) {
				$args[] = (int) $limit;
			}

			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit export of the plugin-owned table.
				$wpdb->prepare( "SELECT {$columns} FROM %i WHERE target_language = %s ORDER BY id" . $limit_sql, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column list and limit clause are fixed fragments.
				ARRAY_A
			);
		}

		$args = array( $table_name );

		if ( $limit > 0 ) {
			$args[] = (int) $limit;
		}

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit export of the plugin-owned table.
			$wpdb->prepare( "SELECT {$columns} FROM %i ORDER BY id" . $limit_sql, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column list and limit clause are fixed fragments.
			ARRAY_A
		);
	}

	private function get_completed_item_count( $item_type, $item_ids, $target_languages, $language_placeholders, $since ) {
		global $wpdb;

		$item_ids = array_values( array_filter( array_map( 'absint', (array) $item_ids ) ) );

		if ( empty( $item_ids ) ) {
			return 0;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$query_args      = array_merge( array( self::table_name(), sanitize_key( $item_type ) ), $target_languages, $item_ids );

		if ( '' !== $since ) {
			$query_args[] = $since;
		}

		$since_clause = '' === $since ? '' : ' AND updated_at >= %s';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The custom translation table is the plugin's persistent cache.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN clauses contain only generated placeholders; the optional clause is a fixed SQL fragment.
				"SELECT COUNT(DISTINCT CONCAT(target_language, '|', item_id))
				FROM %i
				WHERE status IN ('complete', 'manual')
				AND item_type = %s
				AND target_language IN ({$language_placeholders})
				AND item_id IN ({$id_placeholders}){$since_clause}",
				$query_args
			)
		);
	}
}

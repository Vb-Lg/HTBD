<?php

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for bulk translation, reporting, and moving stored
 * translations between sites.
 */
class HTBD_CLI_Command {
	/**
	 * Translates published posts and pages immediately.
	 *
	 * Runs the translation inline instead of queueing cron events, which makes
	 * it usable for large sites where the admin screen would time out.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Translate one post or page.
	 *
	 * [--post_type=<type>]
	 * : Limit the run to one post type. Defaults to the configured types.
	 *
	 * [--language=<code>]
	 * : Target language code. Defaults to every configured target language.
	 *
	 * [--limit=<number>]
	 * : Maximum number of posts to process. Defaults to all of them.
	 *
	 * [--force]
	 * : Retranslate even when a stored translation already exists.
	 *
	 * [--dry-run]
	 * : Report what would be translated without calling the provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htbd translate --language=en --limit=20
	 *     wp htbd translate --post_id=42 --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function translate( $args, $assoc_args ) {
		$languages = $this->target_languages( $assoc_args );
		$force     = ! empty( $assoc_args['force'] );
		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$post_ids  = $this->collect_post_ids( $assoc_args );

		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts matched the request.' );

			return;
		}

		$controller = $this->content_controller();
		$processed  = 0;

		foreach ( $post_ids as $post_id ) {
			foreach ( $languages as $language ) {
				if ( $dry_run ) {
					WP_CLI::log( sprintf( 'Would translate post %d into %s.', $post_id, $language ) );
					++$processed;
					continue;
				}

				$controller->translate_post( $post_id, $language, $force );
				WP_CLI::log( sprintf( 'Translated post %d into %s.', $post_id, $language ) );
				++$processed;
			}
		}

		WP_CLI::success( sprintf( '%d translation job(s) %s.', $processed, $dry_run ? 'planned' : 'completed' ) );
	}

	/**
	 * Translates terms immediately.
	 *
	 * ## OPTIONS
	 *
	 * [--term_id=<id>]
	 * : Translate one term.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Limit the run to one taxonomy. Defaults to the configured taxonomies.
	 *
	 * [--language=<code>]
	 * : Target language code. Defaults to every configured target language.
	 *
	 * [--force]
	 * : Retranslate even when a stored translation already exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htbd translate-terms --taxonomy=category
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function translate_terms( $args, $assoc_args ) {
		$languages = $this->target_languages( $assoc_args );
		$force     = ! empty( $assoc_args['force'] );

		if ( isset( $assoc_args['term_id'] ) ) {
			$term_ids = array( absint( $assoc_args['term_id'] ) );
		} else {
			$taxonomies = isset( $assoc_args['taxonomy'] ) ? array( sanitize_key( $assoc_args['taxonomy'] ) ) : HTBD_Settings::taxonomies();
			$term_ids   = get_terms(
				array(
					'taxonomy'   => $taxonomies,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $term_ids ) ) {
				WP_CLI::error( $term_ids->get_error_message() );
			}
		}

		if ( empty( $term_ids ) ) {
			WP_CLI::warning( 'No terms matched the request.' );

			return;
		}

		$controller = $this->content_controller();
		$processed  = 0;

		foreach ( $term_ids as $term_id ) {
			foreach ( $languages as $language ) {
				$controller->translate_term( $term_id, $language, $force );
				++$processed;
			}
		}

		WP_CLI::success( sprintf( '%d term translation job(s) completed.', $processed ) );
	}

	/**
	 * Shows the stored translation counts and the queued cron events.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp htbd status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$settings = HTBD_Settings::get();
		$store    = new HTBD_Translation_Store();
		$backlog  = HTBD_Cron::backlog();

		WP_CLI::log( 'Source language: ' . $settings['source_language'] );
		WP_CLI::log( 'Target languages: ' . implode( ', ', HTBD_Settings::target_languages() ) );
		WP_CLI::log( 'URL modes: ' . implode( ', ', HTBD_Settings::routing_modes() ) );
		WP_CLI::log( 'Post types: ' . implode( ', ', HTBD_Settings::post_types() ) );
		WP_CLI::log( 'Taxonomies: ' . implode( ', ', HTBD_Settings::taxonomies() ) );
		WP_CLI::log( 'Request interval: ' . (int) $settings['request_interval'] . ' ms' );
		WP_CLI::log( sprintf( 'Queued events: %d', $backlog['count'] ) );

		if ( $backlog['next'] ) {
			WP_CLI::log( 'Next event: ' . gmdate( 'Y-m-d H:i:s', $backlog['next'] ) . ' UTC' );
		}

		$rows = $store->get_status_counts();

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No stored translations yet.' );

			return;
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			array_map(
				static function ( $row ) {
					return array(
						'language' => $row['target_language'],
						'status'   => $row['status'],
						'rows'     => (int) $row['total'],
					);
				},
				$rows
			),
			array( 'language', 'status', 'rows' )
		);
	}

	/**
	 * Exports stored translations as CSV.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Write to this file instead of standard output.
	 *
	 * [--language=<code>]
	 * : Export one target language.
	 *
	 * [--limit=<number>]
	 * : Maximum number of rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htbd export translations.csv --language=en
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$language = isset( $assoc_args['language'] ) ? sanitize_key( $assoc_args['language'] ) : '';
		$limit    = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		$store    = new HTBD_Translation_Store();
		$rows     = $store->get_translations( $language, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No translations to export.' );

			return;
		}

		$handle = isset( $args[0] ) ? fopen( $args[0], 'w' ) : fopen( 'php://stdout', 'w' );

		if ( false === $handle ) {
			WP_CLI::error( 'Unable to open the export destination.' );
		}

		fputcsv( $handle, array_keys( $rows[0] ) );

		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}

		if ( isset( $args[0] ) ) {
			fclose( $handle );
			WP_CLI::success( sprintf( 'Exported %d translation(s) to %s.', count( $rows ), $args[0] ) );
		}
	}

	/**
	 * Imports translations from a CSV file produced by the export command.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file to import.
	 *
	 * [--dry-run]
	 * : Report the number of rows without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htbd import translations.csv --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $args[0] ) || ! is_readable( $args[0] ) ) {
			WP_CLI::error( 'Provide a readable CSV file to import.' );
		}

		$handle = fopen( $args[0], 'r' );

		if ( false === $handle ) {
			WP_CLI::error( 'Unable to open the CSV file.' );
		}

		$header    = fgetcsv( $handle );
		$required  = array( 'identity_key', 'source_language', 'target_language', 'field_context', 'source_fingerprint', 'translated_value', 'status' );
		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$store     = new HTBD_Translation_Store();
		$imported  = 0;
		$skipped   = 0;

		if ( ! is_array( $header ) || array_diff( $required, $header ) ) {
			fclose( $handle );
			WP_CLI::error( 'The CSV header does not match the export format.' );
		}

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( count( $row ) !== count( $header ) ) {
				++$skipped;
				continue;
			}

			$data = array_combine( $header, $row );

			if ( '' === trim( (string) $data['identity_key'] ) || '' === trim( (string) $data['translated_value'] ) ) {
				++$skipped;
				continue;
			}

			if ( ! $dry_run ) {
				$store->save(
					sanitize_text_field( $data['identity_key'] ),
					sanitize_key( $data['source_language'] ),
					sanitize_key( $data['target_language'] ),
					sanitize_text_field( $data['field_context'] ),
					sanitize_text_field( $data['source_fingerprint'] ),
					$data['translated_value'],
					'manual' === $data['status'] ? 'manual' : 'complete'
				);
			}

			++$imported;
		}

		fclose( $handle );

		WP_CLI::success(
			sprintf(
				'%d row(s) %s, %d row(s) skipped.',
				$imported,
				$dry_run ? 'would be imported' : 'imported',
				$skipped
			)
		);
	}

	/**
	 * @param array $assoc_args Command arguments.
	 * @return string[]
	 */
	private function target_languages( $assoc_args ) {
		if ( isset( $assoc_args['language'] ) ) {
			$language = sanitize_key( $assoc_args['language'] );

			if ( ! HTBD_Settings::is_supported_language( $language ) ) {
				WP_CLI::error( sprintf( 'The language "%s" is not configured.', $language ) );
			}

			return array( $language );
		}

		$languages = HTBD_Settings::target_languages();

		if ( empty( $languages ) ) {
			WP_CLI::error( 'No target languages are configured.' );
		}

		return $languages;
	}

	/**
	 * @param array $assoc_args Command arguments.
	 * @return int[]
	 */
	private function collect_post_ids( $assoc_args ) {
		if ( isset( $assoc_args['post_id'] ) ) {
			$post_id = absint( $assoc_args['post_id'] );

			return $post_id ? array( $post_id ) : array();
		}

		$query_args = array(
			'post_type'      => isset( $assoc_args['post_type'] ) ? array( sanitize_key( $assoc_args['post_type'] ) ) : HTBD_Settings::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : -1,
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $query_args );

		return is_array( $post_ids ) ? $post_ids : array();
	}

	private function content_controller() {
		return new HTBD_Content_Controller( new HTBD_Translation_Store(), new HTBD_Language_Router() );
	}
}

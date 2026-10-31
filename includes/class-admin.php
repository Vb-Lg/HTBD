<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Admin {
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_htbd_queue_existing_translations', array( $this, 'queue_existing_translations' ) );
		add_action( 'admin_post_htbd_clear_translation_cache', array( $this, 'clear_translation_cache' ) );
		add_action( 'admin_post_htbd_translate_post', array( $this, 'queue_post_translation' ) );
		add_action( 'admin_post_htbd_retry_failed_translation', array( $this, 'retry_failed_translation' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_translation_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_translation_meta_box' ), 30, 3 );
		add_action( 'wp_ajax_htbd_translation_progress', array( $this, 'translation_progress' ) );
		add_action( 'init', array( $this, 'register_post_type_hooks' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( HTBD_FILE ), array( $this, 'add_plugin_settings_link' ) );
	}

	/**
	 * Registers the list table column once every plugin has declared its post
	 * types, so custom post types are visible at this point.
	 *
	 * @return void
	 */
	public function register_post_type_hooks() {
		foreach ( HTBD_Settings::post_types() as $post_type ) {
			add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_translation_column' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_translation_column' ), 10, 2 );
		}
	}

	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_htbd' !== $screen->id ) {
			return;
		}

		if ( isset( $_GET['htbd_queued'] ) ) {
			$queued  = absint( $_GET['htbd_queued'] );
			$message = sprintf(
				/* translators: %s: number of queued translation tasks. */
				_n( '%s translation task has been queued.', '%s translation tasks have been queued.', $queued, 'hyx-translator-for-baidu-translate' ),
				number_format_i18n( $queued )
			);

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( isset( $_GET['htbd_cache_cleared'] ) ) {
			$cleared = sanitize_key( wp_unslash( $_GET['htbd_cache_cleared'] ) );
			$message = '1' === $cleared
				? __( 'All saved translations have been cleared.', 'hyx-translator-for-baidu-translate' )
				: sprintf(
					/* translators: %s: target language code. */
					__( 'Saved %s translations have been cleared.', 'hyx-translator-for-baidu-translate' ),
					strtoupper( $cleared )
				);

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	public function add_translation_meta_box() {
		foreach ( HTBD_Settings::post_types() as $post_type ) {
			add_meta_box( 'htbd-translation-editor', __( 'Manual translations', 'hyx-translator-for-baidu-translate' ), array( $this, 'render_translation_meta_box' ), $post_type, 'normal', 'default' );
		}
	}

	public function render_translation_meta_box( $post ) {
		wp_nonce_field( 'htbd_save_translation_' . $post->ID, 'htbd_translation_nonce' );
		$fields = $this->get_post_translation_fields( $post );
		if ( empty( $fields ) ) {
			echo '<p>' . esc_html__( 'Generate a translation first, then edit it here.', 'hyx-translator-for-baidu-translate' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Changes are saved as manual translations and remain until you explicitly retranslate this content.', 'hyx-translator-for-baidu-translate' ) . '</p>';
		foreach ( $fields as $language => $language_fields ) {
			echo '<h4>' . esc_html( strtoupper( $language ) ) . '</h4>';
			foreach ( $language_fields as $field ) {
				$input_id = 'htbd-translation-' . md5( $field['context'] );
				echo '<p><label for="' . esc_attr( $input_id ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><textarea class="widefat" rows="' . esc_attr( $field['rows'] ) . '" id="' . esc_attr( $input_id ) . '" name="htbd_translation[' . esc_attr( $language ) . '][' . esc_attr( $field['context'] ) . ']">' . esc_textarea( $field['translated_value'] ) . '</textarea></p>';
			}
		}
	}

	public function save_translation_meta_box( $post_id, $post, $update ) {
		if ( ! $update || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! HTBD_Settings::is_translatable_post_type( $post->post_type ) || ! current_user_can( 'edit_post', $post_id ) || empty( $_POST['htbd_translation_nonce'] ) ) {
			return;
		}
		check_admin_referer( 'htbd_save_translation_' . $post_id, 'htbd_translation_nonce' );
		$submitted = isset( $_POST['htbd_translation'] ) ? (array) wp_unslash( $_POST['htbd_translation'] ) : array();
		$allowed   = $this->get_post_translation_fields( $post );
		$settings  = HTBD_Settings::get();
		$provider  = new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] );
		$store     = new HTBD_Translation_Store();
		foreach ( $allowed as $language => $language_fields ) {
			foreach ( $language_fields as $field ) {
				if ( ! isset( $submitted[ $language ][ $field['context'] ] ) || ! is_string( $submitted[ $language ][ $field['context'] ] ) ) {
					continue;
				}
				$value = 'post_content' === $field['field'] ? wp_kses_post( $submitted[ $language ][ $field['context'] ] ) : sanitize_textarea_field( $submitted[ $language ][ $field['context'] ] );
				if ( '' === trim( $value ) ) {
					continue;
				}
				$identity = HTBD_Translation_Identity::key( $field['source_value'], $settings['source_language'], $language, $field['context'], $provider->get_version() );
				$store->save_manual( $identity, $settings['source_language'], $language, $field['context'], HTBD_Translation_Identity::source_fingerprint( $field['source_value'] ), $value );
			}
		}
	}

	private function get_post_translation_fields( $post ) {
		$settings = HTBD_Settings::get();
		$definitions = array(
			'post_title'   => array( 'label' => __( 'Title', 'hyx-translator-for-baidu-translate' ), 'rows' => 2, 'source' => $post->post_title ),
			'post_excerpt' => array( 'label' => __( 'Excerpt', 'hyx-translator-for-baidu-translate' ), 'rows' => 3, 'source' => $post->post_excerpt ),
		);
		if ( '' !== $post->post_content ) {
			$definitions['post_content'] = array( 'label' => __( 'Content', 'hyx-translator-for-baidu-translate' ), 'rows' => 3, 'source' => $post->post_content );
		}
		foreach ( array( 'wpai_meta_description', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description' ) as $meta_key ) {
			$meta_value = get_post_meta( $post->ID, $meta_key, true );
			if ( is_string( $meta_value ) && '' !== $meta_value ) {
				$definitions[ 'meta:' . $meta_key ] = array( 'label' => $meta_key, 'rows' => 3, 'source' => $meta_value );
			}
		}
		$result = array();
		$store = new HTBD_Translation_Store();
		$provider = new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] );
		foreach ( $settings['target_languages'] as $language ) {
			foreach ( $definitions as $field => $definition ) {
				$segments = 'post_content' === $field ? HTBD_Content_Translator::segments( $definition['source'] ) : array( $definition['source'] );
				$text_index = 0;
				foreach ( (array) $segments as $segment ) {
					if ( 'post_content' === $field ) {
						if ( HTBD_Content_Translator::is_tag( $segment ) || '' === trim( $segment ) ) { continue; }
						$source = HTBD_Content_Translator::surrounding_whitespace( $segment )['text'];
						$context = 'post:' . $post->ID . ':post_content:text:' . $text_index;
						++$text_index;
					} else {
						$source = $segment;
						$context = 'post:' . $post->ID . ':' . $field;
					}
					if ( '' === trim( $source ) ) { continue; }
					$identity = HTBD_Translation_Identity::key( $source, $settings['source_language'], $language, $context, $provider->get_version() );
					$translation = $store->find_valid( $identity );
					if ( empty( $translation['translated_value'] ) ) { continue; }
					$result[ $language ][] = array( 'field' => $field, 'context' => $context, 'source_value' => $source, 'translated_value' => $translation['translated_value'], 'label' => $definition['label'] . ( 'post_content' === $field ? ' #' . $text_index : '' ), 'rows' => $definition['rows'] );
				}
			}
		}
		return $result;
	}

	public function add_settings_page() {
		add_options_page( __( 'HTBD', 'hyx-translator-for-baidu-translate' ), __( 'HTBD', 'hyx-translator-for-baidu-translate' ), 'manage_options', 'htbd', array( $this, 'render_settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'htbd_settings', 'htbd_settings', array( $this, 'sanitize_settings' ) );
	}

	public function enqueue_settings_assets( $hook_suffix ) {
		if ( 'settings_page_htbd' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'htbd-admin',
			plugins_url( 'assets/js/admin.js', HTBD_FILE ),
			array(),
			HTBD_VERSION,
			true
		);
		wp_localize_script(
			'htbd-admin',
			'htbdAdmin',
			array(
				'progressUrl'   => admin_url( 'admin-ajax.php' ),
				'progressNonce' => wp_create_nonce( 'htbd_translation_progress' ),
				'i18n'          => array(
					/* translators: 1: translated content item count, 2: total content item count. */
					'contentItems'  => __( '(%1$s / %2$s content items)', 'hyx-translator-for-baidu-translate' ),
					/* translators: 1: latest task status, 2: translated post/page count, 3: total post/page count, 4: translated category/tag count, 5: total category/tag count. */
					'latestTask'    => __( 'Latest task: %1$s. Posts and pages: %2$s / %3$s; categories and tags: %4$s / %5$s. Updates automatically every 5 seconds.', 'hyx-translator-for-baidu-translate' ),
					'failedItems'   => __( 'Failed translations', 'hyx-translator-for-baidu-translate' ),
					'retranslate'   => __( 'Retranslate', 'hyx-translator-for-baidu-translate' ),
					'progressError' => __( 'Unable to load translation progress at this time.', 'hyx-translator-for-baidu-translate' ),
				),
			)
		);
	}

	public function sanitize_settings( $settings ) {
		$settings                    = (array) $settings;
		$bindings                    = array();
		$auto_detect_source_language = ! empty( $settings['auto_detect_source_language'] );
		$routing_modes               = HTBD_Settings::routing_modes( $settings['routing_mode'] ?? array() );
		$target_languages            = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $settings['target_languages'] ?? 'en' ) ) ) ) );
		$post_types                  = $this->sanitize_choices( $settings['post_types'] ?? '', array_keys( HTBD_Settings::available_post_types() ) );
		$taxonomies                  = $this->sanitize_choices( $settings['taxonomies'] ?? '', array_keys( HTBD_Settings::available_taxonomies() ) );

		if ( empty( $target_languages ) ) {
			add_settings_error( 'htbd_settings', 'htbd_target_languages', __( 'Add at least one target language.', 'hyx-translator-for-baidu-translate' ) );
			$target_languages = HTBD_Settings::defaults()['target_languages'];
		}

		if ( empty( $routing_modes ) ) {
			add_settings_error( 'htbd_settings', 'htbd_routing_mode', __( 'Select at least one URL mode.', 'hyx-translator-for-baidu-translate' ) );
			$routing_modes = HTBD_Settings::defaults()['routing_mode'];
		}

		if ( empty( $post_types ) ) {
			add_settings_error( 'htbd_settings', 'htbd_post_types', __( 'Select at least one post type to translate.', 'hyx-translator-for-baidu-translate' ) );
			$post_types = HTBD_Settings::DEFAULT_POST_TYPES;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $settings['domain_bindings'] ?? '' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			$domain = 2 === count( $parts ) ? HTBD_Settings::normalize_domain( $parts[0] ) : '';
			$language = 2 === count( $parts ) ? sanitize_key( $parts[1] ) : '';
			if ( '' !== $domain && in_array( $language, $target_languages, true ) && ! in_array( $language, $bindings, true ) ) {
				$bindings[ $domain ] = $language;
			}
		}

		return array(
			'auto_detect_source_language' => $auto_detect_source_language,
			'source_language'            => $auto_detect_source_language ? 'auto' : sanitize_key( $settings['source_language'] ?? 'zh' ),
			'target_languages'           => $target_languages,
			'routing_mode'               => $routing_modes,
			'domain_bindings'            => $bindings,
			'fallback_language'          => sanitize_key( $settings['fallback_language'] ?? 'zh' ),
			'post_types'                 => $post_types,
			'taxonomies'                 => $taxonomies,
			'request_interval'           => min( 5000, absint( $settings['request_interval'] ?? 1000 ) ),
			'baidu_app_id'               => sanitize_text_field( $settings['baidu_app_id'] ?? '' ),
			'baidu_secret_key'           => sanitize_text_field( $settings['baidu_secret_key'] ?? '' ),
			'log_requests'               => ! empty( $settings['log_requests'] ),
		);
	}

	/**
	 * Parses a comma separated list and keeps only the allowed values.
	 *
	 * @param string   $value     Raw field value.
	 * @param string[] $available Allowed values.
	 * @return string[]
	 */
	private function sanitize_choices( $value, $available ) {
		$chosen = array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) );

		return array_values( array_unique( array_intersect( $chosen, $available ) ) );
	}

	public function render_settings_page() {
		$settings = HTBD_Settings::get();
		$bindings = array();
		foreach ( (array) $settings['domain_bindings'] as $domain => $language ) {
			$bindings[] = $domain . '=' . $language;
		}
		?>
		<div class="wrap">
			<h1>HTBD</h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'htbd_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Source language detection', 'hyx-translator-for-baidu-translate' ); ?></th><td><label for="htbd-auto-detect-source-language"><input id="htbd-auto-detect-source-language" name="htbd_settings[auto_detect_source_language]" type="checkbox" value="1" <?php checked( $settings['auto_detect_source_language'] ); ?> /> <?php esc_html_e( 'Automatically detect the source language', 'hyx-translator-for-baidu-translate' ); ?></label></td></tr>
					<tr id="htbd-source-language-row"<?php echo $settings['auto_detect_source_language'] ? ' style="display:none"' : ''; ?>><th scope="row"><label for="htbd-source-language"><?php esc_html_e( 'Source language', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-source-language" name="htbd_settings[source_language]" type="text" value="<?php echo esc_attr( $settings['source_language'] ); ?>" /><p class="description"><?php esc_html_e( 'Use a Baidu Translate language code, for example zh.', 'hyx-translator-for-baidu-translate' ); ?></p></td></tr>
					<tr><th scope="row"><label for="htbd-target-languages"><?php esc_html_e( 'Target languages', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-target-languages" name="htbd_settings[target_languages]" type="text" value="<?php echo esc_attr( implode( ',', $settings['target_languages'] ) ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Separate multiple language codes with commas, for example en,jp.', 'hyx-translator-for-baidu-translate' ); ?></p></td></tr>
					<tr><th scope="row"><label for="htbd-post-types"><?php esc_html_e( 'Post types', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-post-types" name="htbd_settings[post_types]" type="text" value="<?php echo esc_attr( implode( ',', HTBD_Settings::post_types() ) ); ?>" class="regular-text" /><p class="description"><?php echo esc_html( sprintf( /* translators: %s: comma separated list of available post type names. */ __( 'Comma separated post type names, for example post,page. Available post types: %s.', 'hyx-translator-for-baidu-translate' ), implode( ', ', array_keys( HTBD_Settings::available_post_types() ) ) ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="htbd-taxonomies"><?php esc_html_e( 'Taxonomies', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-taxonomies" name="htbd_settings[taxonomies]" type="text" value="<?php echo esc_attr( implode( ',', HTBD_Settings::taxonomies() ) ); ?>" class="regular-text" /><p class="description"><?php echo esc_html( sprintf( /* translators: %s: comma separated list of available taxonomy names. */ __( 'Comma separated taxonomy names, for example category,post_tag. Available taxonomies: %s.', 'hyx-translator-for-baidu-translate' ), implode( ', ', array_keys( HTBD_Settings::available_taxonomies() ) ) ) ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'URL mode', 'hyx-translator-for-baidu-translate' ); ?></th><td><fieldset id="htbd-routing-mode"><label><input type="checkbox" name="htbd_settings[routing_mode][]" value="subdirectory" <?php checked( in_array( 'subdirectory', $settings['routing_mode'], true ) ); ?> /> <?php esc_html_e( 'Subdirectory', 'hyx-translator-for-baidu-translate' ); ?></label><br /><label><input type="checkbox" name="htbd_settings[routing_mode][]" value="domain" <?php checked( in_array( 'domain', $settings['routing_mode'], true ) ); ?> /> <?php esc_html_e( 'Domain', 'hyx-translator-for-baidu-translate' ); ?></label></fieldset></td></tr>
					<tr id="htbd-domain-bindings-row"<?php echo in_array( 'domain', $settings['routing_mode'], true ) ? '' : ' style="display:none"'; ?>><th scope="row"><label for="htbd-domain-bindings"><?php esc_html_e( 'Domain bindings', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><textarea id="htbd-domain-bindings" name="htbd_settings[domain_bindings]" rows="4" class="large-text" placeholder="en.example.com=en"><?php echo esc_textarea( implode( "\n", $bindings ) ); ?></textarea><p class="description"><?php esc_html_e( 'Enter one domain=language code pair per line, for example en.example.com=en. Do not include https://, a path, or a port.', 'hyx-translator-for-baidu-translate' ); ?></p></td></tr>
					<tr><th scope="row"><label for="htbd-baidu-app-id"><?php esc_html_e( 'Baidu application ID', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-baidu-app-id" name="htbd_settings[baidu_app_id]" type="text" value="<?php echo esc_attr( $settings['baidu_app_id'] ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="htbd-baidu-secret-key"><?php esc_html_e( 'Baidu secret key', 'hyx-translator-for-baidu-translate' ); ?></label></th><td><input id="htbd-baidu-secret-key" name="htbd_settings[baidu_secret_key]" type="password" value="<?php echo esc_attr( $settings['baidu_secret_key'] ); ?>" class="regular-text" autocomplete="new-password" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Request logging', 'hyx-translator-for-baidu-translate' ); ?></th><td><label for="htbd-log-requests"><input id="htbd-log-requests" name="htbd_settings[log_requests]" type="checkbox" value="1" <?php checked( $settings['log_requests'] ); ?> /> <?php esc_html_e( 'Log each Baidu Translate request', 'hyx-translator-for-baidu-translate' ); ?></label><p class="description"><?php esc_html_e( 'When enabled, fires the htbd_translation_request_logged action with the language, field, text fingerprint, length, and result status. Credentials, source text, translated text, and full API responses are never included.', 'hyx-translator-for-baidu-translate' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'After saving settings for the first time, manually run "Retranslate all content" below.', 'hyx-translator-for-baidu-translate' ); ?></strong></p>
				</div>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Retranslate content', 'hyx-translator-for-baidu-translate' ); ?></h2>
			<p><?php esc_html_e( 'Queue all published posts, pages, categories, and tags for translation again.', 'hyx-translator-for-baidu-translate' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all content and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
				<input type="hidden" name="action" value="htbd_queue_existing_translations" />
				<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
				<?php submit_button( __( 'Retranslate all content', 'hyx-translator-for-baidu-translate' ), 'secondary', 'submit', false ); ?>
			</form>
			<p><?php esc_html_e( 'You can also retranslate a single content type.', 'hyx-translator-for-baidu-translate' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-right:8px" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all posts and pages and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
				<input type="hidden" name="action" value="htbd_queue_existing_translations" />
				<input type="hidden" name="scope" value="posts" />
				<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
				<?php submit_button( __( 'Translate all posts', 'hyx-translator-for-baidu-translate' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all categories and tags and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
				<input type="hidden" name="action" value="htbd_queue_existing_translations" />
				<input type="hidden" name="scope" value="terms" />
				<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
				<?php submit_button( __( 'Translate all categories and tags', 'hyx-translator-for-baidu-translate' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( count( $settings['target_languages'] ) > 1 ) : ?>
				<?php foreach ( $settings['target_languages'] as $target_language ) : ?>
					<hr />
					<h3><?php echo esc_html( strtoupper( $target_language ) ); ?></h3>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all content and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
						<input type="hidden" name="action" value="htbd_queue_existing_translations" />
						<input type="hidden" name="language" value="<?php echo esc_attr( $target_language ); ?>" />
						<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
						<?php submit_button( sprintf( '%s %s', $target_language, __( 'Retranslate all content', 'hyx-translator-for-baidu-translate' ) ), 'secondary', 'submit', false ); ?>
					</form>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-right:8px" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all posts and pages and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
						<input type="hidden" name="action" value="htbd_queue_existing_translations" />
						<input type="hidden" name="scope" value="posts" />
						<input type="hidden" name="language" value="<?php echo esc_attr( $target_language ); ?>" />
						<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
						<?php submit_button( sprintf( '%s %s', $target_language, __( 'Translate all posts', 'hyx-translator-for-baidu-translate' ) ), 'secondary', 'submit', false ); ?>
					</form>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block" data-htbd-confirm="<?php echo esc_attr__( 'This will retranslate all categories and tags and use your API quota. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
						<input type="hidden" name="action" value="htbd_queue_existing_translations" />
						<input type="hidden" name="scope" value="terms" />
						<input type="hidden" name="language" value="<?php echo esc_attr( $target_language ); ?>" />
						<?php wp_nonce_field( 'htbd_queue_existing_translations' ); ?>
						<?php submit_button( sprintf( '%s %s', $target_language, __( 'Translate all categories and tags', 'hyx-translator-for-baidu-translate' ) ), 'secondary', 'submit', false ); ?>
					</form>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-htbd-confirm="<?php echo esc_attr( sprintf( /* translators: %s: target language code. */ __( 'This will delete all saved %s translations. Continue?', 'hyx-translator-for-baidu-translate' ), $target_language ) ); ?>">
						<input type="hidden" name="action" value="htbd_clear_translation_cache" />
						<input type="hidden" name="language" value="<?php echo esc_attr( $target_language ); ?>" />
						<?php wp_nonce_field( 'htbd_clear_translation_cache_' . $target_language ); ?>
						<?php submit_button( sprintf( '%s %s', $target_language, __( 'Clear translation cache', 'hyx-translator-for-baidu-translate' ) ), 'delete', 'submit', false ); ?>
					</form>
				<?php endforeach; ?>
			<?php endif; ?>
			<hr />
			<h2><?php esc_html_e( 'Translation cache', 'hyx-translator-for-baidu-translate' ); ?></h2>
			<p><?php esc_html_e( 'After clearing the cache, the front end temporarily displays source content. Baidu Translate API calls resume when content is translated again.', 'hyx-translator-for-baidu-translate' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-htbd-confirm="<?php echo esc_attr__( 'This will delete all saved translations, cancel pending translation tasks, and reset progress. Continue?', 'hyx-translator-for-baidu-translate' ); ?>">
				<input type="hidden" name="action" value="htbd_clear_translation_cache" />
				<?php wp_nonce_field( 'htbd_clear_translation_cache' ); ?>
				<?php submit_button( __( 'Clear translation cache', 'hyx-translator-for-baidu-translate' ), 'delete', 'submit', false ); ?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Translation progress', 'hyx-translator-for-baidu-translate' ); ?></h2>
			<div id="htbd-translation-progress" aria-live="polite">
				<p><?php esc_html_e( 'Loading translation progress...', 'hyx-translator-for-baidu-translate' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function queue_existing_translations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'hyx-translator-for-baidu-translate' ) );
		}

		check_admin_referer( 'htbd_queue_existing_translations' );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'posts', 'terms' ), true ) ) {
			wp_die( esc_html__( 'Invalid translation scope.', 'hyx-translator-for-baidu-translate' ) );
		}
		$target_language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		$languages       = HTBD_Settings::target_languages();
		if ( '' !== $target_language ) {
			if ( ! in_array( $target_language, $languages, true ) ) {
				wp_die( esc_html__( 'Invalid translation request.', 'hyx-translator-for-baidu-translate' ) );
			}
			$languages = array( $target_language );
		}

		$scheduled = 0;
		$next_run  = time();
		$post_ids  = array();
		$term_ids  = array();

		if ( 'all' === $scope || 'posts' === $scope ) {
			$post_ids = get_posts(
				array(
					'post_type'      => HTBD_Settings::post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

		}

		if ( 'all' === $scope || 'terms' === $scope ) {
			$term_ids = get_terms(
				array(
					'taxonomy'   => HTBD_Settings::taxonomies(),
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( is_wp_error( $term_ids ) ) {
				$term_ids = array();
			}
		}

		$this->update_translation_task( $post_ids, $term_ids, $languages );

		foreach ( $post_ids as $post_id ) {
			foreach ( $languages as $target_language ) {
				HTBD_Cron::clear_post( $post_id, $target_language );
				HTBD_Cron::schedule_post( $post_id, $target_language, true, $next_run );
				$next_run += 2;
				++$scheduled;
			}
		}

		foreach ( $term_ids as $term_id ) {
			foreach ( $languages as $target_language ) {
				HTBD_Cron::clear_term( $term_id, $target_language );
				HTBD_Cron::schedule_term( $term_id, $target_language, true, $next_run );
				$next_run += 2;
				++$scheduled;
			}
		}

		wp_safe_redirect( add_query_arg( 'htbd_queued', $scheduled, admin_url( 'options-general.php?page=htbd' ) ) );
		exit;
	}

	public function clear_translation_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'hyx-translator-for-baidu-translate' ) );
		}

		$target_language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		if ( '' !== $target_language && ! in_array( $target_language, HTBD_Settings::target_languages(), true ) ) {
			wp_die( esc_html__( 'Invalid translation request.', 'hyx-translator-for-baidu-translate' ) );
		}

		check_admin_referer( 'htbd_clear_translation_cache' . ( $target_language ? '_' . $target_language : '' ) );
		$deleted = ( new HTBD_Translation_Store() )->clear( $target_language );

		if ( false === $deleted ) {
			wp_die( esc_html__( 'Failed to clear the translation cache.', 'hyx-translator-for-baidu-translate' ) );
		}

		if ( '' === $target_language ) {
			HTBD_Cron::clear_hooks();
			delete_option( 'htbd_translation_task' );
			delete_option( 'htbd_retranslation_batch' );
		}

		wp_safe_redirect( add_query_arg( 'htbd_cache_cleared', $target_language ? $target_language : 1, admin_url( 'options-general.php?page=htbd' ) ) );
		exit;
	}

	public function add_plugin_settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=htbd' ) ) . '">' . esc_html__( 'Settings', 'hyx-translator-for-baidu-translate' ) . '</a>' );

		return $links;
	}

	public function translation_progress() {
		check_ajax_referer( 'htbd_translation_progress' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$task      = (array) get_option( 'htbd_translation_task', array() );
		$post_ids  = array_values( array_filter( array_map( 'absint', (array) ( $task['post_ids'] ?? array() ) ) ) );
		$term_ids  = array_values( array_filter( array_map( 'absint', (array) ( $task['term_ids'] ?? array() ) ) ) );
		$languages = array_values( array_filter( array_map( 'sanitize_key', (array) ( $task['target_languages'] ?? array() ) ) ) );
		$started_at = isset( $task['started_at'] ) ? sanitize_text_field( $task['started_at'] ) : '';
		$store      = new HTBD_Translation_Store();
		$counts     = $store->get_completed_item_counts( $languages, $post_ids, $term_ids, $started_at );
		$failures   = $this->prepare_failed_items( $store->get_failed_items( $languages, $post_ids, $term_ids, $started_at ) );

		$posts_total      = count( $post_ids ) * count( $languages );
		$terms_total      = count( $term_ids ) * count( $languages );
		$total            = $posts_total + $terms_total;
		$posts_completed  = min( $counts['posts'], $posts_total );
		$terms_completed  = min( $counts['terms'], $terms_total );
		$completed        = $posts_completed + $terms_completed;
		$percent          = $total ? (int) floor( ( $completed / $total ) * 100 ) : 100;

		wp_send_json_success(
			array(
				'posts_completed' => $posts_completed,
				'posts_total'     => $posts_total,
				'terms_completed' => $terms_completed,
				'terms_total'     => $terms_total,
				'completed'       => $completed,
				'total'           => $total,
				'percent'         => $percent,
				'batch_started_at' => $started_at,
				'task_label'      => $this->translation_task_label( $post_ids, $term_ids ),
				'failed_items'    => $failures,
			)
		);
	}

	public function add_translation_column( $columns ) {
		$columns['htbd_translation'] = __( 'Translation status', 'hyx-translator-for-baidu-translate' );

		return $columns;
	}

	public function render_translation_column( $column, $post_id ) {
		if ( 'htbd_translation' !== $column ) {
			return;
		}

		$store = new HTBD_Translation_Store();

		foreach ( HTBD_Settings::target_languages() as $target_language ) {
			$status      = $store->get_post_language_status( $post_id, $target_language );
			$translated  = ! empty( $status['translated_fields'] );
			$button_text = $translated ? __( 'Retranslate', 'hyx-translator-for-baidu-translate' ) : __( 'Translate', 'hyx-translator-for-baidu-translate' );
			$action_url  = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'htbd_translate_post',
						'post_id'  => $post_id,
						'language' => $target_language,
						'force'    => $translated ? '1' : '0',
					),
					admin_url( 'admin-post.php' )
				),
				'htbd_translate_post_' . $post_id . '_' . $target_language
			);

			echo '<p><strong>' . esc_html( strtoupper( $target_language ) ) . '</strong>: ';
			echo $translated ? '<span style="color:#008a20">' . esc_html__( 'Translated', 'hyx-translator-for-baidu-translate' ) . '</span>' : '<span>' . esc_html__( 'Not translated', 'hyx-translator-for-baidu-translate' ) . '</span>';
			if ( $translated && ! empty( $status['last_translated_at'] ) ) {
				echo '<br><small>' . esc_html__( 'Last translated:', 'hyx-translator-for-baidu-translate' ) . ' ' . esc_html( get_date_from_gmt( $status['last_translated_at'], 'Y-m-d H:i' ) ) . '</small>';
			}
			echo '<br><a class="button button-small" href="' . esc_url( $action_url ) . '">' . esc_html( $button_text ) . '</a></p>';
		}
	}

	public function queue_post_translation() {
		$post_id         = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$target_language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
		$force_refresh   = ! empty( $_GET['force'] );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! HTBD_Settings::is_supported_language( $target_language ) || $target_language === HTBD_Settings::get()['source_language'] ) {
			wp_die( esc_html__( 'Invalid translation request.', 'hyx-translator-for-baidu-translate' ) );
		}

		check_admin_referer( 'htbd_translate_post_' . $post_id . '_' . $target_language );
		$this->update_translation_task( array( $post_id ), array(), array( $target_language ) );
		HTBD_Cron::clear_post( $post_id, $target_language );
		HTBD_Cron::schedule_post( $post_id, $target_language, $force_refresh, time() + 5 );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) ) );
		exit;
	}

	public function retry_failed_translation() {
		$item_type       = isset( $_GET['item_type'] ) ? sanitize_key( wp_unslash( $_GET['item_type'] ) ) : '';
		$item_id         = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;
		$target_language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) || ! $item_id || ! in_array( $item_type, array( 'post', 'term' ), true ) || ! in_array( $target_language, HTBD_Settings::target_languages(), true ) ) {
			wp_die( esc_html__( 'Invalid translation request.', 'hyx-translator-for-baidu-translate' ) );
		}

		check_admin_referer( 'htbd_retry_failed_translation_' . $item_type . '_' . $item_id . '_' . $target_language );

		if ( 'post' === $item_type ) {
			HTBD_Cron::clear_post( $item_id, $target_language );
			HTBD_Cron::schedule_post( $item_id, $target_language, true, time() + 5 );
		} else {
			HTBD_Cron::clear_term( $item_id, $target_language );
			HTBD_Cron::schedule_term( $item_id, $target_language, true, time() + 5 );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=htbd' ) );
		exit;
	}

	private function prepare_failed_items( $failures ) {
		$items = array();

		foreach ( (array) $failures as $failure ) {
			$context_parts  = explode( ':', $failure['item_context'], 2 );
			$item_type      = $context_parts[0] ?? '';
			$item_id        = isset( $context_parts[1] ) ? absint( $context_parts[1] ) : 0;
			$target_language = sanitize_key( $failure['target_language'] ?? '' );

			if ( ! $item_id || ! in_array( $item_type, array( 'post', 'term' ), true ) || '' === $target_language ) {
				continue;
			}

			if ( 'post' === $item_type ) {
				$name = get_the_title( $item_id );
			} else {
				$term = get_term( $item_id );
				$name = $term && ! is_wp_error( $term ) ? $term->name : '';
			}

			if ( '' === $name ) {
				/* translators: %d: content item ID. */
				$name = sprintf( __( 'Content #%d', 'hyx-translator-for-baidu-translate' ), $item_id );
			}

			$retry_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'htbd_retry_failed_translation',
						'item_type' => $item_type,
						'item_id'   => $item_id,
						'language'  => $target_language,
					),
					admin_url( 'admin-post.php' )
				),
				'htbd_retry_failed_translation_' . $item_type . '_' . $item_id . '_' . $target_language
			);

			$items[] = array(
				'name'      => $name,
				'language'  => strtoupper( $target_language ),
				'retry_url' => $retry_url,
			);
		}

		return $items;
	}

	private function update_translation_task( $post_ids, $term_ids, $target_languages ) {
		update_option(
			'htbd_translation_task',
			array(
				'started_at'       => current_time( 'mysql', true ),
				'post_ids'         => array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) ),
				'term_ids'         => array_values( array_filter( array_map( 'absint', (array) $term_ids ) ) ),
				'target_languages' => array_values( array_filter( array_map( 'sanitize_key', (array) $target_languages ) ) ),
			),
			false
		);
	}

	private function translation_task_label( $post_ids, $term_ids ) {
		if ( ! empty( $post_ids ) && ! empty( $term_ids ) ) {
			return __( 'Full translation', 'hyx-translator-for-baidu-translate' );
		}

		if ( 1 === count( $post_ids ) ) {
			return __( 'Single post translation', 'hyx-translator-for-baidu-translate' );
		}

		if ( ! empty( $post_ids ) ) {
			return __( 'Posts and pages translation', 'hyx-translator-for-baidu-translate' );
		}

		if ( ! empty( $term_ids ) ) {
			return __( 'Categories and tags translation', 'hyx-translator-for-baidu-translate' );
		}

		return __( 'No translation task', 'hyx-translator-for-baidu-translate' );
	}
}
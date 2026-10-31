<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Content_Controller {
	private $store;
	private $router;

	public function __construct( HTBD_Translation_Store $store, HTBD_Language_Router $router ) {
		$this->store  = $store;
		$this->router = $router;
	}

	public function register() {
		add_action( 'save_post', array( $this, 'schedule_post_translation' ), 20, 3 );
		add_action( 'htbd_process_translation', array( $this, 'translate_post' ), 10, 3 );
		add_action( 'htbd_process_term_translation', array( $this, 'translate_term' ), 10, 3 );
		add_action( 'htbd_process_seo_output_translation', array( $this, 'translate_seo_output' ), 10, 3 );
		add_action( 'created_term', array( $this, 'schedule_term_translation' ), 20, 3 );
		add_action( 'edited_term', array( $this, 'schedule_term_translation' ), 20, 3 );
		add_filter( 'the_title', array( $this, 'translate_title' ), 20, 2 );
		add_filter( 'the_content', array( $this, 'translate_content' ), 1 );
		add_filter( 'get_the_excerpt', array( $this, 'translate_excerpt' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'translate_image_alt' ), 20, 2 );
		add_filter( 'single_term_title', array( $this, 'translate_term_title' ), 20, 2 );
		add_filter( 'term_description', array( $this, 'translate_term_description' ), 20, 3 );
		add_filter( 'document_title_parts', array( $this, 'translate_document_title_parts' ), 20 );
		add_filter( 'wpai_meta_description', array( $this, 'translate_wordpress_ai_meta_description' ), 20 );
		add_filter( 'get_post_metadata', array( $this, 'translate_wordpress_ai_meta_description_metadata' ), 20, 4 );
		add_filter( 'wpseo_title', array( $this, 'translate_seo_title' ), 20 );
		add_filter( 'wpseo_metadesc', array( $this, 'translate_seo_description' ), 20 );
		add_filter( 'rank_math/frontend/title', array( $this, 'translate_seo_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( $this, 'translate_seo_description' ), 20 );
		add_filter( 'aioseo_title', array( $this, 'translate_seo_title' ), 20 );
		add_filter( 'aioseo_description', array( $this, 'translate_seo_description' ), 20 );
		add_filter( 'seopress_titles_the_title', array( $this, 'translate_seo_title' ), 20 );
		add_filter( 'seopress_titles_the_description', array( $this, 'translate_seo_description' ), 20 );
		add_filter( 'post_link', array( $this, 'localize_permalink' ), 20 );
		add_filter( 'page_link', array( $this, 'localize_permalink' ), 20 );
		add_filter( 'post_type_link', array( $this, 'localize_permalink' ), 20 );
		add_filter( 'term_link', array( $this, 'localize_permalink' ), 20 );
		add_filter( 'get_term', array( $this, 'translate_term_object' ), 20, 3 );
	}

	public function schedule_post_translation( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status || ! HTBD_Settings::is_translatable_post_type( $post->post_type ) ) {
			return;
		}

		foreach ( HTBD_Settings::target_languages() as $target_language ) {
			if ( ! HTBD_Cron::is_post_scheduled( $post_id, $target_language ) ) {
				HTBD_Cron::schedule_post( $post_id, $target_language );
			}
		}
	}

	public function translate_post( $post_id, $target_language, $force_refresh = false ) {
		$post     = get_post( $post_id );
		$settings = HTBD_Settings::get();

		if ( ! $post || 'publish' !== $post->post_status || ! HTBD_Settings::is_supported_language( $target_language ) ) {
			return;
		}

		$service = new HTBD_Translation_Service(
			$this->store,
			new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] )
		);

		$fields = array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
		);
		$seo_meta_keys = array(
			'wpai_meta_description',
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'rank_math_title',
			'rank_math_description',
		);

		foreach ( $seo_meta_keys as $meta_key ) {
			$meta_value = get_post_meta( $post_id, $meta_key, true );
			if ( is_string( $meta_value ) && '' !== $meta_value ) {
				$fields[ 'meta:' . $meta_key ] = $meta_value;
			}
		}

		foreach ( $fields as $field => $value ) {
			if ( '' !== $value ) {
				$context = 'post:' . $post_id . ':' . $field;
				if ( 'post_content' === $field ) {
					( new HTBD_Content_Translator( $service ) )->translate( $value, $settings['source_language'], $target_language, $context, $force_refresh );
				} else {
					$service->get_or_translate( $value, $settings['source_language'], $target_language, $context, $force_refresh );
				}
			}
		}

		$this->translate_attachment_alt_text( $post_id, $service, $settings, $target_language, $force_refresh );
		$this->translate_terms( $post_id, $service, $settings, $target_language, $force_refresh );
	}

	public function schedule_term_translation( $term_id, $term_taxonomy_id, $taxonomy ) {
		if ( ! HTBD_Settings::is_translatable_taxonomy( $taxonomy ) ) {
			return;
		}

		foreach ( HTBD_Settings::target_languages() as $target_language ) {
			if ( ! HTBD_Cron::is_term_scheduled( $term_id, $target_language ) ) {
				HTBD_Cron::schedule_term( $term_id, $target_language );
			}
		}
	}

	public function translate_term( $term_id, $target_language, $force_refresh = false ) {
		$term     = get_term( $term_id );
		$settings = HTBD_Settings::get();

		if ( ! $term || is_wp_error( $term ) || ! HTBD_Settings::is_translatable_taxonomy( $term->taxonomy ) || ! HTBD_Settings::is_supported_language( $target_language ) ) {
			return;
		}

		$service = new HTBD_Translation_Service(
			$this->store,
			new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] )
		);

		if ( '' !== $term->name ) {
			$service->get_or_translate( $term->name, $settings['source_language'], $target_language, 'term:' . $term->term_id . ':name', $force_refresh );
		}
		if ( '' !== $term->description ) {
			$service->get_or_translate( $term->description, $settings['source_language'], $target_language, 'term:' . $term->term_id . ':description', $force_refresh );
		}
	}

	/**
	 * Translates a value that only exists as generated output, such as a
	 * description built by an SEO plugin. The value itself is handed over
	 * through a transient so it never has to be serialized into the cron
	 * option, where long descriptions would bloat the options table.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $payload_key Transient key holding the source value.
	 * @param string $context     Field context.
	 * @return void
	 */
	public function translate_seo_output( $post_id, $payload_key, $context ) {
		$payload = get_transient( (string) $payload_key );

		if ( ! is_array( $payload ) || empty( $payload['value'] ) ) {
			return;
		}

		$source_value    = (string) $payload['value'];
		$target_language = isset( $payload['language'] ) ? sanitize_key( $payload['language'] ) : '';
		$settings        = HTBD_Settings::get();

		if ( ! get_post( $post_id ) || ! HTBD_Settings::is_supported_language( $target_language ) || '' === $source_value ) {
			return;
		}

		delete_transient( (string) $payload_key );

		$service = new HTBD_Translation_Service(
			$this->store,
			new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] )
		);

		$service->get_or_translate( $source_value, $settings['source_language'], $target_language, $context );
	}

	public function translate_title( $title, $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id && is_singular() ) {
			$post_id = get_queried_object_id();
		}

		if ( ! $post_id ) {
			return $title;
		}

		$source_title = get_post_field( 'post_title', $post_id, 'raw' );
		if ( ! is_string( $source_title ) || '' === $source_title ) {
			return $title;
		}

		$translated_title = $this->translated_value( $source_title, 'post:' . $post_id . ':post_title' );

		return $translated_title !== $source_title ? esc_html( $translated_title ) : $title;
	}

	public function translate_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$segments = HTBD_Content_Translator::segments( $content );
		if ( false === $segments ) {
			return $content;
		}

		$text_index      = 0;
		$protected_depth = 0;
		foreach ( $segments as $index => $segment ) {
			if ( HTBD_Content_Translator::is_tag( $segment ) ) {
				$protected_depth = HTBD_Content_Translator::protected_depth_after_tag( $segment, $protected_depth );
				continue;
			}

			if ( 0 < $protected_depth || '' === trim( $segment ) ) {
				continue;
			}

			$whitespace = HTBD_Content_Translator::surrounding_whitespace( $segment );
			$translated = $this->escaped_translated_value( $whitespace['text'], 'post:' . $post_id . ':post_content:text:' . $text_index );
			$segments[ $index ] = $whitespace['leading'] . $translated . $whitespace['trailing'];
			++$text_index;
		}

		return implode( '', $segments );
	}

	public function translate_excerpt( $excerpt, $post ) {
		return $this->kses_translated_value( $excerpt, 'post:' . $post->ID . ':post_excerpt' );
	}

	public function translate_image_alt( $attributes, $attachment ) {
		if ( empty( $attributes['alt'] ) ) {
			return $attributes;
		}

		$attributes['alt'] = $this->translated_value( $attributes['alt'], 'attachment:' . $attachment->ID . ':alt' );

		return $attributes;
	}

	public function translate_term_title( $term_name, $term = null ) {
		$term_id = $term instanceof WP_Term ? (int) $term->term_id : (int) get_queried_object_id();

		if ( ! $term_id ) {
			return $term_name;
		}

		return $this->escaped_translated_value( $term_name, 'term:' . $term_id . ':name' );
	}

	public function translate_term_description( $description, $term_id, $taxonomy ) {
		return $this->kses_translated_value( $description, 'term:' . $term_id . ':description' );
	}

	public function translate_document_title_parts( $parts ) {
		if ( ! is_singular() || empty( $parts['title'] ) ) {
			return $parts;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $parts;
		}

		$parts['title'] = $this->translated_value( $parts['title'], 'post:' . $post_id . ':post_title' );

		return $parts;
	}

	public function translate_wordpress_ai_meta_description( $description ) {
		$post_id = get_queried_object_id();

		if ( ! $post_id || '' === $description ) {
			return $description;
		}

		return $this->translated_value( $description, 'post:' . $post_id . ':meta:wpai_meta_description' );
	}

	public function translate_wordpress_ai_meta_description_metadata( $value, $post_id, $meta_key, $single ) {
		static $resolving_raw_value = false;

		if ( 'wpai_meta_description' !== $meta_key || ! $single || $resolving_raw_value ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$source_value = $value;
		} else {
			$resolving_raw_value = true;
			try {
				$source_value = get_metadata_raw( 'post', $post_id, $meta_key, true );
			} finally {
				$resolving_raw_value = false;
			}
		}

		if ( ! is_string( $source_value ) ) {
			return $value;
		}

		return $this->translated_value( $source_value, 'post:' . $post_id . ':meta:wpai_meta_description' );
	}

	public function translate_term_object( $term, $taxonomy, $context = '' ) {
		if ( ! $term instanceof WP_Term || ! HTBD_Settings::is_translatable_taxonomy( $term->taxonomy ) || ! $this->is_render_context() ) {
			return $term;
		}

		$translated_term = clone $term;
		$translated_term->name = sanitize_text_field( $this->translated_value( $term->name, 'term:' . $term->term_id . ':name' ) );
		$translated_term->description = $this->kses_translated_value( $term->description, 'term:' . $term->term_id . ':description' );

		return $translated_term;
	}

	public function translate_seo_title( $value ) {
		return $this->translate_seo_value( $value, array( '_yoast_wpseo_title', 'rank_math_title', 'post_title' ) );
	}

	public function translate_seo_description( $value ) {
		return $this->translate_seo_value( $value, array( '_yoast_wpseo_metadesc', 'rank_math_description', 'post_excerpt', 'post_content' ) );
	}

	private function translate_seo_value( $value, $source_fields ) {
		$post_id = get_queried_object_id();

		if ( ! $post_id || '' === $value ) {
			return $value;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $value;
		}

		foreach ( $source_fields as $source_field ) {
			if ( 0 === strpos( $source_field, '_' ) || 0 === strpos( $source_field, 'rank_math_' ) ) {
				$source_value = get_post_meta( $post_id, $source_field, true );
				$context      = 'post:' . $post_id . ':meta:' . $source_field;
			} else {
				$source_value = $post->{$source_field};
				$context      = 'post:' . $post_id . ':' . $source_field;
			}

			if ( is_string( $source_value ) && '' !== $source_value && $value === $source_value ) {
				return $this->translated_value( $source_value, $context );
			}
		}

		return $this->translated_or_schedule_seo_output( $value, $post_id, in_array( 'post_content', $source_fields, true ) ? 'seo_description' : 'seo_title' );
	}

	public function localize_permalink( $url ) {
		return $this->router->localized_url( $url );
	}

	private function is_render_context() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return ! defined( 'WP_CLI' ) || ! WP_CLI;
	}

	private function translated_value( $source_value, $context ) {
		$settings        = HTBD_Settings::get();
		$target_language = $this->router->current_language();

		if ( $target_language === $settings['source_language'] || '' === $source_value ) {
			return $source_value;
		}

		$provider     = new HTBD_Baidu_Provider( $settings['baidu_app_id'], $settings['baidu_secret_key'] );
		$identity_key = HTBD_Translation_Identity::key( $source_value, $settings['source_language'], $target_language, $context, $provider->get_version() );
		$translation  = $this->store->find_valid( $identity_key );

		return ! empty( $translation['translated_value'] ) ? $translation['translated_value'] : $source_value;
	}

	private function escaped_translated_value( $source_value, $context ) {
		$translated_value = $this->translated_value( $source_value, $context );

		return esc_html( $translated_value );
	}

	private function kses_translated_value( $source_value, $context ) {
		$translated_value = $this->translated_value( $source_value, $context );

		return wp_kses_post( $translated_value );
	}

	private function translated_or_schedule_seo_output( $source_value, $post_id, $field_context ) {
		$settings        = HTBD_Settings::get();
		$target_language = $this->router->current_language();
		$context         = 'post:' . $post_id . ':' . $field_context;

		if ( $target_language === $settings['source_language'] || '' === $source_value ) {
			return $source_value;
		}

		$translated_value = $this->translated_value( $source_value, $context );
		if ( $translated_value !== $source_value ) {
			return $translated_value;
		}

		$payload_key = 'htbd_seo_' . md5( $post_id . '|' . $target_language . '|' . $context );
		set_transient(
			$payload_key,
			array(
				'value'    => $source_value,
				'language' => $target_language,
			),
			10 * MINUTE_IN_SECONDS
		);

		$event_args = array( $post_id, $payload_key, $context );

		if ( ! wp_next_scheduled( HTBD_Cron::SEO_OUTPUT, $event_args ) ) {
			wp_schedule_single_event( time() + 5, HTBD_Cron::SEO_OUTPUT, $event_args );
		}

		return $source_value;
	}

	private function translate_attachment_alt_text( $post_id, $service, $settings, $target_language, $force_refresh ) {
		$attachments = get_attached_media( 'image', $post_id );

		foreach ( $attachments as $attachment ) {
			$alt_text = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
			if ( is_string( $alt_text ) && '' !== $alt_text ) {
				$service->get_or_translate( $alt_text, $settings['source_language'], $target_language, 'attachment:' . $attachment->ID . ':alt', $force_refresh );
			}
		}
	}

	private function translate_terms( $post_id, $service, $settings, $target_language, $force_refresh ) {
		$terms = get_the_terms( $post_id, HTBD_Settings::taxonomies() );

		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( '' !== $term->name ) {
				$service->get_or_translate( $term->name, $settings['source_language'], $target_language, 'term:' . $term->term_id . ':name', $force_refresh );
			}
			if ( '' !== $term->description ) {
				$service->get_or_translate( $term->description, $settings['source_language'], $target_language, 'term:' . $term->term_id . ':description', $force_refresh );
			}
		}
	}
}
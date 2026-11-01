<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Settings {
	const DEFAULT_POST_TYPES = array( 'post', 'page' );
	const DEFAULT_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Request-level cache. Settings are read on nearly every hook and the
	 * option value cannot change between reads within a single request.
	 *
	 * @var array|null
	 */
	private static $cache;

	public static function defaults() {
		return array(
			'auto_detect_source_language' => false,
			'source_language'            => 'zh',
			'target_languages'           => array( 'en' ),
			'routing_mode'               => array( 'subdirectory' ),
			'domain_bindings'            => array(),
			'browser_language_prompt'    => false,
			'fallback_language'          => 'zh',
			'post_types'                 => self::DEFAULT_POST_TYPES,
			'taxonomies'                 => self::DEFAULT_TAXONOMIES,
			'baidu_app_id'               => '',
			'baidu_secret_key'           => '',
			'log_requests'               => false,
			'request_interval'           => 1000,
		);
	}

	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored_settings = get_option( 'htbd_settings', false );
		if ( false === $stored_settings ) {
			$legacy_settings = get_option( 'btranslate_settings', false );
			if ( false !== $legacy_settings ) {
				$stored_settings = $legacy_settings;
				add_option( 'htbd_settings', $legacy_settings, '', false );
			}
		}

		$settings                 = wp_parse_args( (array) $stored_settings, self::defaults() );
		$settings['routing_mode'] = self::routing_modes( $settings['routing_mode'] );

		self::$cache = $settings;

		return $settings;
	}

	/**
	 * Clears the request-level cache. Hooked to option writes so a settings
	 * save is visible on the same request that performed it.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cache = null;
	}

	public static function routing_modes( $routing_mode = null ) {
		if ( null === $routing_mode ) {
			$routing_mode = self::get()['routing_mode'];
		}

		return array_values( array_intersect( (array) $routing_mode, array( 'subdirectory', 'domain' ) ) );
	}

	public static function is_routing_mode_enabled( $mode ) {
		return in_array( $mode, self::routing_modes(), true );
	}

	public static function target_languages() {
		return array_values( array_filter( array_map( 'sanitize_key', (array) self::get()['target_languages'] ) ) );
	}

	public static function subdirectory_languages() {
		return self::target_languages();
	}

	/**
	 * Source language plus every target language, without the Baidu `auto`
	 * pseudo language.
	 *
	 * @return string[]
	 */
	public static function languages() {
		$settings  = self::get();
		$languages = self::target_languages();

		if ( 'auto' !== $settings['source_language'] ) {
			array_unshift( $languages, sanitize_key( $settings['source_language'] ) );
		}

		return array_values( array_unique( array_filter( $languages ) ) );
	}

	/**
	 * @return string[] Public post types that can be translated.
	 */
	public static function post_types() {
		$available = array_keys( self::available_post_types() );
		$stored    = array_values( array_filter( array_map( 'sanitize_key', (array) self::get()['post_types'] ) ) );
		$types     = array_values( array_intersect( $stored, $available ) );

		return empty( $types ) ? self::DEFAULT_POST_TYPES : $types;
	}

	/**
	 * @return string[] Public taxonomies that can be translated.
	 */
	public static function taxonomies() {
		$available  = array_keys( self::available_taxonomies() );
		$stored     = array_values( array_filter( array_map( 'sanitize_key', (array) self::get()['taxonomies'] ) ) );
		$taxonomies = array_values( array_intersect( $stored, $available ) );

		return empty( $taxonomies ) ? self::DEFAULT_TAXONOMIES : $taxonomies;
	}

	/**
	 * @return array<string, WP_Post_Type>
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * @return array<string, WP_Taxonomy>
	 */
	public static function available_taxonomies() {
		return get_taxonomies( array( 'public' => true ), 'objects' );
	}

	public static function is_translatable_post_type( $post_type ) {
		return in_array( sanitize_key( $post_type ), self::post_types(), true );
	}

	public static function is_translatable_taxonomy( $taxonomy ) {
		return in_array( sanitize_key( $taxonomy ), self::taxonomies(), true );
	}

	public static function is_supported_language( $language ) {
		$language = sanitize_key( $language );

		if ( '' === $language || 'auto' === $language ) {
			return false;
		}

		return in_array( $language, self::languages(), true );
	}

	/**
	 * Maps Baidu language codes to BCP 47 codes for hreflang output.
	 *
	 * @param string $language Baidu language code.
	 * @return string
	 */
	public static function hreflang_code( $language ) {
		$map = array(
			'zh'  => 'zh',
			'cht' => 'zh-Hant',
			'yue' => 'yue',
			'en'  => 'en',
			'jp'  => 'ja',
			'kor' => 'ko',
			'fra' => 'fr',
			'spa' => 'es',
			'pt'  => 'pt',
			'de'  => 'de',
			'it'  => 'it',
			'ru'  => 'ru',
			'ara' => 'ar',
			'th'  => 'th',
			'vie' => 'vi',
			'nl'  => 'nl',
			'pl'  => 'pl',
			'bul' => 'bg',
			'est' => 'et',
			'dan' => 'da',
			'fin' => 'fi',
			'cs'  => 'cs',
			'rom' => 'ro',
			'slo' => 'sk',
			'swe' => 'sv',
			'hu'  => 'hu',
			'el'  => 'el',
			'id'  => 'id',
			'tr'  => 'tr',
			'uk'  => 'uk',
		);

		$language = sanitize_key( $language );

		return isset( $map[ $language ] ) ? $map[ $language ] : $language;
	}

	/**
	 * Human readable label for a language code, used by the switcher.
	 *
	 * @param string $language Baidu language code.
	 * @return string
	 */
	public static function language_label( $language ) {
		$labels = array(
			'zh'  => '简体中文',
			'cht' => '繁體中文',
			'en'  => 'English',
			'jp'  => '日本語',
			'kor' => '한국어',
			'fra' => 'Français',
			'spa' => 'Español',
			'pt'  => 'Português',
			'de'  => 'Deutsch',
			'it'  => 'Italiano',
			'ru'  => 'Русский',
			'ara' => 'العربية',
			'th'  => 'ไทย',
			'vie' => 'Tiếng Việt',
			'nl'  => 'Nederlands',
			'pl'  => 'Polski',
		);

		$language = sanitize_key( $language );

		return isset( $labels[ $language ] ) ? $labels[ $language ] : strtoupper( $language );
	}

	public static function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = explode( '/', $domain, 2 )[0];
		$domain = preg_replace( '/:\d+$/', '', $domain );

		return self::is_valid_domain( $domain ) ? $domain : '';
	}

	/**
	 * Accepts a regular host name, an IPv4 address, or a single label such as
	 * localhost, so domain routing can be tested on a development machine.
	 * IPv6 literals are not supported because the port is stripped before the
	 * check and brackets would need their own handling.
	 *
	 * @param string $domain Lower case host without scheme, path, or port.
	 * @return bool
	 */
	private static function is_valid_domain( $domain ) {
		if ( '' === $domain || strlen( $domain ) > 253 ) {
			return false;
		}

		if ( preg_match( '/^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)$/', $domain ) ) {
			return true;
		}

		if ( preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
			return true;
		}

		return (bool) preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain );
	}
}

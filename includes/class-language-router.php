<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Language_Router {
	private $request_language = '';

	public function register() {
		$this->redirect_admin_request_to_source();
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'do_parse_request', array( $this, 'strip_language_prefix' ), 1, 3 );
		add_action( 'parse_request', array( $this, 'resolve_domain_language' ) );
		add_filter( 'home_url', array( $this, 'localize_home_url' ), 20, 4 );
		add_filter( 'site_url', array( $this, 'localize_comment_post_url' ), 20, 4 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_language_redirect_hosts' ) );
		add_filter( 'comment_post_redirect', array( $this, 'localize_comment_redirect' ) );
	}

	private function redirect_admin_request_to_source() {
		$redirect_url = $this->admin_redirect_url();

		if ( '' === $redirect_url ) {
			return;
		}

		wp_redirect( $redirect_url, 302, 'HTBD' );
		exit;
	}

	/**
	 * Resolves the source site URL for a login or admin request that arrived
	 * through a target language prefix or bound domain.
	 *
	 * Kept separate from the redirect itself so the target can be asserted in
	 * tests without exiting the process.
	 *
	 * @return string Redirect target, or an empty string when no redirect is
	 *                needed.
	 */
	public function admin_redirect_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$query       = wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( ! is_string( $path ) ) {
			return '';
		}

		$relative_path      = $this->relative_path( $path );
		$segments           = explode( '/', $relative_path, 2 );
		$language           = sanitize_key( $segments[0] );
		$uses_language_path = HTBD_Settings::is_routing_mode_enabled( 'subdirectory' ) && in_array( $language, HTBD_Settings::subdirectory_languages(), true );
		$uses_language_host = HTBD_Settings::is_routing_mode_enabled( 'domain' ) && $this->request_uses_bound_domain();

		if ( ! $uses_language_path && ! $uses_language_host ) {
			return '';
		}

		if ( $uses_language_path ) {
			$relative_path = isset( $segments[1] ) ? $segments[1] : '';
		}

		if ( ! preg_match( '#^(?:wp-login\.php|wp-admin)(?:/|$)#', $relative_path ) ) {
			return '';
		}

		$source_url = untrailingslashit( (string) get_option( 'home', '' ) );
		if ( '' === $source_url ) {
			return '';
		}

		$redirect_url = $source_url . '/' . ltrim( $relative_path, '/' );

		if ( is_string( $query ) && '' !== $query ) {
			$redirect_url .= '?' . $query;
		}

		return esc_url_raw( $redirect_url );
	}

	public function register_rewrite_rules() {
		// Subdirectory requests reuse WordPress's existing rewrite rules after their language prefix is removed.
	}

	public function query_vars( $query_vars ) {
		$query_vars[] = 'htbd_language';

		return $query_vars;
	}

	public function strip_language_prefix( $do_parse_request, $wp, $extra_query_vars ) {
		if ( ! $do_parse_request || ! HTBD_Settings::is_routing_mode_enabled( 'subdirectory' ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $do_parse_request;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$query       = wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( ! is_string( $path ) ) {
			return $do_parse_request;
		}

		$home_path = $this->home_path();
		$relative  = ltrim( (string) preg_replace( '#^' . preg_quote( $home_path, '#' ) . '(?=/|$)#', '', $path, 1 ), '/' );
		$segments  = explode( '/', $relative, 2 );
		$language  = sanitize_key( $segments[0] );

		if ( ! in_array( $language, HTBD_Settings::subdirectory_languages(), true ) ) {
			return $do_parse_request;
		}

		$this->request_language = $language;
		$remaining_path         = isset( $segments[1] ) ? $segments[1] : '';
		$source_path            = '/' . ( '' !== $home_path ? trim( $home_path, '/' ) . '/' : '' ) . ltrim( $remaining_path, '/' );
		$_SERVER['REQUEST_URI'] = $source_path . ( is_string( $query ) && '' !== $query ? '?' . $query : '' );

		return $do_parse_request;
	}

	public function resolve_domain_language( $wp ) {
		$settings = HTBD_Settings::get();
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? HTBD_Settings::normalize_domain( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$bindings = (array) $settings['domain_bindings'];

		if ( '' !== $this->request_language ) {
			$wp->query_vars['htbd_language'] = $this->request_language;
		}

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) && '' !== $host && isset( $bindings[ $host ] ) && HTBD_Settings::is_supported_language( $bindings[ $host ] ) ) {
			if ( '' !== $this->request_language ) {
				$wp->query_vars = array( 'error' => '404' );

				return;
			}

			$wp->query_vars['htbd_language'] = sanitize_key( $bindings[ $host ] );
		}
	}

	/**
	 * Returns the URL of the current front-end request as the browser sent it,
	 * including the language prefix or bound domain, so callers can build the
	 * equivalent URL in another language.
	 *
	 * @return string
	 */
	public function request_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host || '' === $uri ) {
			return home_url( '/' );
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	public function current_language() {
		$settings = HTBD_Settings::get();
		$language = get_query_var( 'htbd_language' );

		if ( $language && HTBD_Settings::is_supported_language( $language ) ) {
			return sanitize_key( $language );
		}

		return sanitize_key( $settings['source_language'] );
	}

	public function localized_url( $url, $language = '' ) {
		$settings = HTBD_Settings::get();
		$language = '' === $language ? $this->current_language() : sanitize_key( $language );

		if ( $language === $settings['source_language'] || ! HTBD_Settings::is_supported_language( $language ) ) {
			return $url;
		}

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) && $this->request_uses_domain( $language ) ) {
			return $this->localized_domain_url( $url, $language );
		}

		if ( ! HTBD_Settings::is_routing_mode_enabled( 'subdirectory' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return $url;
		}
		$parts = $this->source_origin_parts( $parts );

		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$host     = isset( $parts['host'] ) ? $parts['host'] : '';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path     = $this->localized_path( $parts['path'], $language );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . $host . $port . $path . $query . $fragment;
	}

	/**
	 * Returns the URL of a page in its source language form, even when the
	 * given URL already points at a target language.
	 *
	 * @param string $url URL in any configured language.
	 * @return string
	 */
	public function source_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$parts    = $this->source_origin_parts( $parts );
		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path     = $this->source_path( isset( $parts['path'] ) ? $parts['path'] : '/' );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . $parts['host'] . $port . $path . $query . $fragment;
	}

	/**
	 * Converts a URL into the URL of a specific language, including the
	 * source language and languages that are only reachable through a bound
	 * domain.
	 *
	 * @param string $url      URL in any configured language.
	 * @param string $language Target language code.
	 * @return string
	 */
	public function url_for_language( $url, $language ) {
		$settings = HTBD_Settings::get();
		$language = sanitize_key( $language );

		if ( '' === $language || 'auto' === $language || ! HTBD_Settings::is_supported_language( $language ) ) {
			return $url;
		}

		if ( $language === $settings['source_language'] ) {
			return $this->source_url( $url );
		}

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) ) {
			$domain = array_search( $language, (array) $settings['domain_bindings'], true );

			if ( false !== $domain ) {
				return $this->localized_domain_url( $url, $language );
			}
		}

		return $this->localized_url( $url, $language );
	}

	public function localize_home_url( $url, $path, $scheme, $blog_id ) {
		return $this->localized_url( $url );
	}

	public function localize_comment_post_url( $url, $path, $scheme, $blog_id ) {
		if ( 'wp-comments-post.php' !== basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			return $url;
		}

		$language = $this->current_language();

		if ( ! HTBD_Settings::is_routing_mode_enabled( 'domain' ) || ! $this->request_uses_domain( $language ) ) {
			return $url;
		}

		return $this->localized_domain_url( $url, $language );
	}

	public function allow_language_redirect_hosts( $hosts ) {
		if ( ! HTBD_Settings::is_routing_mode_enabled( 'domain' ) ) {
			return $hosts;
		}

		foreach ( (array) HTBD_Settings::get()['domain_bindings'] as $domain => $language ) {
			$domain = HTBD_Settings::normalize_domain( $domain );

			if ( '' !== $domain && HTBD_Settings::is_supported_language( $language ) ) {
				$hosts[] = $domain;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	public function localize_comment_redirect( $location ) {
		$referer = wp_get_raw_referer();

		if ( ! is_string( $referer ) || '' === $referer ) {
			return $location;
		}

		$language = $this->language_from_url( $referer );

		if ( '' === $language ) {
			return $location;
		}

		$referer_host = HTBD_Settings::normalize_domain( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		$bindings     = (array) HTBD_Settings::get()['domain_bindings'];

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) && isset( $bindings[ $referer_host ] ) ) {
			return $this->localized_domain_url( $location, $language );
		}

		return $this->localized_url( $location, $language );
	}

	private function localized_domain_url( $url, $language ) {
		$domain = array_search( $language, (array) HTBD_Settings::get()['domain_bindings'], true );
		$parts  = wp_parse_url( $url );

		if ( false === $domain || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$path     = $this->source_path( isset( $parts['path'] ) ? $parts['path'] : '/' );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . '://' . $domain . $path . $query . $fragment;
	}

	private function language_from_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$settings = HTBD_Settings::get();
		$host     = HTBD_Settings::normalize_domain( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$bindings = (array) $settings['domain_bindings'];

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) && isset( $bindings[ $host ] ) && HTBD_Settings::is_supported_language( $bindings[ $host ] ) ) {
			return sanitize_key( $bindings[ $host ] );
		}

		$home_host = HTBD_Settings::normalize_domain( (string) wp_parse_url( (string) get_option( 'home', '' ), PHP_URL_HOST ) );
		if ( $host !== $home_host || ! HTBD_Settings::is_routing_mode_enabled( 'subdirectory' ) ) {
			return '';
		}

		$path       = isset( $parts['path'] ) ? $parts['path'] : '/';
		$segments   = explode( '/', $this->relative_path( $path ), 2 );
		$language   = sanitize_key( $segments[0] );
		$languages  = HTBD_Settings::subdirectory_languages();

		return in_array( $language, $languages, true ) ? $language : '';
	}

	private function request_uses_domain( $language ) {
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? HTBD_Settings::normalize_domain( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$bindings = (array) HTBD_Settings::get()['domain_bindings'];

		return '' !== $host && isset( $bindings[ $host ] ) && sanitize_key( $bindings[ $host ] ) === $language;
	}

	private function request_uses_bound_domain() {
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? HTBD_Settings::normalize_domain( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$bindings = (array) HTBD_Settings::get()['domain_bindings'];

		return '' !== $host && isset( $bindings[ $host ] ) && HTBD_Settings::is_supported_language( $bindings[ $host ] );
	}

	private function source_origin_parts( $parts ) {
		$bindings = (array) HTBD_Settings::get()['domain_bindings'];
		$host     = isset( $parts['host'] ) ? HTBD_Settings::normalize_domain( $parts['host'] ) : '';

		if ( '' === $host || ! isset( $bindings[ $host ] ) ) {
			return $parts;
		}

		$home_parts = wp_parse_url( (string) get_option( 'home', '' ) );
		if ( ! is_array( $home_parts ) || empty( $home_parts['host'] ) ) {
			return $parts;
		}

		$parts['scheme'] = isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https';
		$parts['host']   = $home_parts['host'];

		if ( isset( $home_parts['port'] ) ) {
			$parts['port'] = $home_parts['port'];
		} else {
			unset( $parts['port'] );
		}

		return $parts;
	}

	private function localized_path( $path, $language ) {
		$home_path = $this->home_path();
		$relative  = $this->relative_path( $path );
		$languages = array_map( 'preg_quote', HTBD_Settings::target_languages() );

		if ( ! empty( $languages ) ) {
			$relative = preg_replace( '#^(?:' . implode( '|', $languages ) . ')(?=/|$)#', $language, $relative, 1 );
		}

		if ( $language !== strtok( $relative, '/' ) ) {
			$relative = $language . '/' . $relative;
		}

		return '/' . ( '' !== $home_path ? trim( $home_path, '/' ) . '/' : '' ) . ltrim( $relative, '/' );
	}

	private function source_path( $path ) {
		$home_path = $this->home_path();
		$relative  = $this->relative_path( $path );
		$languages = array_map( 'preg_quote', HTBD_Settings::target_languages() );

		if ( ! empty( $languages ) ) {
			$relative = preg_replace( '#^(?:' . implode( '|', $languages ) . ')(?=/|$)/?#', '', $relative, 1 );
		}

		return '/' . ( '' !== $home_path ? trim( $home_path, '/' ) . '/' : '' ) . ltrim( $relative, '/' );
	}

	private function relative_path( $path ) {
		return ltrim( (string) preg_replace( '#^' . preg_quote( $this->home_path(), '#' ) . '(?=/|$)#', '', $path, 1 ), '/' );
	}

	private function home_path() {
		$home_path = wp_parse_url( (string) get_option( 'home', '' ), PHP_URL_PATH );

		return is_string( $home_path ) ? untrailingslashit( $home_path ) : '';
	}
}

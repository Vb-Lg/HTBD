<?php

defined( 'ABSPATH' ) || exit;

/**
 * Emits the hreflang alternate links for translated singular content.
 *
 * The canonical link is left to WordPress core: get_permalink() is already
 * filtered by HTBD_Language_Router, so core's rel_canonical() emits the URL of
 * the language being rendered. A canonical link is added here only when a
 * theme or plugin removed the core callback.
 */
class HTBD_SEO_Head {
	const X_DEFAULT = 'x-default';

	private $router;

	public function __construct( HTBD_Language_Router $router ) {
		$this->router = $router;
	}

	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 1 );
	}

	public function render() {
		$urls = $this->alternate_urls();

		if ( count( $urls ) < 2 ) {
			return;
		}

		$current   = $this->router->current_language();
		$x_default = isset( $urls[ self::X_DEFAULT ] ) ? $urls[ self::X_DEFAULT ] : '';
		unset( $urls[ self::X_DEFAULT ] );

		if ( '' === $x_default ) {
			$x_default = isset( $urls[ $current ] ) ? $urls[ $current ] : reset( $urls );
		}

		echo "\n";

		if ( ! has_action( 'wp_head', 'rel_canonical' ) && isset( $urls[ $current ] ) ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $urls[ $current ] ) );
		}

		foreach ( $urls as $language => $url ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( HTBD_Settings::hreflang_code( $language ) ),
				esc_url( $url )
			);
		}

		if ( '' !== $x_default ) {
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( self::X_DEFAULT ),
				esc_url( $x_default )
			);
		}
	}

	/**
	 * Builds one URL per configured language for the queried singular object.
	 *
	 * @return array<string, string> Language code to URL map, plus x-default.
	 */
	private function alternate_urls() {
		if ( ! is_singular() || is_preview() || is_feed() || is_404() || is_robots() || is_trackback() ) {
			return array();
		}

		$post_id = (int) get_queried_object_id();

		if ( ! $post_id || ! HTBD_Settings::is_translatable_post_type( (string) get_post_type( $post_id ) ) ) {
			return array();
		}

		$permalink = get_permalink( $post_id );

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return array();
		}

		$urls = array();

		foreach ( HTBD_Settings::languages() as $language ) {
			$urls[ $language ] = $this->router->url_for_language( $permalink, $language );
		}

		$urls[ self::X_DEFAULT ] = $this->router->source_url( $permalink );

		/**
		 * Filters the hreflang URL map before it is printed.
		 *
		 * @param array<string, string> $urls Language code to URL map, plus x-default.
		 * @param int                   $post_id Queried post ID.
		 */
		return (array) apply_filters( 'htbd_hreflang_urls', array_filter( $urls ), $post_id );
	}
}

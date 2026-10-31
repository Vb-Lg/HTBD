<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Sitemap_Controller {
	private $router;
	private $target_language = '';

	public function __construct( HTBD_Language_Router $router ) {
		$this->router = $router;
	}

	public function register() {
		add_filter( 'do_parse_request', array( $this, 'intercept_request' ), 0, 3 );
	}

	public function intercept_request( $do_parse_request, $wp, $extra_query_vars ) {
		$settings = HTBD_Settings::get();
		$language = $this->requested_language( $this->request_path(), $settings );

		if ( '' === $language ) {
			return $do_parse_request;
		}

		$this->target_language = $language;
		$this->serve_localized_sitemap();

		return $do_parse_request;
	}

	private function serve_localized_sitemap() {
		$source_url = $this->source_sitemap_url();
		if ( '' === $source_url ) {
			return;
		}

		$source_xml = $this->source_sitemap_xml( $source_url );
		if ( '' === $source_xml ) {
			return;
		}

		$xml = $this->rewrite_sitemap( $source_xml, $source_url );
		if ( '' === $xml ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Serialized XML from DOMDocument.
		exit;
	}

	private function source_sitemap_xml( $source_url ) {
		$cache_key = 'htbd_sitemap_' . md5( $source_url );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			$source_url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'headers'             => array(
					'Accept' => 'application/xml, text/xml;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$source_xml = wp_remote_retrieve_body( $response );
		if ( '' !== $source_xml ) {
			set_transient( $cache_key, $source_xml, 5 * MINUTE_IN_SECONDS );
		}

		return $source_xml;
	}

	private function request_path() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		return is_string( $path ) ? '/' . ltrim( $path, '/' ) : '';
	}

	private function requested_language( $path, $settings ) {
		$home_path = wp_parse_url( (string) get_option( 'home', '' ), PHP_URL_PATH );

		if ( is_string( $home_path ) && '' !== untrailingslashit( $home_path ) ) {
			$path = preg_replace( '#^' . preg_quote( untrailingslashit( $home_path ), '#' ) . '(?=/|$)#', '', $path, 1 );
			$path = '/' . ltrim( (string) $path, '/' );
		}

		if ( HTBD_Settings::is_routing_mode_enabled( 'domain' ) && '/sitemap.xml' === untrailingslashit( $path ) ) {
			$host     = isset( $_SERVER['HTTP_HOST'] ) ? HTBD_Settings::normalize_domain( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
			$bindings = (array) $settings['domain_bindings'];
			$language = isset( $bindings[ $host ] ) ? sanitize_key( $bindings[ $host ] ) : '';

			if ( in_array( $language, HTBD_Settings::target_languages(), true ) ) {
				return $language;
			}
		}

		if ( HTBD_Settings::is_routing_mode_enabled( 'subdirectory' ) ) {
			foreach ( HTBD_Settings::subdirectory_languages() as $language ) {
				$language = sanitize_key( $language );

				if ( '/' . $language . '/sitemap.xml' === untrailingslashit( $path ) ) {
					return $language;
				}
			}
		}

		return '';
	}

	private function source_sitemap_url() {
		$home_url = (string) get_option( 'home', '' );
		$parts    = wp_parse_url( $home_url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';

		return $parts['scheme'] . '://' . $parts['host'] . $port . $path . '/sitemap.xml';
	}

	private function rewrite_sitemap( $xml, $source_url ) {
		if ( '' === trim( $xml ) || ! class_exists( 'DOMDocument' ) ) {
			return '';
		}

		$document                     = new DOMDocument();
		$document->preserveWhiteSpace = true;
		$previous_errors              = libxml_use_internal_errors( true );
		$loaded                       = $document->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded ) {
			return '';
		}

		$xpath = new DOMXPath( $document );
		$nodes = $xpath->query( '//text() | //@* | //processing-instruction("xml-stylesheet")' );

		if ( false === $nodes ) {
			return '';
		}

		$entries = $this->collect_url_entries( $xpath );

		foreach ( $nodes as $node ) {
			$node->nodeValue = $this->rewrite_source_urls( $node->nodeValue, $source_url );
		}

		$this->add_url_alternates( $document, $entries );

		$result = $document->saveXML();

		return false === $result ? '' : $result;
	}

	/**
	 * Collects the original source URL of every sitemap entry before the URLs
	 * are rewritten for the requested language.
	 *
	 * @param DOMXPath $xpath Loaded sitemap document.
	 * @return array<int, array{loc: DOMElement, source: string}>
	 */
	private function collect_url_entries( $xpath ) {
		$entries = array();
		$nodes   = $xpath->query( '//*[local-name()="url"]/*[local-name()="loc"]' );

		if ( false === $nodes ) {
			return $entries;
		}

		foreach ( $nodes as $node ) {
			$source = trim( (string) $node->textContent );

			if ( '' !== $source ) {
				$entries[] = array(
					'loc'    => $node,
					'source' => $source,
				);
			}
		}

		return $entries;
	}

	/**
	 * Adds xhtml:link alternates to every URL entry of a urlset document.
	 *
	 * @param DOMDocument $document Loaded sitemap document.
	 * @param array<int, array{loc: DOMElement, source: string}> $entries Collected entries.
	 * @return void
	 */
	private function add_url_alternates( $document, $entries ) {
		if ( empty( $entries ) || ! $document->documentElement instanceof DOMElement || 'urlset' !== $document->documentElement->localName ) {
			return;
		}

		$languages = HTBD_Settings::languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$namespace = 'http://www.w3.org/1999/xhtml';

		$document->documentElement->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', $namespace );

		foreach ( $entries as $entry ) {
			$url_node = $entry['loc']->parentNode;

			if ( ! $url_node instanceof DOMElement ) {
				continue;
			}

			foreach ( array_reverse( iterator_to_array( $url_node->childNodes, false ) ) as $child ) {
				if ( $child instanceof DOMElement && 'link' === $child->localName && $child->namespaceURI === $namespace ) {
					$url_node->removeChild( $child );
				}
			}

			$cursor = $entry['loc'];

			foreach ( $languages as $language ) {
				$href = $this->router->url_for_language( $entry['source'], $language );

				if ( ! is_string( $href ) || '' === $href ) {
					continue;
				}

				$link = $document->createElementNS( $namespace, 'xhtml:link' );
				$link->setAttribute( 'rel', 'alternate' );
				$link->setAttribute( 'hreflang', HTBD_Settings::hreflang_code( $language ) );
				$link->setAttribute( 'href', $href );

				$url_node->insertBefore( $link, $cursor->nextSibling );
				$cursor = $link;
			}
		}
	}

	private function rewrite_source_urls( $value, $source_url ) {
		$source_parts = wp_parse_url( $source_url );

		if ( ! is_array( $source_parts ) || empty( $source_parts['host'] ) ) {
			return $value;
		}

		$rewritten = preg_replace_callback(
			'#https?://[^\s<>"\']+#i',
			function ( $matches ) use ( $source_parts ) {
				$url_parts = wp_parse_url( $matches[0] );

				if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || 0 !== strcasecmp( $url_parts['host'], $source_parts['host'] ) ) {
					return $matches[0];
				}

				return $this->router->localized_url( $matches[0], $this->target_language );
			},
			$value
		);

		return null === $rewritten ? $value : $rewritten;
	}
}

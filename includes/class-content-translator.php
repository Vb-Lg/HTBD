<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Content_Translator {
	private $service;

	public function __construct( HTBD_Translation_Service $service ) {
		$this->service = $service;
	}

	public function translate( $content, $source_language, $target_language, $context, $force_refresh = false ) {
		$segments = self::segments( $content );
		if ( false === $segments ) {
			return HTBD_Translation_Result::failure( 'content_parse_failed', 'Unable to protect links before translation.' );
		}

		$items           = array();
		$text_index      = 0;
		$protected_depth = 0;

		foreach ( $segments as $segment ) {
			if ( self::is_tag( $segment ) ) {
				$protected_depth = self::protected_depth_after_tag( $segment, $protected_depth );
				continue;
			}

			if ( 0 < $protected_depth || '' === trim( $segment ) ) {
				continue;
			}

			$items[] = array(
				'text'    => trim( $segment ),
				'context' => $context . ':text:' . $text_index,
			);

			++$text_index;
		}

		if ( empty( $items ) ) {
			return HTBD_Translation_Result::success( $content );
		}

		$results = $this->service->translate_many( $items, $source_language, $target_language, $force_refresh );

		foreach ( $results as $result ) {
			if ( ! $result->success ) {
				return $result;
			}
		}

		return HTBD_Translation_Result::success( $content );
	}

	public static function segments( $content ) {
		$segments = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $segments ) {
			return false;
		}

		return array_values( array_filter( $segments, static function ( $segment ) {
			return '' !== $segment;
		} ) );
	}

	public static function is_tag( $segment ) {
		return 1 === preg_match( '/^<[^>]+>$/s', $segment );
	}

	public static function protected_depth_after_tag( $segment, $protected_depth ) {
		if ( 1 !== preg_match( '/^<\s*(\/?)\s*(?:pre|code)\b[^>]*>/i', $segment, $matches ) ) {
			return $protected_depth;
		}

		if ( '/' === $matches[1] ) {
			return max( 0, $protected_depth - 1 );
		}

		return str_ends_with( rtrim( $segment ), '/>' ) ? $protected_depth : $protected_depth + 1;
	}

	public static function surrounding_whitespace( $segment ) {
		preg_match( '/^(\s*)(.*?)(\s*)$/s', $segment, $matches );

		return array(
			'leading' => $matches[1],
			'text'    => $matches[2],
			'trailing' => $matches[3],
		);
	}
}
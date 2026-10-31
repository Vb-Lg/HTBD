<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Translation_Service {
	private $store;
	private $provider;

	public function __construct( HTBD_Translation_Store $store, HTBD_Translation_Provider $provider ) {
		$this->store    = $store;
		$this->provider = $provider;
	}

	public function get_or_translate( $source_value, $source_language, $target_language, $context, $force_refresh = false ) {
		$results = $this->translate_many(
			array(
				array(
					'text'    => $source_value,
					'context' => $context,
				),
			),
			$source_language,
			$target_language,
			$force_refresh
		);

		$result = reset( $results );

		return $result instanceof HTBD_Translation_Result ? $result : HTBD_Translation_Result::success( (string) $source_value );
	}

	/**
	 * Resolves many field values at once, reusing stored translations and
	 * sending everything else to the provider as a single batch.
	 *
	 * @param array<int|string, array{text: string, context: string}> $items          Values to resolve.
	 * @param string                                                  $source_language Source language code.
	 * @param string                                                  $target_language Target language code.
	 * @param bool                                                    $force_refresh   Whether to bypass stored translations.
	 * @return array<int|string, HTBD_Translation_Result> Results keyed as the input.
	 */
	public function translate_many( array $items, $source_language, $target_language, $force_refresh = false ) {
		$results = array();
		$pending = array();

		foreach ( $items as $key => $item ) {
			$text    = isset( $item['text'] ) ? (string) $item['text'] : '';
			$context = isset( $item['context'] ) ? (string) $item['context'] : '';

			$identity = HTBD_Translation_Identity::key( $text, $source_language, $target_language, $context, $this->provider->get_version() );
			$existing = $this->store->find_valid( $identity );

			if ( ! $force_refresh && ! empty( $existing['translated_value'] ) ) {
				$results[ $key ] = HTBD_Translation_Result::success( $existing['translated_value'] );

				continue;
			}

			$pending[ $key ] = array(
				'text'        => $text,
				'context'     => $context,
				'identity'    => $identity,
				'fingerprint' => HTBD_Translation_Identity::source_fingerprint( $text ),
				'previous'    => empty( $existing['translated_value'] ) ? '' : $existing['translated_value'],
			);
		}

		if ( empty( $pending ) ) {
			return $results;
		}

		$texts   = array();
		$context = '';

		foreach ( $pending as $key => $item ) {
			$texts[ $key ] = $item['text'];

			if ( '' === $context ) {
				$context = $item['context'];
			}
		}

		$outcomes = $this->provider->translate_batch( $texts, $source_language, $target_language, $context );

		foreach ( $pending as $key => $item ) {
			$outcome = isset( $outcomes[ $key ] ) && $outcomes[ $key ] instanceof HTBD_Translation_Result
				? $outcomes[ $key ]
				: HTBD_Translation_Result::failure( 'missing_result', __( 'The translation provider returned no result for this field.', 'hyx-translator-for-baidu-translate' ) );

			if ( $outcome->success ) {
				$this->store->save( $item['identity'], $source_language, $target_language, $item['context'], $item['fingerprint'], $outcome->value, 'complete' );
			} elseif ( '' === $item['previous'] ) {
				$this->store->save( $item['identity'], $source_language, $target_language, $item['context'], $item['fingerprint'], '', 'failed' );
			}

			$results[ $key ] = $outcome;
		}

		return $results;
	}
}

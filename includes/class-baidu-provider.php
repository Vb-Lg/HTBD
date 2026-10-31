<?php

defined( 'ABSPATH' ) || exit;

class HTBD_Baidu_Provider implements HTBD_Translation_Provider {
	const API_URL = 'https://fanyi-api.baidu.com/api/trans/vip/translate';

	/**
	 * Limits documented by Baidu: the whole q parameter may not exceed 6000
	 * bytes. Stay below it so the multi line input keeps a safety margin.
	 */
	const MAX_BATCH_BYTES = 5000;
	const MAX_BATCH_LINES = 40;
	const MAX_ATTEMPTS    = 3;

	private $app_id;
	private $secret_key;

	/**
	 * Timestamp of the last HTTP request, used to keep requests below the
	 * per second quota of the standard Baidu Translate plan.
	 *
	 * @var float
	 */
	private static $last_request = 0.0;

	public function __construct( $app_id, $secret_key ) {
		$this->app_id     = $app_id;
		$this->secret_key = $secret_key;
	}

	public function get_version() {
		return 'baidu-vip-v1';
	}

	public function translate( $source_value, $source_language, $target_language, $context ) {
		$results = $this->translate_batch( array( $source_value ), $source_language, $target_language, $context );
		$result  = reset( $results );

		return $result instanceof HTBD_Translation_Result ? $result : HTBD_Translation_Result::success( '' );
	}

	/**
	 * Translates many strings, sending several of them per HTTP request.
	 *
	 * @param string[] $items          Texts to translate, keyed by caller index.
	 * @param string   $source_language Source language code.
	 * @param string   $target_language Target language code.
	 * @param string   $context         Field context, used for logging only.
	 * @return array<string|int, HTBD_Translation_Result> Results keyed as the input.
	 */
	public function translate_batch( array $items, $source_language, $target_language, $context ) {
		$items = array_filter(
			$items,
			static function ( $item ) {
				return is_scalar( $item ) && '' !== (string) $item;
			}
		);

		if ( empty( $items ) ) {
			return array();
		}

		$results = array();

		foreach ( $this->group_items( $items ) as $keys ) {
			$texts = array();

			foreach ( $keys as $key ) {
				$texts[ $key ] = (string) $items[ $key ];
			}

			$response = $this->request( array_values( $texts ), $source_language, $target_language, $context );

			if ( ! $response['success'] ) {
				foreach ( $keys as $key ) {
					$this->log_request( $texts[ $key ], $source_language, $target_language, $context, 'failed', $response['error_code'] );
					$results[ $key ] = HTBD_Translation_Result::failure( $response['error_code'], $response['error_message'] );
				}

				continue;
			}

			if ( count( $response['lines'] ) !== count( $keys ) ) {
				// The provider returned a different number of lines than it was
				// given, so the mapping is unsafe. Translate these texts alone.
				foreach ( $keys as $key ) {
					$single = $this->request( array( $texts[ $key ] ), $source_language, $target_language, $context );

					if ( $single['success'] && 1 === count( $single['lines'] ) ) {
						$this->log_request( $texts[ $key ], $source_language, $target_language, $context, 'complete' );
						$results[ $key ] = HTBD_Translation_Result::success( $single['lines'][0] );
						continue;
					}

					$error_code    = $single['success'] ? 'line_mismatch' : $single['error_code'];
					$error_message = $single['success']
						? __( 'Baidu Translate returned an unexpected number of translated lines.', 'hyx-translator-for-baidu-translate' )
						: $single['error_message'];

					$this->log_request( $texts[ $key ], $source_language, $target_language, $context, 'failed', $error_code );
					$results[ $key ] = HTBD_Translation_Result::failure( $error_code, $error_message );
				}

				continue;
			}

			$lines = array_values( $response['lines'] );
			$index = 0;

			foreach ( $keys as $key ) {
				$this->log_request( $texts[ $key ], $source_language, $target_language, $context, 'complete' );
				$results[ $key ] = HTBD_Translation_Result::success( $lines[ $index ] );
				++$index;
			}
		}

		return $results;
	}

	/**
	 * Splits the texts into groups that Baidu can translate in one request.
	 * Texts containing a line break are sent alone so the returned lines keep
	 * a one to one mapping with the inputs.
	 *
	 * @param array $items Texts keyed by caller index.
	 * @return array<int, array<int, string|int>>
	 */
	private function group_items( array $items ) {
		$groups       = array();
		$current      = array();
		$current_size = 0;

		foreach ( array_keys( $items ) as $key ) {
			$text = (string) $items[ $key ];
			$size = strlen( $text ) + 1;

			if ( false !== strpos( $text, "\n" ) || false !== strpos( $text, "\r" ) ) {
				$groups[] = array( $key );

				continue;
			}

			if ( ! empty( $current ) && ( $current_size + $size > self::MAX_BATCH_BYTES || count( $current ) >= self::MAX_BATCH_LINES ) ) {
				$groups[]     = $current;
				$current      = array();
				$current_size = 0;
			}

			$current[]     = $key;
			$current_size += $size;
		}

		if ( ! empty( $current ) ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * Sends one request, retrying the transient failures Baidu reports for
	 * quota, timeouts, and upstream errors.
	 *
	 * @param string[] $texts           Texts to send as multi line q.
	 * @param string   $source_language Source language code.
	 * @param string   $target_language Target language code.
	 * @param string   $context         Field context, used for logging only.
	 * @return array{success: bool, lines: string[], error_code: string, error_message: string}
	 */
	private function request( array $texts, $source_language, $target_language, $context ) {
		$attempt = 0;

		while ( true ) {
			$response = $this->send( $texts, $source_language, $target_language, $context );

			if ( $response['success'] || ! $this->is_retryable( $response['error_code'] ) || $attempt >= self::MAX_ATTEMPTS ) {
				return $response;
			}

			++$attempt;
			usleep( 500000 * $attempt );
		}
	}

	/**
	 * @return array{success: bool, lines: string[], error_code: string, error_message: string}
	 */
	private function send( array $texts, $source_language, $target_language, $context ) {
		if ( '' === $this->app_id || '' === $this->secret_key ) {
			$this->log_request( implode( "\n", $texts ), $source_language, $target_language, $context, 'missing_credentials' );

			return $this->response( false, array(), 'missing_credentials', __( 'Baidu Translate credentials are not configured.', 'hyx-translator-for-baidu-translate' ) );
		}

		$this->throttle();

		$query     = implode( "\n", $texts );
		$salt      = (string) wp_rand( 100000, 999999 );
		$signature = md5( $this->app_id . $query . $salt . $this->secret_key );
		$response  = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'q'     => $query,
					'from'  => $source_language,
					'to'    => $target_language,
					'appid' => $this->app_id,
					'salt'  => $salt,
					'sign'  => $signature,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->response( false, array(), 'request_failed', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['trans_result'] ) || ! is_array( $body['trans_result'] ) ) {
			$error_code    = isset( $body['error_code'] ) ? sanitize_text_field( (string) $body['error_code'] ) : 'invalid_response';
			$error_message = isset( $body['error_msg'] ) ? sanitize_text_field( (string) $body['error_msg'] ) : __( 'Baidu Translate returned an invalid response.', 'hyx-translator-for-baidu-translate' );

			return $this->response( false, array(), $error_code, $error_message );
		}

		return $this->response( true, array_map( 'strval', wp_list_pluck( $body['trans_result'], 'dst' ) ), '', '' );
	}

	private function response( $success, array $lines, $error_code, $error_message ) {
		return array(
			'success'       => (bool) $success,
			'lines'         => $lines,
			'error_code'    => (string) $error_code,
			'error_message' => (string) $error_message,
		);
	}

	/**
	 * @param string $error_code Provider error code.
	 * @return bool
	 */
	private function is_retryable( $error_code ) {
		return in_array( (string) $error_code, array( 'request_failed', 'invalid_response', '52001', '52002', '54003' ), true );
	}

	/**
	 * Keeps consecutive requests at least request_interval milliseconds apart.
	 *
	 * @return void
	 */
	private function throttle() {
		$interval = (int) HTBD_Settings::get()['request_interval'];

		if ( $interval <= 0 ) {
			self::$last_request = microtime( true );

			return;
		}

		if ( self::$last_request > 0 ) {
			$elapsed = (int) round( ( microtime( true ) - self::$last_request ) * 1000 );

			if ( $elapsed < $interval ) {
				usleep( ( $interval - $elapsed ) * 1000 );
			}
		}

		self::$last_request = microtime( true );
	}

	private function log_request( $source_value, $source_language, $target_language, $context, $status, $error_code = '' ) {
		$settings = HTBD_Settings::get();

		if ( empty( $settings['log_requests'] ) ) {
			return;
		}

		$entry = array(
			'provider'           => 'baidu',
			'source_language'    => sanitize_key( $source_language ),
			'target_language'    => sanitize_key( $target_language ),
			'context'            => sanitize_text_field( (string) $context ),
			'source_fingerprint' => HTBD_Translation_Identity::source_fingerprint( (string) $source_value ),
			'source_length'      => strlen( (string) $source_value ),
			'status'             => sanitize_key( $status ),
			'error_code'         => sanitize_text_field( (string) $error_code ),
		);

		do_action( 'htbd_translation_request_logged', $entry );
	}
}

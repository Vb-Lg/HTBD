<?php

defined( 'ABSPATH' ) || exit;

interface HTBD_Translation_Provider {
	public function get_version();

	public function translate( $source_value, $source_language, $target_language, $context );

	/**
	 * Translates many values, ideally in fewer provider requests than there
	 * are values.
	 *
	 * @param string[] $items          Texts keyed by caller index.
	 * @param string   $source_language Source language code.
	 * @param string   $target_language Target language code.
	 * @param string   $context         Field context used for logging.
	 * @return array<string|int, HTBD_Translation_Result> Results keyed as the input.
	 */
	public function translate_batch( array $items, $source_language, $target_language, $context );
}

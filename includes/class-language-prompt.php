<?php

defined( 'ABSPATH' ) || exit;

/**
 * Suggests the language the visitor's browser asks for.
 *
 * The suggestion is decided in the browser, not on the server, so a page cache
 * never serves a notice meant for a different visitor. Nothing is redirected
 * automatically: the visitor gets a dismissible notice with a real link to the
 * same page in the suggested language, which keeps every language reachable at
 * its own canonical URL for crawlers and for visitors who disagree with their
 * browser's preference.
 */
class HTBD_Language_Prompt {
	const SCRIPT      = 'htbd-language-prompt';
	const STYLE       = 'htbd-language-prompt';
	const STORAGE_KEY = 'htbd-language-prompt-dismissed';
	const CONFIG      = 'htbdLanguagePrompt';

	private $router;

	/**
	 * Request-level cache for links().
	 *
	 * @var array<int, array{code: string, label: string, hreflang: string, aliases: string[], url: string}>|null
	 */
	private $links;

	public function __construct( HTBD_Language_Router $router ) {
		$this->router = $router;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	public function enqueue() {
		if ( empty( $this->links() ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE,
			plugins_url( 'assets/css/language-prompt.css', HTBD_FILE ),
			array(),
			HTBD_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT,
			plugins_url( 'assets/js/language-prompt.js', HTBD_FILE ),
			array(),
			HTBD_VERSION,
			true
		);

		wp_localize_script( self::SCRIPT, self::CONFIG, $this->config() );
	}

	/**
	 * Prints the notice shell. It stays hidden until the script finds a browser
	 * language that matches a configured language, so a visitor without
	 * JavaScript, or with a matching browser language, never sees it.
	 *
	 * @return void
	 */
	public function render() {
		if ( empty( $this->links() ) ) {
			return;
		}

		printf(
			'<aside class="htbd-language-prompt" id="htbd-language-prompt" role="region" aria-label="%1$s" hidden>' . "\n" .
			'<p class="htbd-language-prompt__message" aria-live="polite"></p>' . "\n" .
			'<a class="htbd-language-prompt__link" rel="alternate" hidden></a>' . "\n" .
			'<button type="button" class="htbd-language-prompt__dismiss" aria-label="%2$s">&times;</button>' . "\n" .
			'</aside>' . "\n",
			esc_attr__( 'Language suggestion', 'hyx-translator-for-baidu-translate' ),
			esc_attr__( 'Dismiss this suggestion', 'hyx-translator-for-baidu-translate' )
		);
	}

	/**
	 * Other configured languages of the current page, in settings order.
	 *
	 * A language is skipped when the router cannot produce a different URL for
	 * it, which happens when a language is only reachable through a domain
	 * binding that is not in use.
	 *
	 * @return array<int, array{code: string, label: string, hreflang: string, aliases: string[], url: string}>
	 */
	public function links() {
		if ( null !== $this->links ) {
			return $this->links;
		}

		$this->links = array();

		if ( ! $this->is_enabled() ) {
			return $this->links;
		}

		$current_url = $this->router->request_url();
		$current     = $this->router->current_language();

		foreach ( HTBD_Settings::languages() as $language ) {
			if ( $language === $current ) {
				continue;
			}

			$url = $this->router->url_for_language( $current_url, $language );

			if ( ! is_string( $url ) || '' === $url || $url === $current_url ) {
				continue;
			}

			$this->links[] = array(
				'code'     => $language,
				'label'    => HTBD_Settings::language_label( $language ),
				'hreflang' => HTBD_Settings::hreflang_code( $language ),
				'aliases'  => self::browser_aliases( $language ),
				'url'      => $url,
			);
		}

		return $this->links;
	}

	/**
	 * BCP 47 tags that should select a language in the browser.
	 *
	 * The hreflang code is the primary tag; a short table adds the region and
	 * script variants that browsers actually send, most notably the Chinese
	 * ones that would otherwise all collapse to `zh`.
	 *
	 * @param string $language Baidu language code.
	 * @return string[]
	 */
	public static function browser_aliases( $language ) {
		$language = sanitize_key( $language );
		$aliases  = array( strtolower( (string) HTBD_Settings::hreflang_code( $language ) ) );
		$variants = array(
			'zh'  => array( 'zh-hans', 'zh-cn', 'zh-sg', 'zh-my' ),
			'cht' => array( 'zh-hant', 'zh-tw', 'zh-hk', 'zh-mo' ),
			'pt'  => array( 'pt-br', 'pt-pt' ),
		);

		if ( isset( $variants[ $language ] ) ) {
			$aliases = array_merge( $aliases, $variants[ $language ] );
		}

		return array_values( array_unique( array_filter( $aliases ) ) );
	}

	/**
	 * Data handed to the browser: the current language, every candidate link,
	 * the storage key used to remember an answer, and the two localized strings
	 * the notice needs.
	 *
	 * @return array<string, mixed>
	 */
	public function config() {
		return array(
			'current'    => $this->router->current_language(),
			'links'      => array_values( $this->links() ),
			'storageKey' => self::STORAGE_KEY,
			'i18n'       => array(
				/* translators: %s: language name, for example English. */
				'message' => __( 'It looks like your browser is set to %s.', 'hyx-translator-for-baidu-translate' ),
				/* translators: %s: language name, for example English. */
				'action'  => __( 'View this page in %s', 'hyx-translator-for-baidu-translate' ),
			),
		);
	}

	private function is_enabled() {
		$settings = HTBD_Settings::get();

		if ( empty( $settings['browser_language_prompt'] ) ) {
			return false;
		}

		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		foreach ( array( 'is_feed', 'is_embed', 'is_preview', 'is_robots', 'is_404' ) as $condition ) {
			if ( function_exists( $condition ) && $condition() ) {
				return false;
			}
		}

		return count( HTBD_Settings::languages() ) > 1;
	}
}

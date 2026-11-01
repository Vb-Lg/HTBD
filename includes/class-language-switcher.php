<?php

defined( 'ABSPATH' ) || exit;

/**
 * Renders links to the current page in every configured language.
 *
 * Available as the [htbd_language_switcher] shortcode, the
 * htbd/language-switcher block, and a classic widget.
 */
class HTBD_Language_Switcher {
	const SHORTCODE   = 'htbd_language_switcher';
	const BLOCK       = 'htbd/language-switcher';
	const EDITOR_JS   = 'htbd-language-switcher-block';

	private static $instance;

	private $router;

	public function __construct( HTBD_Language_Router $router ) {
		$this->router = $router;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self( new HTBD_Language_Router() );
		}

		return self::$instance;
	}

	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ), 15 );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_JS,
			plugins_url( 'assets/js/language-switcher-block.js', HTBD_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			HTBD_VERSION,
			true
		);

		// Editor strings live in JavaScript, which reads JED JSON and ignores the MO file.
		wp_set_script_translations(
			self::EDITOR_JS,
			'hyx-translator-for-baidu-translate',
			plugin_dir_path( HTBD_FILE ) . 'languages/'
		);

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'editor_script'   => self::EDITOR_JS,
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'format'      => array(
						'type'    => 'string',
						'default' => 'list',
					),
					'showCurrent' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	public function register_widget() {
		register_widget( 'HTBD_Language_Switcher_Widget' );
	}

	public function render_shortcode( $attributes = array() ) {
		$attributes = shortcode_atts(
			array(
				'format'       => 'list',
				'show_current' => 'yes',
			),
			(array) $attributes,
			self::SHORTCODE
		);

		return $this->render(
			array(
				'format'       => $attributes['format'],
				'show_current' => in_array( strtolower( (string) $attributes['show_current'] ), array( 'yes', 'true', '1' ), true ),
			)
		);
	}

	public function render_block( $attributes = array() ) {
		$attributes = (array) $attributes;

		return $this->render(
			array(
				'format'       => isset( $attributes['format'] ) ? $attributes['format'] : 'list',
				'show_current' => ! isset( $attributes['showCurrent'] ) || $attributes['showCurrent'],
			)
		);
	}

	/**
	 * @param array $args Rendering options.
	 * @return string Escaped markup.
	 */
	public function render( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'format'       => 'list',
				'show_current' => true,
				'class'        => '',
			)
		);

		$links = $this->links();

		if ( count( $links ) < 2 ) {
			return '';
		}

		$classes = array( 'htbd-language-switcher' );

		if ( 'inline' === $args['format'] ) {
			$classes[] = 'htbd-language-switcher--inline';
		}

		if ( is_string( $args['class'] ) && '' !== $args['class'] ) {
			$classes = array_merge( $classes, array_map( 'sanitize_html_class', explode( ' ', $args['class'] ) ) );
		}

		$items = array();

		foreach ( $links as $link ) {
			if ( $link['current'] ) {
				if ( ! $args['show_current'] ) {
					continue;
				}

				$items[] = sprintf(
					'<span class="htbd-language-switcher__current" lang="%1$s" hreflang="%1$s" aria-current="true">%2$s</span>',
					esc_attr( $link['hreflang'] ),
					esc_html( $link['label'] )
				);

				continue;
			}

			$items[] = sprintf(
				'<a class="htbd-language-switcher__link" href="%1$s" lang="%2$s" hreflang="%2$s">%3$s</a>',
				esc_url( $link['url'] ),
				esc_attr( $link['hreflang'] ),
				esc_html( $link['label'] )
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		if ( 'inline' === $args['format'] ) {
			return sprintf(
				'<span class="%1$s">%2$s</span>',
				esc_attr( implode( ' ', $classes ) ),
				implode( '<span class="htbd-language-switcher__separator" aria-hidden="true"> | </span>', $items )
			);
		}

		return sprintf(
			'<ul class="%1$s">%2$s</ul>',
			esc_attr( implode( ' ', $classes ) ),
			implode(
				'',
				array_map(
					static function ( $item ) {
						return '<li class="htbd-language-switcher__item">' . $item . '</li>';
					},
					$items
				)
			)
		);
	}

	/**
	 * @return array<int, array{code: string, label: string, hreflang: string, url: string, current: bool}>
	 */
	public function links() {
		$languages = HTBD_Settings::languages();

		if ( count( $languages ) < 2 ) {
			return array();
		}

		$current_url     = $this->current_url();
		$current_language = $this->router->current_language();
		$links           = array();

		foreach ( $languages as $language ) {
			$links[] = array(
				'code'     => $language,
				'label'    => HTBD_Settings::language_label( $language ),
				'hreflang' => HTBD_Settings::hreflang_code( $language ),
				'url'      => $this->router->url_for_language( $current_url, $language ),
				'current'  => $language === $current_language,
			);
		}

		/**
		 * Filters the links rendered by the language switcher.
		 *
		 * @param array $links Language link data.
		 */
		return (array) apply_filters( 'htbd_language_switcher_links', $links );
	}

	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host || '' === $uri ) {
			return home_url( '/' );
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}
}

class HTBD_Language_Switcher_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'htbd_language_switcher',
			__( 'HTBD language switcher', 'hyx-translator-for-baidu-translate' ),
			array(
				'description' => __( 'Links to this page in every configured language.', 'hyx-translator-for-baidu-translate' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		$switcher = HTBD_Language_Switcher::instance();
		$markup   = $switcher->render(
			array(
				'format'       => isset( $instance['format'] ) && 'inline' === $instance['format'] ? 'inline' : 'list',
				'show_current' => ! empty( $instance['show_current'] ),
			)
		);

		if ( '' === $markup ) {
			return;
		}

		$title = isset( $instance['title'] ) ? $instance['title'] : '';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by the theme.

		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by the theme.
		}

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while building the markup.

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Provided by the theme.
	}

	public function form( $instance ) {
		$title        = isset( $instance['title'] ) ? $instance['title'] : '';
		$format       = isset( $instance['format'] ) ? $instance['format'] : 'list';
		$show_current = isset( $instance['show_current'] ) ? (bool) $instance['show_current'] : true;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'hyx-translator-for-baidu-translate' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>"><?php esc_html_e( 'Format:', 'hyx-translator-for-baidu-translate' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'format' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'format' ) ); ?>">
				<option value="list" <?php selected( 'list', $format ); ?>><?php esc_html_e( 'List', 'hyx-translator-for-baidu-translate' ); ?></option>
				<option value="inline" <?php selected( 'inline', $format ); ?>><?php esc_html_e( 'Inline', 'hyx-translator-for-baidu-translate' ); ?></option>
			</select>
		</p>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_current' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_current' ) ); ?>" value="1" <?php checked( $show_current ); ?> />
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_current' ) ); ?>"><?php esc_html_e( 'Show the current language', 'hyx-translator-for-baidu-translate' ); ?></label>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		return array(
			'title'        => sanitize_text_field( $new_instance['title'] ?? '' ),
			'format'       => isset( $new_instance['format'] ) && 'inline' === $new_instance['format'] ? 'inline' : 'list',
			'show_current' => ! empty( $new_instance['show_current'] ),
		);
	}
}

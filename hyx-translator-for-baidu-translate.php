<?php
/**
 * Plugin Name: HTBD - hyx Translator powered by Baidu Translate
 * Plugin URI: https://www.vblg.top/index.php/archives/147
 * Description: Persistent multilingual WordPress translations powered by Baidu Translate.
 * Version: 0.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: hanyixuanten
 * Author URI: https://www.vblg.top
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: hyx-translator-for-baidu-translate
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HTBD_VERSION', '0.4.0' );
define( 'HTBD_FILE', __FILE__ );
define( 'HTBD_PATH', plugin_dir_path( __FILE__ ) );

require_once HTBD_PATH . 'includes/interface-translation-provider.php';
require_once HTBD_PATH . 'includes/class-translation-result.php';
require_once HTBD_PATH . 'includes/class-cron.php';
require_once HTBD_PATH . 'includes/class-translation-identity.php';
require_once HTBD_PATH . 'includes/class-translation-store.php';
require_once HTBD_PATH . 'includes/class-baidu-provider.php';
require_once HTBD_PATH . 'includes/class-translation-service.php';
require_once HTBD_PATH . 'includes/class-content-translator.php';
require_once HTBD_PATH . 'includes/class-settings.php';
require_once HTBD_PATH . 'includes/class-language-router.php';
require_once HTBD_PATH . 'includes/class-sitemap-controller.php';
require_once HTBD_PATH . 'includes/class-seo-head.php';
require_once HTBD_PATH . 'includes/class-language-switcher.php';
require_once HTBD_PATH . 'includes/class-health-check.php';
require_once HTBD_PATH . 'includes/class-content-controller.php';
require_once HTBD_PATH . 'includes/class-admin.php';
require_once HTBD_PATH . 'includes/class-plugin.php';
require_once HTBD_PATH . 'includes/class-uninstaller.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once HTBD_PATH . 'includes/class-cli.php';
	WP_CLI::add_command( 'htbd', 'HTBD_CLI_Command' );
}

register_activation_hook( HTBD_FILE, array( 'HTBD_Plugin', 'activate' ) );
register_deactivation_hook( HTBD_FILE, array( 'HTBD_Plugin', 'deactivate' ) );

function htbd_load_textdomain() {
	load_plugin_textdomain( 'hyx-translator-for-baidu-translate', false, dirname( plugin_basename( HTBD_FILE ) ) . '/languages' );
}

function htbd_add_plugin_row_meta( $plugin_meta, $plugin_file ) {
	if ( plugin_basename( HTBD_FILE ) === $plugin_file ) {
		$plugin_meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( 'https://github.com/hanyixuanten/HTBD' ),
			esc_html__( 'GitHub Repository', 'hyx-translator-for-baidu-translate' )
		);
	}

	return $plugin_meta;
}

add_action( 'init', 'htbd_load_textdomain', 0 );
add_action( 'plugins_loaded', array( 'HTBD_Plugin', 'instance' ) );
add_filter( 'plugin_row_meta', 'htbd_add_plugin_row_meta', 10, 2 );
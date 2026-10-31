<?php
/**
 * Removes all data created by HTBD.
 *
 * @package HTBD
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-cron.php';
require_once __DIR__ . '/includes/class-uninstaller.php';

HTBD_Uninstaller::uninstall();
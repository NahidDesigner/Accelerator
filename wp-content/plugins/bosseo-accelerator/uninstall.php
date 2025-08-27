<?php
/**
 * Uninstall for Bosseo Accelerator.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bosseo_accelerator_options' );


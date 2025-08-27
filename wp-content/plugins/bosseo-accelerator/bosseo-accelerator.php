<?php
/**
 * Plugin Name:       Bosseo Accelerator
 * Description:       Production-ready performance accelerator for Elementor + Cloudflare. LCP-focused hero video optimization, script delay, async CSS, and diagnostics.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.6
 * Author:            Bosseo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bosseo-accelerator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'BOSSEO_ACCELERATOR_VERSION', '1.0.0' );
define( 'BOSSEO_ACCELERATOR_FILE', __FILE__ );
define( 'BOSSEO_ACCELERATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOSSEO_ACCELERATOR_URL', plugin_dir_url( __FILE__ ) );

// Load includes.
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-plugin.php';
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-settings.php';
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-scripts.php';
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-styles.php';
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-html-optimizer.php';
require_once BOSSEO_ACCELERATOR_DIR . 'includes/class-integrations.php';

/**
 * Bootstrap the plugin.
 */
function bosseo_accelerator_bootstrap() {
	// Safety: PHP version check.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		return;
	}

	$plugin = new Bosseo_Accelerator_Plugin(
		new Bosseo_Accelerator_Settings(),
		new Bosseo_Accelerator_Scripts(),
		new Bosseo_Accelerator_Styles(),
		new Bosseo_Accelerator_HTML_Optimizer()
	);

	$plugin->init();

	// Initialize integrations helpers and admin notices.
	( new Bosseo_Accelerator_Integrations() )->init();
}
add_action( 'plugins_loaded', 'bosseo_accelerator_bootstrap' );


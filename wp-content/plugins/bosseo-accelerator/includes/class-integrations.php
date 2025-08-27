<?php
/**
 * Integrations: Elementor editor detection and Cloudflare awareness.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Bosseo_Accelerator_Integrations {
    public function init() {
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
    }

    public function is_elementor_editor(): bool {
        return isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    public function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $opts = get_option( Bosseo_Accelerator_Settings::OPTION_KEY, [] );
        if ( ! empty( $opts['suppress_notices'] ) ) { return; }

        // Cloudflare Rocket Loader detection heuristic: look for rocket-loader script handle or known attribute
        $has_rocket = false;
        if ( isset( $_SERVER['HTTP_CF_WORKER'] ) ) { /* CF Worker present */ }
        // On admin we cannot reliably detect front; show a generic tip
        echo '<div class="notice notice-info"><p>' . esc_html__( 'Bosseo Accelerator: If using Cloudflare, disable Rocket Loader to avoid double defer/delay issues. Keep Cloudflare minify ON and use this plugin for Critical CSS and hero video logic.', 'bosseo-accelerator' ) . '</p></div>';
    }
}


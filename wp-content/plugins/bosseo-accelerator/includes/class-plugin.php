<?php
/**
 * Core plugin orchestrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
\texit;
}

class Bosseo_Accelerator_Plugin {
\t/** @var Bosseo_Accelerator_Settings */
\tprivate $settings;

\t/** @var Bosseo_Accelerator_Scripts */
\tprivate $scripts;

\t/** @var Bosseo_Accelerator_Styles */
\tprivate $styles;

\t/** @var Bosseo_Accelerator_HTML_Optimizer */
\tprivate $html_optimizer;

\t/** @var bool */
\tprivate $skip = false;

\tpublic function __construct( $settings, $scripts, $styles, $html_optimizer ) {
\t\t$this->settings = $settings;
\t\t$this->scripts = $scripts;
\t\t$this->styles = $styles;
\t\t$this->html_optimizer = $html_optimizer;
\t}

\tpublic function init() {
\t\t$this->settings->init();

\t\tadd_action( 'init', [ $this, 'determine_skip' ], 1 );
\t\tadd_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 0 );
\t\tadd_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 100 );

\t\t// Filters for scripts and styles.
\t\tadd_filter( 'script_loader_tag', [ $this->scripts, 'filter_script_tag' ], 20, 3 );
\t\tadd_filter( 'style_loader_tag', [ $this->styles, 'filter_style_tag' ], 20, 4 );

\t\t// Critical CSS injection.
\t\tadd_action( 'wp_head', [ $this->styles, 'output_critical_css' ], 1 );
\t}

\tpublic function determine_skip() {
\t\t$options = $this->settings->get_options();

\t\t// Global kill switch.
\t\tif ( empty( $options['enabled'] ) ) {
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\t// Skip in admin, AJAX, REST, feeds, previews, Elementor editor.
\t\tif ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\tif ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\tif ( is_feed() ) {
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\t// Elementor editor detection.
\t\tif ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\t// Optimize for logged-in users?
\t\tif ( is_user_logged_in() && empty( $options['optimize_logged_in'] ) ) {
\t\t\t$this->skip = true;
\t\t\treturn;
\t\t}

\t\t// Per-page exclude by ID.
\t\tif ( is_singular() ) {
\t\t\t$excluded = $this->settings->parse_id_list( $options['excluded_ids'] ?? '' );
\t\t\t$qo_id = get_queried_object_id();
\t\t\tif ( $qo_id && in_array( (int) $qo_id, $excluded, true ) ) {
\t\t\t\t$this->skip = true;
\t\t\t\treturn;
\t\t\t}
\t\t}
\t}

\tpublic function maybe_start_buffer() {
\t\tif ( $this->skip ) {
\t\t\treturn;
\t\t}

\t\t// Start output buffering to optimize HTML only when DOM is available.
\t\tob_start( [ $this, 'optimize_html' ] );
\t}

\tpublic function optimize_html( $html ) {
\t\t$options = $this->settings->get_options();

\t\tif ( empty( $html ) || ! is_string( $html ) ) {
\t\t\treturn $html;
\t\t}

\t\t// Skip feeds, JSON, admin-ajax responses, etc. Double check content type.
\t\t$ct = function_exists( 'is_feed' ) ? is_feed() : false;
\t\tif ( $ct ) {
\t\t\treturn $html;
\t\t}

\t\treturn $this->html_optimizer->process_html( $html, $options );
\t}

\tpublic function enqueue_assets() {
\t\tif ( $this->skip ) {
\t\t\treturn;
\t\t}

\t\t// Front-end bootstrap script for hydration and delayed third-party loading.
\t\twp_register_script(
\t\t\t'bacc-bootstrap',
\t\t\tBOSSEO_ACCELERATOR_URL . 'assets/js/bootstrap.js',
\t\t\t[],
\t\t\tBOSSEO_ACCELERATOR_VERSION,
\t\t\ttrue
\t\t);

\t\t$cfg = [
\t\t\t'delay_third_party' => (bool) ( $this->settings->get_options()['delay_third_party'] ?? 1 ),
\t\t\t'diag' => [ 'enabled' => true, 'suppress' => (bool) ( $this->settings->get_options()['suppress_notices'] ?? 0 ) ],
\t\t\t'patterns' => $this->settings->get_patterns_list(),
\t\t\t'hero_mode' => $this->settings->get_options()['hero_mode'] ?? 'smart',
\t\t];

\t\twp_localize_script( 'bacc-bootstrap', 'BACC_BOOT', $cfg );
\t\twp_enqueue_script( 'bacc-bootstrap' );
\t}
}


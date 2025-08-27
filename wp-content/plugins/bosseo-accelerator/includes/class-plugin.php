<?php
/**
 * Core plugin orchestrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bosseo_Accelerator_Plugin {
	/** @var Bosseo_Accelerator_Settings */
	private $settings;

	/** @var Bosseo_Accelerator_Scripts */
	private $scripts;

	/** @var Bosseo_Accelerator_Styles */
	private $styles;

	/** @var Bosseo_Accelerator_HTML_Optimizer */
	private $html_optimizer;

	/** @var bool */
	private $skip = false;

	public function __construct( $settings, $scripts, $styles, $html_optimizer ) {
		$this->settings = $settings;
		$this->scripts = $scripts;
		$this->styles = $styles;
		$this->html_optimizer = $html_optimizer;
	}

	public function init() {
		$this->settings->init();

		add_action( 'init', [ $this, 'maybe_suppress_notices' ], 0 );
		add_action( 'init', [ $this, 'determine_skip' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 0 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 100 );

		// Filters for scripts and styles.
		add_filter( 'script_loader_tag', [ $this->scripts, 'filter_script_tag' ], 20, 3 );
		add_filter( 'style_loader_tag', [ $this->styles, 'filter_style_tag' ], 20, 4 );

		// Critical CSS injection.
		add_action( 'wp_head', [ $this->styles, 'output_critical_css' ], 1 );
	}

	public function maybe_suppress_notices() {
		$options = $this->settings->get_options();
		if ( empty( $options['suppress_notices'] ) ) {
			return;
		}
		// Frontend-only: suppress PHP notices/warnings for environments like Playground.
		if ( is_admin() ) { return; }
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) { return; }
		if ( is_feed() ) { return; }
		@ini_set( 'display_errors', '0' );
		$level = E_ALL;
		$level = $level & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED;
		error_reporting( $level );
	}

	public function determine_skip() {
		$options = $this->settings->get_options();

		// Global kill switch.
		if ( empty( $options['enabled'] ) ) {
			$this->skip = true;
			return;
		}

		// Skip in admin, AJAX, REST, feeds, previews, Elementor editor.
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$this->skip = true;
			return;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			$this->skip = true;
			return;
		}

		if ( is_feed() ) {
			$this->skip = true;
			return;
		}

		// Elementor editor detection.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->skip = true;
			return;
		}

		// Optimize for logged-in users?
		if ( is_user_logged_in() && empty( $options['optimize_logged_in'] ) ) {
			$this->skip = true;
			return;
		}

		// Per-page exclude by ID.
		if ( is_singular() ) {
			$excluded = $this->settings->parse_id_list( $options['excluded_ids'] ?? '' );
			$qo_id = get_queried_object_id();
			if ( $qo_id && in_array( (int) $qo_id, $excluded, true ) ) {
				$this->skip = true;
				return;
			}
		}
	}

	public function maybe_start_buffer() {
		if ( $this->skip ) {
			return;
		}

		// Start output buffering to optimize HTML only when DOM is available.
		ob_start( [ $this, 'optimize_html' ] );
	}

	public function optimize_html( $html ) {
		$options = $this->settings->get_options();

		if ( empty( $html ) || ! is_string( $html ) ) {
			return $html;
		}

		// Skip feeds, JSON, admin-ajax responses, etc. Double check content type.
		$ct = function_exists( 'is_feed' ) ? is_feed() : false;
		if ( $ct ) {
			return $html;
		}

		return $this->html_optimizer->process_html( $html, $options );
	}

	public function enqueue_assets() {
		if ( $this->skip ) {
			return;
		}

		// Front-end bootstrap script for hydration and delayed third-party loading.
		wp_register_script(
			'bacc-bootstrap',
			BOSSEO_ACCELERATOR_URL . 'assets/js/bootstrap.js',
			[],
			BOSSEO_ACCELERATOR_VERSION,
			true
		);

		$cfg = [
			'delay_third_party' => (bool) ( $this->settings->get_options()['delay_third_party'] ?? 1 ),
			'diag' => [ 'enabled' => true, 'suppress' => (bool) ( $this->settings->get_options()['suppress_notices'] ?? 0 ) ],
			'patterns' => $this->settings->get_patterns_list(),
			'hero_mode' => $this->settings->get_options()['hero_mode'] ?? 'smart',
		];

		wp_localize_script( 'bacc-bootstrap', 'BACC_BOOT', $cfg );
		wp_enqueue_script( 'bacc-bootstrap' );
	}
}


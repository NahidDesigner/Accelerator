<?php
/**
 * Style optimization: async load non-critical and inject Critical CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bosseo_Accelerator_Styles {
	public function __construct() {
		add_filter( 'style_loader_src', [ $this, 'filter_style_src' ], 10, 2 );
	}

	public function filter_style_tag( string $html, string $handle, string $href, string $media ): string {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $html;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return $html;
		}
		if ( is_feed() ) {
			return $html;
		}

		$options = get_option( Bosseo_Accelerator_Settings::OPTION_KEY, [] );
		if ( empty( $options['enabled'] ) ) {
			return $html;
		}
		if ( is_user_logged_in() && empty( $options['optimize_logged_in'] ) ) {
			return $html;
		}

		$allow = $this->to_set( (string) ( $options['allowlisted_styles'] ?? '' ) );
		if ( $allow && in_array( $handle, $allow, true ) ) {
			return $html;
		}

		if ( empty( $href ) ) {
			return $html;
		}

		// Extract attributes to preserve id, integrity, crossorigin, media
		$id_attr = $this->extract_attr( $html, 'id' );
		$integrity = $this->extract_attr( $html, 'integrity' );
		$crossorigin = $this->extract_attr( $html, 'crossorigin' );
		$media_attr = $media ? ' media="' . esc_attr( $media ) . '"' : '';

		$attrs = '';
		if ( $id_attr ) { $attrs .= ' id="' . esc_attr( $id_attr ) . '"'; }
		if ( $integrity ) { $attrs .= ' integrity="' . esc_attr( $integrity ) . '"'; }
		if ( $crossorigin ) { $attrs .= ' crossorigin="' . esc_attr( $crossorigin ) . '"'; }

		$preload = '<link rel="preload" as="style" href="' . esc_url( $href ) . '"' . $attrs . $media_attr . ' onload="this.onload=null;this.rel=\'stylesheet\'">';
		$noscript = '<noscript><link rel="stylesheet" href="' . esc_url( $href ) . '"' . $attrs . $media_attr . '></noscript>';
		return $preload . $noscript;
	}

	public function output_critical_css() {
		$options = get_option( Bosseo_Accelerator_Settings::OPTION_KEY, [] );
		if ( empty( $options['enabled'] ) ) {
			return;
		}
		$css = (string) ( $options['critical_css'] ?? '' );
		if ( $css === '' ) {
			return;
		}
		echo '<style id="bacc-critical-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function filter_style_src( string $src, string $handle ): string {
		// Add display=swap to Google Fonts URLs only.
		if ( strpos( $src, 'fonts.googleapis.com' ) !== false && strpos( $src, 'display=' ) === false ) {
			$src .= ( strpos( $src, '?' ) === false ? '?' : '&' ) . 'display=swap';
		}
		return $src;
	}

	private function to_set( string $text ): array {
		$lines = preg_split( '/\r?\n/', $text );
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) { $out[] = $line; }
		}
		return $out;
	}

	private function extract_attr( string $html, string $attr ): string {
		if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '="([^"]*)"/i', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}
}


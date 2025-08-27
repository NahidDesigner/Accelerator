<?php
/**
 * Script optimization: defer and delay third-party.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bosseo_Accelerator_Scripts {
	public function filter_script_tag( string $tag, string $handle, string $src ): string {
		// Fast skips for admin and special requests.
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $tag;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return $tag;
		}
		if ( is_feed() ) {
			return $tag;
		}

		$options = get_option( Bosseo_Accelerator_Settings::OPTION_KEY, [] );
		if ( empty( $options['enabled'] ) ) {
			return $tag;
		}
		if ( is_user_logged_in() && empty( $options['optimize_logged_in'] ) ) {
			return $tag;
		}

		$allow = $this->to_set( (string) ( $options['allowlisted_scripts'] ?? '' ) );
		if ( $allow && in_array( $handle, $allow, true ) ) {
			return $tag;
		}

		// Respect module and nomodule
		if ( $this->tag_has( $tag, 'type="module"' ) || $this->tag_has( $tag, ' nomodule' ) ) {
			return $tag;
		}

		$delay_patterns = $this->to_list( (string) ( $options['third_party_patterns'] ?? '' ) );
		$should_delay = false;
		if ( ! empty( $src ) && ! empty( $delay_patterns ) && ! empty( $options['delay_third_party'] ) ) {
			foreach ( $delay_patterns as $pat ) {
				if ( stripos( $src, $pat ) !== false ) {
					$should_delay = true;
					break;
				}
			}
		}

		if ( $should_delay ) {
			// Convert to non-executing placeholder; JS bootstrap will restore.
			// Remove src and store as data-bacc-src. Set custom type to avoid fetch.
			$tag = preg_replace( '/\s+src=("|\')([^"\']+)("|\')/i', '', $tag );
			$tag = $this->ensure_attr( $tag, 'type', 'bacc/defer' );
			$tag = $this->ensure_attr( $tag, 'data-bacc-src', $src );
			$tag = $this->ensure_attr( $tag, 'data-bacc-handle', $handle );
			return $tag;
		}

		// Default: add defer for non-critical scripts.
		if ( ! $this->tag_has( $tag, ' defer' ) && ! $this->tag_has( $tag, ' async' ) ) {
			$tag = $this->inject_before_tag_end( $tag, ' defer' );
		}

		return $tag;
	}

	private function to_list( string $text ): array {
		$lines = preg_split( '/\r?\n/', $text );
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	private function to_set( string $text ): array {
		$list = $this->to_list( $text );
		return $list;
	}

	private function tag_has( string $tag, string $needle ): bool {
		return stripos( $tag, $needle ) !== false;
	}

	private function inject_before_tag_end( string $tag, string $injection ): string {
		$pos = strripos( $tag, '>' );
		if ( $pos === false ) {
			return $tag . $injection;
		}
		return substr( $tag, 0, $pos ) . $injection . substr( $tag, $pos );
	}

	private function ensure_attr( string $tag, string $attr, string $value ): string {
		if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '=/i', $tag ) ) {
			return $tag;
		}
		$injection = ' ' . $attr . '="' . esc_attr( $value ) . '"';
		return $this->inject_before_tag_end( $tag, $injection );
	}
}


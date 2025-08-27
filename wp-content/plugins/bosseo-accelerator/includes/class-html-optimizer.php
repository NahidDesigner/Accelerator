<?php
/**
 * HTML optimizer using DOM when available.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bosseo_Accelerator_HTML_Optimizer {
	public function process_html( string $html, array $options ): string {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $html; // DOM extension missing, skip transforms.
		}

		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$charset = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'charset' ) : 'UTF-8';
		$html_to_load = $html;
		// Load full HTML document.
		@$doc->loadHTML( $html_to_load );

		$head = $doc->getElementsByTagName( 'head' )->item( 0 );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $html;
		}

		$preload_added = [];
		$delayed_scripts = 0;

		// LCP prioritization: first image in body.
		$imgs = $doc->getElementsByTagName( 'img' );
		$first_img = null;
		foreach ( $imgs as $img ) {
			$first_img = $img; break;
		}
		if ( $first_img instanceof DOMElement ) {
			if ( ! $first_img->hasAttribute( 'fetchpriority' ) ) {
				$first_img->setAttribute( 'fetchpriority', 'high' );
			}
			$first_img->setAttribute( 'decoding', 'async' );
			$src = $first_img->getAttribute( 'src' );
			if ( $src && $head ) {
				$this->append_preload_image( $doc, $head, $first_img );
			}
		}

		// Video optimization: make non-blocking, keep poster as LCP candidate.
		$videos = $doc->getElementsByTagName( 'video' );
		$added_poster_preload = false;
		$hero_mode = $options['hero_mode'] ?? 'smart';
		foreach ( $videos as $video ) {
			if ( $hero_mode === 'off' ) { break; }
			if ( ! $video instanceof DOMElement ) { continue; }
			$video->setAttribute( 'preload', 'none' );
			$video->setAttribute( 'playsinline', '' );
			$video->setAttribute( 'data-bacc-lazy', '1' );

			if ( $video->hasAttribute( 'autoplay' ) ) {
				$video->removeAttribute( 'autoplay' );
				$video->setAttribute( 'data-bacc-autoplay', '1' );
			}
			if ( $video->hasAttribute( 'muted' ) ) {
				$video->removeAttribute( 'muted' );
				$video->setAttribute( 'data-bacc-muted', '1' );
			}
			if ( $video->hasAttribute( 'src' ) ) {
				$src = $video->getAttribute( 'src' );
				$video->removeAttribute( 'src' );
				$video->setAttribute( 'data-bacc-src', $src );
			}

			// Move child <source> src to data-bacc-src
			$children = $video->getElementsByTagName( 'source' );
			foreach ( $children as $source ) {
				if ( ! $source instanceof DOMElement ) { continue; }
				if ( $source->hasAttribute( 'src' ) ) {
					$s = $source->getAttribute( 'src' );
					$source->removeAttribute( 'src' );
					$source->setAttribute( 'data-bacc-src', $s );
				}
			}

			// Poster preload as image for first video poster (hero)
			$poster = $video->getAttribute( 'poster' );
			if ( $poster && $head && ! $added_poster_preload ) {
				$link = $doc->createElement( 'link' );
				$link->setAttribute( 'rel', 'preload' );
				$link->setAttribute( 'as', 'image' );
				$link->setAttribute( 'href', $poster );
				$head->appendChild( $link );
				$added_poster_preload = true;
			}

			// Attempt to infer target device from Elementor hidden classes on self or ancestors.
			$target = $this->infer_elementor_target( $video );
			if ( $target ) {
				$video->setAttribute( 'data-bacc-target', $target ); // mobile|desktop
			}

			// CLS guard: if width+height, set aspect-ratio
			$w = $video->getAttribute( 'width' );
			$h = $video->getAttribute( 'height' );
			if ( $w && $h && ! $video->hasAttribute( 'style' ) ) {
				$ratio = floatval( $w ) > 0 && floatval( $h ) > 0 ? ( floatval( $w ) / floatval( $h ) ) : 0.0;
				if ( $ratio > 0 ) {
					$video->setAttribute( 'style', 'aspect-ratio:' . $w . '/' . $h . ';' );
				}
			}
		}

		// CLS prevention for images: derive aspect-ratio if width/height known
		foreach ( $imgs as $img ) {
			if ( ! $img instanceof DOMElement ) { continue; }
			$w = $img->getAttribute( 'width' );
			$h = $img->getAttribute( 'height' );
			if ( $w && $h ) {
				$style = $img->getAttribute( 'style' );
				if ( strpos( $style, 'aspect-ratio' ) === false ) {
					$style = rtrim( $style, ';' );
					if ( $style !== '' ) { $style .= ';'; }
					$img->setAttribute( 'style', $style . 'aspect-ratio:' . $w . '/' . $h . ';' );
				}
			}
		}

		// Delay third-party scripts inserted directly in markup (non-WP enqueued)
		if ( ! empty( $options['delay_third_party'] ) ) {
			$patterns = $this->to_list( (string) ( $options['third_party_patterns'] ?? '' ) );
			if ( $patterns ) {
				$scripts = $doc->getElementsByTagName( 'script' );
				foreach ( $scripts as $script ) {
					if ( ! $script instanceof DOMElement ) { continue; }
					$type = $script->getAttribute( 'type' );
					if ( $type === 'module' || $script->hasAttribute( 'nomodule' ) ) { continue; }
					$src = $script->getAttribute( 'src' );
					if ( ! $src ) { continue; }
					foreach ( $patterns as $pat ) {
						if ( stripos( $src, $pat ) !== false ) {
							$script->setAttribute( 'data-bacc-src', $src );
							$script->removeAttribute( 'src' );
							$script->setAttribute( 'type', 'bacc/defer' );
							$script->setAttribute( 'data-bacc-delayed', '1' );
							$delayed_scripts++;
							break;
						}
					}
				}
			}
		}

		// Diagnostics: hidden note
		if ( $body && empty( $options['suppress_notices'] ) ) {
			$diag = $doc->createElement( 'div' );
			$diag->setAttribute( 'id', 'bacc-diag' );
			$diag->setAttribute( 'style', 'display:none' );
			$diag->setAttribute( 'data-delayed-scripts', (string) $delayed_scripts );
			$body->appendChild( $diag );
		}

		$out = $doc->saveHTML();
		libxml_clear_errors();
		return $out ?: $html;
	}

	private function append_preload_image( DOMDocument $doc, DOMElement $head, DOMElement $img ) {
		$href = $img->getAttribute( 'src' );
		if ( ! $href ) { return; }
		$link = $doc->createElement( 'link' );
		$link->setAttribute( 'rel', 'preload' );
		$link->setAttribute( 'as', 'image' );
		$link->setAttribute( 'href', $href );
		$srcset = $img->getAttribute( 'srcset' );
		if ( $srcset ) { $link->setAttribute( 'imagesrcset', $srcset ); }
		$sizes = $img->getAttribute( 'sizes' );
		if ( $sizes ) { $link->setAttribute( 'imagesizes', $sizes ); }
		$head->appendChild( $link );
	}

	private function infer_elementor_target( DOMElement $el ): string {
		$node = $el;
		for ( $i = 0; $i < 3 && $node; $i++ ) {
			$class = $node->getAttribute( 'class' );
			if ( stripos( $class, 'elementor-hidden-desktop' ) !== false ) { return 'mobile'; }
			if ( stripos( $class, 'elementor-hidden-mobile' ) !== false ) { return 'desktop'; }
			$node = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
		}
		return '';
	}

	private function to_list( string $text ): array {
		$lines = preg_split( '/\r?\n/', $text );
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) { $out[] = $line; }
		}
		return $out;
	}
}


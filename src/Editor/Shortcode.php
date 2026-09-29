<?php
/**
 * [videooptimizer] shortcode.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor;

use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * For the classic editor, page builders and widgets. Same attributes as the blocks:
 *
 *   [videooptimizer uuid="…"]
 *   [videooptimizer uuid="…" player="native" presentation="lightbox" autoplay="1" muted="1"]
 *   [videooptimizer layout="media-split" uuid="…" side="right" headline="…" cta_label="…" cta_url="/shop"]Text[/videooptimizer]
 *   [videooptimizer layout="background-hero" uuid="…" height="full" overlay="dark" headline="…"]
 *   [videooptimizer layout="video-grid" items="uuid|Label, uuid|Label" columns="3"]
 */
class Shortcode {

	public const TAG = 'videooptimizer';

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, mixed>|string $atts    Attributes.
	 * @param string|null                 $content Enclosed content.
	 */
	public function render( $atts, ?string $content = null ): string {
		$atts   = is_array( $atts ) ? $atts : array();
		$layout = isset( $atts['layout'] ) && is_string( $atts['layout'] ) ? sanitize_key( $atts['layout'] ) : 'video';
		unset( $atts['layout'] );

		$inner = null !== $content && '' !== trim( $content ) ? wp_kses_post( wpautop( do_shortcode( $content ) ) ) : '';
		if ( '' !== $inner && 'media-split' === $layout ) {
			$inner = '<div class="vo-prose">' . $inner . '</div>';
		}
		// Enclosed content for the text layouts only; for the others it is ignored.
		if ( ! in_array( $layout, array( 'media-split', 'background-hero' ), true ) ) {
			$inner = '';
		} elseif ( '' !== $inner ) {
			$inner = $this->prepend_text_attributes( $atts, $inner );
		}

		return $this->renderer->render( $layout, $atts, $inner );
	}

	/**
	 * When content is enclosed, headline/eyebrow/CTA attributes still render around it.
	 *
	 * @param array<string, mixed> $atts  Attributes.
	 * @param string               $inner Inner HTML.
	 */
	private function prepend_text_attributes( array $atts, string $inner ): string {
		$get = static fn ( string $key ): string => isset( $atts[ $key ] ) && is_scalar( $atts[ $key ] ) ? sanitize_text_field( (string) $atts[ $key ] ) : '';

		$head = '';
		if ( '' !== $get( 'eyebrow' ) ) {
			$head .= '<span class="vo-eyebrow">' . esc_html( $get( 'eyebrow' ) ) . '</span>';
		}
		if ( '' !== $get( 'headline' ) ) {
			$head .= '<h2 class="vo-heading">' . esc_html( $get( 'headline' ) ) . '</h2>';
		}
		$cta_label = '' !== $get( 'cta_label' ) ? $get( 'cta_label' ) : $get( 'ctalabel' );
		$cta_url   = \ScaleCommerce\VideoOptimizer\Render\Attributes::safe_url( $atts['cta_url'] ?? $atts['ctaurl'] ?? '' );
		$tail      = '' !== $cta_label && '' !== $cta_url ? '<a class="vo-btn" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>' : '';

		return $head . $inner . $tail;
	}
}

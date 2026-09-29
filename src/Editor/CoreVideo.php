<?php
/**
 * Replaces media library videos with the VideoOptimizer player.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Existing content keeps working without edits: once a media library video has been optimized,
 * the core/video block and the [video] shortcode deliver it through VideoOptimizer. Until then —
 * or if VideoOptimizer is unreachable — the original output stays untouched.
 */
class CoreVideo {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Renderer    $renderer    Renderer.
	 * @param Attachments $attachments Attachment mapping.
	 */
	public function __construct( Renderer $renderer, Attachments $attachments ) {
		$this->renderer    = $renderer;
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_filter( 'render_block_core/video', array( $this, 'render_block' ), 10, 2 );
		add_filter( 'wp_video_shortcode_override', array( $this, 'render_shortcode' ), 10, 2 );
	}

	/**
	 * Core video block.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 */
	public function render_block( string $html, array $block ): string {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$uuid  = $this->attachments->ready_uuid( (int) ( $attrs['id'] ?? 0 ) );
		if ( null === $uuid ) {
			return $html;
		}

		$surface = $this->renderer->surface_for( $uuid, $this->options_from( $attrs ) );
		if ( '' === $surface || str_contains( $surface, 'vo-notice' ) ) {
			return $html;
		}

		$caption = preg_match( '~<figcaption[^>]*>.*?</figcaption>~is', $html, $m ) ? $m[0] : '';
		$class   = preg_match( '~<figure[^>]*class="([^"]*)"~i', $html, $c ) ? $c[1] : 'wp-block-video';

		return '<figure class="' . esc_attr( $class . ' vo-blocks videooptimizer videooptimizer--core-video' ) . '">' . $surface . $caption . '</figure>';
	}

	/**
	 * [video] shortcode (classic editor). Returning '' keeps the default output.
	 *
	 * @param mixed $output Override output.
	 * @param mixed $attr   Shortcode attributes.
	 */
	public function render_shortcode( $output, $attr ): string {
		if ( ! is_array( $attr ) ) {
			return (string) $output;
		}
		$id = 0;
		foreach ( array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'mov' ) as $key ) {
			if ( ! empty( $attr[ $key ] ) && is_string( $attr[ $key ] ) ) {
				$id = attachment_url_to_postid( $attr[ $key ] );
				if ( $id > 0 ) {
					break;
				}
			}
		}
		$uuid = $this->attachments->ready_uuid( $id );
		if ( null === $uuid ) {
			return (string) $output;
		}

		$surface = $this->renderer->surface_for( $uuid, $this->options_from( $attr ) );

		return '' === $surface || str_contains( $surface, 'vo-notice' ) ? (string) $output : '<div class="vo-blocks videooptimizer videooptimizer--core-video">' . $surface . '</div>';
	}

	/**
	 * Maps core video options onto a direct player (autoplay/loop/muted/controls carry over).
	 *
	 * @param array<string, mixed> $attrs Core block / shortcode attributes.
	 * @return array<string, mixed>
	 */
	private function options_from( array $attrs ): array {
		$tri = static function ( $value ): string {
			if ( null === $value ) {
				return '';
			}

			return in_array( strtolower( (string) $value ), array( '1', 'true', 'on', 'yes', 'autoplay', 'loop', 'muted' ), true ) || true === $value ? '1' : '0';
		};

		$options = array(
			'presentation' => 'direct',
			'autoplay'     => $tri( $attrs['autoplay'] ?? false ),
			'loop'         => $tri( $attrs['loop'] ?? false ),
			'muted'        => $tri( $attrs['muted'] ?? false ),
			'controls'     => array_key_exists( 'controls', $attrs ) ? $tri( $attrs['controls'] ) : '1',
		);

		/**
		 * Filters the player options used when a media library video is delivered via VideoOptimizer.
		 *
		 * @param array<string, mixed> $options Options (player, presentation, autoplay, loop, muted, controls).
		 * @param array<string, mixed> $attrs   Original block / shortcode attributes.
		 */
		return (array) apply_filters( 'videooptimizer_core_video_options', $options, $attrs );
	}
}

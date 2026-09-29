<?php
/**
 * WooCommerce's own product gallery videos, delivered via VideoOptimizer.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Woo;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce 11 can show media library videos in the product gallery (classic template and the
 * `woocommerce/product-gallery` block; feature "Product gallery videos"). Its data model only
 * accepts media library attachments, so VideoOptimizer plugs in at delivery: once a gallery
 * video has been sent to VideoOptimizer and is ready,
 *  - its source becomes the VideoOptimizer MP4 (largest rendition up to 1080p, CDN),
 *  - the HLS master is added as data-hls, which the frontend script attaches (adaptive
 *    streaming; native HLS or hls.js),
 *  - the VideoOptimizer poster is used when WooCommerce has none.
 * Not ready or unreachable: WooCommerce's original output stays untouched.
 */
class NativeGallery {

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Nesting depth of woocommerce/product-gallery blocks being rendered.
	 *
	 * @var int
	 */
	private int $block_depth = 0;

	/**
	 * VideoOptimizer MP4 URL => {hls, poster} for the block currently being rendered.
	 *
	 * @var array<string, array{hls: ?string, poster: ?string}>
	 */
	private array $delivered = array();

	/**
	 * Constructor.
	 *
	 * @param Attachments     $attachments Attachment mapping.
	 * @param EmbedRepository $embeds      Embed cache.
	 */
	public function __construct( Attachments $attachments, EmbedRepository $embeds ) {
		$this->attachments = $attachments;
		$this->embeds      = $embeds;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_single_product_video_thumbnail_html', array( $this, 'classic_video_html' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'enter_block' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-gallery', array( $this, 'leave_block' ), 10, 1 );
		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 10, 2 );
	}

	/**
	 * VideoOptimizer delivery data for a ready media library video.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{mp4: string, hls: ?string, poster: ?string}|null
	 */
	public function delivery( int $attachment_id ): ?array {
		$uuid  = $this->attachments->ready_uuid( $attachment_id );
		$embed = null !== $uuid ? $this->embeds->get( $uuid ) : null;
		if ( null === $embed ) {
			return null;
		}
		$mp4 = null;
		foreach ( $embed['files'] as $file ) {
			if ( 'video/mp4' === $file['type'] && ( null === $mp4 || ! preg_match( '/(1440|2160)p/', $file['label'] ) ) ) {
				$mp4 = $file['src'];
			}
		}
		if ( null === $mp4 ) {
			return null;
		}
		// The frontend script upgrades the MP4 to HLS.
		wp_enqueue_script( Assets::HANDLE );

		return array(
			'mp4'    => $mp4,
			'hls'    => $embed['hls'],
			'poster' => $embed['poster'],
		);
	}

	/**
	 * Classic gallery: rewrite WooCommerce's video slide.
	 *
	 * @param mixed $html          Slide HTML.
	 * @param mixed $attachment_id Video attachment id.
	 */
	public function classic_video_html( $html, $attachment_id ): string {
		$html = (string) $html;
		$data = $this->delivery( (int) $attachment_id );
		if ( null === $data ) {
			return $html;
		}
		$original = wp_get_attachment_url( (int) $attachment_id );
		if ( is_string( $original ) && '' !== $original ) {
			$html = str_replace( array( esc_url( $original ), $original ), esc_url( $data['mp4'] ), $html );
		}

		return self::add_video_attributes( $html, $data['mp4'], $data );
	}

	/**
	 * Tracks when a product gallery block starts rendering.
	 *
	 * @param mixed $pre   Short-circuit value.
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public function enter_block( $pre, $block ) {
		if ( is_array( $block ) && 'woocommerce/product-gallery' === ( $block['blockName'] ?? '' ) && null === $pre ) {
			++$this->block_depth;
		}

		return $pre;
	}

	/**
	 * Adds HLS + poster to the videos of a rendered product gallery block.
	 *
	 * @param mixed $html Block HTML.
	 */
	public function leave_block( $html ): string {
		$html              = (string) $html;
		$this->block_depth = max( 0, $this->block_depth - 1 );
		foreach ( $this->delivered as $mp4 => $data ) {
			$html = self::add_video_attributes( $html, $mp4, $data );
		}
		if ( 0 === $this->block_depth ) {
			$this->delivered = array();
		}

		return $html;
	}

	/**
	 * Inside a product gallery block: serve ready videos from VideoOptimizer.
	 *
	 * @param mixed $url           Attachment URL.
	 * @param mixed $attachment_id Attachment id.
	 * @return mixed
	 */
	public function attachment_url( $url, $attachment_id ) {
		if ( $this->block_depth <= 0 || is_admin() || ! $this->attachments->is_video( (int) $attachment_id ) ) {
			return $url;
		}
		$data = $this->delivery( (int) $attachment_id );
		if ( null === $data ) {
			return $url;
		}
		$this->delivered[ $data['mp4'] ] = array(
			'hls'    => $data['hls'],
			'poster' => $data['poster'],
		);

		return $data['mp4'];
	}

	/**
	 * Adds data-hls (and a poster when missing) to every <video> whose src is $mp4.
	 *
	 * @param string                               $html HTML.
	 * @param string                               $mp4  MP4 URL.
	 * @param array{hls: ?string, poster: ?string} $data Delivery data.
	 */
	public static function add_video_attributes( string $html, string $mp4, array $data ): string {
		$src = preg_quote( esc_url( $mp4 ), '~' );

		return (string) preg_replace_callback(
			'~<video\b(?=[^>]*\ssrc="(?:' . $src . '|' . preg_quote( $mp4, '~' ) . ')")([^>]*)>~i',
			static function ( array $m ) use ( $data ): string {
				$attrs = $m[1];
				if ( str_contains( $attrs, 'data-hls=' ) ) {
					return $m[0];
				}
				$extra = array(
					'data-hls'   => $data['hls'],
					'data-vo-wc' => true,
				);
				if ( ! preg_match( '/\sposter="[^"]+"/', $attrs ) ) {
					$extra['poster'] = $data['poster'];
				}

				return '<video' . $attrs . Renderer::attrs( $extra ) . '>';
			},
			$html
		);
	}
}

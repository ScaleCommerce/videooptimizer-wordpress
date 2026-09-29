<?php
/**
 * WooCommerce's own gallery videos delivered via VideoOptimizer.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Plugin;

final class NativeGalleryTest extends IntegrationTestCase {

	private int $video;

	private string $original;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available.' );
		}
		$this->video    = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'video/mp4',
				'file'           => 'clip.mp4',
			)
		);
		$this->original = (string) wp_get_attachment_url( $this->video );
		Plugin::instance()->attachments->link( $this->video, self::UUID, 'ready' );
	}

	private function classic_html(): string {
		return '<div data-thumb-video-src="' . esc_url( $this->original ) . '" class="woocommerce-product-gallery__image woocommerce-product-gallery__video"><a href="' . esc_url( $this->original ) . '"><video autoplay="autoplay" class="wp-post-video" src="' . esc_url( $this->original ) . '" muted="muted"></video></a></div>';
	}

	public function test_classic_gallery_video_uses_videooptimizer(): void {
		$html = (string) apply_filters( 'woocommerce_single_product_video_thumbnail_html', $this->classic_html(), $this->video, array() );

		$this->assertStringNotContainsString( $this->original, $html );
		$this->assertStringContainsString( 'src="https://cdn.example/360p.mp4"', $html );
		$this->assertStringContainsString( 'data-hls="https://cdn.example/hls/master.m3u8"', $html );
		$this->assertStringContainsString( 'data-vo-wc', $html );
		$this->assertStringContainsString( 'poster="https://cdn.example/poster.jpg"', $html );
	}

	public function test_not_ready_video_stays_untouched(): void {
		update_post_meta( $this->video, Attachments::META_STATUS, 'processing' );

		$this->assertSame( $this->classic_html(), apply_filters( 'woocommerce_single_product_video_thumbnail_html', $this->classic_html(), $this->video, array() ) );
	}

	public function test_block_gallery_source_is_swapped_only_while_rendering(): void {
		$this->assertSame( $this->original, wp_get_attachment_url( $this->video ), 'outside the block' );

		apply_filters( 'pre_render_block', null, array( 'blockName' => 'woocommerce/product-gallery' ) );
		$src  = (string) wp_get_attachment_url( $this->video );
		$html = (string) apply_filters( 'render_block_woocommerce/product-gallery', '<div><video class="x" poster="https://own.example/p.jpg" src="' . esc_url( $src ) . '"></video></div>' );

		$this->assertSame( 'https://cdn.example/360p.mp4', $src );
		$this->assertStringContainsString( 'data-hls="https://cdn.example/hls/master.m3u8"', $html );
		$this->assertStringContainsString( 'poster="https://own.example/p.jpg"', $html, 'an existing poster is kept' );
		$this->assertSame( 1, substr_count( $html, 'poster=' ) );
		$this->assertSame( $this->original, wp_get_attachment_url( $this->video ), 'restored after the block' );
	}
}

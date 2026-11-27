<?php
/**
 * End-to-end behaviour inside WordPress.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Plugin;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Woo\ProductVideos;

final class PluginTest extends IntegrationTestCase {

	public function test_blocks_and_shortcode_are_registered(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'video', 'media-split', 'background-hero', 'spotlight', 'video-grid' ) as $name ) {
			$this->assertTrue( $registry->is_registered( 'videooptimizer/' . $name ), $name );
		}
		$this->assertTrue( shortcode_exists( 'videooptimizer' ) );
	}

	public function test_block_renders_through_renderer_and_caches_embed(): void {
		$html = do_blocks( '<!-- wp:videooptimizer/video {"uuid":"' . self::UUID . '","align":"wide"} /-->' );

		$this->assertMatchesRegularExpression( '~^<div class="[^"]*alignwide[^"]*wp-block-videooptimizer-video[^"]*vo-blocks~', $html );
		$this->assertStringContainsString( 'data-vo-embed="https://player.videooptimizer.eu/embed/' . self::UUID, $html );
		$this->assertTrue( wp_style_is( 'videooptimizer-frontend', 'enqueued' ) );

		// Second render of the same video: served from the transient, no extra API call.
		do_shortcode( '[videooptimizer uuid="' . self::UUID . '" player="native"]' );
		$this->assertCount( 1, $this->api_calls );
		$this->assertIsArray( get_transient( 'videooptimizer_embed_' . self::UUID ) );
	}

	public function test_rest_requires_capabilities(): void {
		$this->login_as( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/videos' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status() );

		$this->login_as( 'editor' );
		$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status(), 'editors cannot read settings' );
		$this->assertSame( 428, $this->rest( 'GET', '/libraries' )->get_status(), 'not configured yet' );
	}

	public function test_token_is_write_only(): void {
		$this->login_as( 'administrator' );

		$response = $this->rest( 'POST', '/settings', array( 'token' => 'vp_integration_token', 'default_player' => 'native' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['token_configured'] );
		$this->assertArrayNotHasKey( 'token', $data );
		$this->assertSame( 'native', $data['default_player'] );
		$this->assertStringNotContainsString( 'vp_integration_token', (string) wp_json_encode( get_option( Settings::OPTION ) ) );

		$this->assertSame( 400, $this->rest( 'POST', '/settings', array( 'token' => 'x' ) )->get_status(), 'invalid token format' );

		$libraries = $this->rest( 'GET', '/libraries' );
		$this->assertSame( 200, $libraries->get_status() );
		$this->assertSame( 'lib-1', $libraries->get_data()[0]['id'] );
	}

	public function test_library_encoding_quality_is_forwarded_and_validated(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();

		$update = $this->rest( 'PATCH', '/libraries/lib-1', array( 'name' => 'Main', 'encoding_quality' => 'quality', 'encoding_tier' => 'legacy' ) );
		$this->assertSame( 200, $update->get_status() );
		$sent = json_decode( $this->api_bodies[0], true );
		$this->assertSame( 'quality', $sent['encoding_quality'] );
		$this->assertArrayNotHasKey( 'encoding_tier', $sent );

		$create = $this->rest( 'POST', '/libraries', array( 'name' => 'New', 'encoding_quality' => 'performance' ) );
		$this->assertSame( 'performance', json_decode( end( $this->api_bodies ), true )['encoding_quality'] );
		$this->assertSame( 200, $create->get_status() );

		$calls = count( $this->api_calls );
		$this->assertSame( 400, $this->rest( 'PATCH', '/libraries/lib-1', array( 'encoding_quality' => 'ultra' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/libraries', array( 'name' => 'X', 'encoding_quality' => 'ultra' ) )->get_status() );
		$this->assertCount( $calls, $this->api_calls, 'invalid quality never reaches the API' );
	}

	public function test_reprocess_sends_force_only_when_requested(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();

		$this->assertSame( 200, $this->rest( 'POST', '/libraries/lib-1/reprocess' )->get_status() );
		$this->assertSame( '', $this->api_bodies[0] );

		$forced = $this->rest( 'POST', '/libraries/lib-1/reprocess', array( 'force' => true ) );
		$this->assertSame( 2, $forced->get_data()['queued'] );
		$this->assertSame( array( 'force' => true ), json_decode( $this->api_bodies[1], true ) );

		$this->rest( 'POST', '/libraries/lib-1/reprocess', array( 'force' => 'yes' ) );
		$this->assertSame( '', $this->api_bodies[2], 'only a real boolean true forces' );
	}

	public function test_ingest_of_a_video_platform_link_returns_the_hint(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();
		add_filter(
			'pre_http_request',
			fn ( $pre, $args, $url ) => ( 'POST' === $args['method'] && str_ends_with( $url, '/videos' ) )
				? self::json( 400, array( 'statusCode' => 400, 'message' => 'Not supported', 'data' => array( 'code' => 'source_url_video_platform' ) ) )
				: $pre,
			20,
			3
		);

		$response = $this->rest( 'POST', '/videos/ingest', array( 'library_id' => 'lib-1', 'source_url' => 'https://www.youtube.com/watch?v=x' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'not to a video page', $response->get_data()['message'] );
		$this->assertSame( 'source_url_video_platform', $response->get_data()['data']['upstream_code'] );
	}

	public function test_ingest_requires_https(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();

		$this->assertSame( 400, $this->rest( 'POST', '/videos/ingest', array( 'library_id' => 'lib-1', 'source_url' => 'http://example.com/a.mp4' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/videos/ingest', array( 'library_id' => 'lib-1', 'source_url' => 'file:///etc/passwd' ) )->get_status() );
	}

	public function test_core_video_block_is_replaced_once_ready(): void {
		$id    = self::factory()->attachment->create( array( 'post_mime_type' => 'video/mp4', 'guid' => 'https://example.org/clip.mp4' ) );
		$block = '<!-- wp:video {"id":' . $id . '} --><figure class="wp-block-video"><video controls src="https://example.org/clip.mp4"></video><figcaption class="wp-element-caption">Caption</figcaption></figure><!-- /wp:video -->';

		$attachments = Plugin::instance()->attachments;
		$attachments->link( $id, self::UUID, 'processing' );
		$this->assertStringContainsString( '<video controls src="https://example.org/clip.mp4">', do_blocks( $block ), 'original while processing' );

		update_post_meta( $id, Attachments::META_STATUS, 'ready' );
		$html = do_blocks( $block );
		$this->assertStringContainsString( 'videooptimizer--core-video', $html );
		$this->assertStringContainsString( 'data-vo-autoload="https://player.videooptimizer.eu/embed/' . self::UUID, $html );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Caption</figcaption>', $html );
		$this->assertStringNotContainsString( 'clip.mp4', $html );
	}

	public function test_webhook_updates_attachments(): void {
		$settings = Plugin::instance()->settings;
		$settings->set_webhook_secret( 'whsec_test' );
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'video/mp4' ) );
		Plugin::instance()->attachments->link( $id, self::UUID, 'processing' );

		$body = (string) wp_json_encode(
			array(
				'event' => 'video.failed',
				'data'  => array(
					'uuid'   => self::UUID,
					'status' => 'failed',
					'error'  => 'source returned status 403',
				),
			)
		);
		$send = static function ( string $signature, string $delivery ) use ( $body ): \WP_REST_Response {
			$request = new \WP_REST_Request( 'POST', '/videooptimizer/v1/webhook' );
			$request->set_body( $body );
			$request->set_header( 'x-videooptimizer-timestamp', (string) time() );
			$request->set_header( 'x-videooptimizer-signature', $signature );
			$request->set_header( 'x-videooptimizer-delivery-id', $delivery );

			return rest_get_server()->dispatch( $request );
		};

		$this->assertSame( 401, $send( 'sha256=nope', 'd1' )->get_status() );

		$valid = 'sha256=' . hash_hmac( 'sha256', time() . '.' . $body, 'whsec_test' );
		$this->assertSame( 1, $send( $valid, 'd2' )->get_data()['updated'] );
		$this->assertSame( 'failed', get_post_meta( $id, Attachments::META_STATUS, true ) );
		$this->assertSame( 'source returned status 403', get_post_meta( $id, Attachments::META_ERROR, true ) );
		$this->assertTrue( $send( $valid, 'd2' )->get_data()['duplicate'] );
	}

	public function test_woocommerce_product_videos(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available.' );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( 'Test product' );
		$product->update_meta_data(
			ProductVideos::META,
			ProductVideos::sanitize(
				array(
					'gallery' => array( self::UUID, 'invalid', self::UUID ),
					'tab'     => self::UUID,
					'hover'   => 'nope',
				)
			)
		);
		$product->save();

		$videos = ProductVideos::for_product( $product->get_id() );
		$this->assertSame( array( self::UUID ), $videos['gallery'] );
		$this->assertSame( '', $videos['hover'] );

		$GLOBALS['product'] = wc_get_product( $product->get_id() );
		$GLOBALS['post']    = get_post( $product->get_id() );
		$tabs               = apply_filters( 'woocommerce_product_tabs', array() );
		$this->assertArrayHasKey( 'videooptimizer', $tabs );

		ob_start();
		do_action( 'woocommerce_product_thumbnails' );
		$gallery = (string) ob_get_clean();
		$this->assertStringContainsString( 'woocommerce-product-gallery__image woocommerce-product-gallery__video videooptimizer-gallery__slide', $gallery );
		$this->assertStringContainsString( 'data-large_image="https://cdn.example/poster.jpg"', $gallery );
		unset( $GLOBALS['product'], $GLOBALS['post'] );
	}
}

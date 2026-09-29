<?php
/**
 * Delete offers after media deletion + WooCommerce variation videos.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

use ScaleCommerce\VideoOptimizer\Media\Usage;
use ScaleCommerce\VideoOptimizer\Plugin;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Woo\ProductVideos;

final class DeleteAndVariationTest extends IntegrationTestCase {

	private function linked_attachment( string $uuid = self::UUID ): int {
		$id = self::factory()->attachment->create( array( 'post_mime_type' => 'video/mp4', 'post_title' => 'Clip' ) );
		Plugin::instance()->attachments->link( $id, $uuid, 'ready' );

		return $id;
	}

	private function set_behavior( string $behavior ): void {
		Plugin::instance()->settings->update( array( 'delete_behavior' => $behavior ) );
	}

	private function deletes(): int {
		return count( array_filter( $this->api_calls, static fn ( $c ) => 'DELETE' === $c[0] ) );
	}

	public function test_ask_creates_offer_and_resolve_deletes(): void {
		$user = $this->login_as( 'administrator' );
		$this->configure_token();
		$this->set_behavior( 'ask' );

		wp_delete_attachment( $this->linked_attachment(), true );

		$offers = Plugin::instance()->delete_offers->offers( $user, true );
		$this->assertArrayHasKey( self::UUID, $offers );
		$this->assertSame( 'Clip', $offers[ self::UUID ]['title'] );
		$this->assertSame( array(), $offers[ self::UUID ]['usage'] );
		$this->assertSame( 0, $this->deletes() );

		$response = $this->rest( 'POST', '/delete-offers/' . self::UUID, array( 'delete' => true ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['offers'] );
		$this->assertSame( 1, $this->deletes() );
	}

	public function test_keep_resolution_does_not_call_the_api(): void {
		$this->login_as( 'administrator' );
		$this->configure_token();
		wp_delete_attachment( $this->linked_attachment(), true );

		$this->rest( 'POST', '/delete-offers/' . self::UUID, array( 'delete' => false ) );
		$this->assertSame( 0, $this->deletes() );
		$this->assertSame( 404, $this->rest( 'POST', '/delete-offers/' . self::UUID, array( 'delete' => true ) )->get_status() );
	}

	public function test_editor_without_delete_capability_can_only_keep(): void {
		$this->login_as( 'author' );
		$this->configure_token();
		wp_delete_attachment( $this->linked_attachment(), true );

		$this->assertSame( 403, $this->rest( 'POST', '/delete-offers/' . self::UUID, array( 'delete' => true ) )->get_status() );
		$this->assertFalse( $this->rest( 'GET', '/delete-offers' )->get_data()['can_delete'] );
	}

	public function test_delete_behavior_deletes_unused_but_asks_when_used(): void {
		$user = $this->login_as( 'administrator' );
		$this->configure_token();
		$this->set_behavior( 'delete' );

		wp_delete_attachment( $this->linked_attachment(), true );
		$this->assertSame( 1, $this->deletes(), 'unused video deleted right away' );

		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<!-- wp:videooptimizer/video {"uuid":"' . self::UUID . '"} /-->' ) );
		wp_delete_attachment( $this->linked_attachment(), true );
		$this->assertSame( 1, $this->deletes(), 'used video is not deleted' );
		$offers = Plugin::instance()->delete_offers->offers( $user, true );
		$this->assertSame( $page, $offers[ self::UUID ]['usage'][0]['id'] );
	}

	public function test_keep_behavior_and_shared_videos_create_no_offer(): void {
		$user = $this->login_as( 'administrator' );
		$this->set_behavior( 'ask' );

		// Two media files deliver the same video: deleting one must not offer deletion.
		$first = $this->linked_attachment();
		$this->linked_attachment();
		wp_delete_attachment( $first, true );
		$this->assertSame( array(), Plugin::instance()->delete_offers->offers( $user ) );

		$this->set_behavior( 'keep' );
		$other = '11111111-2222-3333-4444-555555555555';
		wp_delete_attachment( $this->linked_attachment( $other ), true );
		$this->assertSame( array(), Plugin::instance()->delete_offers->offers( $user ) );
		$this->assertSame( 'keep', Plugin::instance()->settings->string( 'delete_behavior' ) );
		$this->assertSame( array( 'ask', 'keep', 'delete' ), Settings::DELETE );
	}

	public function test_usage_finds_blocks_shortcodes_and_products(): void {
		$post = self::factory()->post->create( array( 'post_content' => '[videooptimizer uuid="' . self::UUID . '"]' ) );
		self::factory()->post->create( array( 'post_content' => 'unrelated' ) );

		$ids = array_column( Usage::find( self::UUID ), 'id' );
		$this->assertContains( $post, $ids );
		$this->assertCount( 1, $ids );
		$this->assertSame( array(), Usage::find( 'not-a-uuid' ) );
	}

	public function test_variation_video_is_saved_exposed_and_added_to_gallery(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available.' );
		}
		$product = new \WC_Product_Variable();
		$product->set_name( 'Variable' );
		$product->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();

		// Simulates the variations form request (nonce is verified by WooCommerce itself).
		$_POST['videooptimizer_variation'] = array( 0 => strtoupper( self::UUID ) );
		do_action( 'woocommerce_admin_process_variation_object', $variation, 0 );
		$variation->save();
		unset( $_POST['videooptimizer_variation'] );

		$this->assertSame( self::UUID, ProductVideos::for_variation( $variation->get_id() ) );

		$data = apply_filters( 'woocommerce_available_variation', array(), $product, $variation );
		$this->assertSame( self::UUID, $data['videooptimizer_video'] );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( array( self::UUID ), ProductVideos::gallery_uuids( $product ) );

		$GLOBALS['product'] = $product;
		ob_start();
		do_action( 'woocommerce_product_thumbnails' );
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );
		$this->assertStringContainsString( 'data-vo-uuid="' . self::UUID . '"', $html );

		// Clearing the field removes the video.
		$_POST['videooptimizer_variation'] = array( 0 => '' );
		do_action( 'woocommerce_admin_process_variation_object', $variation, 0 );
		$variation->save();
		unset( $_POST['videooptimizer_variation'] );
		$this->assertSame( '', ProductVideos::for_variation( $variation->get_id() ) );
	}
}

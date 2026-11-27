<?php
/**
 * Purging cached pages when a video changes.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

use ScaleCommerce\VideoOptimizer\Cache\PageCache;
use ScaleCommerce\VideoOptimizer\Plugin;

final class PageCacheTest extends IntegrationTestCase {

	/**
	 * Calls of the videooptimizer_purge_video action.
	 *
	 * @var array<int, array{0: string, 1: array<int, int>, 2: bool}>
	 */
	private array $purges = array();

	public function set_up(): void {
		parent::set_up();
		$this->purges = array();
		add_action(
			'videooptimizer_purge_video',
			function ( $uuid, $ids, $all ) {
				$this->purges[] = array( $uuid, $ids, $all );
			},
			10,
			3
		);
	}

	private function cache(): PageCache {
		return Plugin::instance()->page_cache;
	}

	private function post( string $content, string $status = 'publish', string $type = 'post' ): int {
		return self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => $type,
			)
		);
	}

	public function test_posts_using_the_video_are_found_in_content_and_page_builder_data(): void {
		$shortcode = $this->post( 'Intro [videooptimizer uuid="' . self::UUID . '"]' );
		$block     = $this->post( '<!-- wp:videooptimizer/video {"uuid":"' . strtoupper( self::UUID ) . '"} /-->' );
		$elementor = $this->post( 'No video in the content' );
		update_post_meta( $elementor, '_elementor_data', '[{"settings":{"uuid":"' . self::UUID . '"}}]' );
		$this->post( 'Other video [videooptimizer uuid="15e2662a-e0f2-4c3d-9a11-0123456789ab"]' );
		$this->post( '[videooptimizer uuid="' . self::UUID . '"]', 'draft' );
		$this->post( '[videooptimizer uuid="' . self::UUID . '"]', 'trash' );

		$found = $this->cache()->locate( self::UUID );

		$this->assertEqualsCanonicalizing( array( $shortcode, $block, $elementor ), $found['ids'] );
		$this->assertFalse( $found['all'] );
	}

	public function test_variation_videos_purge_the_parent_product(): void {
		$parent    = $this->post( 'Product', 'publish', 'post' );
		$variation = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_parent' => $parent,
			)
		);
		update_post_meta( $variation, '_videooptimizer_variation', self::UUID );
		wp_update_post(
			array(
				'ID'        => $variation,
				'post_type' => 'product_variation',
			)
		);

		$found = $this->cache()->locate( self::UUID );

		$this->assertSame( array( $parent ), $found['ids'] );
	}

	public function test_shared_content_purges_the_whole_site(): void {
		$template = $this->post( '<!-- wp:videooptimizer/video {"uuid":"' . self::UUID . '"} /-->', 'publish', 'wp_template_part' );
		$this->assertTrue( $this->cache()->locate( self::UUID )['all'], 'template parts show on every page' );
		wp_delete_post( $template, true );
		$this->assertFalse( $this->cache()->locate( self::UUID )['all'] );
	}

	public function test_linked_media_only_purges_the_posts_that_embed_the_attachment(): void {
		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );
		$other = $attachment + 10;

		$block   = $this->post( '<!-- wp:video {"id":' . $attachment . '} --><figure></figure><!-- /wp:video -->' );
		$block2  = $this->post( '<!-- wp:video {"id":' . $attachment . ',"src":"x"} /-->' );
		$product = $this->post( 'Product' );
		update_post_meta( $product, '_product_image_gallery', '3,' . $attachment . ',9' );
		$builder = $this->post( 'Builder' );
		update_post_meta( $builder, '_elementor_data', '[{"settings":{"video":{"url":"x","id":' . $attachment . '}}}]' );
		$this->post( '<!-- wp:video {"id":' . $other . '} /-->' );
		$noise = $this->post( 'Other gallery' );
		update_post_meta( $noise, '_product_image_gallery', (string) ( $attachment * 10 ) . ',' . $other );

		$found = $this->cache()->locate( self::UUID );

		$this->assertEqualsCanonicalizing( array( $block, $block2, $product, $builder ), $found['ids'] );
		$this->assertFalse( $found['all'] );
	}

	public function test_widgets_purge_the_whole_site(): void {
		update_option( 'widget_block', array( 2 => array( 'content' => '[videooptimizer uuid="' . self::UUID . '"]' ) ) );

		$this->assertTrue( $this->cache()->locate( self::UUID )['all'] );
	}

	public function test_too_many_pages_fall_back_to_a_site_wide_purge(): void {
		for ( $i = 0; $i <= PageCache::LIMIT; $i++ ) {
			$this->post( '[videooptimizer uuid="' . self::UUID . '"]' );
		}

		$this->assertTrue( $this->cache()->locate( self::UUID )['all'] );
	}

	/**
	 * Posts the page-cache hook was called for (LiteSpeed's action is the only unguarded one).
	 *
	 * @return \ArrayObject<int, int>
	 */
	private function purged_posts(): \ArrayObject {
		$posts = new \ArrayObject();
		add_action(
			'litespeed_purge_post',
			static function ( $id ) use ( $posts ) {
				$posts[] = $id;
			}
		);

		return $posts;
	}

	public function test_a_changed_video_fires_the_purge_action_once_per_request(): void {
		$post = $this->post( '[videooptimizer uuid="' . self::UUID . '"]' );
		$this->cache()->flush(); // Drop what set_up() queued.
		$this->purges = array();

		$embeds = Plugin::instance()->embeds;
		$embeds->forget( self::UUID );
		$embeds->forget( strtoupper( self::UUID ) );
		$this->assertSame( array(), $this->purges, 'purging waits for the end of the request' );

		$this->cache()->flush();

		$this->assertSame( array( array( self::UUID, array( $post ), false ) ), $this->purges );
	}

	public function test_changes_of_several_videos_share_one_purge_call(): void {
		$b = '15e2662a-e0f2-4c3d-9a11-0123456789ab';
		$p1 = $this->post( '[videooptimizer uuid="' . self::UUID . '"]' );
		$p2 = $this->post( '[videooptimizer uuid="' . self::UUID . '"] [videooptimizer uuid="' . $b . '"]' );
		$p3 = $this->post( '[videooptimizer uuid="' . $b . '"]' );
		$this->cache()->flush();
		$posts = $this->purged_posts();
		$this->purges = array();

		$embeds = Plugin::instance()->embeds;
		$embeds->forget( self::UUID );
		$embeds->forget( $b );
		$this->cache()->flush();

		$this->assertCount( 2, $this->purges, 'the action still fires per video' );
		$this->assertEqualsCanonicalizing( array( $p1, $p2, $p3 ), $posts->getArrayCopy(), 'every post once' );
	}

	public function test_site_wide_purges_are_scheduled_once(): void {
		$this->post( '[videooptimizer uuid="' . self::UUID . '"]', 'publish', 'wp_template_part' );
		$this->cache()->flush();
		wp_clear_scheduled_hook( 'videooptimizer_purge_all' );
		$embeds = Plugin::instance()->embeds;

		$embeds->forget( self::UUID );
		$this->cache()->flush();
		$first = wp_next_scheduled( 'videooptimizer_purge_all' );
		$embeds->forget( self::UUID );
		$this->cache()->flush();

		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( 'videooptimizer_purge_all' ) );
		$this->assertCount( 1, _get_cron_array()[ $first ]['videooptimizer_purge_all'] );
		$this->assertSame( array( self::UUID, array(), true ), $this->purges[ count( $this->purges ) - 1 ] );
	}

	public function test_large_batches_skip_the_search_and_purge_the_whole_site(): void {
		wp_clear_scheduled_hook( 'videooptimizer_purge_all' );
		$this->cache()->flush();
		$this->purges = array();
		$embeds       = Plugin::instance()->embeds;
		for ( $i = 0; $i <= PageCache::MAX_UUIDS; $i++ ) {
			$embeds->forget( sprintf( '00000000-0000-4000-8000-%012d', $i ) );
		}
		global $wpdb;
		$before = $wpdb->num_queries;

		$this->cache()->flush();

		$this->assertCount( PageCache::MAX_UUIDS + 1, $this->purges );
		$this->assertTrue( $this->purges[0][2] );
		$this->assertNotFalse( wp_next_scheduled( 'videooptimizer_purge_all' ) );
		$this->assertLessThan( 10, $wpdb->num_queries - $before, 'no per-video search' );
	}

	public function test_unchanged_status_polls_and_unused_videos_do_not_purge_pages(): void {
		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );
		update_post_meta( $attachment, '_videooptimizer_status', 'processing' );
		$this->cache()->flush();
		$this->purges = array();
		$attachments  = Plugin::instance()->attachments;
		$posts        = $this->purged_posts();

		$attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'processing',
			)
		);
		$this->cache()->flush();
		$this->assertSame( array(), $this->purges, 'nothing changed' );

		$attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'ready',
			)
		);
		$this->cache()->flush();
		$this->assertSame( array( array( self::UUID, array(), false ) ), $this->purges );
		$this->assertSame( array(), $posts->getArrayCopy(), 'no post uses it, so no page is purged' );
		$this->assertFalse( wp_next_scheduled( 'videooptimizer_purge_all' ) );
	}

	public function test_forget_without_change_does_not_queue(): void {
		$this->cache()->flush();
		$this->purges = array();

		Plugin::instance()->embeds->forget( self::UUID, false );
		$this->cache()->flush();

		$this->assertSame( array(), $this->purges );
	}

	public function test_nothing_is_searched_without_a_page_cache_or_listener(): void {
		remove_all_actions( 'videooptimizer_purge_video' );
		$this->cache()->flush();
		Plugin::instance()->embeds->forget( self::UUID );
		global $wpdb;
		$before = $wpdb->num_queries;

		$this->cache()->flush();

		$this->assertSame( $before, $wpdb->num_queries );
	}
}

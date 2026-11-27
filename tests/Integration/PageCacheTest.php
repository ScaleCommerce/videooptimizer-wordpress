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

	public function test_shared_content_and_linked_media_purge_the_whole_site(): void {
		$template = $this->post( '<!-- wp:videooptimizer/video {"uuid":"' . self::UUID . '"} /-->', 'publish', 'wp_template_part' );
		$this->assertTrue( $this->cache()->locate( self::UUID )['all'], 'template parts show on every page' );
		wp_delete_post( $template, true );
		$this->assertFalse( $this->cache()->locate( self::UUID )['all'] );

		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );
		$this->assertTrue( $this->cache()->locate( self::UUID )['all'], 'core/video and Woo galleries reference the attachment' );
	}

	public function test_widgets_purge_the_whole_site(): void {
		update_option( 'widget_block', array( 2 => array( 'content' => '[videooptimizer uuid="' . self::UUID . '"]' ) ) );

		$this->assertTrue( $this->cache()->locate( self::UUID )['all'] );
	}

	public function test_too_many_pages_fall_back_to_a_site_wide_purge(): void {
		for ( $i = 0; $i <= PageCache::LIMIT; $i++ ) {
			$this->post( '[videooptimizer uuid="' . self::UUID . '"]' );
		}

		$found = $this->cache()->locate( self::UUID );

		$this->assertTrue( $found['all'] );
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

	public function test_unchanged_status_polls_do_not_purge(): void {
		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );
		update_post_meta( $attachment, '_videooptimizer_status', 'processing' );
		$this->cache()->flush();
		$this->purges = array();
		$attachments  = Plugin::instance()->attachments;

		$attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'processing',
			)
		);
		$this->cache()->flush();
		$this->assertSame( array(), $this->purges );

		$attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'ready',
			)
		);
		$this->cache()->flush();
		$this->assertCount( 1, $this->purges );
	}

	public function test_nothing_is_searched_without_a_page_cache_or_listener(): void {
		remove_all_actions( 'videooptimizer_purge_video' );
		global $wpdb;
		$before = $wpdb->num_queries;

		$this->cache()->purge( self::UUID );

		$this->assertSame( $before, $wpdb->num_queries );
	}
}

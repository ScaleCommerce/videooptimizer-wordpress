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
		delete_transient( PageCache::LOCK );
		wp_clear_scheduled_hook( 'videooptimizer_purge_all' );
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

	public function test_pages_with_a_video_list_or_detail_are_purged_for_any_video(): void {
		$list      = $this->post( '<!-- wp:videooptimizer/video-list {"libraryId":"3293aeb1-64b7-42a8-b2f7-c5355dcdd58d"} /-->' );
		$shortcode = $this->post( '[videooptimizer layout="video-list" library="3293aeb1-64b7-42a8-b2f7-c5355dcdd58d"]' );
		$elementor = $this->post( 'Builder page' );
		update_post_meta( $elementor, '_elementor_data', '[{"settings":{"layout":"video-list"}}]' );
		$this->post( 'Unrelated page' );
		$this->post( '<!-- wp:videooptimizer/video-list /-->', 'draft' );

		$found = $this->cache()->locate( self::UUID );

		$this->assertEqualsCanonicalizing( array( $list, $shortcode, $elementor ), $found['ids'] );
		$this->assertFalse( $found['all'] );
	}

	public function test_a_video_change_invalidates_cached_list_pages(): void {
		$generation = (int) get_option( \ScaleCommerce\VideoOptimizer\Render\VideoLists::GENERATION_KEY, 0 );

		do_action( PageCache::CHANGED, self::UUID );
		Plugin::instance()->embeds->forget( self::UUID );

		$this->assertGreaterThan( $generation, (int) get_option( \ScaleCommerce\VideoOptimizer\Render\VideoLists::GENERATION_KEY, 0 ) );
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

	public function test_a_linked_media_library_video_purges_the_whole_site(): void {
		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );

		$found = $this->cache()->locate( self::UUID );

		$this->assertTrue( $found['all'], 'core/video, [video], galleries and widgets reference the attachment, not the uuid' );
		$this->assertSame( array(), $found['ids'] );
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

	/**
	 * Counts whole-site purges (LiteSpeed's action is the only unguarded one).
	 *
	 * @return \ArrayObject<int, string>
	 */
	private function site_purges(): \ArrayObject {
		$calls = new \ArrayObject();
		add_action(
			'litespeed_purge',
			static function ( $tag ) use ( $calls ) {
				$calls[] = $tag;
			}
		);

		return $calls;
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

	public function test_site_wide_purges_run_at_once_then_share_one_follow_up(): void {
		$this->post( '[videooptimizer uuid="' . self::UUID . '"]', 'publish', 'wp_template_part' );
		$this->cache()->flush(); // set_up()'s change already purged the site (shared content).
		delete_transient( PageCache::LOCK );
		$calls  = $this->site_purges();
		$embeds = Plugin::instance()->embeds;

		$embeds->forget( self::UUID );
		$this->cache()->flush();
		$this->assertSame( array( '*' ), $calls->getArrayCopy(), 'the first one runs immediately' );
		$this->assertFalse( wp_next_scheduled( 'videooptimizer_purge_all' ) );

		$embeds->forget( self::UUID );
		$this->cache()->flush();
		$first = wp_next_scheduled( 'videooptimizer_purge_all' );
		$embeds->forget( self::UUID );
		$this->cache()->flush();

		$this->assertCount( 1, $calls, 'within the minute nothing runs again' );
		$this->assertNotFalse( $first );
		$this->assertSame( $first, wp_next_scheduled( 'videooptimizer_purge_all' ) );
		$this->assertCount( 1, _get_cron_array()[ $first ]['videooptimizer_purge_all'] );

		do_action( 'videooptimizer_purge_all' ); // The follow-up.
		$this->assertCount( 2, $calls );
	}

	public function test_a_changed_api_token_purges_the_whole_site(): void {
		$this->cache()->flush();
		$this->purges = array();
		$calls        = $this->site_purges();

		Plugin::instance()->settings->set_token( 'vp_new_token_value' );
		$this->cache()->flush();

		$this->assertSame( array( '*' ), $calls->getArrayCopy() );
		$this->assertSame( array( array( '', array(), true ) ), $this->purges, 'custom listeners are told too' );
	}

	public function test_saving_other_settings_does_not_purge(): void {
		Plugin::instance()->settings->set_token( 'vp_same_token_value' );
		$this->cache()->flush();
		$calls = $this->site_purges();

		Plugin::instance()->settings->set_token( 'vp_same_token_value' );
		update_option( 'videooptimizer_settings', array_merge( (array) get_option( 'videooptimizer_settings' ), array( 'woo_tab_title' => 'Videos' ) ) );
		$this->cache()->flush();

		$this->assertCount( 0, $calls );
	}

	public function test_large_batches_skip_the_search_and_purge_the_whole_site(): void {
		$this->cache()->flush();
		$this->purges = array();
		$calls        = $this->site_purges();
		$embeds       = Plugin::instance()->embeds;
		for ( $i = 0; $i <= PageCache::MAX_UUIDS; $i++ ) {
			$embeds->forget( sprintf( '00000000-0000-4000-8000-%012d', $i ) );
		}
		global $wpdb;
		$before = $wpdb->num_queries;

		$this->cache()->flush();

		$this->assertCount( PageCache::MAX_UUIDS + 1, $this->purges );
		$this->assertTrue( $this->purges[0][2] );
		$this->assertCount( 1, $calls );
		$this->assertLessThan( 10, $wpdb->num_queries - $before, 'no per-video search' );
	}

	public function test_unchanged_status_polls_do_not_purge(): void {
		$attachment = self::factory()->attachment->create();
		update_post_meta( $attachment, '_videooptimizer_uuid', self::UUID );
		update_post_meta( $attachment, '_videooptimizer_status', 'processing' );
		$this->cache()->flush();
		delete_transient( PageCache::LOCK );
		$this->purges = array();
		$calls        = $this->site_purges();

		Plugin::instance()->attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'processing',
			)
		);
		$this->cache()->flush();
		$this->assertSame( array(), $this->purges, 'nothing changed' );

		Plugin::instance()->attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'ready',
			)
		);
		$this->cache()->flush();
		$this->assertCount( 1, $calls, 'a linked video that became ready purges the site' );
	}

	public function test_a_video_no_post_uses_purges_nothing_when_it_becomes_ready(): void {
		$this->cache()->flush();
		$this->purges = array();
		$posts        = $this->purged_posts();
		$calls        = $this->site_purges();

		Plugin::instance()->attachments->apply_video(
			array(
				'uuid'   => self::UUID,
				'status' => 'ready',
			)
		);
		$this->cache()->flush();

		$this->assertSame( array( array( self::UUID, array(), false ) ), $this->purges );
		$this->assertSame( array(), $posts->getArrayCopy() );
		$this->assertCount( 0, $calls );
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

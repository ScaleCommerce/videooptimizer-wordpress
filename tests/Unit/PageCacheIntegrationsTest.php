<?php
/**
 * Purge calls into page-cache plugins.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Cache\Integrations;
use ScaleCommerce\VideoOptimizer\Cache\PageCache;

final class PageCacheIntegrationsTest extends TestCase {

	// Runs first on purpose: Brain Monkey cannot undefine the plugin functions the next tests create.
	public function test_without_a_page_cache_plugin_only_the_action_based_caches_are_notified(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/?p=5' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 5 );

		$this->assertFalse( Integrations::detected() );
		Integrations::purge( array( 5 ), false );
	}

	public function test_posts_are_purged_in_every_detected_plugin(): void {
		Functions\when( 'get_permalink' )->alias( static fn ( $id ) => 'https://example.test/?p=' . $id );
		Functions\expect( 'rocket_clean_post' )->once()->with( 5 );
		Functions\expect( 'w3tc_flush_post' )->once()->with( 5 );
		Functions\expect( 'wpsc_delete_post_cache' )->once()->with( 5 );
		Functions\expect( 'wp_cache_post_change' )->never();
		Functions\expect( 'sg_cachepress_purge_cache' )->once()->with( 'https://example.test/?p=5' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 5 );
		$GLOBALS['cloudflareHooks'] = new class() {
			/**
			 * Calls.
			 *
			 * @var array<int, mixed>
			 */
			public array $calls = array();
			public function purgeCacheByRelevantURLs( $id ): void {
				$this->calls[] = $id;
			}
		};

		$this->assertTrue( Integrations::detected() );
		Integrations::purge( array( 5 ), false );

		$this->assertSame( array( 5 ), $GLOBALS['cloudflareHooks']->calls );
		unset( $GLOBALS['cloudflareHooks'] );
	}

	public function test_site_wide_purge_flushes_page_caches_only(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\expect( 'rocket_clean_domain' )->once();
		Functions\expect( 'w3tc_flush_posts' )->once();
		Functions\expect( 'wp_cache_clear_cache' )->once()->with( 1 );
		Functions\expect( 'sg_cachepress_purge_cache' )->once()->with();
		Functions\expect( 'rocket_clean_post' )->never();
		Actions\expectDone( 'litespeed_purge' )->once()->with( '*' );
		$GLOBALS['cloudflareHooks'] = new class() {
			/**
			 * Calls.
			 *
			 * @var int
			 */
			public int $calls = 0;
			public function purgeCacheEverything(): void {
				++$this->calls;
			}
		};

		Integrations::purge( array( 5, 6 ), true );

		$this->assertSame( 1, $GLOBALS['cloudflareHooks']->calls );
		unset( $GLOBALS['cloudflareHooks'] );
	}

	public function test_the_cloudflare_hooks_object_without_the_methods_is_ignored(): void {
		Functions\when( 'get_permalink' )->justReturn( '' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		foreach ( array( 'rocket_clean_post', 'w3tc_flush_post', 'wpsc_delete_post_cache', 'sg_cachepress_purge_cache', 'rocket_clean_domain', 'w3tc_flush_posts', 'wp_cache_clear_cache' ) as $function ) {
			Functions\when( $function )->justReturn( null );
		}
		$GLOBALS['cloudflareHooks'] = new \stdClass();

		Integrations::purge( array( 5 ), false );
		Integrations::purge( array(), true );

		unset( $GLOBALS['cloudflareHooks'] );
		$this->addToAssertionCount( 1 );
	}

	public function test_stable_failures_do_not_flag_the_page(): void {
		PageCache::reset_degraded();

		PageCache::report_failure( 404, false );
		PageCache::report_failure( 400, false );
		PageCache::report_failure( 403, false );

		$this->assertFalse( PageCache::is_degraded() );
	}

	public function test_temporary_failures_keep_the_page_out_of_page_caches(): void {
		PageCache::reset_degraded();

		PageCache::report_failure( 500, false );

		$this->assertTrue( PageCache::is_degraded() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_processing_videos_flag_the_page(): void {
		PageCache::reset_degraded();

		PageCache::mark_degraded();

		$this->assertTrue( PageCache::is_degraded() );
	}

	public function test_an_outage_only_shortens_the_cache_time(): void {
		PageCache::reset_degraded();
		Actions\expectDone( 'litespeed_control_set_ttl' )->once()->with( 60 );

		PageCache::report_failure( 503, true );
		PageCache::report_failure( 0, true ); // Already flagged: no second call.

		$this->assertTrue( PageCache::is_degraded() );
	}

	public function test_degraded_flag_can_be_disabled_by_filter(): void {
		PageCache::reset_degraded();
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => 'videooptimizer_cache_degraded_pages' === $hook ? false : $value );

		PageCache::mark_degraded();

		$this->assertFalse( PageCache::is_degraded() );
	}
}

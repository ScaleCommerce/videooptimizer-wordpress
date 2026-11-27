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
		Functions\expect( 'wp_cache_post_change' )->once()->with( 5 );
		Functions\expect( 'sg_cachepress_purge_cache' )->once()->with( 'https://example.test/?p=5' );
		Actions\expectDone( 'litespeed_purge_post' )->once()->with( 5 );
		Actions\expectDone( 'cloudflare_purge_by_url' )->once()->with( array( 'https://example.test/?p=5' ), 5 );

		$this->assertTrue( Integrations::detected() );
		Integrations::purge( array( 5 ), false );
	}

	public function test_site_wide_purge_flushes_everything(): void {
		Functions\expect( 'rocket_clean_domain' )->once();
		Functions\expect( 'w3tc_flush_all' )->once();
		Functions\expect( 'wp_cache_clear_cache' )->once();
		Functions\expect( 'sg_cachepress_purge_cache' )->once()->with();
		Functions\expect( 'rocket_clean_post' )->never();
		Actions\expectDone( 'litespeed_purge_all' )->once();
		Actions\expectDone( 'cloudflare_purge_everything' )->once();

		Integrations::purge( array( 5, 6 ), true );
		$this->addToAssertionCount( 1 );
	}

	public function test_degraded_pages_are_flagged_uncacheable_once(): void {
		PageCache::reset_degraded();
		$this->assertFalse( PageCache::is_degraded() );

		PageCache::mark_degraded();

		$this->assertTrue( PageCache::is_degraded() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_degraded_flag_can_be_disabled_by_filter(): void {
		PageCache::reset_degraded();
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => 'videooptimizer_cache_degraded_pages' === $hook ? false : $value );

		PageCache::mark_degraded();

		$this->assertFalse( PageCache::is_degraded() );
	}
}

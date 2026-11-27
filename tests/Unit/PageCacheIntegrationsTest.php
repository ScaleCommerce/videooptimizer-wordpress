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
		Functions\expect( 'rocket_clean_post' )->twice();
		Functions\expect( 'w3tc_flush_post' )->twice();
		Functions\expect( 'wp_cache_post_change' )->twice();
		Functions\expect( 'sg_cachepress_purge_cache' )->twice();
		Actions\expectDone( 'litespeed_purge_post' )->twice();
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
		Integrations::purge( array( 5, 6 ), false );

		$this->assertSame( array( array( 5, 6 ) ), $GLOBALS['cloudflareHooks']->calls, 'one call with all post ids' );
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
		foreach ( array( 'rocket_clean_post', 'w3tc_flush_post', 'wp_cache_post_change', 'sg_cachepress_purge_cache', 'rocket_clean_domain', 'w3tc_flush_posts', 'wp_cache_clear_cache' ) as $function ) {
			Functions\when( $function )->justReturn( null );
		}
		$GLOBALS['cloudflareHooks'] = new \stdClass();

		Integrations::purge( array( 5 ), false );
		Integrations::purge( array(), true );

		unset( $GLOBALS['cloudflareHooks'] );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_deleted_video_is_stable_and_does_not_flag_the_page(): void {
		PageCache::reset_degraded();

		PageCache::report_failure( 404 );

		$this->assertFalse( PageCache::is_degraded() );
	}

	/**
	 * Failures that may be fixed a minute later.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function temporary_failures(): array {
		return array(
			'transport'   => array( 0 ),
			'unauthorized' => array( 401 ),
			'forbidden'   => array( 403 ),
			'precondition' => array( 428 ),
			'rate limit'  => array( 429 ),
			'server'      => array( 500 ),
			'unavailable' => array( 503 ),
		);
	}

	/**
	 * @dataProvider temporary_failures
	 */
	public function test_every_other_failure_keeps_the_page_out_of_page_caches( int $status ): void {
		PageCache::reset_degraded();

		PageCache::report_failure( $status );

		$this->assertTrue( PageCache::is_degraded() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_processing_videos_flag_the_page(): void {
		PageCache::reset_degraded();

		PageCache::mark_degraded();

		$this->assertTrue( PageCache::is_degraded() );
	}

	public function test_degraded_flag_can_be_disabled_by_filter(): void {
		PageCache::reset_degraded();
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => 'videooptimizer_cache_degraded_pages' === $hook ? false : $value );

		PageCache::mark_degraded();

		$this->assertFalse( PageCache::is_degraded() );
	}

	// Last on purpose: defines the LiteSpeed constant for the rest of the process.
	public function test_litespeed_gets_a_short_ttl_instead_of_no_cache(): void {
		PageCache::reset_degraded();
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '7' );
		}
		Actions\expectDone( 'litespeed_control_set_ttl' )->once()->with( 60 );

		PageCache::mark_degraded();
		PageCache::mark_degraded(); // Already flagged: not repeated.

		$this->assertTrue( PageCache::is_degraded() );
	}
}

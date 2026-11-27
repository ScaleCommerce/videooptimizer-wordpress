<?php
/**
 * Purge calls into common page-cache plugins.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the page caches through their public purge functions and actions. Plugins that are
 * not active are skipped: functions are checked with function_exists(), actions without listeners
 * do nothing.
 */
final class Integrations {

	/**
	 * Purge functions that signal an active page-cache plugin.
	 */
	private const FUNCTIONS = array( 'rocket_clean_post', 'w3tc_flush_post', 'wp_cache_post_change', 'wpsc_delete_post_cache', 'sg_cachepress_purge_cache' );

	/**
	 * Whether a supported page-cache plugin is active.
	 */
	public static function detected(): bool {
		foreach ( self::FUNCTIONS as $function ) {
			if ( function_exists( $function ) ) {
				return true;
			}
		}

		return defined( 'LSCWP_V' ) || null !== self::cloudflare();
	}

	/**
	 * Purges cached pages (page-cache entries only, never other caches).
	 *
	 * @param array<int, int> $post_ids Posts to purge.
	 * @param bool            $all      Purge the whole site instead.
	 */
	public static function purge( array $post_ids, bool $all ): void {
		if ( $all ) {
			self::purge_all();

			return;
		}
		$cloudflare = self::cloudflare();
		foreach ( $post_ids as $id ) {
			$url = (string) get_permalink( $id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
			if ( function_exists( 'wpsc_delete_post_cache' ) ) {
				wpsc_delete_post_cache( $id ); // The light variant: only this post's cache files.
			} elseif ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) && '' !== $url ) {
				sg_cachepress_purge_cache( $url );
			}
			do_action( 'litespeed_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
		}
		// One call for all posts: it looks up the zone and page rules once per call.
		if ( null !== $cloudflare && array() !== $post_ids && method_exists( $cloudflare, 'purgeCacheByRelevantURLs' ) ) {
			$cloudflare->purgeCacheByRelevantURLs( array_values( $post_ids ) );
		}
	}

	/**
	 * Scheduled whole-site purge (several changes within a minute share one).
	 */
	public static function purge_site(): void {
		self::purge_all();
	}

	/**
	 * Purges every cached page.
	 */
	private static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache( get_current_blog_id() );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'litespeed_purge', '*' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
		$cloudflare = self::cloudflare();
		if ( null !== $cloudflare && method_exists( $cloudflare, 'purgeCacheEverything' ) ) {
			$cloudflare->purgeCacheEverything();
		}
	}

	/**
	 * The Cloudflare plugin's hooks object (its global, or a fresh one when only the class exists).
	 */
	private static function cloudflare(): ?object {
		$hooks = $GLOBALS['cloudflareHooks'] ?? null;
		if ( is_object( $hooks ) ) {
			return $hooks;
		}
		$class = '\\Cloudflare\\APO\\WordPress\\Hooks';
		if ( class_exists( $class ) ) {
			try {
				return new $class();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return null;
	}
}

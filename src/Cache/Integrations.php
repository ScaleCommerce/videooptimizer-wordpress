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
	private const FUNCTIONS = array( 'rocket_clean_post', 'w3tc_flush_post', 'wp_cache_post_change', 'sg_cachepress_purge_cache' );

	/**
	 * Whether a supported page-cache plugin is active (LiteSpeed and Cloudflare are action-based
	 * and cannot be detected here).
	 */
	public static function detected(): bool {
		foreach ( self::FUNCTIONS as $function ) {
			if ( function_exists( $function ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Purges cached pages.
	 *
	 * @param array<int, int> $post_ids Posts to purge.
	 * @param bool            $all      Purge the whole site instead.
	 */
	public static function purge( array $post_ids, bool $all ): void {
		if ( $all ) {
			self::purge_all();

			return;
		}
		foreach ( $post_ids as $id ) {
			$url = (string) get_permalink( $id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) && '' !== $url ) {
				sg_cachepress_purge_cache( $url );
			}
			do_action( 'litespeed_purge_post', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
			if ( '' !== $url ) {
				do_action( 'cloudflare_purge_by_url', array( $url ), $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
			}
		}
	}

	/**
	 * Purges every cached page.
	 */
	private static function purge_all(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
		do_action( 'cloudflare_purge_everything' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.
	}
}

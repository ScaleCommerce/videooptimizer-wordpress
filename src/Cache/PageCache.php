<?php
/**
 * Page-cache invalidation for changed videos.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Cache;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\Embed;

defined( 'ABSPATH' ) || exit;

/**
 * When a video changes (admin edit or webhook) the cached pages that show it are purged, once at
 * the end of the request. Pages are found by searching post content and page-builder meta for
 * the uuid, so no index has to be maintained and existing content works without a backfill. Shared
 * content (template parts, reusable blocks, widgets, linked media library items) and very popular
 * videos purge the whole site instead.
 */
final class PageCache {

	public const CHANGED = 'videooptimizer_video_changed';
	public const ACTION  = 'videooptimizer_purge_video';

	/**
	 * More pages than this: purge the whole site.
	 */
	public const LIMIT = 100;

	/**
	 * Post types whose content appears on many pages.
	 */
	private const SHARED_TYPES = array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'elementor_library' );

	/**
	 * Whether the current page rendered a degraded video.
	 *
	 * @var bool
	 */
	private static bool $degraded = false;

	/**
	 * Changed uuids waiting for the end of the request.
	 *
	 * @var array<string, true>
	 */
	private array $queue = array();

	/**
	 * Attachment lookups.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Attachments $attachments Attachment lookups.
	 */
	public function __construct( Attachments $attachments ) {
		$this->attachments = $attachments;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( self::CHANGED, array( $this, 'queue' ) );
		add_action( 'shutdown', array( $this, 'flush' ), 99 );
	}

	/**
	 * Remembers a changed video.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function queue( string $uuid ): void {
		$this->queue[ strtolower( $uuid ) ] = true;
	}

	/**
	 * Purges the pages of every queued video.
	 */
	public function flush(): void {
		$uuids       = array_keys( $this->queue );
		$this->queue = array();
		foreach ( $uuids as $uuid ) {
			$this->purge( $uuid );
		}
	}

	/**
	 * Purges the pages that show a video: fires `videooptimizer_purge_video` for custom setups,
	 * then calls the supported page-cache plugins. Does nothing (and searches nothing) when
	 * neither exists.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function purge( string $uuid ): void {
		if ( ! Embed::is_uuid( $uuid ) || ( ! Integrations::detected() && ! has_action( 'videooptimizer_purge_video' ) && ! has_action( 'litespeed_purge_post' ) && ! has_action( 'cloudflare_purge_by_url' ) ) ) {
			return;
		}
		$found = $this->locate( $uuid );

		/**
		 * Fires when the cached pages of a changed video should be purged.
		 *
		 * @param string          $uuid     Video uuid.
		 * @param array<int, int> $post_ids Published posts that use the video (empty when $all).
		 * @param bool            $all      The video is used in shared content: purge the whole site.
		 */
		do_action( 'videooptimizer_purge_video', strtolower( $uuid ), $found['ids'], $found['all'] );

		Integrations::purge( $found['ids'], $found['all'] );
	}

	/**
	 * Finds the published posts that reference a video.
	 *
	 * @param string $uuid Video uuid.
	 * @return array{ids: array<int, int>, all: bool}
	 */
	public function locate( string $uuid ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( strtolower( $uuid ) ) . '%';
		$max  = self::LIMIT + 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- ad-hoc lookup on rare events, uuid is validated.
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s LIMIT %d",
				$like,
				$max
			)
		);
		$meta   = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_videooptimizer_product','_videooptimizer_variation') AND meta_value LIKE %s LIMIT %d",
				$like,
				$max
			)
		);
		$widget = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'widget\_%' AND option_value LIKE %s LIMIT 1",
				$like
			)
		);
		// phpcs:enable

		/**
		 * Filters the post types whose content appears on many pages (a match purges the whole site).
		 *
		 * @param array<int, string> $types Post types.
		 */
		$shared = (array) apply_filters( 'videooptimizer_shared_post_types', self::SHARED_TYPES );
		$all    = null !== $widget || array() !== $this->attachments->find_by_uuid( $uuid );
		$ids    = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row->post_type, $shared, true ) ) {
				$all = true;
			}
			$ids[] = 'product_variation' === $row->post_type ? (int) $row->post_parent : (int) $row->ID;
		}
		foreach ( $meta as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			if ( in_array( $post->post_type, $shared, true ) ) {
				$all = true;
			}
			$ids[] = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( count( $ids ) > self::LIMIT || count( $rows ) > self::LIMIT || count( $meta ) > self::LIMIT ) {
			$all = true;
		}

		return array(
			'ids' => $all ? array() : $ids,
			'all' => $all,
		);
	}

	/**
	 * Flags the current page as not cacheable (degraded video: unavailable, or still processing)
	 * so page caches that honor DONOTCACHEPAGE do not keep the degraded version. Front end only.
	 */
	public static function mark_degraded(): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			return;
		}

		/**
		 * Filters whether pages with a degraded video are kept out of page caches.
		 *
		 * @param bool $uncacheable Default true.
		 */
		if ( ! apply_filters( 'videooptimizer_cache_degraded_pages', true ) ) {
			return;
		}
		self::$degraded = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention honored by page-cache plugins.
		}
	}

	/**
	 * Whether a degraded video was rendered on this page.
	 */
	public static function is_degraded(): bool {
		return self::$degraded;
	}

	/**
	 * Clears the flag (tests).
	 */
	public static function reset_degraded(): void {
		self::$degraded = false;
	}
}

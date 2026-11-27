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
 * When videos change (admin edit or webhook) the cached pages that show them are purged, once at
 * the end of the request for all changed videos together. Pages are found by searching post
 * content and page-builder meta for the uuid (and for the id of linked media library items), so no
 * index has to be maintained and existing content works without a backfill. Shared content
 * (template parts, reusable blocks, widgets), very popular videos and large batches purge the
 * whole site, as one scheduled purge per minute.
 */
final class PageCache {

	public const CHANGED   = 'videooptimizer_video_changed';
	public const PURGE_ALL = 'videooptimizer_purge_all';

	/**
	 * More pages than this: purge the whole site.
	 */
	public const LIMIT = 100;

	/**
	 * More changed videos than this in one request: skip the search and purge the whole site.
	 */
	public const MAX_UUIDS = 20;

	/**
	 * Post types whose content appears on many pages.
	 */
	private const SHARED_TYPES = array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'elementor_library' );

	/**
	 * Whether the current page was already flagged as degraded.
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
		// Before output is flushed (priority < 0), so page caches that purge by response header
		// (LiteSpeed) can still send it with the current response.
		add_action( 'shutdown', array( $this, 'flush' ), -1 );
		add_action( self::PURGE_ALL, array( Integrations::class, 'purge_site' ) );
	}

	/**
	 * Remembers a changed video.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function queue( string $uuid ): void {
		if ( Embed::is_uuid( $uuid ) ) {
			$this->queue[ strtolower( $uuid ) ] = true;
		}
	}

	/**
	 * Purges the pages of every queued video with one search and one purge call.
	 */
	public function flush(): void {
		$uuids       = array_keys( $this->queue );
		$this->queue = array();
		if ( array() === $uuids || ( ! Integrations::detected() && ! has_action( 'videooptimizer_purge_video' ) ) ) {
			return;
		}

		$ids = array();
		$all = count( $uuids ) > self::MAX_UUIDS;
		foreach ( $uuids as $uuid ) {
			// Once the whole site is purged anyway, no further search is needed.
			$found = $all ? array(
				'ids' => array(),
				'all' => true,
			) : $this->locate( $uuid );
			$all   = $all || $found['all'];
			$ids   = array_merge( $ids, $found['ids'] );

			/**
			 * Fires when the cached pages of a changed video should be purged.
			 *
			 * @param string          $uuid     Video uuid.
			 * @param array<int, int> $post_ids Published posts that use the video (empty when $all).
			 * @param bool            $all      The whole site is purged: shared content, many pages or a large batch.
			 */
			do_action( 'videooptimizer_purge_video', $uuid, $found['ids'], $found['all'] || $all );
		}

		if ( $all ) {
			$this->schedule_site_purge();
		} elseif ( array() !== $ids ) {
			Integrations::purge( array_values( array_unique( $ids ) ), false );
		}
	}

	/**
	 * Finds the published posts that reference a video, directly or through a linked media
	 * library item.
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

		$post_ids = array_map( 'intval', array_merge( wp_list_pluck( $rows, 'ID' ), $meta ) );
		$all      = null !== $widget || count( $rows ) > self::LIMIT || count( $meta ) > self::LIMIT;
		foreach ( array_slice( $this->attachments->find_by_uuid( $uuid ), 0, 10 ) as $attachment_id ) {
			$post_ids = array_merge( $post_ids, $this->posts_using_attachment( $attachment_id ) );
		}

		/**
		 * Filters the post types whose content appears on many pages (a match purges the whole site).
		 *
		 * @param array<int, string> $types Post types.
		 */
		$shared = (array) apply_filters( 'videooptimizer_shared_post_types', self::SHARED_TYPES );
		$ids    = array();
		foreach ( array_unique( $post_ids ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$all   = $all || in_array( $post->post_type, $shared, true );
			$ids[] = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$all = $all || count( $ids ) > self::LIMIT;

		return array(
			'ids' => $all ? array() : $ids,
			'all' => $all,
		);
	}

	/**
	 * Published posts that embed a media library item: core/video blocks (`"id":N`), Elementor
	 * media controls and WooCommerce product galleries.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<int, int>
	 */
	private function posts_using_attachment( int $attachment_id ): array {
		global $wpdb;
		$max = self::LIMIT + 1;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- ad-hoc lookup on rare events, id is an integer.
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
				'%' . $wpdb->esc_like( '"id":' . $attachment_id . ',' ) . '%',
				'%' . $wpdb->esc_like( '"id":' . $attachment_id . '}' ) . '%',
				$max
			)
		);
		$values = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_product_image_gallery','_wc_video_gallery') AND meta_value LIKE %s LIMIT %d",
				'%' . $wpdb->esc_like( (string) $attachment_id ) . '%',
				$max
			)
		);
		// phpcs:enable

		$ids = array_map( 'intval', (array) $ids );
		foreach ( $values as $row ) {
			// LIKE matched the digits anywhere: require the whole number.
			$pattern = '_elementor_data' === $row->meta_key ? '/"id":"?' . $attachment_id . '(?!\d)/' : '/(?<!\d)' . $attachment_id . '(?!\d)/';
			if ( preg_match( $pattern, (string) $row->meta_value ) ) {
				$ids[] = (int) $row->post_id;
			}
		}

		return $ids;
	}

	/**
	 * Schedules one whole-site purge a minute from now unless one is already pending.
	 */
	private function schedule_site_purge(): void {
		if ( ! wp_next_scheduled( self::PURGE_ALL ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PURGE_ALL );
		}
	}

	/**
	 * Reports a video that could not be shown. Only processing videos and temporary failures
	 * (transport error, rate limit, 5xx) keep the page out of page caches; a missing or invalid
	 * video is stable and cacheable. During an API outage (every page is affected) the page is
	 * cached for a short time only, so the cache keeps absorbing traffic.
	 *
	 * @param int  $status Failure status (0 = transport error).
	 * @param bool $outage The API as a whole is unavailable.
	 */
	public static function report_failure( int $status, bool $outage ): void {
		if ( $outage ) {
			self::mark_degraded( true );
		} elseif ( $status >= 500 ) {
			self::mark_degraded( false );
		}
	}

	/**
	 * Flags the current page as degraded, for page caches that honor DONOTCACHEPAGE (or, for an
	 * outage, a short LiteSpeed TTL). Front end only.
	 *
	 * @param bool $short_ttl Cache for a minute instead of not at all.
	 */
	public static function mark_degraded( bool $short_ttl = false ): void {
		if ( self::$degraded || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			return;
		}

		/**
		 * Filters whether pages with a degraded video are kept out of (or cached only briefly by) page caches.
		 *
		 * @param bool $uncacheable Default true.
		 */
		if ( ! apply_filters( 'videooptimizer_cache_degraded_pages', true ) ) {
			return;
		}
		self::$degraded = true;
		if ( $short_ttl ) {
			do_action( 'litespeed_control_set_ttl', MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party hook.

			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention honored by page-cache plugins.
		}
	}

	/**
	 * Whether the current page was flagged as degraded.
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

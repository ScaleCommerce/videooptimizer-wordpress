<?php
/**
 * Page-cache invalidation for changed videos.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Cache;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * When videos change (admin edit or webhook) the cached pages that show them are purged, once at
 * the end of the request for all changed videos together. Pages are found by searching post
 * content and page-builder meta for the uuid, so no index has to be maintained and existing content
 * works without a backfill. When that cannot be precise the whole site is purged: shared content
 * (template parts, reusable blocks, widgets), videos linked to a media library item (core/video,
 * `[video]`, galleries and media widgets reference the attachment, not the uuid), very popular
 * videos and large batches. Site-wide purges run at once, at most one per minute; further ones
 * within that minute share one follow-up.
 */
final class PageCache {

	public const CHANGED   = 'videooptimizer_video_changed';
	public const PURGE_ALL = 'videooptimizer_purge_all';
	public const LOCK      = 'videooptimizer_purge_all_lock';

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
	 * A site-wide purge was requested (e.g. the API token changed).
	 *
	 * @var bool
	 */
	private bool $site = false;

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
		add_action( self::PURGE_ALL, array( $this, 'run_site_purge' ) );
		// Pages cached while the token was missing or broken show no video: purge them once it changes.
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'queue_site' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_updated' ), 10, 2 );
	}

	/**
	 * Queues a site-wide purge for the end of the request.
	 */
	public function queue_site(): void {
		$this->site = true;
	}

	/**
	 * Purges the site when the API token changed.
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $value New settings.
	 */
	public function settings_updated( $old, $value ): void {
		$before = is_array( $old ) ? ( $old['token'] ?? '' ) : '';
		$after  = is_array( $value ) ? ( $value['token'] ?? '' ) : '';
		if ( $before !== $after ) {
			$this->queue_site();
		}
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
		$site        = $this->site;
		$this->queue = array();
		$this->site  = false;
		if ( ( array() === $uuids && ! $site ) || ( ! Integrations::detected() && ! has_action( 'videooptimizer_purge_video' ) ) ) {
			return;
		}

		if ( $site ) {
			// Not about one video (e.g. the API token changed): listeners get an empty uuid.
			do_action( 'videooptimizer_purge_video', '', array(), true );
		}
		$ids = array();
		$all = $site || count( $uuids ) > self::MAX_UUIDS;
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
			 * @param string          $uuid     Video uuid; '' for a site-wide purge that concerns no single video (API token changed).
			 * @param array<int, int> $post_ids Published posts that use the video (empty when $all).
			 * @param bool            $all      The whole site is purged: shared content, many pages or a large batch.
			 */
			do_action( 'videooptimizer_purge_video', $uuid, $found['ids'], $found['all'] || $all );
		}

		if ( $all ) {
			$this->request_site_purge();
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
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s LIMIT %d",
				$like,
				$max
			)
		);
		$meta = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_videooptimizer_product','_videooptimizer_variation') AND meta_value LIKE %s LIMIT %d",
				$like,
				$max
			)
		);
		// Video lists show whatever videos a library has right now and the detail element a video
		// chosen by the URL: their pages cannot be found by uuid, so every video change purges them.
		$lists  = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
				'%' . $wpdb->esc_like( 'videooptimizer/video-list' ) . '%',
				'%' . $wpdb->esc_like( 'layout="video-list"' ) . '%',
				$max
			)
		);
		$lists  = array_merge(
			$lists,
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT %d",
					'%' . $wpdb->esc_like( '"video-list"' ) . '%',
					$max
				)
			)
		);
		$widget = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'widget\_%' AND option_value LIKE %s LIMIT 1",
				$like
			)
		);
		// phpcs:enable

		$post_ids = array_map( 'intval', array_merge( wp_list_pluck( $rows, 'ID' ), $meta, $lists ) );
		// A linked media library video is referenced by attachment id (core/video, [video], galleries,
		// media widgets, thumbnails): not findable by uuid, so purge everything.
		$all = null !== $widget || count( $rows ) > self::LIMIT || count( $meta ) > self::LIMIT || count( $lists ) > self::LIMIT || array() !== $this->attachments->find_by_uuid( $uuid );

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
	 * Purges the whole site now unless that already happened within the last minute; then exactly
	 * one follow-up is scheduled, which covers every request in between.
	 */
	private function request_site_purge(): void {
		if ( false === get_transient( self::LOCK ) ) {
			$this->run_site_purge();

			return;
		}
		if ( ! wp_next_scheduled( self::PURGE_ALL ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PURGE_ALL );
		}
	}

	/**
	 * Whole-site purge (also the scheduled follow-up). Starts the one-minute window.
	 *
	 * With LiteSpeed Cache, a purge made during cron or after output is queued by that plugin and
	 * goes out with the next response (checked in its Purge::_add()), so the follow-up works there too.
	 */
	public function run_site_purge(): void {
		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );
		Integrations::purge_site();
	}

	/**
	 * Reports a video that could not be shown. Everything but a stable "does not exist" (404) keeps
	 * the page out of page caches: the failure may be fixed a minute later (processing, transport
	 * error, rate limit, token or permission problems, 5xx), and a cached page would keep it.
	 *
	 * @param int $status Failure status (0 = transport error).
	 */
	public static function report_failure( int $status ): void {
		if ( 404 !== $status ) {
			self::mark_degraded();
		}
	}

	/**
	 * Flags the current page as not cacheable (DONOTCACHEPAGE); with LiteSpeed Cache active it is
	 * cached for 60 s instead. Front end only.
	 */
	public static function mark_degraded(): void {
		if ( self::$degraded || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
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
		if ( defined( 'LSCWP_V' ) ) {
			// LiteSpeed Cache honors DONOTCACHEPAGE too, which would make a TTL pointless: it gets the TTL only.
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

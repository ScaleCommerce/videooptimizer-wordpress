<?php
/**
 * Cached video list pages.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Cache\PageCache;

defined( 'ABSPATH' ) || exit;

/**
 * One page of the videos of a library (status ready only) for the video list element. Pages are
 * cached in transients for five minutes, keyed by library, tags, cursor and size plus a generation
 * counter that is bumped whenever a video changes (admin edit, webhook), so edits show up at
 * once. A failure is remembered for 60 seconds and, like for embeds, a transport error or rate
 * limit flags the API as unavailable site-wide.
 */
class VideoLists {

	public const TTL            = 5 * MINUTE_IN_SECONDS;
	public const TTL_FAILURE    = MINUTE_IN_SECONDS;
	public const MAX_CALLS      = 3;
	public const GENERATION_KEY = 'videooptimizer_list_generation';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Failure status of the last lookup that failed in this request.
	 *
	 * @var int|null
	 */
	private ?int $failure = null;

	/**
	 * Ownership cache seeded from list results (null: not seeded).
	 *
	 * @var Ownership|null
	 */
	private ?Ownership $ownership;

	/**
	 * Constructor.
	 *
	 * @param Client         $client    API client.
	 * @param Ownership|null $ownership Ownership cache to seed with the listed videos.
	 */
	public function __construct( Client $client, ?Ownership $ownership = null ) {
		$this->client    = $client;
		$this->ownership = $ownership;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( PageCache::CHANGED, array( $this, 'bump' ) );
	}

	/**
	 * Invalidates every cached list page.
	 */
	public function bump(): void {
		update_option( self::GENERATION_KEY, (int) get_option( self::GENERATION_KEY, 0 ) + 1, false );
	}

	/**
	 * Whether a cursor looks like one the API issued (guards the query string).
	 *
	 * @param mixed $cursor Candidate.
	 */
	public static function is_cursor( mixed $cursor ): bool {
		return is_string( $cursor ) && 1 === preg_match( '/^[A-Za-z0-9_=+\/.:-]{1,200}$/D', $cursor );
	}

	/**
	 * Status of the failed lookup (0 = transport error), null when the last lookup worked.
	 */
	public function failure(): ?int {
		return $this->failure;
	}

	/**
	 * One page of ready videos.
	 *
	 * @param string      $library_id Library uuid ('' = every library of the token).
	 * @param string      $tags       Comma separated tag names (all required).
	 * @param string|null $cursor     Cursor of the page.
	 * @param int         $limit      Page size.
	 * @return array{items: array<int, array<string, mixed>>, next: ?string}|null Null when the list could not be loaded.
	 */
	public function page( string $library_id, string $tags, ?string $cursor, int $limit ): ?array {
		$this->failure = null;
		$tag_list      = array_values( array_filter( array_map( 'trim', explode( ',', $tags ) ), static fn ( string $tag ): bool => '' !== $tag ) );
		$key           = 'videooptimizer_list_' . md5( (string) wp_json_encode( array( (int) get_option( self::GENERATION_KEY, 0 ), strtolower( $library_id ), $tag_list, $cursor, $limit ) ) );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			if ( isset( $cached['__failed'] ) ) {
				$this->failure = (int) $cached['__failed'];

				return null;
			}

			return array(
				'items' => (array) ( $cached['items'] ?? array() ),
				'next'  => is_string( $cached['next'] ?? null ) ? $cached['next'] : null,
			);
		}
		if ( false !== get_transient( EmbedRepository::DOWN_KEY ) ) {
			$this->failure = ApiException::UNREACHABLE;

			return null;
		}
		// A cursor comes from the query string: new ones share the site-wide lookup budget.
		if ( null !== $cursor && ! LookupLimit::reserve() ) {
			// Temporary (429), not a 404: the page must stay out of page caches.
			$this->failure = 429;

			return null;
		}

		// Only ready videos are shown: when an API page holds fewer, ask for the missing ones from
		// the following pages (at most MAX_CALLS calls). Each call asks for exactly what is still
		// missing, so a page never exceeds its size and the next cursor is exact.
		$items   = array();
		$next    = null;
		$from    = $cursor;
		$missing = $limit;
		for ( $call = 0; $call < self::MAX_CALLS && $missing > 0; $call++ ) {
			try {
				$page = $this->client->list_videos_page( '' !== $library_id ? $library_id : null, $from, $missing, array( 'tags' => $tag_list ) );
			} catch ( ApiException $e ) {
				if ( array() !== $items ) {
					break; // Show what was found; the failed part is retried with the next request.
				}
				$this->failure = $e->status();
				set_transient( $key, array( '__failed' => $e->status() ), self::TTL_FAILURE );
				// Only the first page (no visitor-chosen cursor) may flag the API as down.
				if ( null === $cursor && in_array( $e->status(), array( ApiException::UNREACHABLE, 429, 502, 503, 504 ), true ) ) {
					set_transient( EmbedRepository::DOWN_KEY, $e->status(), EmbedRepository::TTL_DOWN );
				}

				return null;
			}
			$cards = self::cards( $page['items'] );
			// Listed with the token, so these videos belong to the organization: a card's detail
			// page then needs no lookup (and no share of the lookup budget).
			foreach ( $cards as $card ) {
				$this->ownership?->seed( $card['uuid'], $card['library'] );
			}
			$items   = array_merge( $items, $cards );
			$missing = $limit - count( $items );
			$from    = self::is_cursor( $page['next_cursor'] ) ? $page['next_cursor'] : null;
			$next    = $from;
			if ( null === $from ) {
				break;
			}
		}

		$result = array(
			'items' => $items,
			'next'  => $next,
		);
		set_transient( $key, $result, self::TTL );

		return $result;
	}

	/**
	 * Card data of the ready videos in an API page.
	 *
	 * @param array<int, array<string, mixed>> $videos API items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function cards( array $videos ): array {
		$cards = array();
		foreach ( $videos as $video ) {
			$uuid = $video['uuid'] ?? null;
			if ( ! Embed::is_uuid( $uuid ) || ! ( 'ready' === ( $video['status'] ?? '' ) || true === ( $video['ready'] ?? false ) ) ) {
				continue;
			}
			$poster             = $video['poster_url'] ?? $video['thumbnail_url'] ?? null;
			[ $width, $height ] = Embed::parse_resolution( $video['resolution'] ?? null );
			$cards[]            = array(
				'uuid'     => strtolower( (string) $uuid ),
				'library'  => is_string( $video['library_id'] ?? null ) ? strtolower( $video['library_id'] ) : '',
				'title'    => Embed::clean_title( $video['title'] ?? null ),
				'poster'   => is_string( $poster ) && preg_match( '~^https?://~i', $poster ) ? $poster : null,
				'duration' => is_numeric( $video['duration'] ?? null ) ? (int) round( (float) $video['duration'] ) : null,
				'width'    => $width,
				'height'   => $height,
			);
		}

		return $cards;
	}
}

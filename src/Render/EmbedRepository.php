<?php
/**
 * Cached embed lookups.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Caches the public /embed/{uuid} payload in a transient. CDN source URLs are stable, so a ready
 * video is cached for an hour; a video that is still processing only briefly, so it appears as
 * soon as encoding is done; a failed lookup for 60 seconds, so an outage never hammers the API
 * or slows down every page view. After a transport error, a rate limit or a gateway error the whole
 * API is flagged as unavailable for a minute: videos that are not cached are then not even asked
 * for (cached ones are still served), and the flag is never renewed while it is set.
 */
class EmbedRepository {

	public const TTL_READY      = HOUR_IN_SECONDS;
	public const TTL_PROCESSING = MINUTE_IN_SECONDS;
	public const TTL_FAILURE    = MINUTE_IN_SECONDS;
	public const TTL_DOWN       = MINUTE_IN_SECONDS;

	/**
	 * Transient flagging the whole API as unavailable (fail fast, site-wide).
	 */
	public const DOWN_KEY = 'videooptimizer_api_down';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Per-request memo.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $memo = array();

	/**
	 * Why a lookup returned null, per uuid (this request only).
	 *
	 * @var array<string, array{status: int, outage: bool}>
	 */
	private array $failures = array();

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Normalized embed data, or null when the video does not exist / cannot be loaded.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<string, mixed>|null See Embed::normalize().
	 */
	public function get( string $uuid ): ?array {
		if ( ! Embed::is_uuid( $uuid ) ) {
			return null;
		}
		$uuid = strtolower( $uuid );
		if ( array_key_exists( $uuid, $this->memo ) ) {
			return $this->memo[ $uuid ];
		}

		$cached = get_transient( self::key( $uuid ) );
		if ( is_array( $cached ) ) {
			$this->memo[ $uuid ] = isset( $cached['__failed'] ) ? null : $cached;
			if ( isset( $cached['__failed'] ) ) {
				$this->failures[ $uuid ] = array(
					'status' => (int) $cached['__failed'],
					'outage' => self::is_outage_status( (int) $cached['__failed'] ),
				);
			}

			return $this->memo[ $uuid ];
		}

		// Fail fast: no API call (and no per-video failure marker) while the API is flagged down.
		if ( false !== get_transient( self::DOWN_KEY ) ) {
			$this->memo[ $uuid ]     = null;
			$this->failures[ $uuid ] = array(
				'status' => ApiException::UNREACHABLE,
				'outage' => true,
			);

			return null;
		}

		try {
			$embed = Embed::normalize( $uuid, $this->client->get_embed( $uuid ) );
			set_transient( self::key( $uuid ), $embed, $embed['playable'] ? self::TTL_READY : self::TTL_PROCESSING );
		} catch ( ApiException $e ) {
			$embed                   = null;
			$this->failures[ $uuid ] = array(
				'status' => $e->status(),
				'outage' => self::indicates_outage( $e ),
			);
			set_transient( self::key( $uuid ), array( '__failed' => $e->status() ), self::TTL_FAILURE );
			if ( self::indicates_outage( $e ) ) {
				set_transient( self::DOWN_KEY, $e->status(), self::TTL_DOWN );
			}
		}

		$this->memo[ $uuid ] = $embed;

		return $embed;
	}

	/**
	 * The normalized embed when it is already cached (this request or transient); never calls the API.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<string, mixed>|null
	 */
	public function cached( string $uuid ): ?array {
		if ( ! Embed::is_uuid( $uuid ) ) {
			return null;
		}
		$uuid = strtolower( $uuid );
		if ( array_key_exists( $uuid, $this->memo ) ) {
			return $this->memo[ $uuid ];
		}
		$cached = get_transient( self::key( $uuid ) );

		return is_array( $cached ) && ! isset( $cached['__failed'] ) ? $cached : null;
	}

	/**
	 * Why the last lookup of a video returned null in this request: the API status and whether
	 * the API as a whole is unavailable. Null when the video loaded or was not looked up.
	 *
	 * @param string $uuid Video uuid.
	 * @return array{status: int, outage: bool}|null
	 */
	public function failure( string $uuid ): ?array {
		return $this->failures[ strtolower( $uuid ) ] ?? null;
	}

	/**
	 * Drops the cached payload (after admin edits and webhook events).
	 *
	 * @param string $uuid    Video uuid.
	 * @param bool   $changed Whether the video really changed (cached pages showing it are purged);
	 *                        false for status polls that found nothing new.
	 */
	public function forget( string $uuid, bool $changed = true ): void {
		$uuid = strtolower( $uuid );
		unset( $this->memo[ $uuid ] );
		delete_transient( self::key( $uuid ) );
		if ( $changed ) {
			/**
			 * Fires when a video changed (admin edit, webhook, status change).
			 *
			 * @param string $uuid Video uuid.
			 */
			do_action( 'videooptimizer_video_changed', $uuid );
		}
	}

	/**
	 * Transport errors, rate limiting and gateway errors mean the API as a whole is unavailable; a
	 * 404 or a plain 500 belongs to one video and stays in that video's own failure marker.
	 *
	 * @param ApiException $e Exception.
	 */
	private static function indicates_outage( ApiException $e ): bool {
		return self::is_outage_status( $e->status() );
	}

	/**
	 * Whether a status means the API as a whole is unavailable.
	 *
	 * @param int $status Status.
	 */
	private static function is_outage_status( int $status ): bool {
		return in_array( $status, array( ApiException::UNREACHABLE, 429, 502, 503, 504 ), true );
	}

	/**
	 * Transient key.
	 *
	 * @param string $uuid Video uuid.
	 */
	private static function key( string $uuid ): string {
		return 'videooptimizer_embed_' . $uuid;
	}
}

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
 * or slows down every page view.
 */
class EmbedRepository {

	public const TTL_READY      = HOUR_IN_SECONDS;
	public const TTL_PROCESSING = MINUTE_IN_SECONDS;
	public const TTL_FAILURE    = MINUTE_IN_SECONDS;

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

			return $this->memo[ $uuid ];
		}

		try {
			$embed = Embed::normalize( $uuid, $this->client->get_embed( $uuid ) );
			set_transient( self::key( $uuid ), $embed, $embed['playable'] ? self::TTL_READY : self::TTL_PROCESSING );
		} catch ( ApiException $e ) {
			$embed = null;
			set_transient( self::key( $uuid ), array( '__failed' => $e->status() ), self::TTL_FAILURE );
		}

		$this->memo[ $uuid ] = $embed;

		return $embed;
	}

	/**
	 * Drops the cached payload (after admin edits and webhook events).
	 *
	 * @param string $uuid Video uuid.
	 */
	public function forget( string $uuid ): void {
		$uuid = strtolower( $uuid );
		unset( $this->memo[ $uuid ] );
		delete_transient( self::key( $uuid ) );
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

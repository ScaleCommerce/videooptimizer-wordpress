<?php
/**
 * Ownership check for videos named by a URL.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /embed/{uuid}` is public and proves nothing: a visitor could put any organization's video
 * (and its title) on a page of this site by editing the URL. A uuid taken from the URL is therefore
 * verified once with the token (`GET /videos/{uuid}` answers 404 for foreign and unknown videos)
 * and, when the element has a library, must belong to it. Results are cached (found: one hour,
 * not found: five minutes). Lookups driven by query strings never mark the API as down and are
 * limited site-wide (LookupLimit): beyond its budget they are answered "temporarily unavailable"
 * (never cached, never a 404) without calling the API.
 */
class Ownership {

	public const FOUND         = 'found';
	public const NOT_FOUND     = 'not_found';
	public const UNAVAILABLE   = 'unavailable';
	public const TTL_FOUND     = HOUR_IN_SECONDS;
	public const TTL_NOT_FOUND = 5 * MINUTE_IN_SECONDS;
	public const TTL_ERROR     = MINUTE_IN_SECONDS;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Per-request memo: uuid => [state, library id].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $memo = array();

	/**
	 * Fingerprint of the current API token (cache keys are scoped by it).
	 *
	 * @var callable
	 */
	private $fingerprint;

	/**
	 * Constructor.
	 *
	 * @param Client        $client      API client.
	 * @param callable|null $fingerprint Returns a short fingerprint of the token (keys change with the token).
	 */
	public function __construct( Client $client, ?callable $fingerprint = null ) {
		$this->client      = $client;
		$this->fingerprint = $fingerprint ?? static fn (): string => '';
	}

	/**
	 * Hooks registration: a changed or deleted video (admin edit, webhook) drops its verification.
	 */
	public function register_hooks(): void {
		add_action( \ScaleCommerce\VideoOptimizer\Cache\PageCache::CHANGED, array( $this, 'forget' ) );
	}

	/**
	 * Drops the cached verification of a video.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function forget( string $uuid ): void {
		$uuid = strtolower( $uuid );
		unset( $this->memo[ $uuid ] );
		delete_transient( $this->key( $uuid ) );
	}

	/**
	 * Transient key, scoped by the token (another organization's token never reads these results).
	 *
	 * @param string $uuid Lower-case uuid.
	 */
	private function key( string $uuid ): string {
		return 'videooptimizer_own_' . ( $this->fingerprint )() . '_' . $uuid;
	}

	/**
	 * Whether the video belongs to the organization (and the library, when given).
	 *
	 * @param string $uuid       Video uuid.
	 * @param string $library_id Required library uuid ('' = any).
	 * @return string FOUND, NOT_FOUND or UNAVAILABLE (the API could not answer: temporary).
	 */
	public function check( string $uuid, string $library_id = '' ): string {
		if ( ! Embed::is_uuid( $uuid ) ) {
			return self::NOT_FOUND;
		}
		$uuid            = strtolower( $uuid );
		[ $state, $lib ] = $this->lookup( $uuid );
		if ( self::FOUND === $state && '' !== $library_id && strtolower( $library_id ) !== $lib ) {
			return self::NOT_FOUND;
		}

		return $state;
	}

	/**
	 * Cached verification of one video.
	 *
	 * @param string $uuid Lower-case uuid.
	 * @return array{0: string, 1: string} State and library id.
	 */
	private function lookup( string $uuid ): array {
		if ( isset( $this->memo[ $uuid ] ) ) {
			return $this->memo[ $uuid ];
		}
		$key    = $this->key( $uuid );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			$this->memo[ $uuid ] = array( (string) $cached['state'], (string) ( $cached['library'] ?? '' ) );

			return $this->memo[ $uuid ];
		}

		// Cheap guard first: a marked-down API or a flood of new uuids never reaches the API.
		if ( false !== get_transient( EmbedRepository::DOWN_KEY ) ) {
			return array( self::UNAVAILABLE, '' );
		}
		if ( ! LookupLimit::reserve() ) {
			// Temporary: neither cached nor a 404 (a later request may be answered).
			return array( self::UNAVAILABLE, '' );
		}

		try {
			$video   = $this->client->get_video( $uuid );
			$library = is_string( $video['library_id'] ?? null ) ? strtolower( $video['library_id'] ) : '';
			$result  = array( self::FOUND, $library );
			set_transient(
				$key,
				array(
					'state'   => self::FOUND,
					'library' => $library,
				),
				self::TTL_FOUND
			);
		} catch ( ApiException $e ) {
			if ( 404 === $e->status() ) {
				$result = array( self::NOT_FOUND, '' );
				set_transient( $key, array( 'state' => self::NOT_FOUND ), self::TTL_NOT_FOUND );
			} else {
				// Outage, rate limit, token problems: temporary, remembered briefly, and (on purpose)
				// never the site-wide "down" marker: a visitor must not be able to trip it.
				$result = array( self::UNAVAILABLE, '' );
				set_transient( $key, array( 'state' => self::UNAVAILABLE ), self::TTL_ERROR );
			}
		}
		$this->memo[ $uuid ] = $result;

		return $result;
	}
}

<?php
/**
 * Media library attachment <-> VideoOptimizer video mapping.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores which VideoOptimizer video belongs to a media library video, and its processing state.
 * The original file stays untouched in the media library; VideoOptimizer is used for delivery
 * once the video is ready.
 */
class Attachments {

	public const META_UUID    = '_videooptimizer_uuid';
	public const META_STATUS  = '_videooptimizer_status';
	public const META_ERROR   = '_videooptimizer_error';
	public const META_LIBRARY = '_videooptimizer_library';
	public const META_PENDING = '_videooptimizer_pending';

	public const STATUSES = array( 'processing', 'ready', 'failed' );

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Constructor.
	 *
	 * @param Client          $client API client.
	 * @param EmbedRepository $embeds Embed cache.
	 */
	public function __construct( Client $client, EmbedRepository $embeds ) {
		$this->client = $client;
		$this->embeds = $embeds;
	}

	/**
	 * Whether the attachment is a video.
	 *
	 * @param int $id Attachment id.
	 */
	public function is_video( int $id ): bool {
		return 'attachment' === get_post_type( $id ) && str_starts_with( (string) get_post_mime_type( $id ), 'video/' );
	}

	/**
	 * Links an attachment to a VideoOptimizer video.
	 *
	 * @param int    $id      Attachment id.
	 * @param string $uuid    Video uuid.
	 * @param string $status  processing|ready|failed.
	 * @param string $library Library id.
	 */
	public function link( int $id, string $uuid, string $status = 'processing', string $library = '' ): void {
		update_post_meta( $id, self::META_UUID, strtolower( $uuid ) );
		update_post_meta( $id, self::META_STATUS, in_array( $status, self::STATUSES, true ) ? $status : 'processing' );
		update_post_meta( $id, self::META_LIBRARY, $library );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_PENDING );
		StatusSync::schedule();
	}

	/**
	 * Removes the link (the VideoOptimizer video itself is kept).
	 *
	 * @param int $id Attachment id.
	 */
	public function unlink( int $id ): void {
		foreach ( array( self::META_UUID, self::META_STATUS, self::META_ERROR, self::META_LIBRARY, self::META_PENDING ) as $key ) {
			delete_post_meta( $id, $key );
		}
	}

	/**
	 * Current state of an attachment.
	 *
	 * @param int $id Attachment id.
	 * @return array{id: int, uuid: string, status: string, error: string, library: string, pending: bool}
	 */
	public function state( int $id ): array {
		$uuid   = (string) get_post_meta( $id, self::META_UUID, true );
		$status = (string) get_post_meta( $id, self::META_STATUS, true );

		return array(
			'id'      => $id,
			'uuid'    => Embed::is_uuid( $uuid ) ? $uuid : '',
			'status'  => '' !== $uuid ? ( in_array( $status, self::STATUSES, true ) ? $status : 'processing' ) : 'none',
			'error'   => (string) get_post_meta( $id, self::META_ERROR, true ),
			'library' => (string) get_post_meta( $id, self::META_LIBRARY, true ),
			'pending' => '' !== (string) get_post_meta( $id, self::META_PENDING, true ),
		);
	}

	/**
	 * The uuid of a linked attachment whose video is ready for delivery, else null.
	 *
	 * @param int $id Attachment id.
	 */
	public function ready_uuid( int $id ): ?string {
		if ( $id <= 0 ) {
			return null;
		}
		$state = $this->state( $id );

		return 'ready' === $state['status'] && '' !== $state['uuid'] ? $state['uuid'] : null;
	}

	/**
	 * Re-reads the video status from the API for one attachment.
	 *
	 * @param int $id Attachment id.
	 * @return array{id: int, uuid: string, status: string, error: string, library: string, pending: bool}
	 */
	public function refresh( int $id ): array {
		$state = $this->state( $id );
		if ( '' === $state['uuid'] ) {
			return $state;
		}

		try {
			$this->apply_video( $this->client->get_video( $state['uuid'] ) );
		} catch ( ApiException $e ) {
			if ( 404 === $e->status() ) {
				update_post_meta( $id, self::META_STATUS, 'failed' );
				update_post_meta( $id, self::META_ERROR, __( 'The video was deleted in VideoOptimizer.', 'videooptimizer' ) );
			}
		}

		return $this->state( $id );
	}

	/**
	 * Applies a Video payload (API response or webhook `data`) to every linked attachment.
	 *
	 * @param array<string, mixed> $video Video object.
	 * @return int Number of attachments updated.
	 */
	public function apply_video( array $video ): int {
		$uuid = $video['uuid'] ?? null;
		if ( ! Embed::is_uuid( $uuid ) ) {
			return 0;
		}
		$this->embeds->forget( (string) $uuid );

		$status = is_string( $video['status'] ?? null ) && in_array( $video['status'], self::STATUSES, true ) ? $video['status'] : 'processing';
		$ids    = $this->find_by_uuid( (string) $uuid );
		foreach ( $ids as $id ) {
			update_post_meta( $id, self::META_STATUS, $status );
			if ( 'failed' === $status ) {
				update_post_meta( $id, self::META_ERROR, is_string( $video['error'] ?? null ) ? sanitize_text_field( $video['error'] ) : '' );
			} else {
				delete_post_meta( $id, self::META_ERROR );
			}
		}

		return count( $ids );
	}

	/**
	 * Attachments linked to a uuid.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<int, int>
	 */
	public function find_by_uuid( string $uuid ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_key'       => self::META_UUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed meta lookup, rare.
				'meta_value'     => strtolower( $uuid ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Attachments still processing (for the fallback status poll).
	 *
	 * @param int $limit Max items.
	 * @return array<int, int>
	 */
	public function processing_ids( int $limit = 50 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'processing', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Attachments waiting for the browser to upload them (auto-send on local/non-public sites).
	 *
	 * @param int $limit Max items.
	 * @return array<int, int>
	 */
	public function pending_ids( int $limit = 20 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Whether a URL can be fetched by VideoOptimizer (public https host).
	 *
	 * @param string $url File URL.
	 */
	public static function is_publicly_reachable( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		if ( 'localhost' === $host || preg_match( '/\.(local|localhost|test|example|invalid|internal|lan|home)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( 'local' === wp_get_environment_type() ) {
			return false;
		}

		return true;
	}
}

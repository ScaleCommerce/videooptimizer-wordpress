<?php
/**
 * Server-side transfer of media library videos.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a media library video to VideoOptimizer by URL (VideoOptimizer downloads the file), which
 * works for publicly reachable https sites. Local or protected sites use the browser upload
 * instead (see assets/src/media).
 */
class Sender {

	public const LIBRARY_CACHE = 'videooptimizer_upload_library';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Client      $client      API client.
	 * @param Attachments $attachments Attachment mapping.
	 */
	public function __construct( Settings $settings, Client $client, Attachments $attachments ) {
		$this->settings    = $settings;
		$this->client      = $client;
		$this->attachments = $attachments;
	}

	/**
	 * Whether server-side (URL) transfer can be used for this attachment.
	 *
	 * @param int $id Attachment id.
	 */
	public function can_send_by_url( int $id ): bool {
		$url = wp_get_attachment_url( $id );

		return 'auto' === $this->settings->string( 'transfer' ) && is_string( $url ) && Attachments::is_publicly_reachable( $url );
	}

	/**
	 * Sends an attachment by URL and links it.
	 *
	 * @param int $id Attachment id.
	 * @return array<string, mixed> Attachment state.
	 * @throws ApiException When the API rejects the request.
	 */
	public function send_by_url( int $id ): array {
		$url = wp_get_attachment_url( $id );
		if ( ! is_string( $url ) || ! $this->attachments->is_video( $id ) ) {
			throw new ApiException( __( 'This attachment is not a video.', 'videooptimizer' ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}

		$library = $this->upload_library();
		$result  = $this->client->ingest_url(
			array(
				'library_id' => $library,
				'source_url' => $url,
				'title'      => get_the_title( $id ),
			)
		);
		$uuid    = is_string( $result['uuid'] ?? null ) ? $result['uuid'] : '';
		if ( '' === $uuid ) {
			throw new ApiException( __( 'VideoOptimizer did not return a video id.', 'videooptimizer' ), 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}
		$this->attachments->link( $id, $uuid, 'processing', $library );

		return $this->attachments->state( $id );
	}

	/**
	 * The library new uploads go to: the configured default, else the first media-managed one.
	 *
	 * @throws ApiException When no usable library exists.
	 */
	public function upload_library(): string {
		$configured = $this->settings->string( 'default_library' );
		if ( '' !== $configured ) {
			return $configured;
		}

		$cached = get_transient( self::LIBRARY_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		foreach ( $this->client->list_libraries() as $library ) {
			if ( false !== ( $library['media_managed'] ?? true ) && is_string( $library['id'] ?? null ) ) {
				set_transient( self::LIBRARY_CACHE, $library['id'], HOUR_IN_SECONDS );

				return $library['id'];
			}
		}

		throw new ApiException( __( 'No media-managed library found. Please create a library under VideoOptimizer → Libraries.', 'videooptimizer' ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
	}
}

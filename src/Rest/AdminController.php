<?php
/**
 * Admin REST proxy.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Rest;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Media\DeleteOffers;
use ScaleCommerce\VideoOptimizer\Media\Usage;
use ScaleCommerce\VideoOptimizer\Media\Sender;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * /wp-json/videooptimizer/v1/* — the admin UI, block editor, Elementor and the media library talk
 * only to these routes; the API token never leaves the server. Every route checks a capability
 * (filterable via `videooptimizer_capability`), request bodies are reduced to an allowlist of
 * keys, and path segments are validated before they are forwarded.
 */
class AdminController {

	public const NAMESPACE = 'videooptimizer/v1';

	private const UUID   = '(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
	private const LIB_ID = '(?P<id>[A-Za-z0-9-]{1,64})';

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
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Delete offers.
	 *
	 * @var DeleteOffers
	 */
	private DeleteOffers $delete_offers;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings      Settings.
	 * @param Client          $client        API client.
	 * @param EmbedRepository $embeds        Embed cache.
	 * @param Attachments     $attachments   Attachment mapping.
	 * @param DeleteOffers    $delete_offers Delete offers.
	 */
	public function __construct( Settings $settings, Client $client, EmbedRepository $embeds, Attachments $attachments, DeleteOffers $delete_offers ) {
		$this->settings      = $settings;
		$this->client        = $client;
		$this->embeds        = $embeds;
		$this->attachments   = $attachments;
		$this->delete_offers = $delete_offers;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route table: [methods, path, handler, capability].
	 *
	 * @return array<int, array{0: string, 1: non-falsy-string, 2: string, 3: string}>
	 */
	private function routes(): array {
		return array(
			array( 'GET', '/status', 'status', 'upload_files' ),
			array( 'GET', '/settings', 'get_settings', 'manage_options' ),
			array( 'POST', '/settings', 'save_settings', 'manage_options' ),
			array( 'DELETE', '/settings/token', 'delete_token', 'manage_options' ),
			array( 'POST', '/settings/test', 'test_connection', 'manage_options' ),

			array( 'GET', '/libraries', 'list_libraries', 'upload_files' ),
			array( 'POST', '/libraries', 'create_library', 'manage_options' ),
			array( 'PATCH', '/libraries/' . self::LIB_ID, 'update_library', 'manage_options' ),
			array( 'DELETE', '/libraries/' . self::LIB_ID, 'delete_library', 'manage_options' ),
			array( 'POST', '/libraries/' . self::LIB_ID . '/reprocess', 'reprocess_library', 'manage_options' ),
			array( 'GET', '/encodings', 'list_encodings', 'upload_files' ),
			array( 'GET', '/upload-library', 'upload_library', 'upload_files' ),

			array( 'GET', '/videos', 'list_videos', 'upload_files' ),
			array( 'POST', '/videos/ingest', 'ingest', 'upload_files' ),
			array( 'POST', '/videos/upload/initiate', 'initiate_upload', 'upload_files' ),
			array( 'POST', '/videos/upload/complete', 'complete_upload', 'upload_files' ),
			array( 'GET', '/videos/' . self::UUID, 'get_video', 'upload_files' ),
			array( 'PATCH', '/videos/' . self::UUID, 'update_video', 'upload_files' ),
			array( 'DELETE', '/videos/' . self::UUID, 'delete_video', 'delete_others_posts' ),
			array( 'GET', '/videos/' . self::UUID . '/thumbnails', 'list_thumbnails', 'upload_files' ),
			array( 'POST', '/videos/' . self::UUID . '/thumbnail', 'select_thumbnail', 'upload_files' ),
			array( 'POST', '/videos/' . self::UUID . '/poster/initiate', 'initiate_poster', 'upload_files' ),
			array( 'POST', '/videos/' . self::UUID . '/poster/complete', 'complete_poster', 'upload_files' ),
			array( 'POST', '/videos/' . self::UUID . '/poster/select', 'select_poster', 'upload_files' ),
			array( 'DELETE', '/videos/' . self::UUID . '/poster', 'delete_poster', 'upload_files' ),

			array( 'GET', '/videos/' . self::UUID . '/usage', 'video_usage', 'upload_files' ),
			array( 'GET', '/delete-offers', 'list_delete_offers', 'upload_files' ),
			array( 'POST', '/delete-offers/' . self::UUID, 'resolve_delete_offer', 'upload_files' ),

			array( 'GET', '/attachments/(?P<attachment>\d+)', 'attachment_state', 'upload_files' ),
			array( 'POST', '/attachments/(?P<attachment>\d+)/link', 'attachment_link', 'upload_files' ),
			array( 'DELETE', '/attachments/(?P<attachment>\d+)/link', 'attachment_unlink', 'upload_files' ),
			array( 'POST', '/attachments/(?P<attachment>\d+)/send-url', 'attachment_send_url', 'upload_files' ),
		);
	}

	/**
	 * Registers all routes.
	 */
	public function register_routes(): void {
		register_rest_field(
			'attachment',
			'videooptimizer',
			array(
				'get_callback' => fn ( array $item ) => str_starts_with( (string) ( $item['mime_type'] ?? '' ), 'video/' ) && current_user_can( 'upload_files' ) ? $this->attachments->state( (int) $item['id'] ) : null,
				'schema'       => array(
					'description' => 'VideoOptimizer delivery state of a media library video.',
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
			)
		);

		foreach ( $this->routes() as [ $method, $path, $handler, $capability ] ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => fn ( \WP_REST_Request $request ) => $this->dispatch( $handler, $request ),
					'permission_callback' => fn ( \WP_REST_Request $request ): bool => $this->allowed( $handler, $capability, $request ),
				)
			);
		}
	}

	/**
	 * Capability check (plus edit_post for attachment routes).
	 *
	 * @param string           $handler    Handler name.
	 * @param string           $capability Default capability.
	 * @param \WP_REST_Request $request    Request.
	 */
	private function allowed( string $handler, string $capability, \WP_REST_Request $request ): bool {
		/**
		 * Filters the capability required for a VideoOptimizer admin REST action.
		 *
		 * @param string $capability Capability.
		 * @param string $handler    Action name, e.g. "delete_video".
		 */
		$capability = (string) apply_filters( 'videooptimizer_capability', $capability, $handler );
		if ( ! current_user_can( $capability ) ) {
			return false;
		}
		if ( null !== $request->get_param( 'attachment' ) ) {
			$id = (int) $request->get_param( 'attachment' );

			return current_user_can( 'edit_post', $id ) && $this->attachments->is_video( $id );
		}

		return true;
	}

	/**
	 * Runs a handler and converts API errors to WP_Error.
	 *
	 * @param string           $handler Handler name.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( string $handler, \WP_REST_Request $request ) {
		try {
			$result = $this->{$handler}( $request );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			return rest_ensure_response( null === $result ? array( 'success' => true ) : $result );
		} catch ( ApiException $e ) {
			$status = $e->status();

			return new \WP_Error(
				'videooptimizer_api_error',
				$e->user_message(),
				array(
					'status'          => $status >= 400 && $status < 600 ? $status : 502,
					'upstream_status' => $status,
					'upstream'        => $e->getMessage(),
				)
			);
		}
	}

	/* ---------------------------------------------------------------- Settings */

	/**
	 * Lightweight status for editors (no secrets).
	 *
	 * @return array<string, mixed>
	 */
	private function status(): array {
		return array(
			'configured'           => null !== $this->settings->token(),
			'default_library'      => $this->settings->string( 'default_library' ),
			'default_player'       => $this->settings->string( 'default_player' ),
			'default_presentation' => $this->settings->string( 'default_presentation' ),
			'can_manage'           => current_user_can( 'manage_options' ),
			'can_delete'           => current_user_can( (string) apply_filters( 'videooptimizer_capability', 'delete_others_posts', 'delete_video' ) ),
			'settings_url'         => admin_url( 'admin.php?page=videooptimizer#/settings' ),
		);
	}

	/**
	 * Settings (secrets as booleans).
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		return $this->settings->public_view() + array( 'webhook_url' => rest_url( self::NAMESPACE . '/webhook' ) );
	}

	/**
	 * Saves settings; `token` / `webhook_secret` are only written when present.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function save_settings( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		if ( isset( $body['token'] ) && is_string( $body['token'] ) && '' !== trim( $body['token'] ) ) {
			if ( 'constant' === $this->settings->token_source() ) {
				return new \WP_Error( 'videooptimizer_token_locked', __( 'The token is defined in wp-config.php (VIDEOOPTIMIZER_API_TOKEN) and cannot be changed here.', 'videooptimizer' ), array( 'status' => 400 ) );
			}
			$token = trim( $body['token'] );
			if ( ! preg_match( '/^[A-Za-z0-9_\-.]{10,512}$/', $token ) ) {
				return new \WP_Error( 'videooptimizer_token_invalid', __( 'This does not look like a VideoOptimizer API token (vp_…).', 'videooptimizer' ), array( 'status' => 400 ) );
			}
			$this->settings->set_token( $token );
			delete_transient( Sender::LIBRARY_CACHE );
		}
		if ( isset( $body['webhook_secret'] ) && is_string( $body['webhook_secret'] ) ) {
			$this->settings->set_webhook_secret( $body['webhook_secret'] );
		}

		$this->settings->update( $body );
		if ( array_key_exists( 'default_library', $body ) ) {
			delete_transient( Sender::LIBRARY_CACHE );
		}

		return $this->get_settings();
	}

	/**
	 * Removes the stored token.
	 *
	 * @return array<string, mixed>
	 */
	private function delete_token(): array {
		$this->settings->set_token( '' );
		delete_transient( Sender::LIBRARY_CACHE );

		return $this->get_settings();
	}

	/**
	 * Verifies the token by listing libraries.
	 *
	 * @return array<string, mixed>
	 */
	private function test_connection(): array {
		$libraries = $this->client->list_libraries();

		return array(
			'ok'            => true,
			'libraries'     => count( $libraries ),
			'media_managed' => count( array_filter( $libraries, static fn ( array $l ): bool => false !== ( $l['media_managed'] ?? true ) ) ),
		);
	}

	/* ---------------------------------------------------------------- Libraries */

	/**
	 * All libraries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function list_libraries(): array {
		return $this->client->list_libraries();
	}

	/**
	 * Creates a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_library( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'name', 'description', 'codec', 'resolutions' ) );
		if ( ! isset( $payload['name'] ) || '' === trim( (string) $payload['name'] ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please enter a name.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		delete_transient( Sender::LIBRARY_CACHE );

		return $this->client->create_library( $payload );
	}

	/**
	 * Updates a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function update_library( \WP_REST_Request $request ): array {
		return $this->client->update_library( (string) $request['id'], self::only( $request, array( 'name', 'description', 'codec', 'resolutions', 'encoding_tier' ) ) );
	}

	/**
	 * Deletes a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	private function delete_library( \WP_REST_Request $request ): array {
		$this->client->delete_library( (string) $request['id'] );
		delete_transient( Sender::LIBRARY_CACHE );

		return array( 'deleted' => true );
	}

	/**
	 * Re-encodes a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function reprocess_library( \WP_REST_Request $request ): array {
		return $this->client->reprocess_library( (string) $request['id'] );
	}

	/**
	 * Available encodings.
	 *
	 * @return array<string, mixed>
	 */
	private function list_encodings(): array {
		return $this->client->list_encodings();
	}

	/**
	 * Library new uploads go to.
	 *
	 * @return array<string, string>
	 */
	private function upload_library(): array {
		return array( 'id' => ( new Sender( $this->settings, $this->client, $this->attachments ) )->upload_library() );
	}

	/* ---------------------------------------------------------------- Videos */

	/**
	 * One page of videos.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function list_videos( \WP_REST_Request $request ): array {
		$library = (string) $request->get_param( 'library_id' );
		$cursor  = (string) $request->get_param( 'cursor' );

		return $this->client->list_videos_page(
			preg_match( '/^[A-Za-z0-9-]{1,64}$/', $library ) ? $library : null,
			'' !== $cursor ? substr( $cursor, 0, 512 ) : null,
			(int) ( $request->get_param( 'limit' ) ?? Client::PAGE_LIMIT )
		);
	}

	/**
	 * Remote URL ingest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function ingest( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'library_id', 'source_url', 'title' ) );
		$url     = is_string( $payload['source_url'] ?? null ) ? trim( $payload['source_url'] ) : '';
		$parts   = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please enter a public https:// URL.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$payload['source_url'] = $url;
		if ( empty( $payload['title'] ) ) {
			unset( $payload['title'] );
		}

		return $this->client->ingest_url( $payload );
	}

	/**
	 * Starts a presigned multipart upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function initiate_upload( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'libraryId', 'filename', 'contentType', 'fileSize' ) );
		if ( empty( $payload['libraryId'] ) || empty( $payload['filename'] ) || ! is_numeric( $payload['fileSize'] ?? null ) || (int) $payload['fileSize'] <= 0 ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid upload request.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$payload['fileSize'] = (int) $payload['fileSize'];
		$payload['filename'] = sanitize_file_name( (string) $payload['filename'] );

		return $this->client->initiate_upload( $payload );
	}

	/**
	 * Completes a presigned multipart upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function complete_upload( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'libraryId', 'uuid', 'key', 'uploadId', 'title', 'parts' ) );
		$parts   = array();
		foreach ( (array) ( $payload['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && is_numeric( $part['partNumber'] ?? null ) && is_string( $part['etag'] ?? null ) ) {
				$parts[] = array(
					'partNumber' => (int) $part['partNumber'],
					'etag'       => $part['etag'],
				);
			}
		}
		if ( array() === $parts || empty( $payload['uuid'] ) || empty( $payload['uploadId'] ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid upload request.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$payload['parts'] = $parts;
		if ( empty( $payload['title'] ) ) {
			unset( $payload['title'] );
		}

		return $this->client->complete_upload( $payload );
	}

	/**
	 * One video.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function get_video( \WP_REST_Request $request ): array {
		return $this->client->get_video( (string) $request['uuid'] );
	}

	/**
	 * Updates title / options.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function update_video( \WP_REST_Request $request ): array {
		$body    = (array) $request->get_json_params();
		$payload = array();
		if ( isset( $body['title'] ) && is_string( $body['title'] ) ) {
			$payload['title'] = sanitize_text_field( $body['title'] );
		}
		$option = $body['option'] ?? $body['options'] ?? null;
		if ( is_array( $option ) ) {
			foreach ( array( 'responsive', 'autoplay', 'preload', 'loop', 'muted' ) as $key ) {
				if ( array_key_exists( $key, $option ) ) {
					$payload['option'][ $key ] = (bool) filter_var( $option[ $key ], FILTER_VALIDATE_BOOLEAN );
				}
			}
		}
		$uuid   = (string) $request['uuid'];
		$result = $this->client->update_video( $uuid, $payload );
		$this->embeds->forget( $uuid );

		return $result;
	}

	/**
	 * Deletes a video.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	private function delete_video( \WP_REST_Request $request ): array {
		$uuid = (string) $request['uuid'];
		$this->client->delete_video( $uuid );
		$this->embeds->forget( $uuid );
		foreach ( $this->attachments->find_by_uuid( $uuid ) as $id ) {
			$this->attachments->unlink( $id );
		}

		return array( 'deleted' => true );
	}

	/**
	 * The 10 poster frames.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function list_thumbnails( \WP_REST_Request $request ): array {
		return array( 'thumbnails' => $this->client->list_thumbnails( (string) $request['uuid'] ) );
	}

	/**
	 * Picks a frame as poster.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function select_thumbnail( \WP_REST_Request $request ) {
		$index = $request->get_param( 'thumbnailIndex' );
		if ( ! is_numeric( $index ) || (int) $index < 0 || (int) $index > 9 ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid frame.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$uuid   = (string) $request['uuid'];
		$result = $this->client->select_thumbnail( $uuid, (int) $index );
		$this->embeds->forget( $uuid );

		return $result;
	}

	/**
	 * Starts a custom poster upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function initiate_poster( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'contentType', 'fileSize' ) );
		if ( ! in_array( $payload['contentType'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || ! is_numeric( $payload['fileSize'] ?? null ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please choose a JPEG, PNG or WebP image.', 'videooptimizer' ), array( 'status' => 415 ) );
		}
		$payload['fileSize'] = (int) $payload['fileSize'];

		return $this->client->initiate_poster( (string) $request['uuid'], $payload );
	}

	/**
	 * Completes a custom poster upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function complete_poster( \WP_REST_Request $request ): array {
		$uuid   = (string) $request['uuid'];
		$result = $this->client->complete_poster( $uuid, (string) $request->get_param( 'key' ) );
		$this->embeds->forget( $uuid );

		return $result;
	}

	/**
	 * Switches the poster source.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function select_poster( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'source', 'thumbnailIndex' ) );
		if ( ! in_array( $payload['source'] ?? '', array( 'custom', 'thumbnail' ), true ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid poster source.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		if ( isset( $payload['thumbnailIndex'] ) ) {
			$payload['thumbnailIndex'] = max( 0, min( 9, (int) $payload['thumbnailIndex'] ) );
		}
		$uuid   = (string) $request['uuid'];
		$result = $this->client->select_poster( $uuid, $payload );
		$this->embeds->forget( $uuid );

		return $result;
	}

	/**
	 * Removes the custom poster.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	private function delete_poster( \WP_REST_Request $request ): array {
		$uuid = (string) $request['uuid'];
		$this->client->delete_poster( $uuid );
		$this->embeds->forget( $uuid );

		return array( 'deleted' => true );
	}

	/**
	 * Where a video is still used (blocks, shortcodes, Elementor, products, media library).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	private function video_usage( \WP_REST_Request $request ): array {
		return Usage::find( (string) $request['uuid'] );
	}

	/**
	 * Open "also delete in VideoOptimizer?" offers of the current user.
	 *
	 * @return array<string, mixed>
	 */
	private function list_delete_offers(): array {
		return array(
			'offers'     => array_values( $this->delete_offers->offers( get_current_user_id(), true ) ),
			'can_delete' => current_user_can( (string) apply_filters( 'videooptimizer_capability', 'delete_others_posts', 'delete_video' ) ),
		);
	}

	/**
	 * Resolves an offer: {"delete": true} deletes the video in VideoOptimizer, false keeps it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function resolve_delete_offer( \WP_REST_Request $request ) {
		$delete = (bool) filter_var( $request->get_param( 'delete' ), FILTER_VALIDATE_BOOLEAN );
		$uuid   = strtolower( (string) $request['uuid'] );
		$offers = $this->delete_offers->offers( get_current_user_id() );
		if ( ! isset( $offers[ $uuid ] ) ) {
			return new \WP_Error( 'videooptimizer_no_offer', __( 'This video is no longer waiting for a decision.', 'videooptimizer' ), array( 'status' => 404 ) );
		}
		if ( $delete && ! current_user_can( (string) apply_filters( 'videooptimizer_capability', 'delete_others_posts', 'delete_video' ) ) ) {
			return new \WP_Error( 'videooptimizer_forbidden', __( 'You are not allowed to delete videos in VideoOptimizer.', 'videooptimizer' ), array( 'status' => 403 ) );
		}
		$this->delete_offers->resolve( get_current_user_id(), $uuid, $delete );

		return $this->list_delete_offers();
	}

	/* ---------------------------------------------------------------- Media library */

	/**
	 * Attachment state, refreshed from the API while processing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function attachment_state( \WP_REST_Request $request ): array {
		$id    = (int) $request['attachment'];
		$state = $this->attachments->state( $id );

		return 'processing' === $state['status'] ? $this->attachments->refresh( $id ) : $state;
	}

	/**
	 * Links an attachment after a browser upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function attachment_link( \WP_REST_Request $request ) {
		$uuid    = (string) $request->get_param( 'uuid' );
		$library = (string) $request->get_param( 'library' );
		if ( ! preg_match( '/^' . self::UUID . '$/', $uuid ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid video id.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$id = (int) $request['attachment'];
		$this->attachments->link( $id, $uuid, 'processing', preg_match( '/^[A-Za-z0-9-]{1,64}$/', $library ) ? $library : '' );

		return $this->attachments->refresh( $id );
	}

	/**
	 * Unlinks an attachment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function attachment_unlink( \WP_REST_Request $request ): array {
		$id = (int) $request['attachment'];
		$this->attachments->unlink( $id );

		return $this->attachments->state( $id );
	}

	/**
	 * Server-side URL transfer (public sites).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function attachment_send_url( \WP_REST_Request $request ) {
		$id     = (int) $request['attachment'];
		$sender = new Sender( $this->settings, $this->client, $this->attachments );
		if ( ! $sender->can_send_by_url( $id ) ) {
			return new \WP_Error( 'videooptimizer_not_public', __( 'This site is not publicly reachable, so the file is uploaded from your browser instead.', 'videooptimizer' ), array( 'status' => 409 ) );
		}

		return $sender->send_by_url( $id );
	}

	/**
	 * Allowlisted JSON body keys.
	 *
	 * @param \WP_REST_Request   $request Request.
	 * @param array<int, string> $keys    Allowed keys.
	 * @return array<string, mixed>
	 */
	private static function only( \WP_REST_Request $request, array $keys ): array {
		$body = (array) $request->get_json_params();
		$out  = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $body ) && null !== $body[ $key ] ) {
				$out[ $key ] = is_string( $body[ $key ] ) ? sanitize_text_field( $body[ $key ] ) : $body[ $key ];
			}
		}

		return $out;
	}
}

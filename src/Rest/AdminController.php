<?php
/**
 * Admin REST proxy.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Rest;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Api\VideoFields;
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

	private const UUID               = '(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
	private const ENCODING_QUALITIES = array( 'performance', 'quality' );
	private const LIB_ID             = '(?P<id>[A-Za-z0-9-]{1,64})';
	private const LANGUAGE           = '(?P<language>[A-Za-z0-9-]{2,35})';
	private const SUBTITLE_MAX_BYTES = 2 * 1024 * 1024;

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

			array( 'GET', '/savings', 'savings', 'upload_files' ),
			array( 'GET', '/tags', 'list_tags', 'upload_files' ),
			array( 'GET', '/subtitle-languages', 'list_subtitle_languages', 'upload_files' ),
			array( 'POST', '/subtitle-languages', 'create_subtitle_language', 'manage_options' ),
			array( 'DELETE', '/subtitle-languages/' . self::LANGUAGE, 'delete_subtitle_language', 'manage_options' ),
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

			array( 'GET', '/videos/' . self::UUID . '/subtitles', 'list_subtitles', 'upload_files' ),
			array( 'POST', '/videos/' . self::UUID . '/subtitles', 'upload_subtitle', 'upload_files' ),
			array( 'GET', '/videos/' . self::UUID . '/subtitles/' . self::LANGUAGE, 'download_subtitle', 'upload_files' ),
			array( 'DELETE', '/videos/' . self::UUID . '/subtitles/' . self::LANGUAGE, 'delete_subtitle', 'upload_files' ),

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
					'upstream_code'   => $e->api_code(),
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
		$payload = self::library_payload( $request );
		if ( $payload instanceof \WP_Error ) {
			return $payload;
		}
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
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_library( \WP_REST_Request $request ) {
		$payload = self::library_payload( $request );

		if ( $payload instanceof \WP_Error ) {
			return $payload;
		}

		$library = $this->client->update_library( (string) $request['id'], $payload );
		// A changed ladder or quality re-encodes the videos, so their cached embed data is stale.
		if ( array_intersect_key( $payload, array_flip( array( 'codec', 'resolutions', 'encoding_quality' ) ) ) ) {
			$this->forget_embeds( $this->library_video_uuids( (string) $request['id'] ) );
		}

		return $library;
	}

	/**
	 * Video uuids of a library, best effort (empty when the API cannot be reached).
	 *
	 * @param string $library_id Library id.
	 * @return array<int, string>
	 */
	private function library_video_uuids( string $library_id ): array {
		try {
			$videos = $this->client->list_videos( $library_id );
		} catch ( \Throwable $e ) {
			return array();
		}

		$uuids = array();
		foreach ( $videos as $video ) {
			$uuid = $video['uuid'] ?? ( $video['id'] ?? null );
			if ( is_string( $uuid ) && '' !== $uuid ) {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}

	/**
	 * Drops the cached embed data of the given videos.
	 *
	 * @param array<int, string> $uuids Video uuids.
	 */
	private function forget_embeds( array $uuids ): void {
		foreach ( $uuids as $uuid ) {
			$this->embeds->forget( $uuid );
		}
	}

	/**
	 * Library fields the API accepts. `encoding_quality` is validated here; the former `encoding_tier`
	 * is not an API field and is dropped.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function library_payload( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'name', 'description', 'codec', 'resolutions', 'encoding_quality' ) );
		if ( isset( $payload['encoding_quality'] ) && ! in_array( $payload['encoding_quality'], self::ENCODING_QUALITIES, true ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'The encoding quality must be "performance" or "quality".', 'videooptimizer' ), array( 'status' => 400 ) );
		}

		foreach ( array( 'codec', 'resolutions' ) as $field ) {
			if ( isset( $payload[ $field ] ) && '' === trim( (string) $payload[ $field ] ) ) {
				return new \WP_Error( 'videooptimizer_invalid', __( 'Please keep at least one codec and one resolution.', 'videooptimizer' ), array( 'status' => 400 ) );
			}
		}

		return $payload;
	}

	/**
	 * Deletes a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>
	 */
	private function delete_library( \WP_REST_Request $request ): array {
		// Collect the videos first: they are gone from the API after the delete.
		$uuids = $this->library_video_uuids( (string) $request['id'] );
		$this->client->delete_library( (string) $request['id'] );
		delete_transient( Sender::LIBRARY_CACHE );
		$this->forget_embeds( $uuids );

		return array( 'deleted' => true );
	}

	/**
	 * Re-encodes a library.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function reprocess_library( \WP_REST_Request $request ) {
		// Optional {"force": true}: re-encode finished renditions too (needed after a quality change).
		$body = (array) $request->get_json_params();
		if ( array_key_exists( 'force', $body ) && ! is_bool( $body['force'] ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'The "force" option must be true or false.', 'videooptimizer' ), array( 'status' => 400 ) );
		}

		$force = true === ( $body['force'] ?? false );

		$result = $this->client->reprocess_library( (string) $request['id'], $force );
		$this->forget_embeds( $this->library_video_uuids( (string) $request['id'] ) );

		return $result;
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
	 * Bandwidth and storage savings (optionally of a date range, one library or one video). Cached
	 * for five minutes per filter: the numbers move slowly and the call is not free.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function savings( \WP_REST_Request $request ) {
		$filters = array();
		foreach ( array( 'from', 'to' ) as $key ) {
			$value = (string) $request->get_param( $key );
			if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return new \WP_Error( 'videooptimizer_invalid', __( 'Dates must look like 2026-11-27.', 'videooptimizer' ), array( 'status' => 400 ) );
			}
			$filters[ $key ] = '' !== $value ? $value : null;
		}
		$library = (string) $request->get_param( 'library_id' );
		$video   = (string) $request->get_param( 'video_uuid' );
		if ( ( '' !== $library && ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $library ) ) || ( '' !== $video && ! preg_match( '/^' . self::UUID . '$/', $video ) ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Invalid library or video id.', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$filters['library_id'] = '' !== $library ? $library : null;
		$filters['video_uuid'] = '' !== $video ? strtolower( $video ) : null;

		$key    = 'videooptimizer_savings_' . md5( (string) wp_json_encode( $filters ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->client->get_savings( $filters['from'], $filters['to'], $filters['library_id'], $filters['video_uuid'] );
		set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * The organization's tags.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function list_tags(): array {
		return $this->client->list_tags();
	}

	/**
	 * One page of videos, optionally filtered by library, tags (all required) and metadata.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function list_videos( \WP_REST_Request $request ) {
		$library = (string) $request->get_param( 'library_id' );
		$cursor  = (string) $request->get_param( 'cursor' );

		$filters = array();
		try {
			$tags = (string) $request->get_param( 'tags' );
			if ( '' !== $tags ) {
				$filters['tags'] = VideoFields::tags( explode( ',', $tags ) );
			}
			$metadata = $request->get_param( 'metadata' );
			if ( is_array( $metadata ) ) {
				$metadata = array_filter( VideoFields::metadata( $metadata ), static fn ( $value ): bool => null !== $value );
				if ( count( $metadata ) > 5 ) {
					return new \WP_Error( 'videooptimizer_invalid', __( 'Filter by at most 5 metadata keys.', 'videooptimizer' ), array( 'status' => 400 ) );
				}
				if ( array() !== $metadata ) {
					$filters['metadata'] = $metadata;
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		return $this->client->list_videos_page(
			preg_match( '/^[A-Za-z0-9-]{1,64}$/', $library ) ? $library : null,
			'' !== $cursor ? substr( $cursor, 0, 512 ) : null,
			(int) ( $request->get_param( 'limit' ) ?? Client::PAGE_LIMIT ),
			$filters
		);
	}

	/**
	 * Validated `tags` / `metadata` of a request body. On create, empty values are left out; on
	 * update, `tags: []` clears the tags, `metadata: null` clears all metadata and a null or empty
	 * value removes one key.
	 *
	 * @param array<string, mixed> $body   Decoded JSON body.
	 * @param bool                 $update Whether this is an update (PATCH).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function video_fields( array $body, bool $update ) {
		$fields = array();
		try {
			if ( is_array( $body['tags'] ?? null ) ) {
				$tags = VideoFields::tags( $body['tags'] );
				if ( $update || array() !== $tags ) {
					$fields['tags'] = $tags;
				}
			}
			if ( $update && array_key_exists( 'metadata', $body ) && null === $body['metadata'] ) {
				$fields['metadata'] = null;
			} elseif ( is_array( $body['metadata'] ?? null ) ) {
				$metadata = VideoFields::metadata( $body['metadata'] );
				if ( ! $update ) {
					$metadata = array_filter( $metadata, static fn ( $value ): bool => null !== $value );
				}
				if ( array() !== $metadata ) {
					$fields['metadata'] = $metadata;
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		return $fields;
	}

	/**
	 * Remote URL ingest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function ingest( \WP_REST_Request $request ) {
		$payload = self::only( $request, array( 'library_id', 'source_url', 'title' ) );
		$fields  = self::video_fields( (array) $request->get_json_params(), false );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		$payload += $fields;
		$url      = is_string( $payload['source_url'] ?? null ) ? trim( $payload['source_url'] ) : '';
		$parts    = wp_parse_url( $url );
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
		$fields = self::video_fields( (array) $request->get_json_params(), false );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		$payload += $fields;

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
	 * Updates title, options, tags and metadata.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_video( \WP_REST_Request $request ) {
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
		$fields = self::video_fields( $body, true );
		if ( $fields instanceof \WP_Error ) {
			return $fields;
		}
		$payload += $fields;
		$uuid     = (string) $request['uuid'];
		$result   = $this->client->update_video( $uuid, $payload );
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

	/* ---------------------------------------------------------------- Subtitles */

	/**
	 * The organization's subtitle languages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function list_subtitle_languages(): array {
		return $this->client->list_subtitle_languages();
	}

	/**
	 * Adds a subtitle language.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function create_subtitle_language( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		try {
			$code = VideoFields::language( is_string( $body['code'] ?? null ) ? $body['code'] : '' );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$label = is_string( $body['label'] ?? null ) ? mb_substr( sanitize_text_field( $body['label'] ), 0, 60 ) : '';

		return $this->client->create_subtitle_language( $code, $label );
	}

	/**
	 * Removes a subtitle language (the API refuses while videos still use it).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>|\WP_Error
	 */
	private function delete_subtitle_language( \WP_REST_Request $request ) {
		try {
			$code = VideoFields::language( (string) $request['language'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$this->client->delete_subtitle_language( $code );

		return array( 'deleted' => true );
	}

	/**
	 * The subtitles of a video.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	private function list_subtitles( \WP_REST_Request $request ): array {
		return $this->client->list_subtitles( (string) $request['uuid'] );
	}

	/**
	 * Uploads or replaces a subtitle (multipart form: file, language, kind?, is_default?).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function upload_subtitle( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : null;
		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please choose a .vtt or .srt file (up to 2 MB).', 'videooptimizer' ), array( 'status' => 400 ) );
		}
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( ! preg_match( '/\.(vtt|srt)$/i', $name ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please choose a .vtt or .srt file (up to 2 MB).', 'videooptimizer' ), array( 'status' => 415 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::SUBTITLE_MAX_BYTES ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'The subtitle file is larger than 2 MB.', 'videooptimizer' ), array( 'status' => 413 ) );
		}

		try {
			$language = VideoFields::language( (string) $request->get_param( 'language' ) );
			$kind     = VideoFields::subtitle_kind( (string) $request->get_param( 'kind' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( ! self::is_upload_file( $tmp ) ) {
			return new \WP_Error( 'videooptimizer_invalid', __( 'Please choose a .vtt or .srt file (up to 2 MB).', 'videooptimizer' ), array( 'status' => 400 ) );
		}

		$contents = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads PHP's own upload temp file.
		$uuid     = (string) $request['uuid'];
		$result   = $this->client->upload_subtitle(
			$uuid,
			$name,
			$contents,
			$language,
			$kind,
			null === $request->get_param( 'is_default' ) || '' === $request->get_param( 'is_default' ) ? null : (bool) filter_var( $request->get_param( 'is_default' ), FILTER_VALIDATE_BOOLEAN )
		);
		// The player tracks come from the cached embed data.
		$this->embeds->forget( $uuid );

		return $result;
	}

	/**
	 * The stored WebVTT of one subtitle, for a download in the browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, string>|\WP_Error
	 */
	private function download_subtitle( \WP_REST_Request $request ) {
		try {
			$language = VideoFields::language( (string) $request['language'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}

		return array(
			'language' => $language,
			'content'  => $this->client->download_subtitle( (string) $request['uuid'], $language ),
		);
	}

	/**
	 * Removes the subtitle of one language.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, bool>|\WP_Error
	 */
	private function delete_subtitle( \WP_REST_Request $request ) {
		try {
			$language = VideoFields::language( (string) $request['language'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'videooptimizer_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$uuid = (string) $request['uuid'];
		$this->client->delete_subtitle( $uuid, $language );
		$this->embeds->forget( $uuid );

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
	 * Whether a path is a file PHP received as an upload (or, for internal REST calls, a file in the
	 * system temp directory), never an arbitrary server path.
	 *
	 * @param string $path Temp file path.
	 */
	private static function is_upload_file( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		if ( is_uploaded_file( $path ) ) {
			return true;
		}
		$real = realpath( $path );
		$temp = realpath( sys_get_temp_dir() );

		return false !== $real && false !== $temp && str_starts_with( $real, rtrim( $temp, '/\\' ) . DIRECTORY_SEPARATOR );
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

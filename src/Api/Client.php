<?php
/**
 * VideoOptimizer REST API client.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin server-side client for https://api.videooptimizer.eu/api/v1. The token is only ever used
 * here, so it never reaches the browser. Payloads are wrapped in { "data": … } except /encodings.
 *
 * @see https://api.videooptimizer.eu/developers
 */
class Client {

	public const PAGE_LIMIT      = 100;
	public const MAX_PAGES       = 100;
	public const MAX_RETRY_AFTER = 5;
	public const TIMEOUT         = 30;
	public const EMBED_TIMEOUT   = 3;

	/**
	 * Resolves the API token lazily (null when not configured).
	 *
	 * @var callable(): ?string
	 */
	private $token_provider;

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param callable(): ?string $token_provider Returns the API token.
	 * @param string              $base_url       API base URL (must be https).
	 */
	public function __construct( callable $token_provider, string $base_url ) {
		$this->token_provider = $token_provider;
		$this->base_url       = rtrim( $base_url, '/' );
	}

	/**
	 * Whether a token is available.
	 */
	public function is_configured(): bool {
		return null !== ( $this->token_provider )();
	}

	/* ---------------------------------------------------------------- Libraries */

	/**
	 * All libraries (all pages).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_libraries(): array {
		return $this->request_all_pages( '/libraries' );
	}

	/**
	 * Creates a library.
	 *
	 * @param array<string, mixed> $payload name, description, codec, resolutions.
	 * @return array<string, mixed>
	 */
	public function create_library( array $payload ): array {
		return $this->request_data( 'POST', '/libraries', $payload );
	}

	/**
	 * Updates a library.
	 *
	 * @param string               $id      Library id.
	 * @param array<string, mixed> $payload Fields to change.
	 * @return array<string, mixed>
	 */
	public function update_library( string $id, array $payload ): array {
		return $this->request_data( 'PATCH', '/libraries/' . rawurlencode( $id ), $payload );
	}

	/**
	 * Deletes a library and all of its videos.
	 *
	 * @param string $id Library id.
	 */
	public function delete_library( string $id ): void {
		$this->request( 'DELETE', '/libraries/' . rawurlencode( $id ) );
	}

	/**
	 * Re-queues encoding of every video in a (media-managed) library.
	 *
	 * @param string $id Library id.
	 * @return array<string, mixed> {queued}
	 */
	public function reprocess_library( string $id ): array {
		return $this->request_data( 'POST', '/libraries/' . rawurlencode( $id ) . '/reprocess' );
	}

	/**
	 * Codecs and resolutions the organization may enable. Not wrapped in { data }.
	 *
	 * @return array<string, mixed> {codecs, resolutions}
	 */
	public function list_encodings(): array {
		return $this->request( 'GET', '/encodings' )['body'];
	}

	/* ---------------------------------------------------------------- Videos */

	/**
	 * One page of videos, newest first.
	 *
	 * @param string|null $library_id Optional library filter.
	 * @param string|null $cursor     Pagination cursor.
	 * @param int         $limit      Page size (max 100).
	 * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string}
	 */
	public function list_videos_page( ?string $library_id = null, ?string $cursor = null, int $limit = self::PAGE_LIMIT ): array {
		$query = array( 'limit' => max( 1, min( self::PAGE_LIMIT, $limit ) ) );
		if ( null !== $cursor && '' !== $cursor ) {
			$query['cursor'] = $cursor;
		}
		$path = null !== $library_id && '' !== $library_id ? '/libraries/' . rawurlencode( $library_id ) . '/videos' : '/videos';

		$body       = $this->request( 'GET', $path, null, $query )['body'];
		$pagination = is_array( $body['pagination'] ?? null ) ? $body['pagination'] : array();
		$next       = true === ( $pagination['has_more'] ?? false ) && is_string( $pagination['next_cursor'] ?? null ) && '' !== $pagination['next_cursor'] ? $pagination['next_cursor'] : null;

		return array(
			'items'       => self::list_of_arrays( $body['data'] ?? null ),
			'next_cursor' => $next,
		);
	}

	/**
	 * Every video (all pages), optionally of one library.
	 *
	 * @param string|null $library_id Optional library filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_videos( ?string $library_id = null ): array {
		$path = null !== $library_id && '' !== $library_id ? '/libraries/' . rawurlencode( $library_id ) . '/videos' : '/videos';

		return $this->request_all_pages( $path );
	}

	/**
	 * A single video including renditions.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<string, mixed>
	 */
	public function get_video( string $uuid ): array {
		return $this->request_data( 'GET', '/videos/' . rawurlencode( $uuid ) );
	}

	/**
	 * Updates title and/or player options. Note: the write field is `option` (singular).
	 *
	 * @param string               $uuid    Video uuid.
	 * @param array<string, mixed> $payload {title?, option?}.
	 * @return array<string, mixed>
	 */
	public function update_video( string $uuid, array $payload ): array {
		return $this->request_data( 'PATCH', '/videos/' . rawurlencode( $uuid ), $payload );
	}

	/**
	 * Deletes a video.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function delete_video( string $uuid ): void {
		$this->request( 'DELETE', '/videos/' . rawurlencode( $uuid ) );
	}

	/**
	 * Starts a presigned multipart upload. The browser PUTs the parts straight to storage.
	 *
	 * @param array<string, mixed> $payload libraryId, filename, contentType, fileSize.
	 * @return array<string, mixed> {uuid, key, uploadId, partSize, partCount, parts[]}
	 */
	public function initiate_upload( array $payload ): array {
		return $this->request_data( 'POST', '/videos/upload/initiate', $payload );
	}

	/**
	 * Completes a presigned multipart upload (idempotent).
	 *
	 * @param array<string, mixed> $payload libraryId, uuid, key, uploadId, parts[], title?.
	 * @return array<string, mixed>
	 */
	public function complete_upload( array $payload ): array {
		return $this->request_data( 'POST', '/videos/upload/complete', $payload );
	}

	/**
	 * Creates a video from a public https URL; VideoOptimizer downloads it.
	 *
	 * @param array<string, mixed> $payload library_id, source_url, title?.
	 * @return array<string, mixed> {uuid, status}
	 */
	public function ingest_url( array $payload ): array {
		return $this->request_data( 'POST', '/videos', $payload );
	}

	/**
	 * The 10 candidate poster frames.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<int, array<string, mixed>> [{index, url}]
	 */
	public function list_thumbnails( string $uuid ): array {
		$data = $this->request_data( 'GET', '/videos/' . rawurlencode( $uuid ) . '/thumbnails' );

		return self::list_of_arrays( $data['thumbnails'] ?? null );
	}

	/**
	 * Picks one of the 10 frames as poster.
	 *
	 * @param string $uuid  Video uuid.
	 * @param int    $index Frame index 0–9.
	 * @return array<string, mixed>
	 */
	public function select_thumbnail( string $uuid, int $index ): array {
		return $this->request_data( 'POST', '/videos/' . rawurlencode( $uuid ) . '/thumbnail', array( 'thumbnailIndex' => $index ) );
	}

	/**
	 * Starts a custom poster upload (single presigned PUT).
	 *
	 * @param string               $uuid    Video uuid.
	 * @param array<string, mixed> $payload contentType, fileSize.
	 * @return array<string, mixed> {key, uploadUrl}
	 */
	public function initiate_poster( string $uuid, array $payload ): array {
		return $this->request_data( 'POST', '/videos/' . rawurlencode( $uuid ) . '/poster/initiate', $payload );
	}

	/**
	 * Completes a custom poster upload.
	 *
	 * @param string $uuid Video uuid.
	 * @param string $key  Storage key from initiate.
	 * @return array<string, mixed>
	 */
	public function complete_poster( string $uuid, string $key ): array {
		return $this->request_data( 'POST', '/videos/' . rawurlencode( $uuid ) . '/poster/complete', array( 'key' => $key ) );
	}

	/**
	 * Switches the poster source.
	 *
	 * @param string               $uuid    Video uuid.
	 * @param array<string, mixed> $payload source (custom|thumbnail), thumbnailIndex?.
	 * @return array<string, mixed>
	 */
	public function select_poster( string $uuid, array $payload ): array {
		return $this->request_data( 'POST', '/videos/' . rawurlencode( $uuid ) . '/poster/select', $payload );
	}

	/**
	 * Removes the custom poster.
	 *
	 * @param string $uuid Video uuid.
	 */
	public function delete_poster( string $uuid ): void {
		$this->request( 'DELETE', '/videos/' . rawurlencode( $uuid ) . '/poster' );
	}

	/**
	 * Public embed payload (sources, poster, theme). Runs during page rendering: no token, a short
	 * timeout and never a retry sleep, so a slow upstream can never block the page.
	 *
	 * @param string $uuid Video uuid.
	 * @return array<string, mixed>
	 */
	public function get_embed( string $uuid ): array {
		$response = $this->request( 'GET', '/embed/' . rawurlencode( $uuid ), null, array(), false, self::EMBED_TIMEOUT, false );

		return is_array( $response['body']['data'] ?? null ) ? $response['body']['data'] : array();
	}

	/* ---------------------------------------------------------------- Transport */

	/**
	 * Follows the cursor of a paginated endpoint and merges all items.
	 *
	 * @param string $path API path.
	 * @return array<int, array<string, mixed>>
	 */
	private function request_all_pages( string $path ): array {
		$items    = array();
		$cursor   = null;
		$previous = null;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array( 'limit' => self::PAGE_LIMIT );
			if ( null !== $cursor ) {
				$query['cursor'] = $cursor;
			}

			$body  = $this->request( 'GET', $path, null, $query )['body'];
			$items = array_merge( $items, self::list_of_arrays( $body['data'] ?? null ) );

			$pagination = $body['pagination'] ?? null;
			if ( ! is_array( $pagination ) || true !== ( $pagination['has_more'] ?? false ) ) {
				break;
			}
			$cursor = is_string( $pagination['next_cursor'] ?? null ) ? $pagination['next_cursor'] : null;
			// Stop on an empty or repeated cursor so a misbehaving upstream cannot loop forever.
			if ( null === $cursor || '' === $cursor || $cursor === $previous ) {
				break;
			}
			$previous = $cursor;
		}

		return $items;
	}

	/**
	 * Request returning the unwrapped { data } object.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   API path.
	 * @param array<string, mixed>|null $json   JSON body.
	 * @return array<string, mixed>
	 */
	private function request_data( string $method, string $path, ?array $json = null ): array {
		$data = $this->request( $method, $path, $json )['body']['data'] ?? null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Performs a request; throws ApiException on transport errors and HTTP >= 400.
	 *
	 * @param string                    $method     HTTP method.
	 * @param string                    $path       API path.
	 * @param array<string, mixed>|null $json       JSON body.
	 * @param array<string, mixed>      $query      Query parameters.
	 * @param bool                      $auth       Send the bearer token.
	 * @param int                       $timeout    Timeout in seconds.
	 * @param bool                      $retry_429  Retry once after Retry-After on 429.
	 * @return array{status: int, body: array<string, mixed>}
	 * @throws ApiException On failure.
	 */
	private function request( string $method, string $path, ?array $json = null, array $query = array(), bool $auth = true, int $timeout = self::TIMEOUT, bool $retry_429 = true ): array {
		if ( 0 !== stripos( $this->base_url, 'https://' ) ) {
			throw new ApiException( 'The VideoOptimizer API base URL must use https.', 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}

		$headers = array( 'Accept' => 'application/json' );
		if ( $auth ) {
			$token = ( $this->token_provider )();
			if ( null === $token || '' === $token ) {
				throw new ApiException( 'VideoOptimizer API token is not configured.', ApiException::NOT_CONFIGURED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'headers'    => $headers,
			'user-agent' => 'VideoOptimizer-WordPress/' . ( defined( 'VIDEOOPTIMIZER_VERSION' ) ? VIDEOOPTIMIZER_VERSION : 'dev' ) . '; ' . home_url( '/' ),
		);
		if ( null !== $json ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( (object) $json );
		}

		$url = $this->base_url . $path;
		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		for ( $attempt = 0; ; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				throw new ApiException( $response->get_error_message(), ApiException::UNREACHABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			// Respect Retry-After once; paginated loops can trip the 120 req/min limit.
			if ( 429 === $status && $retry_429 && 0 === $attempt ) {
				sleep( self::retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) ) );
				continue;
			}
			break;
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $raw ? array() : json_decode( $raw, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 400 ) {
			$message = 'VideoOptimizer request failed (HTTP ' . $status . ').';
			foreach ( array( 'message', 'statusMessage' ) as $key ) {
				if ( is_string( $decoded[ $key ] ?? null ) && '' !== $decoded[ $key ] ) {
					$message = $decoded[ $key ];
					break;
				}
			}
			throw new ApiException( $message, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}

		return array(
			'status' => $status,
			'body'   => $decoded,
		);
	}

	/**
	 * Seconds to wait from a Retry-After header, capped so admin requests never hang.
	 *
	 * @param mixed $value Header value.
	 */
	private static function retry_after( mixed $value ): int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
			return 1;
		}

		return min( (int) $value, self::MAX_RETRY_AFTER );
	}

	/**
	 * Filters a decoded list down to its array items.
	 *
	 * @param mixed $value Decoded value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function list_of_arrays( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}
}

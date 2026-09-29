<?php
/**
 * API client.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;

final class ClientTest extends TestCase {

	/**
	 * Recorded requests [url, args].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Queued responses.
	 *
	 * @var array<int, array<string, mixed>|\WP_Error>
	 */
	private array $responses = array();

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array( $url, $args );
				return array_shift( $this->responses ) ?? $this->response( 200, array( 'data' => array() ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn ( $r, $h ) => $r['headers'][ $h ] ?? '' );
	}

	/**
	 * Builds a fake wp_remote_request() response.
	 *
	 * @param int                  $code    Status.
	 * @param array<string, mixed> $body    JSON body.
	 * @param array<string, mixed> $headers Headers.
	 * @return array<string, mixed>
	 */
	private function response( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function client( ?string $token = 'vp_secret', string $base = 'https://api.videooptimizer.eu/api/v1' ): Client {
		return new Client( static fn () => $token, $base );
	}

	public function test_sends_bearer_token_and_unwraps_data(): void {
		$this->responses[] = $this->response(
			200,
			array(
				'data' => array(
					'uuid'   => 'abc',
					'status' => 'ready',
				),
			)
		);

		$video = $this->client()->get_video( 'abc' );

		$this->assertSame( 'ready', $video['status'] );
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://api.videooptimizer.eu/api/v1/videos/abc', $url );
		$this->assertSame( 'Bearer vp_secret', $args['headers']['Authorization'] );
		$this->assertSame( 'GET', $args['method'] );
	}

	public function test_json_body_and_path_encoding(): void {
		$this->client()->update_video( 'a/b', array( 'option' => array( 'loop' => true ) ) );

		[ $url, $args ] = $this->requests[0];
		$this->assertStringEndsWith( '/videos/a%2Fb', $url );
		$this->assertSame( 'PATCH', $args['method'] );
		$this->assertSame( '{"option":{"loop":true}}', $args['body'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
	}

	public function test_follows_pagination_cursor_and_stops_on_repeat(): void {
		$page = fn ( array $items, bool $more, ?string $cursor ) => $this->response(
			200,
			array(
				'data'       => $items,
				'pagination' => array(
					'has_more'    => $more,
					'next_cursor' => $cursor,
				),
			)
		);
		$this->responses = array(
			$page( array( array( 'id' => 1 ), array( 'id' => 2 ) ), true, 'c1' ),
			$page( array( array( 'id' => 3 ) ), true, 'c2' ),
			$page( array( array( 'id' => 4 ) ), true, 'c2' ), // Stuck cursor.
			$page( array( array( 'id' => 5 ) ), false, null ),
		);

		$items = $this->client()->list_libraries();

		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $items, 'id' ) );
		$this->assertCount( 3, $this->requests );
		$this->assertStringContainsString( 'cursor=c1', $this->requests[1][0] );
	}

	public function test_videos_page_returns_cursor(): void {
		$this->responses[] = $this->response(
			200,
			array(
				'data'       => array( array( 'uuid' => 'x' ) ),
				'pagination' => array(
					'has_more'    => true,
					'next_cursor' => 'next',
				),
			)
		);

		$page = $this->client()->list_videos_page( 'lib-1', null, 500 );

		$this->assertSame( 'next', $page['next_cursor'] );
		$this->assertStringContainsString( '/libraries/lib-1/videos?limit=100', $this->requests[0][0] );
	}

	public function test_retries_once_on_429(): void {
		$this->responses = array(
			$this->response( 429, array( 'message' => 'Too many' ), array( 'retry-after' => '0' ) ),
			$this->response( 429, array( 'message' => 'Too many' ), array( 'retry-after' => '0' ) ),
		);

		try {
			$this->client()->list_encodings();
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 429, $e->status() );
		}
		$this->assertCount( 2, $this->requests );
	}

	public function test_embed_is_public_short_and_never_retries(): void {
		$this->responses[] = $this->response( 429, array( 'message' => 'Too many' ) );

		try {
			$this->client( null )->get_embed( 'abc' );
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 429, $e->status() );
		}
		$this->assertCount( 1, $this->requests );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0][1]['headers'] );
		$this->assertSame( Client::EMBED_TIMEOUT, $this->requests[0][1]['timeout'] );
	}

	public function test_encodings_are_not_unwrapped(): void {
		$this->responses[] = $this->response( 200, array( 'codecs' => array( array( 'key' => 'h264' ) ) ) );

		$this->assertSame( 'h264', $this->client()->list_encodings()['codecs'][0]['key'] );
	}

	public function test_error_message_and_user_messages(): void {
		$this->responses[] = $this->response(
			403,
			array(
				'statusCode'    => 403,
				'statusMessage' => 'Forbidden',
				'message'       => 'Video limit of your plan reached',
			)
		);

		try {
			$this->client()->ingest_url( array( 'library_id' => 'x' ) );
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'Video limit of your plan reached', $e->getMessage() );
			$this->assertStringContainsString( 'plan limit', $e->user_message() );
		}
	}

	public function test_missing_token_and_insecure_base_url(): void {
		try {
			$this->client( null )->list_libraries();
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::NOT_CONFIGURED, $e->status() );
		}

		$this->expectException( ApiException::class );
		$this->client( 'vp_x', 'http://api.example' )->list_libraries();
	}

	public function test_transport_error(): void {
		$this->responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		try {
			$this->client()->get_video( 'abc' );
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::UNREACHABLE, $e->status() );
			$this->assertStringContainsString( 'cURL error 28', $e->user_message() );
		}
		$this->assertEmpty( $this->responses );
	}
}

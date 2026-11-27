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
use ScaleCommerce\VideoOptimizer\Api\UserAgent;

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
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		// The User-Agent is memoized per request; start every test from a fresh one.
		( new \ReflectionProperty( UserAgent::class, 'cached' ) )->setValue( null, null );
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
		$this->assertStringStartsWith( UserAgent::PRODUCT . '/', $this->requests[0][1]['user-agent'] );
	}

	public function test_every_request_identifies_the_plugin(): void {
		$this->client()->get_video( 'abc' );
		$this->client()->delete_video( 'abc' );

		$this->assertCount( 2, $this->requests );
		foreach ( $this->requests as [ , $args ] ) {
			$this->assertMatchesRegularExpression(
				'#^VideoOptimizer-WordPress/0\.1\.0-test \(WordPress 6\.8\.1; PHP [^;()]+; \+https://shop\.example/?\)$#',
				$args['user-agent']
			);
		}
	}

	public function test_reprocess_sends_force_only_when_requested(): void {
		$this->client()->reprocess_library( 'lib' );
		$this->client()->reprocess_library( 'lib', true );

		$this->assertStringEndsWith( '/libraries/lib/reprocess', $this->requests[0][0] );
		$this->assertArrayNotHasKey( 'body', $this->requests[0][1] );
		$this->assertSame( '{"force":true}', $this->requests[1][1]['body'] );
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

	public function test_rejected_video_platform_link_is_explained(): void {
		$this->responses[] = $this->response(
			400,
			array(
				'statusCode' => 400,
				'message'    => 'Video platform links are not supported',
				'data'       => array( 'code' => 'source_url_video_platform' ),
			)
		);

		try {
			$this->client()->ingest_url( array( 'source_url' => 'https://youtu.be/x' ) );
			$this->fail( 'Expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 'source_url_video_platform', $e->api_code() );
			$this->assertStringContainsString( 'not to a video page', $e->user_message() );
		}
	}

	public function test_rate_limit_and_forbidden_messages(): void {
		$limit = new ApiException( 'Reprocess limit reached (5 per hour)', 429 );
		$this->assertStringContainsString( '5 per hour', $limit->user_message() );
		$this->assertStringContainsString( 'wait a minute', ( new ApiException( 'Too many requests', 429 ) )->user_message() );

		$this->assertStringContainsString( 'Organization is suspended', ( new ApiException( 'Organization is suspended', 403 ) )->user_message() );
		$this->assertStringContainsString( 'permission', ( new ApiException( 'Missing permission library:update', 403 ) )->user_message() );
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

	public function test_requests_do_not_follow_redirects_and_reject_unsafe_urls(): void {
		$this->client()->get_video( 'abc' );

		$this->assertSame( 0, $this->requests[0][1]['redirection'] );
		$this->assertTrue( $this->requests[0][1]['reject_unsafe_urls'] );
	}

	/**
	 * @dataProvider unsafe_base_urls
	 */
	public function test_rejects_internal_base_urls( string $base ): void {
		try {
			$this->client( 'vp_secret', $base )->get_video( 'abc' );
			$this->fail( 'Expected ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->getCode() );
		}
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsafe_base_urls(): array {
		return array(
			'localhost'         => array( 'https://localhost/api/v1' ),
			'localhost suffix'  => array( 'https://api.localhost/api/v1' ),
			'loopback ip'       => array( 'https://127.0.0.1/api/v1' ),
			'private ip'        => array( 'https://10.0.0.5/api/v1' ),
			'link local ip'     => array( 'https://169.254.169.254/api/v1' ),
			'ipv6 loopback'     => array( 'https://[::1]/api/v1' ),
			'userinfo'          => array( 'https://user:pw@api.videooptimizer.eu/api/v1' ),
			'single label host' => array( 'https://intranet/api/v1' ),
			'query'             => array( 'https://api.videooptimizer.eu/api/v1?x=1' ),
			'short ipv4'        => array( 'https://127.1/api/v1' ),
			'hex ipv4'          => array( 'https://0x7f.0.0.1/api/v1' ),
			'decimal ipv4'      => array( 'https://2130706433/api/v1' ),
			'trailing dot ip'   => array( 'https://127.0.0.1./api/v1' ),
			'cgnat'             => array( 'https://100.64.0.1/api/v1' ),
			'doc ipv6'          => array( 'https://[2001:db8::1]/api/v1' ),
			'mapped ipv6'       => array( 'https://[::ffff:127.0.0.1]/api/v1' ),
		);
	}

	public function test_accepts_public_and_idn_hosts(): void {
		$this->client( 'vp_secret', 'https://api.videooptimizer.eu/api/v1' )->get_video( 'abc' );
		$this->client( 'vp_secret', 'https://api.bücher.example/api/v1' )->get_video( 'abc' );
		$this->assertCount( 2, $this->requests );
	}

	public function test_redirect_responses_are_errors(): void {
		$this->responses[] = $this->response( 302, array(), array( 'location' => 'https://internal.example/' ) );

		try {
			$this->client()->get_video( 'abc' );
			$this->fail( 'Expected ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 502, $e->status() );
			$this->assertStringContainsString( 'redirect', $e->getMessage() );
		}
	}

	public function test_wordpress_url_rejection_is_a_configuration_error(): void {
		$this->responses[] = new \WP_Error( 'http_request_failed', 'A valid URL was not provided.' );

		try {
			$this->client()->get_video( 'abc' );
			$this->fail( 'Expected ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->status() );
			$this->assertStringContainsString( 'base URL', $e->getMessage() );
		}
	}

	public function test_lists_tags(): void {
		$this->responses[] = $this->response(
			200,
			array(
				'data' => array(
					array(
						'name'        => 'promo',
						'video_count' => 3,
					),
				),
			)
		);

		$tags = $this->client()->list_tags();

		$this->assertSame( 'promo', $tags[0]['name'] );
		$this->assertSame( 'https://api.videooptimizer.eu/api/v1/tags', $this->requests[0][0] );
	}

	public function test_videos_page_filters_by_tags_and_metadata(): void {
		$this->client()->list_videos_page(
			'lib-1',
			null,
			60,
			array(
				'tags'     => array( 'promo', 'summer 26' ),
				'metadata' => array( 'cms_id' => 'a b&c' ),
			)
		);

		$url = $this->requests[0][0];
		$this->assertStringContainsString( '/libraries/lib-1/videos?', $url );
		$this->assertStringContainsString( 'tags=promo%2Csummer%2026', $url );
		$this->assertStringContainsString( 'metadata[cms_id]=a%20b%26c', $url );
		$this->assertStringContainsString( 'limit=60', $url );
	}

	public function test_updates_tags_and_metadata_in_one_patch(): void {
		$this->client()->update_video(
			'abc',
			array(
				'tags'     => array( 'a' ),
				'metadata' => array(
					'k' => 'v',
					'x' => null,
				),
			)
		);

		$this->assertSame( '{"tags":["a"],"metadata":{"k":"v","x":null}}', $this->requests[0][1]['body'] );
	}
}

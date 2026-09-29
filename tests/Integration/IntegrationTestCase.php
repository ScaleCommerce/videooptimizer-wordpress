<?php
/**
 * Base integration test case.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Integration;

/**
 * Real WordPress; all outgoing VideoOptimizer HTTP calls are answered by a fake API via
 * pre_http_request, so the tests never touch the network.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

	public const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	/**
	 * Requests seen by the fake API [method, url].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	protected array $api_calls = array();

	public function set_up(): void {
		parent::set_up();
		$this->api_calls = array();
		\ScaleCommerce\VideoOptimizer\Plugin::instance()->settings->flush();
		\ScaleCommerce\VideoOptimizer\Plugin::instance()->embeds->forget( self::UUID );
		add_filter( 'pre_http_request', array( $this, 'fake_api' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_api' ), 10 );
		parent::tear_down();
	}

	/**
	 * Fake VideoOptimizer API.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit value.
	 * @param array<string, mixed>       $args Request args.
	 * @param string                     $url  URL.
	 * @return false|array<string, mixed>
	 */
	public function fake_api( $pre, $args, $url ) {
		if ( ! str_starts_with( $url, 'https://api.videooptimizer.eu/' ) ) {
			return $pre;
		}
		$this->api_calls[] = array( $args['method'], $url );
		$path              = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( str_contains( $path, '/embed/' ) ) {
			return self::json(
				200,
				array(
					'data' => array(
						'title'      => 'Demo',
						'duration'   => 12,
						'resolution' => '1920x1080',
						'poster'     => 'https://cdn.example/poster.jpg',
						'sources'    => array(
							array(
								'src'  => 'https://cdn.example/hls/master.m3u8',
								'type' => 'application/vnd.apple.mpegurl',
							),
							array(
								'src'   => 'https://cdn.example/360p.mp4',
								'type'  => 'video/mp4',
								'label' => '360p',
							),
						),
					),
				)
			);
		}
		if ( str_ends_with( $path, '/libraries' ) ) {
			return self::json(
				200,
				array(
					'data'       => array(
						array(
							'id'            => 'lib-1',
							'name'          => 'Main',
							'media_managed' => true,
						),
					),
					'pagination' => array( 'has_more' => false ),
				)
			);
		}
		if ( preg_match( '~/videos/([0-9a-f-]{36})$~', $path, $m ) ) {
			return self::json(
				200,
				array(
					'data' => array(
						'uuid'   => $m[1],
						'status' => 'ready',
					),
				)
			);
		}

		return self::json( 404, array( 'message' => 'Not found' ) );
	}

	/**
	 * JSON response in the WP_Http format.
	 *
	 * @param int                  $code Status.
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	protected static function json( int $code, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Performs a REST request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below /videooptimizer/v1.
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function rest( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/videooptimizer/v1' . $route );
		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	protected function login_as( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );

		return $id;
	}

	protected function configure_token(): void {
		\ScaleCommerce\VideoOptimizer\Plugin::instance()->settings->set_token( 'vp_integration_token' );
	}
}

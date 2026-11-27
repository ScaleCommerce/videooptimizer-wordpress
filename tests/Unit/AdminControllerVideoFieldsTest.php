<?php
/**
 * Tags, metadata and filters through the admin REST controller.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Media\DeleteOffers;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Rest\AdminController;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

final class AdminControllerVideoFieldsTest extends TestCase {

	private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	/**
	 * Recorded client calls [method, args].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public array $calls = array();

	/**
	 * Forgotten embed uuids.
	 *
	 * @var array<int, string>
	 */
	public array $forgotten = array();

	private function controller(): AdminController {
		$test  = $this;
		$noctr = static fn ( string $class ) => ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function update_video( string $uuid, array $payload ): array {
				$this->test->calls[] = array( 'update_video', array( $uuid, $payload ) );
				return array( 'uuid' => $uuid );
			}
			public function list_tags(): array {
				$this->test->calls[] = array( 'list_tags', array() );
				return array(
					array(
						'name'        => 'promo',
						'video_count' => 2,
					),
				);
			}
			public function list_videos_page( ?string $library_id = null, ?string $cursor = null, int $limit = self::PAGE_LIMIT, array $filters = array() ): array {
				$this->test->calls[] = array( 'list_videos_page', array( $library_id, $cursor, $limit, $filters ) );
				return array(
					'items'       => array(),
					'next_cursor' => null,
				);
			}
			public function ingest_url( array $payload ): array {
				$this->test->calls[] = array( 'ingest_url', array( $payload ) );
				return array( 'uuid' => 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8' );
			}
			public function complete_upload( array $payload ): array {
				$this->test->calls[] = array( 'complete_upload', array( $payload ) );
				return array( 'uuid' => 'x' );
			}
		};

		$embeds = new class( $client, $test ) extends EmbedRepository {
			public function __construct( Client $c, private $test ) {
				parent::__construct( $c );
			}
			public function forget( string $uuid, bool $changed = true ): void {
				$this->test->forgotten[] = $uuid;
			}
		};

		return new AdminController( $noctr( Settings::class ), $client, $embeds, $noctr( Attachments::class ), $noctr( DeleteOffers::class ) );
	}

	/**
	 * Calls a private handler.
	 *
	 * @param string               $method Handler.
	 * @param array<string, mixed> $json   JSON body.
	 * @param array<string, mixed> $params URL/query params.
	 * @return mixed
	 */
	private function call( string $method, array $json = array(), array $params = array() ) {
		$controller = $this->controller();
		$m          = new \ReflectionMethod( $controller, $method );
		return $m->invoke( $controller, new \WP_REST_Request( $json, $params + array( 'uuid' => self::UUID ) ) );
	}

	public function test_update_sends_normalized_tags_and_metadata_and_forgets_the_embed(): void {
		$this->call(
			'update_video',
			array(
				'title'    => 'T',
				'tags'     => array( ' Promo ', 'promo', '2026' ),
				'metadata' => array(
					'cms_id' => ' 42 ',
					'old'    => '',
				),
			)
		);

		$this->assertSame(
			array(
				'title'    => 'T',
				'tags'     => array( 'Promo', '2026' ),
				'metadata' => array(
					'cms_id' => '42',
					'old'    => null,
				),
			),
			$this->calls[0][1][1]
		);
		$this->assertSame( array( self::UUID ), $this->forgotten );
	}

	public function test_update_can_clear_all_tags_and_all_metadata(): void {
		$this->call(
			'update_video',
			array(
				'tags'     => array(),
				'metadata' => null,
			)
		);

		$payload = $this->calls[0][1][1];
		$this->assertSame( array(), $payload['tags'] );
		$this->assertArrayHasKey( 'metadata', $payload );
		$this->assertNull( $payload['metadata'] );
	}

	public function test_update_leaves_tags_and_metadata_alone_when_not_sent(): void {
		$this->call( 'update_video', array( 'title' => 'Only title' ) );

		$this->assertSame( array( 'title' => 'Only title' ), $this->calls[0][1][1] );
	}

	public function test_invalid_tags_or_metadata_never_reach_the_api(): void {
		$result = $this->call( 'update_video', array( 'tags' => array( 'a,b' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );

		$result = $this->call( 'update_video', array( 'metadata' => array( 'Bad Key' => 'x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );

		$this->assertSame( array(), $this->calls );
	}

	public function test_lists_tags(): void {
		$this->assertSame(
			array(
				array(
					'name'        => 'promo',
					'video_count' => 2,
				),
			),
			$this->call( 'list_tags' )
		);
	}

	public function test_video_list_passes_tag_and_metadata_filters(): void {
		$this->call(
			'list_videos',
			array(),
			array(
				'tags'     => 'promo, 2026',
				'metadata' => array( 'cms_id' => '42' ),
				'limit'    => '60',
			)
		);

		$this->assertSame(
			array(
				null,
				null,
				60,
				array(
					'tags'     => array( 'promo', '2026' ),
					'metadata' => array( 'cms_id' => '42' ),
				),
			),
			$this->calls[0][1]
		);
	}

	public function test_video_list_rejects_invalid_filters(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'list_videos', array(), array( 'tags' => str_repeat( 'x', 60 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->call( 'list_videos', array(), array( 'metadata' => array( 'Bad' => 'x' ) ) ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_ingest_and_upload_complete_forward_tags_and_metadata(): void {
		$this->call(
			'ingest',
			array(
				'library_id' => 'lib',
				'source_url' => 'https://example.com/a.mp4',
				'tags'       => array( 'promo' ),
				'metadata'   => array( 'cms_id' => '42' ),
			)
		);
		$this->call(
			'complete_upload',
			array(
				'libraryId' => 'lib',
				'uuid'      => self::UUID,
				'uploadId'  => 'u',
				'parts'     => array(
					array(
						'partNumber' => 1,
						'etag'       => '"e"',
					),
				),
				'tags'      => array( 'promo' ),
				'metadata'  => array( 'cms_id' => '42' ),
			)
		);

		foreach ( $this->calls as [ , $args ] ) {
			$this->assertSame( array( 'promo' ), $args[0]['tags'] );
			$this->assertSame( array( 'cms_id' => '42' ), $args[0]['metadata'] );
		}
		$this->assertCount( 2, $this->calls );
	}
}

<?php
/**
 * Library actions drop the cached embed data of the library's videos.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Media\DeleteOffers;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Rest\AdminController;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

final class AdminControllerLibraryTest extends TestCase {

	/**
	 * Forgotten uuids.
	 *
	 * @var array<int, string>
	 */
	public array $forgotten = array();

	/**
	 * Client call log.
	 *
	 * @var array<int, string>
	 */
	public array $calls = array();

	private function controller( bool $list_fails = false ): AdminController {
		$test  = $this;
		$noctr = static fn ( string $class ) => ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test, $list_fails ) extends Client {
			public function __construct( callable $t, string $b, private $test, private bool $fails ) {
				parent::__construct( $t, $b );
			}
			public function list_videos( ?string $library_id = null ): array {
				$this->test->log( 'list' );
				if ( $this->fails ) {
					throw new ApiException( 'down', 502 );
				}
				return array( array( 'uuid' => 'aaa' ), array( 'uuid' => 'bbb' ) );
			}
			public function delete_library( string $id ): void {
				$this->test->log( 'delete' );
			}
			public function reprocess_library( string $id, bool $force = false ): array {
				$this->test->log( 'reprocess' );
				return array( 'queued' => 2 );
			}
			public function update_library( string $id, array $payload ): array {
				$this->test->log( 'update' );
				return array( 'id' => $id );
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

	public function log( string $call ): void {
		$this->calls[] = $call;
	}

	private function call( AdminController $controller, string $method, array $json = array() ) {
		$m = new \ReflectionMethod( $controller, $method );
		return $m->invoke( $controller, new \WP_REST_Request( $json, array( 'id' => 'lib-1' ) ) );
	}

	public function test_delete_forgets_embeds_listed_before_the_delete(): void {
		$this->call( $this->controller(), 'delete_library' );

		$this->assertSame( array( 'list', 'delete' ), $this->calls );
		$this->assertSame( array( 'aaa', 'bbb' ), $this->forgotten );
	}

	public function test_reprocess_forgets_embeds(): void {
		$result = $this->call( $this->controller(), 'reprocess_library', array( 'force' => true ) );

		$this->assertSame( array( 'queued' => 2 ), $result );
		$this->assertSame( array( 'aaa', 'bbb' ), $this->forgotten );
	}

	public function test_reprocess_rejects_a_non_boolean_force(): void {
		foreach ( array( 'true', 1, 'yes', null ) as $value ) {
			$result = $this->call( $this->controller(), 'reprocess_library', array( 'force' => $value ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->calls );
		$this->assertSame( array( 'queued' => 2 ), $this->call( $this->controller(), 'reprocess_library', array( 'force' => false ) ) );
	}

	public function test_ladder_change_forgets_embeds_but_rename_does_not(): void {
		$this->call( $this->controller(), 'update_library', array( 'resolutions' => '720p,1080p' ) );
		$this->assertSame( array( 'aaa', 'bbb' ), $this->forgotten );

		$this->forgotten = array();
		$this->call( $this->controller(), 'update_library', array( 'name' => 'Renamed' ) );
		$this->assertSame( array(), $this->forgotten );
	}

	public function test_listing_failure_does_not_break_the_action(): void {
		$result = $this->call( $this->controller( true ), 'reprocess_library' );

		$this->assertSame( array( 'queued' => 2 ), $result );
		$this->assertSame( array(), $this->forgotten );

		$this->call( $this->controller( true ), 'delete_library' );
		$this->assertContains( 'delete', $this->calls );
	}
}

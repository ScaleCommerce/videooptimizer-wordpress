<?php
/**
 * Savings route of the admin REST controller.
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

final class AdminControllerSavingsTest extends TestCase {

	/**
	 * Client calls: arguments of get_savings().
	 *
	 * @var array<int, array<int, ?string>>
	 */
	public array $calls = array();

	/**
	 * Whether the API fails.
	 *
	 * @var bool
	 */
	public bool $fails = false;

	private function controller(): AdminController {
		$test  = $this;
		$noctr = static fn ( string $class ) => ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function get_savings( ?string $from = null, ?string $to = null, ?string $library_id = null, ?string $video_uuid = null ): array {
				$this->test->calls[] = array( $from, $to, $library_id, $video_uuid );
				if ( $this->test->fails ) {
					throw new ApiException( 'down', 503 );
				}
				return array( 'traffic_available' => true );
			}
		};

		return new AdminController( $noctr( Settings::class ), $client, new EmbedRepository( $client ), $noctr( Attachments::class ), $noctr( DeleteOffers::class ) );
	}

	/**
	 * Calls the savings handler.
	 *
	 * @param array<string, mixed> $params Query params.
	 * @return mixed
	 */
	private function savings( array $params = array() ) {
		$controller = $this->controller();
		$m          = new \ReflectionMethod( $controller, 'savings' );
		return $m->invoke( $controller, new \WP_REST_Request( array(), $params ) );
	}

	public function test_forwards_valid_filters(): void {
		$result = $this->savings(
			array(
				'from'       => '2026-11-01',
				'to'         => '2026-11-27',
				'library_id' => 'lib-1',
			)
		);

		$this->assertSame( array( 'traffic_available' => true ), $result );
		$this->assertSame( array( '2026-11-01', '2026-11-27', 'lib-1', null ), $this->calls[0] );
	}

	public function test_rejects_invalid_dates_and_ids_before_calling_the_api(): void {
		foreach (
			array(
				array( 'from' => '01.11.2026' ),
				array( 'to' => 'tomorrow' ),
				array( 'library_id' => '../x' ),
				array( 'video_uuid' => 'not-a-uuid' ),
			) as $params
		) {
			$this->assertInstanceOf( \WP_Error::class, $this->savings( $params ) );
		}
		$this->assertSame( array(), $this->calls );
	}

	public function test_answers_are_cached_for_a_few_minutes_per_filter(): void {
		$this->savings();
		$this->savings();
		$this->savings( array( 'library_id' => 'lib-1' ) );

		$this->assertCount( 2, $this->calls );
		$this->assertContains( 300, $this->transient_ttls );
	}

	public function test_failures_are_not_cached(): void {
		$this->fails = true;
		try {
			$this->savings();
			$this->fail( 'Expected ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( array(), $this->transients );
		}
	}
}

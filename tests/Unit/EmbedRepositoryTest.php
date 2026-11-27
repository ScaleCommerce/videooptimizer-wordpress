<?php
/**
 * Embed lookups fail fast during an API outage.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;

final class EmbedRepositoryTest extends TestCase {

	private const A = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
	private const B = '15e2662a-e0f2-4c3d-9a11-0123456789ab';

	/**
	 * Lookups that reached the API.
	 *
	 * @var array<int, string>
	 */
	public array $lookups = array();

	/**
	 * Per-uuid outcome: an ApiException to throw, or an embed payload.
	 *
	 * @var array<string, mixed>
	 */
	public array $outcomes = array();

	private function repository(): EmbedRepository {
		$test   = $this;
		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function get_embed( string $uuid ): array {
				$this->test->lookups[] = $uuid;
				$outcome               = $this->test->outcomes[ $uuid ];
				if ( $outcome instanceof ApiException ) {
					throw $outcome;
				}
				return $outcome;
			}
		};

		return new EmbedRepository( $client );
	}

	/**
	 * A ready embed payload.
	 *
	 * @return array<string, mixed>
	 */
	private function ready(): array {
		return array(
			'sources' => array(
				array(
					'src'   => 'https://cdn.example/encoded/x/h264/720p.mp4',
					'type'  => 'video/mp4',
					'label' => '720p',
				),
			),
		);
	}

	/**
	 * Statuses that mean the whole API is unavailable.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function outage_statuses(): array {
		return array(
			'transport error' => array( ApiException::UNREACHABLE ),
			'rate limit'      => array( 429 ),
			'bad gateway'     => array( 502 ),
			'unavailable'     => array( 503 ),
			'gateway timeout' => array( 504 ),
		);
	}

	/**
	 * @dataProvider outage_statuses
	 */
	public function test_outage_makes_other_uncached_videos_skip_the_api( int $status ): void {
		$this->outcomes = array(
			self::A => new ApiException( 'down', $status ),
			self::B => $this->ready(),
		);

		$this->assertNull( $this->repository()->get( self::A ) );
		// A fresh repository (next request): B is not cached, but the API is flagged down.
		$this->assertNull( $this->repository()->get( self::B ) );

		$this->assertSame( array( self::A ), $this->lookups );
	}

	public function test_a_single_missing_or_broken_video_does_not_flag_the_api(): void {
		$this->outcomes = array(
			self::A => new ApiException( 'not found', 404 ),
			self::B => $this->ready(),
		);
		$this->assertNull( $this->repository()->get( self::A ) );
		$this->assertNotNull( $this->repository()->get( self::B ) );

		unset( $this->transients[ 'videooptimizer_embed_' . self::B ] );
		$this->outcomes[ self::B ] = new ApiException( 'boom', 500 );
		$this->assertNull( $this->repository()->get( self::B ) );
		$this->assertArrayNotHasKey( EmbedRepository::DOWN_KEY, $this->transients );
	}

	public function test_cached_videos_are_still_served_during_an_outage(): void {
		$this->outcomes = array(
			self::A => $this->ready(),
			self::B => new ApiException( 'down', 503 ),
		);
		$this->assertNotNull( $this->repository()->get( self::A ) );
		$this->assertNull( $this->repository()->get( self::B ) );

		$this->assertNotNull( $this->repository()->get( self::A ) );
		$this->assertSame( array( self::A, self::B ), $this->lookups );
	}

	public function test_the_outage_flag_expires_and_never_extends_itself(): void {
		$this->outcomes = array(
			self::A => new ApiException( 'down', 503 ),
			self::B => $this->ready(),
		);
		$this->repository()->get( self::A );
		$this->assertSame( 60, $this->transient_ttls[ EmbedRepository::DOWN_KEY ] );

		// Requests during the outage skip the API and do not write the flag again.
		$this->repository()->get( self::B );
		$this->assertSame( 1, $this->transient_writes[ EmbedRepository::DOWN_KEY ] );

		// After the flag expired the API is asked again.
		unset( $this->transients[ EmbedRepository::DOWN_KEY ] );
		$this->assertNotNull( $this->repository()->get( self::B ) );
		$this->assertSame( array( self::A, self::B ), $this->lookups );
	}
}

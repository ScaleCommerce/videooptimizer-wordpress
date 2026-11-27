<?php
/**
 * Cached video list pages.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\VideoLists;

final class VideoListsTest extends TestCase {

	private const A = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
	private const B = '15e2662a-e0f2-4c3d-9a11-0123456789ab';
	private const LIB = '3293aeb1-64b7-42a8-b2f7-c5355dcdd58d';

	/**
	 * Calls that reached the API: [library, cursor, limit, filters].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = array();

	/**
	 * Result of the fake API call (an exception to throw).
	 *
	 * @var array<string, mixed>|ApiException
	 */
	public $result;

	protected function set_up(): void {
		parent::set_up();
		$this->calls  = array();
		$this->result = array(
			'items'       => array(
				array(
					'uuid'       => self::A,
					'title'      => 'Clip.mp4',
					'status'     => 'ready',
					'duration'   => 61.4,
					'resolution' => '1280x720',
					'poster_url' => 'https://poster.example/a.jpg',
				),
				array(
					'uuid'   => self::B,
					'title'  => 'Still processing',
					'status' => 'processing',
				),
				array(
					'uuid'   => 'not-a-uuid',
					'status' => 'ready',
				),
			),
			'next_cursor' => 'abc_DEF-123=',
		);
	}

	private function lists(): VideoLists {
		$test   = $this;
		$client = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function list_videos_page( ?string $library_id = null, ?string $cursor = null, int $limit = 100, array $filters = array() ): array {
				$this->test->calls[] = array( $library_id, $cursor, $limit, $filters );
				if ( $this->test->result instanceof ApiException ) {
					throw $this->test->result;
				}
				return $this->test->result;
			}
		};

		return new VideoLists( $client );
	}

	public function test_page_returns_only_ready_videos_as_cards(): void {
		$page = $this->lists()->page( self::LIB, '', null, 12 );

		$this->assertSame( 'abc_DEF-123=', $page['next'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame(
			array(
				'uuid'     => self::A,
				'title'    => 'Clip',
				'poster'   => 'https://poster.example/a.jpg',
				'duration' => 61,
				'width'    => 1280,
				'height'   => 720,
			),
			$page['items'][0]
		);
		$this->assertSame( array( array( self::LIB, null, 12, array( 'tags' => array() ) ) ), $this->calls );
	}

	public function test_tags_are_split_and_sent_as_a_filter(): void {
		$this->lists()->page( '', ' news , launch ,', 'cur', 6 );

		$this->assertSame( array( null, 'cur', 6, array( 'tags' => array( 'news', 'launch' ) ) ), $this->calls[0] );
	}

	public function test_pages_are_cached_per_library_cursor_and_size(): void {
		$lists = $this->lists();
		$lists->page( self::LIB, '', null, 12 );
		$lists->page( self::LIB, '', null, 12 );
		$this->assertCount( 1, $this->calls );

		$lists->page( self::LIB, '', 'next', 12 );
		$lists->page( self::LIB, '', null, 6 );
		$lists->page( '', '', null, 12 );
		$this->assertCount( 4, $this->calls );
		$this->assertContains( 300, $this->transient_ttls );
	}

	public function test_a_video_change_invalidates_the_cached_pages(): void {
		$lists = $this->lists();
		$lists->page( self::LIB, '', null, 12 );

		$lists->bump();
		$lists->page( self::LIB, '', null, 12 );

		$this->assertCount( 2, $this->calls );
	}

	public function test_failures_are_reported_and_remembered_for_a_minute(): void {
		$this->result = new ApiException( 'nope', 404 );
		$lists        = $this->lists();

		$this->assertNull( $lists->page( self::LIB, '', null, 12 ) );
		$this->assertSame( 404, $lists->failure() );
		$this->assertNull( $lists->page( self::LIB, '', null, 12 ) );
		$this->assertSame( 404, $lists->failure() );
		$this->assertCount( 1, $this->calls, 'the failure marker spares the API' );
		$this->assertContains( 60, $this->transient_ttls );
		$this->assertArrayNotHasKey( EmbedRepository::DOWN_KEY, $this->transients );
	}

	public function test_an_outage_flags_the_api_as_down_and_stops_further_calls(): void {
		$this->result = new ApiException( 'down', 503 );
		$lists        = $this->lists();
		$lists->page( self::LIB, '', null, 12 );

		$this->assertSame( 503, $this->transients[ EmbedRepository::DOWN_KEY ] );
		$this->assertNull( $lists->page( self::LIB, '', 'other', 12 ) );
		$this->assertSame( ApiException::UNREACHABLE, $lists->failure() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_cursor_validation(): void {
		$this->assertTrue( VideoLists::is_cursor( 'MjAyNi0xMS0wNVQxMTo1NDoxMS43ODVafDEyNDM' ) );
		$this->assertFalse( VideoLists::is_cursor( '' ) );
		$this->assertFalse( VideoLists::is_cursor( 'a b' ) );
		$this->assertFalse( VideoLists::is_cursor( '"><script>' ) );
		$this->assertFalse( VideoLists::is_cursor( array( 'x' ) ) );
		$this->assertFalse( VideoLists::is_cursor( str_repeat( 'a', 513 ) ) );
	}
}

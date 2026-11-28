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

	/**
	 * Optional pages by cursor ('' = first); takes precedence over $result.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $pages = array();

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
				if ( array() !== $this->test->pages ) {
					$page = $this->test->pages[ (string) $cursor ] ?? null;
					if ( $page instanceof ApiException ) {
						throw $page;
					}
					return $page;
				}
				if ( $this->test->result instanceof ApiException ) {
					throw $this->test->result;
				}
				return $this->test->result;
			}
		};

		return new VideoLists( $client );
	}

	public function test_page_returns_only_ready_videos_as_cards(): void {
		$this->result['next_cursor'] = 'abc_DEF-123=';
		$this->pages                 = array(
			''             => $this->result,
			'abc_DEF-123=' => array(
				'items'       => array(),
				'next_cursor' => null,
			),
		);
		$page                        = $this->lists()->page( self::LIB, '', null, 12 );

		$this->assertNull( $page['next'], 'the follow-up page was empty and last' );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame(
			array(
				'uuid'     => self::A,
				'library'  => '',
				'title'    => 'Clip',
				'poster'   => 'https://poster.example/a.jpg',
				'duration' => 61,
				'width'    => 1280,
				'height'   => 720,
			),
			$page['items'][0]
		);
		$this->assertSame( array( self::LIB, null, 12, array( 'tags' => array() ) ), $this->calls[0] );
		$this->assertSame( 11, $this->calls[1][2], 'asks only for what is still missing' );
	}

	public function test_tags_are_split_and_sent_as_a_filter(): void {
		$this->lists()->page( '', ' news , launch ,', 'cur', 6 );

		$this->assertSame( array( null, 'cur', 6, array( 'tags' => array( 'news', 'launch' ) ) ), $this->calls[0] );
	}

	public function test_pages_are_cached_per_library_cursor_and_size(): void {
		$this->result['next_cursor'] = null;
		$lists                       = $this->lists();
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
		$this->result['next_cursor'] = null;
		$lists                       = $this->lists();
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

	/**
	 * Builds API items.
	 *
	 * @param array<int, string> $statuses Status per item.
	 * @param int                $offset   Numbering offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( array $statuses, int $offset ): array {
		$items = array();
		foreach ( $statuses as $i => $status ) {
			$items[] = array(
				'uuid'   => sprintf( '00000000-0000-4000-8000-%012d', $offset + $i ),
				'title'  => 'V' . ( $offset + $i ),
				'status' => $status,
			);
		}

		return $items;
	}

	public function test_a_page_is_filled_with_ready_videos_from_the_following_api_pages(): void {
		$this->pages = array(
			''   => array(
				'items'       => $this->items( array( 'ready', 'processing', 'processing', 'ready' ), 0 ),
				'next_cursor' => 'c1',
			),
			'c1' => array(
				'items'       => $this->items( array( 'processing', 'ready' ), 10 ),
				'next_cursor' => 'c2',
			),
			'c2' => array(
				'items'       => $this->items( array( 'ready' ), 20 ),
				'next_cursor' => 'c3',
			),
		);
		$page = $this->lists()->page( '', '', null, 4 );

		$this->assertCount( 4, $page['items'] );
		$this->assertSame( 'c3', $page['next'], 'the cursor is right behind the last consumed API item' );
		$this->assertSame( array( 4, 2, 1 ), array_column( $this->calls, 2 ), 'each call asks for what is missing' );
		$this->assertSame( array( null, 'c1', 'c2' ), array_column( $this->calls, 1 ) );
	}

	public function test_filling_is_bounded_to_three_api_calls_and_never_hides_older_videos(): void {
		$this->pages = array(
			''   => array( 'items' => $this->items( array( 'processing', 'processing' ), 0 ), 'next_cursor' => 'c1' ),
			'c1' => array( 'items' => $this->items( array( 'processing', 'processing' ), 10 ), 'next_cursor' => 'c2' ),
			'c2' => array( 'items' => $this->items( array( 'processing', 'processing' ), 20 ), 'next_cursor' => 'c3' ),
			'c3' => array( 'items' => $this->items( array( 'ready' ), 30 ), 'next_cursor' => null ),
		);
		$page = $this->lists()->page( '', '', null, 2 );

		$this->assertCount( 3, $this->calls );
		$this->assertSame( array(), $page['items'] );
		$this->assertSame( 'c3', $page['next'], 'an empty page still points to the older ready videos' );
	}

	public function test_a_failure_after_some_videos_keeps_what_was_found(): void {
		$this->pages = array(
			''   => array( 'items' => $this->items( array( 'ready', 'processing' ), 0 ), 'next_cursor' => 'c1' ),
			'c1' => new ApiException( 'boom', 500 ),
		);
		$lists = $this->lists();
		$page  = $lists->page( '', '', null, 4 );

		$this->assertCount( 1, $page['items'] );
		$this->assertNull( $lists->failure() );
	}

	public function test_cursor_lookups_share_the_site_wide_budget_and_never_flag_the_api_as_down(): void {
		$this->result = new ApiException( 'down', 503 );
		$lists        = $this->lists();
		$lists->page( '', '', 'visitor-chosen', 12 );
		$this->assertArrayNotHasKey( EmbedRepository::DOWN_KEY, $this->transients, 'a visitor-chosen cursor must not trip the marker' );

		$this->transients[ \ScaleCommerce\VideoOptimizer\Render\LookupLimit::KEY ] = 30;
		$this->calls                                                                  = array();
		$this->assertNull( $lists->page( '', '', 'another', 12 ) );
		$this->assertSame( 429, $lists->failure(), 'temporary, so the page stays out of page caches' );
		$this->assertSame( array(), $this->calls, 'over budget: no API call' );
	}

	public function test_listed_videos_seed_the_ownership_cache(): void {
		$this->result['items'][0]['library_id'] = self::LIB;
		$this->result['next_cursor']            = null;
		$ownership                              = new \ScaleCommerce\VideoOptimizer\Render\Ownership( $this->lists_client(), static fn () => 'fp' );
		$lists                                  = new VideoLists( $this->lists_client(), $ownership );
		$lists->page( self::LIB, '', null, 12 );

		$this->assertSame(
			array(
				'state'   => 'found',
				'library' => self::LIB,
			),
			$this->transients[ 'videooptimizer_own_fp_' . self::A ]
		);
		$this->assertArrayNotHasKey( 'videooptimizer_own_fp_' . self::B, $this->transients, 'only ready videos' );
		$this->assertSame( 'found', $ownership->check( self::A, self::LIB ), 'the detail page needs no lookup' );
	}

	/**
	 * The fake client of this test.
	 */
	private function lists_client(): Client {
		$test = $this;

		return new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function list_videos_page( ?string $library_id = null, ?string $cursor = null, int $limit = 100, array $filters = array() ): array {
				$this->test->calls[] = array( $library_id, $cursor, $limit, $filters );
				return $this->test->result;
			}
			public function get_video( string $uuid ): array {
				throw new ApiException( 'must not be called', 500 );
			}
		};
	}

	public function test_cursor_validation(): void {
		$this->assertTrue( VideoLists::is_cursor( 'MjAyNi0xMS0wNVQxMTo1NDoxMS43ODVafDEyNDM' ) );
		$this->assertFalse( VideoLists::is_cursor( '' ) );
		$this->assertFalse( VideoLists::is_cursor( 'a b' ) );
		$this->assertTrue( VideoLists::is_cursor( 'ab+/cd==:1.2' ) );
		$this->assertFalse( VideoLists::is_cursor( str_repeat( 'a', 201 ) ) );
		$this->assertFalse( VideoLists::is_cursor( '"><script>' ) );
		$this->assertFalse( VideoLists::is_cursor( array( 'x' ) ) );
			}
}

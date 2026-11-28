<?php
/**
 * Video list and video detail elements.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Cache\PageCache;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\DetailPage;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\LookupLimit;
use ScaleCommerce\VideoOptimizer\Render\Ownership;
use ScaleCommerce\VideoOptimizer\Render\Renderer;
use ScaleCommerce\VideoOptimizer\Render\VideoLists;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Settings\TokenCipher;

final class VideoListDetailTest extends TestCase {

	private const A   = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
	private const B   = '15e2662a-e0f2-4c3d-9a11-0123456789ab';
	private const LIB = '3293aeb1-64b7-42a8-b2f7-c5355dcdd58d';

	/**
	 * Embeds by uuid (missing = not found).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $embeds = array();

	/**
	 * Uuids with a cached embed.
	 *
	 * @var array<int, string>
	 */
	private array $cached = array();

	/**
	 * Failure reported for missing videos.
	 *
	 * @var array{status: int, outage: bool}|null
	 */
	private ?array $failure = null;

	/**
	 * List page returned by the fake (null = failure).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $page;

	/**
	 * Failure status of the list.
	 *
	 * @var int|null
	 */
	private ?int $list_failure = null;

	/**
	 * Arguments of the list lookups.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $list_calls = array();

	/**
	 * Videos of the organization: uuid => library id (an exception to throw instead).
	 *
	 * @var array<string, string|ApiException>
	 */
	public array $owned = array();

	/**
	 * Authenticated lookups that reached the API.
	 *
	 * @var array<int, string>
	 */
	public array $own_calls = array();

	private Renderer $renderer;

	private DetailPage $detail;

	private Ownership $ownership;

	private EmbedRepository $repo;

	protected function set_up(): void {
		parent::set_up();
		PageCache::reset_degraded();
		$_GET = array();

		$this->embeds = array(
			self::A => $this->embed( self::A, 'Demo Film' ),
		);
		$this->transients = array();
		// A minimal block parser: flat `<!-- wp:name {json} /-->` / opening comments are enough here.
		Functions\when( 'parse_blocks' )->alias(
			static function ( string $content ): array {
				preg_match_all( '/<!--\s+wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+\/?-->/s', $content, $m, PREG_SET_ORDER );
				$blocks = array();
				foreach ( $m as $row ) {
					$blocks[] = array(
						'blockName'   => $row[1],
						'attrs'       => isset( $row[2] ) ? (array) json_decode( $row[2], true ) : array(),
						'innerBlocks' => array(),
					);
				}
				return $blocks;
			}
		);
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'shortcode_parse_atts' )->alias(
			static function ( $text ) {
				preg_match_all( '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', (string) $text, $m, PREG_SET_ORDER );
				$out = array();
				foreach ( $m as $row ) {
					$out[ $row[1] ] = $row[2] . ( $row[3] ?? '' ) . ( $row[4] ?? '' );
				}
				return $out;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->page   = array(
			'items' => array(
				array(
					'uuid'     => self::A,
					'title'    => 'Demo <b>Film</b>',
					'poster'   => 'https://poster.example/a.jpg',
					'duration' => 125,
					'width'    => 1280,
					'height'   => 720,
				),
				array(
					'uuid'     => self::B,
					'title'    => '',
					'poster'   => null,
					'duration' => null,
					'width'    => null,
					'height'   => null,
				),
			),
			'next'  => 'CURSOR2',
		);

		$embeds = $this->createMock( EmbedRepository::class );
		$embeds->method( 'get' )->willReturnCallback( fn ( string $uuid ) => $this->embeds[ $uuid ] ?? null );
		$embeds->method( 'failure' )->willReturnCallback( fn () => $this->failure );
		$embeds->method( 'cached' )->willReturnCallback( fn ( string $uuid ) => in_array( $uuid, $this->cached, true ) ? ( $this->embeds[ $uuid ] ?? null ) : null );
		$lists = $this->createMock( VideoLists::class );
		$lists->method( 'page' )->willReturnCallback(
			function ( ...$args ) {
				$this->list_calls[] = $args;

				return $this->page;
			}
		);
		$lists->method( 'failure' )->willReturnCallback( fn () => $this->list_failure );
		$assets = $this->createMock( Assets::class );

		Functions\when( 'get_post_status' )->alias( static fn ( $id ) => 77 === $id ? 'publish' : false );
		Functions\when( 'get_permalink' )->alias( static fn ( $id ) => 77 === $id ? 'https://shop.example/video/' : 'https://shop.example/videos/' );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->alias( static fn ( $key, $url ) => preg_replace( '/[?&]' . preg_quote( (string) $key, '/' ) . '=[^&]*/', '', $url ) );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->owned     = array(
			self::A => self::LIB,
			self::B => self::LIB,
		);
		$this->own_calls = array();
		$test            = $this;
		$client          = new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function get_video( string $uuid ): array {
				$this->test->own_calls[] = $uuid;
				$owned                   = $this->test->owned[ $uuid ] ?? new ApiException( 'Not found', 404 );
				if ( $owned instanceof ApiException ) {
					throw $owned;
				}
				return array( 'uuid' => $uuid, 'library_id' => $owned );
			}
		};
		$this->ownership = new Ownership( $client );
		$this->repo      = $embeds;
		$this->detail    = new DetailPage( $embeds, $this->ownership );
		$this->renderer  = new Renderer( $embeds, new Settings( new TokenCipher( 'k' ) ), $assets, $lists, $this->detail );
	}

	protected function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Embed fixture.
	 *
	 * @param string $uuid  Uuid.
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function embed( string $uuid, string $title ): array {
		return Embed::normalize(
			$uuid,
			array(
				'title'        => $title,
				'duration'     => 90,
				'resolution'   => '1920x1080',
				'poster'       => 'https://cdn.example/poster.jpg',
				'posterSrcset' => array(
					array(
						'width' => 320,
						'url'   => 'https://cdn.example/p320.jpg',
					),
				),
				'sources'      => array(
					array(
						'src'  => 'https://cdn.example/hls/master.m3u8',
						'type' => 'application/vnd.apple.mpegurl',
					),
				),
			)
		);
	}

	public function test_list_renders_cards_with_poster_title_and_duration(): void {
		$html = $this->renderer->render( 'video-list', array( 'libraryId' => self::LIB, 'perPage' => 2, 'columns' => 2, 'headline' => 'Latest' ) );

		$this->assertSame( array( array( self::LIB, '', null, 2 ) ), $this->list_calls );
		$this->assertStringContainsString( 'videooptimizer--video-list', $html );
		$this->assertStringContainsString( '<h2 class="vo-heading">Latest</h2>', $html );
		$this->assertSame( 2, substr_count( $html, 'class="vo-card"' ) );
		$this->assertStringContainsString( 'src="https://poster.example/a.jpg"', $html );
		$this->assertStringContainsString( '<span class="vo-card__duration">02:05</span>', $html );
		$this->assertStringContainsString( '<span class="vo-card__title">Demo &lt;b&gt;Film&lt;/b&gt;</span>', $html );
		$this->assertStringContainsString( '<span class="vo-card__title">Video</span>', $html, 'untitled videos get a generic title' );
		$this->assertStringContainsString( '--vo-grid-columns:2', $html );
		$this->assertStringContainsString( 'alt="" loading="lazy" decoding="async"', $html );
	}

	public function test_cards_use_the_responsive_poster_when_the_embed_is_cached(): void {
		$this->cached = array( self::A );
		$html         = $this->renderer->render( 'video-list', array() );

		$this->assertStringContainsString( 'src="https://cdn.example/poster.jpg"', $html );
		$this->assertStringContainsString( 'srcset="https://cdn.example/p320.jpg 320w"', $html );
		$this->assertStringContainsString( 'sizes="', $html );
	}

	public function test_cards_open_the_lightbox_without_a_detail_page(): void {
		$html = $this->renderer->render( 'video-list', array() );

		$this->assertStringContainsString( '<button type="button" class="vo-card__link" data-vo-player="hosted" data-vo-lightbox="https://player.videooptimizer.eu/embed/' . self::A . '?autoplay=1&#038;muted=0"', $html );
		$this->assertStringContainsString( 'aria-label="Play video: Demo &lt;b&gt;Film&lt;/b&gt;"', $html );
	}

	public function test_cards_link_to_the_detail_page_with_the_uuid(): void {
		$html = $this->renderer->render( 'video-list', array( 'detailPage' => 77 ) );

		$this->assertStringContainsString( '<a class="vo-card__link" href="https://shop.example/video/?vo_video=' . self::A . '">', $html );
		$this->assertStringNotContainsString( 'data-vo-lightbox', $html );
	}

	public function test_an_unpublished_detail_page_falls_back_to_the_lightbox(): void {
		$html = $this->renderer->render( 'video-list', array( 'detailPage' => 99 ) );

		$this->assertStringContainsString( 'data-vo-lightbox', $html );
	}

	public function test_pagination_links_use_the_cursor_argument(): void {
		$html = $this->renderer->render( 'video-list', array() );

		$this->assertStringContainsString( '<nav class="vo-pagination"', $html );
		$this->assertStringContainsString( 'rel="next" href="https://shop.example/videos/?vo_cursor=CURSOR2"', $html );
		$this->assertStringNotContainsString( 'rel="first"', $html );

		// The second list on a page has its own argument (vo_cursor1).
		$_GET['vo_cursor1'] = 'CURSOR1';
		$this->page['next'] = null;
		$html               = $this->renderer->render( 'video-list', array() );
		$this->assertSame( 'CURSOR1', $this->list_calls[1][2] );
		$this->assertStringContainsString( 'rel="first" href="https://shop.example/videos/"', $html );
		$this->assertStringNotContainsString( 'rel="next"', $html );
	}

	public function test_pagination_keeps_other_query_arguments_but_not_the_detail_video(): void {
		$_GET = array(
			'lang'       => 'de',
			'vo_cursor1' => 'OTHER',
			'vo_video'   => 'zzz',
		);
		$html = $this->renderer->render( 'video-list', array() );

		$this->assertStringContainsString( 'lang=de', $html );
		$this->assertStringContainsString( 'vo_cursor1=OTHER', $html, 'the cursor of another list survives' );
		$this->assertStringNotContainsString( 'vo_video', $html );
		$this->assertStringContainsString( 'vo_cursor=CURSOR2', $html );
	}

	public function test_an_invalid_cursor_is_ignored(): void {
		$_GET['vo_cursor'] = '"><script>';
		$this->renderer->render( 'video-list', array() );

		$this->assertNull( $this->list_calls[0][2] );
	}

	public function test_a_second_list_pages_independently(): void {
		$_GET['vo_cursor1'] = 'SECOND';
		$this->renderer->render( 'video-list', array() );
		$html = $this->renderer->render( 'video-list', array() );

		$this->assertNull( $this->list_calls[0][2] );
		$this->assertSame( 'SECOND', $this->list_calls[1][2] );
		$this->assertStringContainsString( 'rel="first" href="https://shop.example/videos/"', $html );
	}

	public function test_a_temporary_list_failure_keeps_the_page_out_of_caches(): void {
		$this->page         = null;
		$this->list_failure = 503;
		$html               = $this->renderer->render( 'video-list', array() );

		$this->assertTrue( PageCache::is_degraded() );
		$this->assertStringContainsString( 'The videos are temporarily unavailable.', $html );
	}

	public function test_a_rate_limited_list_is_temporary_and_flags_the_page(): void {
		$this->page         = null;
		$this->list_failure = 429;
		$html               = $this->renderer->render( 'video-list', array() );

		$this->assertTrue( PageCache::is_degraded(), 'never a cacheable page' );
		$this->assertStringContainsString( 'temporarily unavailable', $html );
	}

	public function test_a_missing_list_is_an_editor_hint_only(): void {
		$this->page = null;
		$this->assertSame( '', $this->renderer->render( 'video-list', array() ) );
		$this->assertFalse( PageCache::is_degraded() );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertStringContainsString( 'could not be loaded', $this->renderer->render( 'video-list', array() ) );
	}

	public function test_a_cached_processing_video_flags_the_page(): void {
		$this->embeds[ self::A ] = Embed::normalize( self::A, array( 'title' => 'Processing' ) );
		$this->cached            = array( self::A );
		$this->renderer->render( 'video-list', array() );

		$this->assertTrue( PageCache::is_degraded() );
	}

	public function test_detail_shows_the_video_of_the_url(): void {
		$this->embeds[ self::B ] = $this->embed( self::B, 'Other <i>clip</i>' );
		$_GET['vo_video']        = strtoupper( self::B );
		$html                    = $this->renderer->render( 'video-detail', array( 'uuid' => self::A ) );

		$this->assertStringContainsString( 'videooptimizer--video-detail', $html );
		$this->assertStringContainsString( '<h2 class="vo-heading vo-detail__title">Other &lt;i&gt;clip&lt;/i&gt;</h2>', $html );
		$this->assertStringContainsString( self::B, $html );
		$this->assertStringNotContainsString( self::A, $html );
		$this->assertStringContainsString( 'application/ld+json', $html );
		$this->assertStringContainsString( '<iframe', $html, 'direct hosted player by default' );
	}

	public function test_detail_falls_back_to_the_default_video(): void {
		$html = $this->renderer->render( 'video-detail', array( 'uuid' => self::A, 'player' => 'native' ) );

		$this->assertStringContainsString( 'Demo Film', $html );
		$this->assertStringContainsString( '<video', $html );
	}

	public function test_detail_with_an_invalid_or_foreign_uuid_is_a_neutral_not_found_without_any_title(): void {
		// A foreign video is public through /embed (an embed exists) but not in this organization.
		unset( $this->owned[ self::B ] );
		$this->embeds[ self::B ] = $this->embed( self::B, 'Foreign Secret Title' );

		foreach ( array( 'not-a-uuid', self::B, "' OR 1=1" ) as $value ) {
			$_GET['vo_video'] = $value;
			$html             = $this->renderer->render( 'video-detail', array( 'uuid' => self::A ) );

			$this->assertStringContainsString( 'This video could not be found.', $html );
			$this->assertStringNotContainsString( 'Demo Film', $html, 'no fallback to the default video' );
			$this->assertStringNotContainsString( 'Foreign Secret Title', $html );
			$this->assertStringNotContainsString( 'VideoOptimizer:', $html, 'no internals for visitors' );
			$this->assertStringNotContainsString( 'application/ld+json', $html );
		}
	}

	public function test_detail_requires_the_library_of_the_element(): void {
		$_GET['vo_video'] = self::A;

		$this->assertStringContainsString( 'vo-detail__title', $this->renderer->render( 'video-detail', array( 'libraryId' => self::LIB ) ) );
		$this->assertStringContainsString(
			'could not be found',
			$this->renderer->render( 'video-detail', array( 'libraryId' => '00000000-0000-4000-8000-000000000001' ) )
		);
	}

	public function test_detail_ownership_is_cached_and_negative_results_too(): void {
		$_GET['vo_video'] = self::A;
		$this->renderer->render( 'video-detail', array() );
		$this->assertSame( array( self::A ), $this->own_calls );
		$this->assertSame( Ownership::TTL_FOUND, $this->transient_ttls[ 'videooptimizer_own__' . self::A ] );

		unset( $this->owned[ self::B ] );
		$this->ownership = new Ownership( $this->ownershipClient() );
		$this->assertSame( Ownership::NOT_FOUND, $this->ownership->check( self::B ) );
		$this->assertSame( Ownership::NOT_FOUND, ( new Ownership( $this->ownershipClient() ) )->check( self::B ) );
		$this->assertSame( 1, count( array_keys( $this->own_calls, self::B, true ) ), 'the negative result is cached' );
		$this->assertSame( Ownership::TTL_NOT_FOUND, $this->transient_ttls[ 'videooptimizer_own__' . self::B ] );
	}

	/**
	 * A client wired to the fake organization (for fresh Ownership objects).
	 */
	private function ownershipClient(): Client {
		$test = $this;

		return new class( static fn () => 'tok', 'https://api.videooptimizer.eu/api/v1', $test ) extends Client {
			public function __construct( callable $t, string $b, private $test ) {
				parent::__construct( $t, $b );
			}
			public function get_video( string $uuid ): array {
				$this->test->own_calls[] = $uuid;
				$owned                   = $this->test->owned[ $uuid ] ?? new ApiException( 'Not found', 404 );
				if ( $owned instanceof ApiException ) {
					throw $owned;
				}
				return array( 'uuid' => $uuid, 'library_id' => $owned );
			}
		};
	}

	public function test_an_unavailable_api_says_so_never_not_found_and_never_trips_the_down_marker(): void {
		$_GET['vo_video'] = self::A;
		$this->owned[ self::A ] = new ApiException( 'down', 503 );
		$html                   = $this->renderer->render( 'video-detail', array() );

		$this->assertStringContainsString( 'This video is temporarily unavailable.', $html );
		$this->assertStringNotContainsString( 'could not be found', $html );
		$this->assertTrue( PageCache::is_degraded() );
		$this->assertArrayNotHasKey( EmbedRepository::DOWN_KEY, $this->transients );
	}

	public function test_new_lookups_are_limited_site_wide_without_an_api_call(): void {
		$this->transients[ LookupLimit::KEY ] = LookupLimit::MAX_PER_MINUTE;
		$_GET['vo_video']                     = self::A;
		$html                                 = $this->renderer->render( 'video-detail', array() );

		$this->assertStringContainsString( 'temporarily unavailable', $html, 'rate limited is temporary, not a 404' );
		$this->assertStringNotContainsString( 'could not be found', $html );
		$this->assertTrue( PageCache::is_degraded() );
		$this->assertArrayNotHasKey( 'videooptimizer_own__' . self::A, $this->transients, 'and never cached' );
		$this->assertSame( array(), $this->own_calls );

		$this->transients = array();
		$count            = 0;
		for ( $i = 0; $i < 40; $i++ ) {
			$uuid = sprintf( '00000000-0000-4000-8000-%012d', $i );
			( new Ownership( $this->ownershipClient() ) )->check( $uuid );
		}
		$this->assertCount( LookupLimit::MAX_PER_MINUTE, $this->own_calls, 'only 30 new uuids a minute reach the API' );
		unset( $count );
	}

	public function test_a_video_that_is_not_ready_yet_is_temporary_not_a_404(): void {
		$this->embeds[ self::A ] = Embed::normalize( self::A, array( 'title' => 'Still processing' ) );
		$_GET['vo_video']        = self::A;
		$html                    = $this->renderer->render( 'video-detail', array() );

		$this->assertStringContainsString( 'temporarily unavailable', $html );
		$this->assertStringNotContainsString( 'Still processing', $html );
		$this->assertTrue( PageCache::is_degraded() );
		$this->assertSame( 'Site', $this->detail_title_after( 'Site' ), 'and no title or 404 for the page' );
	}

	/**
	 * Document title the detail page would use.
	 *
	 * @param string $title Title so far.
	 */
	private function detail_title_after( string $title ): string {
		$this->detail_post();

		return (string) $this->detail->seo_title( $title );
	}

	public function test_ownership_results_are_scoped_by_token_and_dropped_when_the_video_changes(): void {
		$one = new Ownership( $this->ownershipClient(), static fn () => 'aaaa' );
		$two = new Ownership( $this->ownershipClient(), static fn () => 'bbbb' );

		$one->check( self::A );
		$this->assertArrayHasKey( 'videooptimizer_own_aaaa_' . self::A, $this->transients );
		$two->check( self::A );
		$this->assertCount( 2, $this->own_calls, 'another token never reads the first token\'s result' );

		$one->forget( self::A );
		$this->assertArrayNotHasKey( 'videooptimizer_own_aaaa_' . self::A, $this->transients );
		$this->assertArrayHasKey( 'videooptimizer_own_bbbb_' . self::A, $this->transients );
	}

	public function test_detail_without_any_video_is_an_editor_hint(): void {
		$this->assertSame( '', $this->renderer->render( 'video-detail', array() ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertStringContainsString( 'No video selected yet.', $this->renderer->render( 'video-detail', array() ) );
	}

	public function test_detail_flags_a_degraded_video(): void {
		$this->embeds[ self::A ] = Embed::normalize( self::A, array( 'title' => 'Processing' ) );
		$this->renderer->render( 'video-detail', array( 'uuid' => self::A ) );

		$this->assertTrue( PageCache::is_degraded() );
	}

	/**
	 * A singular page with a detail block (and the fakes the page-level hooks need).
	 *
	 * @param string $content Post content.
	 */
	private function detail_post( string $content = '<!-- wp:videooptimizer/video-detail /-->' ): \WP_Post {
		$post               = new \WP_Post();
		$post->ID           = 5;
		$post->post_content = $content;
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		return $post;
	}

	public function test_page_title_description_and_canonical_come_from_an_owned_video(): void {
		$post = $this->detail_post();
		$this->assertSame( array( 'title' => 'Site' ), $this->detail->title( array( 'title' => 'Site' ) ), 'no video in the URL' );

		$_GET['vo_video'] = self::A;
		$this->assertSame( array( 'title' => 'Demo Film' ), $this->detail->title( array( 'title' => 'Site' ) ) );
		$this->assertSame( 'https://shop.example/video/?vo_video=' . self::A, $this->detail->canonical( 'https://shop.example/video/', $post ) );

		ob_start();
		$this->detail->description();
		$this->assertSame( '<meta name="description" content="Demo Film">' . "\n", ob_get_clean() );
	}

	public function test_a_foreign_video_never_reaches_title_description_or_canonical(): void {
		$post = $this->detail_post();
		unset( $this->owned[ self::B ] );
		$this->embeds[ self::B ] = $this->embed( self::B, 'Foreign Secret Title' );
		$_GET['vo_video']        = self::B;

		$this->assertSame( array( 'title' => 'Site' ), $this->detail->title( array( 'title' => 'Site' ) ) );
		$this->assertSame( 'Site', $this->detail->seo_title( 'Site' ) );
		$this->assertSame( 'Desc', $this->detail->seo_description( 'Desc' ) );
		$this->assertSame( 'https://shop.example/video/', $this->detail->canonical( 'https://shop.example/video/', $post ) );
		ob_start();
		$this->detail->description();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_library_of_the_element_limits_the_page_hooks_too(): void {
		$this->detail_post( '<!-- wp:videooptimizer/video-detail {"libraryId":"00000000-0000-4000-8000-000000000009"} /-->' );
		$_GET['vo_video'] = self::A;

		$this->assertSame( 'Site', $this->detail->seo_title( 'Site' ), 'the video is not in the element\'s library' );

		$this->detail_post( '<!-- wp:videooptimizer/video-detail {"libraryId":"' . self::LIB . '"} /-->' );
		$this->detail = new DetailPage( $this->repo, new Ownership( $this->ownershipClient() ) );
		$this->assertSame( 'Demo Film', $this->detail->seo_title( 'Site' ) );
	}

	public function test_the_library_of_an_element_is_read_from_every_editor(): void {
		$make = function ( string $content, string $meta = '' ): \WP_Post {
			$post               = new \WP_Post();
			$post->ID           = 1;
			$post->post_content = $content;
			Functions\when( 'get_post_meta' )->justReturn( $meta );
			return $post;
		};

		$this->assertSame( self::LIB, DetailPage::library_of( $make( '<!-- wp:videooptimizer/video-detail {"libraryId":"' . self::LIB . '"} /-->' ) ) );
		$this->assertSame( self::LIB, DetailPage::library_of( $make( "[videooptimizer layout='video-detail' library='" . self::LIB . "']" ) ) );
		$this->assertSame( self::LIB, DetailPage::library_of( $make( '[videooptimizer layout=video-detail library=' . self::LIB . ']' ) ) );
		$this->assertSame(
			self::LIB,
			DetailPage::library_of( $make( '', '[{"elements":[{"widgetType":"videooptimizer","settings":{"layout":"video-detail","libraryId":"' . self::LIB . '"}}]}]' ) )
		);
		$this->assertSame( '', DetailPage::library_of( $make( '<!-- wp:videooptimizer/video-detail /-->' ) ) );
	}

	public function test_temporary_detail_pages_are_not_indexed(): void {
		$this->detail_post();
		$_GET['vo_video'] = self::A;
		$this->assertSame( array( 'max-image-preview' => 'large' ), $this->detail->core_robots( array( 'max-image-preview' => 'large' ) ), 'a shown video stays indexable' );

		// Outage: temporary.
		$this->transients       = array();
		$this->owned[ self::A ] = new ApiException( 'down', 503 );
		$this->detail           = new DetailPage( $this->repo, new Ownership( $this->ownershipClient() ) );
		$this->assertTrue( $this->detail->core_robots( array() )['noindex'] );
		$this->assertSame( 'noindex', $this->detail->seo_robots( array( 'index' => 'index', 'follow' => 'follow' ) )['index'] );
		$this->assertSame( 'follow', $this->detail->seo_robots( array( 'index' => 'index', 'follow' => 'follow' ) )['follow'] );

		// Not ready yet: temporary too.
		$this->owned[ self::A ]  = self::LIB;
		$this->embeds[ self::A ] = Embed::normalize( self::A, array( 'title' => 'Processing' ) );
		$this->transients        = array();
		$this->detail            = new DetailPage( $this->repo, new Ownership( $this->ownershipClient() ) );
		$this->assertTrue( $this->detail->core_robots( array() )['noindex'] );
	}

	public function test_seo_plugin_filters_get_the_video_values(): void {
		$this->detail_post();
		$_GET['vo_video'] = self::A;
		$added            = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$added ) {
				$added[] = $hook;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		$this->detail->register_hooks();

		foreach ( array( 'wpseo_title', 'wpseo_metadesc', 'wpseo_canonical', 'wpseo_opengraph_title', 'wpseo_opengraph_desc', 'wpseo_opengraph_url', 'rank_math/frontend/title', 'rank_math/frontend/description', 'rank_math/frontend/canonical', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/facebook/og_url', 'wp_robots', 'wpseo_robots_array', 'rank_math/frontend/robots' ) as $hook ) {
			$this->assertContains( $hook, $added, $hook );
		}
		$this->assertSame( 'Demo Film', $this->detail->seo_title( 'Plugin title' ) );
		$this->assertSame( 'Demo Film', $this->detail->seo_description( 'Plugin description' ) );
		$this->assertSame( 'https://shop.example/video/?vo_video=' . self::A, $this->detail->seo_canonical( 'https://shop.example/video/' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_second_meta_description_next_to_an_seo_plugin(): void {
		define( 'WPSEO_VERSION', '99' );
		$this->detail_post();
		$_GET['vo_video'] = self::A;

		$this->assertTrue( DetailPage::seo_plugin_active() );
		ob_start();
		$this->detail->description();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_page_detection_covers_every_shortcode_quoting(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$make = static function ( string $content ): \WP_Post {
			$post               = new \WP_Post();
			$post->ID           = 1;
			$post->post_content = $content;
			return $post;
		};

		$this->assertTrue( DetailPage::has_detail( $make( '<!-- wp:videooptimizer/video-detail {"uuid":"x"} /-->' ) ) );
		$this->assertTrue( DetailPage::has_detail( $make( '[videooptimizer layout="video-detail"]' ) ) );
		$this->assertTrue( DetailPage::has_detail( $make( "[videooptimizer layout='video-detail']" ) ) );
		$this->assertTrue( DetailPage::has_detail( $make( '[videooptimizer layout=video-detail]' ) ) );
		$this->assertFalse( DetailPage::has_detail( $make( '[videooptimizer uuid="x"]' ) ) );
	}

	public function test_a_foreign_unknown_or_invalid_video_sets_404_but_an_outage_does_not(): void {
		$this->detail_post( '[videooptimizer layout="video-detail"]' );
		$GLOBALS['wp_query'] = new class() {
			public bool $is404 = false;
			public function set_404(): void {
				$this->is404 = true;
			}
		};
		$sent = array();
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$sent ) {
				$sent[] = $code;
			}
		);
		Functions\when( 'nocache_headers' )->justReturn( null );

		$_GET['vo_video'] = self::A;
		$this->owned[ self::A ] = new ApiException( 'down', 503 );
		$this->detail->maybe_not_found();
		$this->assertSame( array(), $sent, 'an outage is not a 404' );

		unset( $this->owned[ self::B ] );
		$this->embeds[ self::B ] = $this->embed( self::B, 'Foreign' );
		$_GET['vo_video']        = self::B;
		$this->detail->maybe_not_found();
		$this->assertSame( array( 404 ), $sent, 'a foreign video with a public embed is a 404' );
		$this->assertTrue( $GLOBALS['wp_query']->is404 );

		$sent             = array();
		$_GET['vo_video'] = 'garbage';
		$this->detail->maybe_not_found();
		$this->assertSame( array( 404 ), $sent );

		$sent = array();
		$_GET = array();
		$this->detail->maybe_not_found();
		$this->assertSame( array(), $sent, 'the default video is fine' );
		unset( $GLOBALS['wp_query'] );
	}
}

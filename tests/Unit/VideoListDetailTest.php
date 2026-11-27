<?php
/**
 * Video list and video detail elements.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Cache\PageCache;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\DetailPage;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
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

	private Renderer $renderer;

	protected function set_up(): void {
		parent::set_up();
		PageCache::reset_degraded();
		$_GET = array();

		$this->embeds = array(
			self::A => $this->embed( self::A, 'Demo Film' ),
		);
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

		$this->renderer = new Renderer( $embeds, new Settings( new TokenCipher( 'k' ) ), $assets, $lists );
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
		$this->assertStringContainsString( 'loading="lazy" decoding="async" alt=""', $html );
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

	public function test_detail_with_an_invalid_or_unknown_uuid_is_a_neutral_not_found(): void {
		foreach ( array( 'not-a-uuid', self::B, "' OR 1=1" ) as $value ) {
			$_GET['vo_video'] = $value;
			$html             = $this->renderer->render( 'video-detail', array( 'uuid' => self::A ) );

			$this->assertStringContainsString( 'This video could not be found.', $html );
			$this->assertStringNotContainsString( 'Demo Film', $html, 'no fallback to the default video' );
			$this->assertStringNotContainsString( 'VideoOptimizer:', $html, 'no internals for visitors' );
		}
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

	public function test_detail_page_title_comes_from_the_video(): void {
		$post               = new \WP_Post();
		$post->ID           = 5;
		$post->post_content = '<!-- wp:videooptimizer/video-detail /-->';
		Functions\when( 'get_queried_object' )->justReturn( $post );
		$embeds = $this->createMock( EmbedRepository::class );
		$embeds->method( 'get' )->willReturnCallback( fn ( string $uuid ) => $this->embeds[ $uuid ] ?? null );
		$detail = new DetailPage( $embeds );

		$this->assertSame( array( 'title' => 'Site' ), $detail->title( array( 'title' => 'Site' ) ), 'no video in the URL' );

		$_GET['vo_video'] = self::A;
		$this->assertSame( array( 'title' => 'Demo Film' ), $detail->title( array( 'title' => 'Site' ) ) );
		$this->assertSame( 'https://shop.example/video/?vo_video=' . self::A, $detail->canonical( 'https://shop.example/video/', $post ) );

		ob_start();
		$detail->description();
		$this->assertSame( '<meta name="description" content="Demo Film">' . "\n", ob_get_clean() );

		$_GET['vo_video'] = self::B;
		$this->assertSame( array( 'title' => 'Site' ), $detail->title( array( 'title' => 'Site' ) ) );
	}

	public function test_detail_page_detection(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$make = static function ( string $content ): \WP_Post {
			$post               = new \WP_Post();
			$post->ID           = 1;
			$post->post_content = $content;
			return $post;
		};

		$this->assertTrue( DetailPage::has_detail( $make( '<!-- wp:videooptimizer/video-detail {"uuid":"x"} /-->' ) ) );
		$this->assertTrue( DetailPage::has_detail( $make( '[videooptimizer layout="video-detail"]' ) ) );
		$this->assertFalse( DetailPage::has_detail( $make( '[videooptimizer uuid="x"]' ) ) );
	}

	public function test_not_found_for_an_unknown_video_sets_404(): void {
		$post               = new \WP_Post();
		$post->ID           = 5;
		$post->post_content = '[videooptimizer layout="video-detail"]';
		Functions\when( 'get_queried_object' )->justReturn( $post );
		$embeds = $this->createMock( EmbedRepository::class );
		$embeds->method( 'get' )->willReturn( null );
		$embeds->method( 'failure' )->willReturnCallback( fn () => $this->failure );
		$GLOBALS['wp_query'] = new class() {
			public bool $is404 = false;
			public function set_404(): void {
				$this->is404 = true;
			}
		};
		$sent = array();
		Functions\when( 'status_header' )->alias( static function ( $code ) use ( &$sent ) {
			$sent[] = $code;
		} );
		Functions\when( 'nocache_headers' )->justReturn( null );
		$detail = new DetailPage( $embeds );

		$_GET['vo_video'] = self::B;
		$this->failure    = array( 'status' => 503, 'outage' => true );
		$detail->maybe_not_found();
		$this->assertSame( array(), $sent, 'an outage is not a 404' );

		$this->failure = array( 'status' => 404, 'outage' => false );
		$detail->maybe_not_found();
		$this->assertSame( array( 404 ), $sent );
		$this->assertTrue( $GLOBALS['wp_query']->is404 );

		$sent             = array();
		$_GET['vo_video'] = 'garbage';
		$detail->maybe_not_found();
		$this->assertSame( array( 404 ), $sent, 'an invalid uuid is a 404' );

		$sent             = array();
		$_GET             = array();
		$detail->maybe_not_found();
		$this->assertSame( array(), $sent, 'the default video is fine' );
		unset( $GLOBALS['wp_query'] );
	}
}

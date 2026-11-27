<?php
/**
 * Renderer output.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\Renderer;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Settings\TokenCipher;

final class RendererTest extends TestCase {

	private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

	/**
	 * Embed payload returned by the fake repository (null = missing video).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $embed;

	private Renderer $renderer;

	protected function set_up(): void {
		parent::set_up();

		$this->embed = Embed::normalize(
			self::UUID,
			array(
				'title'      => 'Demo <b>Film</b>',
				'duration'   => 90,
				'resolution' => '1080x1920',
				'poster'     => 'https://cdn.example/poster.jpg',
				'theme'      => array(
					'accentColor' => '#C72876',
					'controls'    => true,
				),
				'sources'    => array(
					array(
						'src'  => 'https://cdn.example/hls/master.m3u8',
						'type' => 'application/vnd.apple.mpegurl',
					),
					array(
						'src'   => 'https://cdn.example/720p.mp4',
						'type'  => 'video/mp4',
						'label' => '720p',
					),
				),
			)
		);

		$embeds = $this->createMock( EmbedRepository::class );
		$embeds->method( 'get' )->willReturnCallback( fn () => $this->embed );
		$assets = $this->createMock( Assets::class );
		$assets->expects( $this->any() )->method( 'enqueue' );

		$this->renderer = new Renderer( $embeds, new Settings( new TokenCipher( 'k' ) ), $assets );
	}

	public function test_facade_with_hosted_player(): void {
		$html = $this->renderer->render( 'video', array( 'uuid' => self::UUID ) );

		$this->assertStringContainsString( 'class="vo-blocks videooptimizer videooptimizer--video"', $html );
		$this->assertStringContainsString( 'data-vo-embed="https://player.videooptimizer.eu/embed/' . self::UUID . '?autoplay=1&#038;muted=0"', $html );
		$this->assertStringContainsString( 'aspect-ratio:1080 / 1920', $html );
		$this->assertStringContainsString( 'vo-frame--portrait', $html );
		$this->assertStringContainsString( '--vo-player-accent:#C72876', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<b>', $html, 'title must be escaped' );
	}

	public function test_json_ld_is_printed_once_and_escaped(): void {
		$html = $this->renderer->render( 'video', array( 'uuid' => self::UUID ) ) . $this->renderer->render( 'video', array( 'uuid' => self::UUID ) );

		$this->assertSame( 1, substr_count( $html, 'application/ld+json' ) );
		$this->assertStringContainsString( '"duration":"PT1M30S"', $html );
		$this->assertStringContainsString( '\\u003Cb\\u003E', $html );
	}

	public function test_native_lightbox_prerenders_hidden_video(): void {
		$html = $this->renderer->render(
			'video',
			array(
				'uuid'         => self::UUID,
				'player'       => 'native',
				'presentation' => 'lightbox',
			)
		);

		$this->assertStringContainsString( 'data-vo-lightbox=', $html );
		$this->assertStringContainsString( '<div class="vo-native-holder" hidden><video class="vo-native" playsinline preload="none" controls', $html );
		$this->assertStringContainsString( 'data-hls="https://cdn.example/hls/master.m3u8"', $html );
		$this->assertStringContainsString( '<source src="https://cdn.example/720p.mp4" type="video/mp4">', $html );
	}

	public function test_direct_hosted_is_lazy_injected_unless_priority(): void {
		$lazy     = $this->renderer->render(
			'video',
			array(
				'uuid'         => self::UUID,
				'presentation' => 'direct',
				'controls'     => '0',
			)
		);
		$priority = $this->renderer->render(
			'video',
			array(
				'uuid'         => self::UUID,
				'presentation' => 'direct',
				'priority'     => true,
			)
		);

		$this->assertStringContainsString( 'data-vo-autoload="https://player.videooptimizer.eu/embed/' . self::UUID . '?controls=0"', $lazy );
		$this->assertStringContainsString( '<noscript><iframe', $lazy );
		$this->assertMatchesRegularExpression( '~^<div[^>]*><figure class="vo-video"><div[^>]*><iframe src="https://player.videooptimizer.eu/embed/' . self::UUID . '" [^>]*loading="eager"~', $priority );
		$this->assertStringContainsString( 'fetchpriority', $this->renderer->render( 'spotlight', array( 'uuid' => self::UUID, 'priority' => true ) ) );
	}

	public function test_native_direct_autoplay_is_muted(): void {
		$html = $this->renderer->render(
			'video',
			array(
				'uuid'         => self::UUID,
				'player'       => 'native',
				'presentation' => 'direct',
				'autoplay'     => '1',
				'priority'     => true,
			)
		);

		$this->assertStringContainsString( 'preload="auto"', $html );
		$this->assertStringContainsString( ' autoplay muted', $html );
	}

	/**
	 * Renders a direct native video with the given theme keys merged into the embed theme.
	 *
	 * @param array<string, mixed> $theme Theme keys.
	 * @param array<string, mixed> $extra Extra attributes.
	 */
	private function native_with_theme( array $theme, array $extra = array() ): string {
		$this->embed['theme'] = array_merge( $this->embed['theme'], $theme );

		return $this->renderer->render(
			'video',
			array_merge(
				array(
					'uuid'         => self::UUID,
					'player'       => 'native',
					'presentation' => 'direct',
					'priority'     => true,
				),
				$extra
			)
		);
	}

	public function test_theme_preload_applies_to_above_the_fold_video(): void {
		$this->assertStringContainsString( 'preload="metadata"', $this->native_with_theme( array( 'preload' => 'metadata' ) ) );
		$this->assertStringContainsString( 'preload="none"', $this->native_with_theme( array( 'preload' => 'none' ) ) );
		$this->assertStringContainsString( 'preload="auto"', $this->native_with_theme( array( 'preload' => 'bogus' ) ) );
	}

	public function test_theme_preload_never_wakes_a_deferred_video(): void {
		$html = $this->native_with_theme( array( 'preload' => 'auto' ), array( 'priority' => false ) );

		$this->assertStringContainsString( 'preload="none"', $html );
	}

	public function test_theme_interactive_false_makes_an_autoplaying_video_static(): void {
		$html = $this->native_with_theme(
			array(
				'interactive' => false,
				'autoplay'    => true,
			)
		);

		$this->assertStringContainsString( 'class="vo-native vo-native--static"', $html );
	}

	public function test_theme_interactive_false_without_autoplay_stays_usable(): void {
		$html = $this->native_with_theme( array( 'interactive' => false ) );

		$this->assertStringNotContainsString( 'vo-native--static', $html );
	}

	public function test_theme_buttons_map_to_native_controls(): void {
		$html = $this->native_with_theme(
			array(
				'fullscreenButton' => false,
				'playbackSpeed'    => false,
				'pictureInPicture' => false,
			)
		);

		$this->assertStringContainsString( 'controlslist="nofullscreen noplaybackrate"', $html );
		$this->assertStringContainsString( ' disablepictureinpicture', $html );
	}

	public function test_default_theme_adds_no_control_hints(): void {
		$html = $this->native_with_theme( array() );

		$this->assertStringNotContainsString( 'controlslist', $html );
		$this->assertStringNotContainsString( 'disablepictureinpicture', $html );
	}

	public function test_hero_validates_colors_and_cta(): void {
		$html = $this->renderer->render(
			'background-hero',
			array(
				'uuid'          => self::UUID,
				'headline'      => 'Hello',
				'headlineColor' => '#ff0000;background:url(x)',
				'textColor'     => '#00ff00',
				'ctaLabel'      => 'Go',
				'ctaUrl'        => 'javascript:alert(1)',
			)
		);

		$this->assertStringContainsString( 'style="--vo-hero-text:#00ff00"', $html );
		$this->assertStringNotContainsString( 'background:url', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'vo-btn', $html );
		$this->assertStringContainsString( 'class="vo-bg-hero__video" muted loop playsinline', $html );
		$this->assertStringContainsString( 'data-mp4="https://cdn.example/720p.mp4"', $html );
	}

	public function test_inner_content_replaces_text_stack(): void {
		$html = $this->renderer->render(
			'media-split',
			array(
				'uuid'     => self::UUID,
				'headline' => 'Ignored',
				'side'     => 'right',
			),
			'<h2>From blocks</h2>'
		);

		$this->assertStringContainsString( 'vo-media-split--right', $html );
		$this->assertStringContainsString( '<h2>From blocks</h2>', $html );
		$this->assertStringNotContainsString( 'Ignored</h2>', $html );
	}

	public function test_grid_renders_each_item(): void {
		$html = $this->renderer->render(
			'video-grid',
			array(
				'items'   => array(
					array(
						'uuid'  => self::UUID,
						'label' => 'One',
					),
					array(
						'uuid'  => self::UUID,
						'label' => 'Two',
					),
				),
				'columns' => 2,
			)
		);

		$this->assertSame( 2, substr_count( $html, 'class="vo-grid__item"' ) );
		$this->assertSame( 2, substr_count( $html, 'data-vo-lightbox=' ) );
		$this->assertStringContainsString( '--vo-grid-columns:2', $html );
	}

	public function test_wrapper_attributes_are_merged(): void {
		$html = $this->renderer->render( 'video', array( 'uuid' => self::UUID ), '', 'class="wp-block-videooptimizer-video alignwide" id="x"' );

		$this->assertStringStartsWith( '<div class="wp-block-videooptimizer-video alignwide vo-blocks videooptimizer videooptimizer--video" id="x">', $html );
	}

	public function test_missing_video_renders_nothing_for_visitors_and_notice_for_editors(): void {
		$this->embed = null;

		$this->assertSame( '', $this->renderer->render( 'video', array( 'uuid' => self::UUID ) ) );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertStringContainsString( 'vo-notice', $this->renderer->render( 'video', array( 'uuid' => self::UUID ) ) );
	}

	public function test_unknown_layout(): void {
		$this->assertSame( '', $this->renderer->render( 'carousel', array( 'uuid' => self::UUID ) ) );
	}
}

<?php
/**
 * Frontend renderer.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders every layout (video, media-split, background-hero, spotlight, video-grid) plus the
 * shared video surface. Blocks, shortcode, Elementor, the core/video replacement and WooCommerce
 * all go through here, so the markup and behaviour are identical everywhere.
 *
 * Players:
 *  - hosted: the VideoOptimizer iframe player (/embed/{uuid}) – themable in the VideoOptimizer app.
 *  - native: a <video> in the page, HLS via Safari or hls.js (lazy-loaded), MP4 fallback.
 * Presentations:
 *  - facade:   poster button, swapped for the player in place on click.
 *  - lightbox: poster button opening a modal player.
 *  - direct:   the player itself; lazily injected once scrolled into view unless "priority".
 */
class Renderer {

	/**
	 * Embed lookups.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Asset loader.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * UUIDs whose JSON-LD was already printed on this page.
	 *
	 * @var array<string, true>
	 */
	private array $schema_printed = array();

	/**
	 * Constructor.
	 *
	 * @param EmbedRepository $embeds   Embed lookups.
	 * @param Settings        $settings Settings.
	 * @param Assets          $assets   Asset loader.
	 */
	public function __construct( EmbedRepository $embeds, Settings $settings, Assets $assets ) {
		$this->embeds   = $embeds;
		$this->settings = $settings;
		$this->assets   = $assets;
	}

	/**
	 * Renders a layout.
	 *
	 * @param string               $layout        One of Attributes::LAYOUTS.
	 * @param array<string, mixed> $raw           Raw attributes (see Attributes::schema()).
	 * @param string               $content       Pre-rendered inner content (InnerBlocks / enclosed shortcode), trusted HTML.
	 * @param string               $wrapper_attrs Extra wrapper attributes (e.g. get_block_wrapper_attributes()), already escaped.
	 */
	public function render( string $layout, array $raw, string $content = '', string $wrapper_attrs = '' ): string {
		if ( ! in_array( $layout, Attributes::LAYOUTS, true ) ) {
			return '';
		}
		$a = Attributes::normalize( $raw );
		if ( '' === $a['presentation'] ) {
			$a['presentation'] = '' !== Attributes::DEFAULT_PRESENTATION[ $layout ] ? Attributes::DEFAULT_PRESENTATION[ $layout ] : $this->settings->string( 'default_presentation' );
		}

		$this->assets->enqueue();

		$html = match ( $layout ) {
			'video'           => $this->layout_video( $a ),
			'media-split'     => $this->layout_media_split( $a, $content ),
			'background-hero' => $this->layout_hero( $a, $content ),
			'spotlight'       => $this->layout_spotlight( $a ),
			'video-grid'      => $this->layout_grid( $a ),
		};
		if ( '' === $html ) {
			return '';
		}

		$classes = 'vo-blocks videooptimizer videooptimizer--' . $layout;
		if ( '' !== $wrapper_attrs ) {
			// Merge our classes into the block wrapper's class attribute.
			if ( preg_match( '/\bclass="([^"]*)"/', $wrapper_attrs ) ) {
				$wrapper_attrs = (string) preg_replace( '/\bclass="([^"]*)"/', 'class="$1 ' . esc_attr( $classes ) . '"', $wrapper_attrs, 1 );
			} else {
				$wrapper_attrs .= ' class="' . esc_attr( $classes ) . '"';
			}

			return '<div ' . $wrapper_attrs . '>' . $html . '</div>';
		}

		return '<div class="' . esc_attr( $classes ) . '">' . $html . '</div>';
	}

	/**
	 * Just the video surface for one uuid (used by the core/video replacement, Woo gallery/tab).
	 *
	 * @param string               $uuid    Video uuid.
	 * @param array<string, mixed> $options presentation, player, autoplay, muted, loop, controls, priority, label, sizes.
	 */
	public function surface_for( string $uuid, array $options = array() ): string {
		$a = Attributes::normalize( array_merge( $options, array( 'uuid' => $uuid ) ) );
		if ( '' === $a['presentation'] ) {
			$a['presentation'] = $this->settings->string( 'default_presentation' );
		}
		$this->assets->enqueue();

		$embed = $this->embeds->get( $a['uuid'] );
		if ( null === $embed ) {
			return $this->missing_notice( $a['uuid'] );
		}

		$label = is_string( $options['label'] ?? null ) ? $options['label'] : $a['title'];
		$sizes = is_string( $options['sizes'] ?? null ) ? $options['sizes'] : '100vw';

		return $this->surface( $embed, $a, $label, $sizes, (bool) $a['priority'] );
	}

	/* ---------------------------------------------------------------- Layouts */

	/**
	 * Single video.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	private function layout_video( array $a ): string {
		$embed = $this->embeds->get( $a['uuid'] );
		if ( null === $embed ) {
			return $this->missing_notice( $a['uuid'] );
		}

		$html = $this->surface( $embed, $a, $a['title'], '(min-width: 1200px) 1200px, 100vw', $a['priority'] );
		if ( '' !== $a['caption'] ) {
			$html .= '<figcaption class="vo-caption">' . esc_html( $a['caption'] ) . '</figcaption>';
		}

		return '<figure class="vo-video">' . $html . '</figure>';
	}

	/**
	 * Video next to text.
	 *
	 * @param array<string, mixed> $a       Attributes.
	 * @param string               $content Inner content.
	 */
	private function layout_media_split( array $a, string $content ): string {
		$embed = $this->embeds->get( $a['uuid'] );
		$media = null === $embed ? $this->missing_notice( $a['uuid'] ) : $this->surface( $embed, $a, $a['headline'], '(min-width: 820px) 45vw, 100vw', $a['priority'] );

		$body = '' !== trim( $content ) ? $content : $this->text_stack( $a, false );

		return sprintf(
			'<section class="vo-block vo-media-split vo-media-split--%1$s%2$s"><div class="vo-container vo-media-split__grid"><div class="vo-media-split__media">%3$s</div><div class="vo-media-split__body">%4$s</div></div></section>',
			esc_attr( $a['side'] ),
			$a['priority'] ? '' : ' vo-reveal',
			$media,
			$body
		);
	}

	/**
	 * Full-bleed looping background video with overlay text.
	 *
	 * @param array<string, mixed> $a       Attributes.
	 * @param string               $content Inner content.
	 */
	private function layout_hero( array $a, string $content ): string {
		$embed = $this->embeds->get( $a['uuid'] );

		$styles = array();
		if ( '' !== $a['headlineColor'] ) {
			$styles[] = '--vo-hero-heading:' . $a['headlineColor'];
		}
		if ( '' !== $a['textColor'] ) {
			$styles[] = '--vo-hero-text:' . $a['textColor'];
		}

		$video = '';
		if ( null !== $embed ) {
			$video = $this->background_video( $embed, $a['priority'] ) . $this->schema( $embed, $a['headline'] );
		} elseif ( '' !== $a['uuid'] ) {
			$video = $this->missing_notice( $a['uuid'] );
		}

		$body = '' !== trim( $content ) ? $content : $this->text_stack( $a, true );

		return sprintf(
			'<section class="vo-block vo-bg-hero vo-bg-hero--%1$s vo-bg-hero--overlay-%2$s"%3$s>%4$s<div class="vo-bg-hero__overlay"></div><div class="vo-container vo-bg-hero__content">%5$s</div></section>',
			esc_attr( $a['height'] ),
			esc_attr( $a['overlay'] ),
			array() !== $styles ? ' style="' . esc_attr( implode( ';', $styles ) ) . '"' : '',
			$video,
			$body
		);
	}

	/**
	 * Centered headline + large video + caption.
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	private function layout_spotlight( array $a ): string {
		$embed = $this->embeds->get( $a['uuid'] );
		$media = null === $embed ? $this->missing_notice( $a['uuid'] ) : $this->surface( $embed, $a, $a['headline'], '(min-width: 1000px) 960px, 100vw', $a['priority'] );

		$html  = '<section class="vo-block vo-spotlight' . ( $a['priority'] ? '' : ' vo-reveal' ) . '"><div class="vo-container vo-spotlight__inner">';
		$html .= '' !== $a['eyebrow'] ? '<span class="vo-eyebrow">' . esc_html( $a['eyebrow'] ) . '</span>' : '';
		$html .= '' !== $a['headline'] ? '<h2 class="vo-heading">' . esc_html( $a['headline'] ) . '</h2>' : '';
		$html .= $media;
		$html .= '' !== $a['caption'] ? '<p class="vo-spotlight__caption">' . esc_html( $a['caption'] ) . '</p>' : '';

		return $html . '</div></section>';
	}

	/**
	 * Grid of videos (lightbox by default).
	 *
	 * @param array<string, mixed> $a Attributes.
	 */
	private function layout_grid( array $a ): string {
		$tiles = '';
		foreach ( $a['items'] as $index => $item ) {
			$embed = $this->embeds->get( $item['uuid'] );
			if ( null === $embed ) {
				$tiles .= $this->missing_notice( $item['uuid'] );
				continue;
			}
			$surface = $this->surface( $embed, $a, $item['label'], '(min-width: 900px) 33vw, (min-width: 560px) 50vw, 100vw', $a['priority'] && 0 === $index );
			$tiles  .= '<figure class="vo-grid__item">' . $surface . ( '' !== $item['label'] ? '<figcaption>' . esc_html( $item['label'] ) . '</figcaption>' : '' ) . '</figure>';
		}
		if ( '' === $tiles && '' === $a['headline'] ) {
			return '';
		}

		$html  = '<section class="vo-block vo-grid' . ( $a['priority'] ? '' : ' vo-reveal' ) . '"><div class="vo-container">';
		$html .= '' !== $a['headline'] ? '<h2 class="vo-heading">' . esc_html( $a['headline'] ) . '</h2>' : '';
		$html .= '' !== $a['intro'] ? '<p class="vo-grid__intro">' . esc_html( $a['intro'] ) . '</p>' : '';
		$html .= '<div class="vo-grid__items" style="--vo-grid-columns:' . (int) $a['columns'] . '">' . $tiles . '</div>';

		return $html . '</div></section>';
	}

	/**
	 * Eyebrow, headline, subline/text and CTA from attributes (when no inner content is given).
	 *
	 * @param array<string, mixed> $a    Attributes.
	 * @param bool                 $hero Hero variant (light eyebrow, subline).
	 */
	private function text_stack( array $a, bool $hero ): string {
		$html  = '' !== $a['eyebrow'] ? '<span class="vo-eyebrow' . ( $hero ? ' vo-eyebrow--light' : '' ) . '">' . esc_html( $a['eyebrow'] ) . '</span>' : '';
		$html .= '' !== $a['headline'] ? '<h2 class="vo-heading">' . esc_html( $a['headline'] ) . '</h2>' : '';
		if ( $hero && '' !== $a['subline'] ) {
			$html .= '<p class="vo-bg-hero__subline">' . esc_html( $a['subline'] ) . '</p>';
		}
		if ( '' !== $a['text'] ) {
			$html .= '<div class="vo-prose">' . wpautop( $a['text'] ) . '</div>';
		}
		if ( '' !== $a['ctaLabel'] && '' !== $a['ctaUrl'] ) {
			$html .= '<a class="vo-btn" href="' . esc_url( $a['ctaUrl'] ) . '">' . esc_html( $a['ctaLabel'] ) . '</a>';
		}

		return $html;
	}

	/* ---------------------------------------------------------------- Surface */

	/**
	 * The shared video surface: poster/facade, lightbox trigger or player.
	 *
	 * @param array<string, mixed> $embed    Normalized embed.
	 * @param array<string, mixed> $a        Attributes (player, presentation, autoplay, …).
	 * @param string               $label    Accessible label / schema name.
	 * @param string               $sizes    <img sizes>.
	 * @param bool                 $priority Above the fold: eager, no lazy injection.
	 */
	private function surface( array $embed, array $a, string $label, string $sizes, bool $priority ): string {
		$player       = in_array( $a['player'], Settings::PLAYERS, true ) ? $a['player'] : $this->settings->string( 'default_player' );
		$player       = in_array( $player, Settings::PLAYERS, true ) ? $player : 'hosted';
		$presentation = $a['presentation'];
		$click        = 'direct' !== $presentation;
		$label        = '' !== $label ? $label : ( '' !== $embed['title'] ? $embed['title'] : __( 'Video', 'videooptimizer' ) );
		$url          = $this->embed_url( $embed['uuid'], $a, $click );

		$frame_attrs = array(
			'class'          => 'vo-frame' . ( null !== $embed['orientation'] ? ' vo-frame--' . $embed['orientation'] : '' ),
			'data-vo-player' => $player,
		);
		$styles      = array();
		if ( null !== $embed['width'] && null !== $embed['height'] ) {
			$styles[] = 'aspect-ratio:' . $embed['width'] . ' / ' . $embed['height'];
		}
		$accent = Attributes::safe_color( $embed['theme']['accentColor'] ?? null );
		if ( '' !== $accent ) {
			$styles[] = '--vo-player-accent:' . $accent;
		}
		if ( array() !== $styles ) {
			$frame_attrs['style'] = implode( ';', $styles );
		}

		$poster = $this->poster_img( $embed, $sizes, $priority );

		if ( ! $click ) {
			if ( 'native' === $player ) {
				$inner = $this->native_video( $embed, $a, $priority, ! $priority );
			} elseif ( $priority ) {
				$inner = $this->iframe( $url, $label, true );
			} else {
				// Injected by JS once scrolled into view; noscript keeps it working without JS.
				$frame_attrs['data-vo-autoload'] = $url;
				$inner                           = $poster . '<noscript>' . $this->iframe( $url, $label, false ) . '</noscript>';
			}
		} else {
			$trigger_attr = 'lightbox' === $presentation ? 'data-vo-lightbox' : 'data-vo-embed';
			$inner        = sprintf(
				'<button type="button" class="vo-facade" data-vo-player="%1$s" %2$s="%3$s" aria-label="%4$s">%5$s%6$s</button>',
				esc_attr( $player ),
				$trigger_attr,
				esc_url( $url ),
				/* translators: %s: video title */
				esc_attr( sprintf( __( 'Play video: %s', 'videooptimizer' ), $label ) ),
				$poster,
				self::play_icon()
			);
			if ( 'native' === $player ) {
				$inner .= '<div class="vo-native-holder" hidden>' . $this->native_video( $embed, $a, false, false ) . '</div>';
			}
		}

		return '<div' . self::attrs( $frame_attrs ) . '>' . $inner . '</div>' . $this->schema( $embed, $label );
	}

	/**
	 * Hosted player URL with only the explicitly set player params (unset ones follow the player
	 * theme configured in VideoOptimizer). Click-to-play surfaces autoplay with sound.
	 *
	 * @param string               $uuid  Video uuid.
	 * @param array<string, mixed> $a     Attributes.
	 * @param bool                 $click Opened by a click (facade/lightbox).
	 */
	public function embed_url( string $uuid, array $a, bool $click ): string {
		$query = array();
		foreach ( array( 'autoplay', 'controls', 'loop', 'muted' ) as $param ) {
			$value = (string) ( $a[ $param ] ?? '' );
			if ( '0' === $value || '1' === $value ) {
				$query[ $param ] = $value;
			}
		}
		if ( $click ) {
			// The visitor clicked play: start immediately, with sound unless explicitly muted.
			if ( '0' !== ( $query['autoplay'] ?? '' ) ) {
				$query['autoplay'] = '1';
			}
			if ( ! isset( $query['muted'] ) ) {
				$query['muted'] = '0';
			}
		}

		$url = Settings::embed_base_url() . '/embed/' . rawurlencode( $uuid );

		return array() === $query ? $url : $url . '?' . http_build_query( $query );
	}

	/**
	 * Hosted player iframe.
	 *
	 * @param string $url   Embed URL.
	 * @param string $title Accessible title.
	 * @param bool   $eager Load eagerly.
	 */
	private function iframe( string $url, string $title, bool $eager ): string {
		return sprintf(
			'<iframe src="%1$s" title="%2$s" loading="%3$s" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>',
			esc_url( $url ),
			esc_attr( $title ),
			$eager ? 'eager' : 'lazy'
		);
	}

	/**
	 * Native <video> built from the embed sources (HLS master + MP4/WebM fallbacks). Deferred
	 * (preload=none, no autoplay) unless $eager, so it fetches nothing until played.
	 *
	 * @param array<string, mixed> $embed    Normalized embed.
	 * @param array<string, mixed> $a        Attributes.
	 * @param bool                 $eager    Above the fold: preload + autoplay when requested.
	 * @param bool                 $autoload Play once scrolled into view (direct, non-priority).
	 */
	private function native_video( array $embed, array $a, bool $eager, bool $autoload ): string {
		$theme    = $embed['theme'];
		$autoplay = self::resolve( $a['autoplay'], ! empty( $theme['autoplay'] ) );
		$muted    = self::resolve( $a['muted'], ! empty( $theme['mutedStart'] ) );
		$loop     = self::resolve( $a['loop'], ! empty( $theme['loopButton'] ) );
		$controls = self::resolve( $a['controls'], ! isset( $theme['controls'] ) || (bool) $theme['controls'] );

		$attrs = array(
			'class'       => 'vo-native',
			'playsinline' => true,
			'preload'     => $eager ? 'auto' : 'none',
			'controls'    => $controls,
			'loop'        => $loop,
			'poster'      => $embed['poster'],
			'data-hls'    => $embed['hls'],
		);
		if ( $eager && $autoplay ) {
			// Browsers only allow autoplay when muted.
			$attrs['autoplay'] = true;
			$attrs['muted']    = true;
		} elseif ( $muted || ( $autoload && $autoplay ) ) {
			$attrs['muted'] = true;
		}
		if ( $autoload && $autoplay ) {
			$attrs['data-vo-native-autoload'] = true;
		}
		if ( ! $controls ) {
			// Without controls the video must still be startable by tapping it.
			$attrs['data-vo-tap'] = true;
		}

		$sources = '';
		foreach ( $embed['files'] as $file ) {
			$sources .= '<source src="' . esc_url( $file['src'] ) . '" type="' . esc_attr( $file['type'] ) . '">';
		}

		return '<video' . self::attrs( $attrs ) . '>' . $sources . '</video>';
	}

	/**
	 * Muted looping background <video> for the hero. Reduced-motion users only see the poster.
	 *
	 * @param array<string, mixed> $embed    Normalized embed.
	 * @param bool                 $priority Above the fold.
	 */
	private function background_video( array $embed, bool $priority ): string {
		$fallback = null;
		foreach ( $embed['files'] as $file ) {
			// Largest MP4 up to 1080p for browsers without HLS/MSE.
			if ( 'video/mp4' === $file['type'] && ( null === $fallback || ! preg_match( '/(1440|2160)p/', $file['label'] ) ) ) {
				$fallback = $file['src'];
			}
		}

		return '<video' . self::attrs(
			array(
				'class'       => 'vo-bg-hero__video',
				'muted'       => true,
				'loop'        => true,
				'playsinline' => true,
				'aria-hidden' => 'true',
				'preload'     => $priority ? 'auto' : 'metadata',
				'poster'      => $embed['poster'],
				'data-hls'    => $embed['hls'],
				'data-mp4'    => $fallback,
			)
		) . '></video>';
	}

	/**
	 * Responsive poster <img>.
	 *
	 * @param array<string, mixed> $embed    Normalized embed.
	 * @param string               $sizes    Sizes attribute.
	 * @param bool                 $priority Eager + high fetch priority.
	 */
	private function poster_img( array $embed, string $sizes, bool $priority ): string {
		if ( null === $embed['poster'] ) {
			return '';
		}

		return '<img' . self::attrs(
			array(
				'class'         => 'vo-poster__img',
				'src'           => $embed['poster'],
				'srcset'        => $embed['srcset'],
				'sizes'         => null !== $embed['srcset'] ? $sizes : null,
				'width'         => $embed['width'],
				'height'        => $embed['height'],
				'alt'           => '',
				'loading'       => $priority ? 'eager' : 'lazy',
				'decoding'      => 'async',
				'fetchpriority' => $priority ? 'high' : null,
			)
		) . '>';
	}

	/**
	 * Schema.org VideoObject JSON-LD, once per video per page.
	 *
	 * @param array<string, mixed> $embed Normalized embed.
	 * @param string               $name  Preferred name.
	 */
	private function schema( array $embed, string $name ): string {
		if ( ! $this->settings->flag( 'schema' ) || isset( $this->schema_printed[ $embed['uuid'] ] ) || null === $embed['poster'] ) {
			return '';
		}
		$this->schema_printed[ $embed['uuid'] ] = true;

		$name      = '' !== $name ? $name : $embed['title'];
		$data      = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => '' !== $name ? $name : __( 'Video', 'videooptimizer' ),
			'description'  => '' !== $embed['title'] ? $embed['title'] : $name,
			'thumbnailUrl' => $embed['poster'],
			'embedUrl'     => Settings::embed_base_url() . '/embed/' . rawurlencode( $embed['uuid'] ),
		);
		$post_date = get_post_time( 'c', true );
		if ( is_string( $post_date ) ) {
			$data['uploadDate'] = $post_date;
		}
		$duration = Embed::iso_duration( $embed['duration'] );
		if ( null !== $duration ) {
			$data['duration'] = $duration;
		}
		$largest = end( $embed['files'] );
		if ( is_array( $largest ) ) {
			$data['contentUrl'] = $largest['src'];
		}

		/**
		 * Filters the schema.org VideoObject printed for a video (return an empty array to skip).
		 *
		 * @param array<string, mixed> $data  JSON-LD data.
		 * @param array<string, mixed> $embed Normalized embed payload.
		 */
		$data = (array) apply_filters( 'videooptimizer_schema', $data, $embed );
		if ( array() === $data ) {
			return '';
		}

		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * Visible only to editors: explains why a video is not shown. Visitors get nothing.
	 *
	 * @param string $uuid Video uuid ('' when none was chosen).
	 */
	private function missing_notice( string $uuid ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$message = '' === $uuid
			? __( 'No video selected yet.', 'videooptimizer' )
			/* translators: %s: video uuid */
			: sprintf( __( 'The video %s could not be loaded (deleted, or VideoOptimizer is unreachable). Only editors see this notice.', 'videooptimizer' ), $uuid );

		return '<div class="vo-notice" role="note"><strong>VideoOptimizer:</strong> ' . esc_html( $message ) . '</div>';
	}

	/**
	 * Tri-state ('' | '1' | '0') to bool with a fallback for ''.
	 *
	 * @param mixed $value    Tri-state value.
	 * @param bool  $fallback Value for ''.
	 */
	private static function resolve( mixed $value, bool $fallback ): bool {
		return '1' === $value ? true : ( '0' === $value ? false : $fallback );
	}

	/**
	 * Builds an escaped attribute string; true renders a boolean attribute, null/false/'' skip it.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 */
	public static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( null === $value || false === $value || '' === $value ) {
				continue;
			}
			if ( true === $value ) {
				$out .= ' ' . $name;
				continue;
			}
			$value = (string) $value;
			$out  .= ' ' . $name . '="' . ( in_array( $name, array( 'src', 'poster', 'data-hls', 'data-mp4', 'data-vo-autoload' ), true ) ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}

		return $out;
	}

	/**
	 * Play button icon.
	 */
	public static function play_icon(): string {
		return '<span class="vo-poster__play" aria-hidden="true"><svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z" fill="currentColor"/></svg></span>';
	}
}

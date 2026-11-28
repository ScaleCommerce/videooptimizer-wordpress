<?php
/**
 * Video detail page integration.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Pages with a "video detail" element show the video named by `?vo_video=<uuid>`. This class
 * resolves that request (the video must belong to the organization and, if the element has one,
 * to its library, see Ownership) and makes the page behave like a page of its own: an invalid,
 * foreign or unknown uuid answers 404 (theme 404 template, never cached) without the title of any
 * video anywhere, and for an existing video the document title, meta description and canonical URL
 * come from the video. With Yoast SEO or Rank Math the values go through their filters (and no
 * second meta description is printed).
 */
class DetailPage {

	public const PARAM = 'vo_video';

	/**
	 * Embed lookups.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Ownership checks.
	 *
	 * @var Ownership
	 */
	private Ownership $ownership;

	/**
	 * Resolved requests per uuid and library (this request).
	 *
	 * @var array<string, array{uuid: string, state: string, embed: ?array<string, mixed>}>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param EmbedRepository $embeds    Embed lookups.
	 * @param Ownership       $ownership Ownership checks.
	 */
	public function __construct( EmbedRepository $embeds, Ownership $ownership ) {
		$this->embeds    = $embeds;
		$this->ownership = $ownership;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_not_found' ), 1 );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'description' ), 1 );

		// Yoast SEO and Rank Math build title, description and canonical themselves.
		add_filter( 'wpseo_title', array( $this, 'seo_title' ) );
		add_filter( 'wpseo_opengraph_title', array( $this, 'seo_title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'seo_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'seo_description' ) );
		add_filter( 'wpseo_canonical', array( $this, 'seo_canonical' ) );
		add_filter( 'wpseo_opengraph_url', array( $this, 'seo_canonical' ) );
		add_filter( 'rank_math/frontend/title', array( $this, 'seo_title' ) );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( $this, 'seo_title' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'seo_description' ) );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'seo_description' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'seo_canonical' ) );
		add_filter( 'rank_math/opengraph/facebook/og_url', array( $this, 'seo_canonical' ) );
	}

	/**
	 * Whether an SEO plugin owns title, description and canonical (then no meta description of ours).
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Whether a post contains the detail element (block, shortcode in any quoting, or Elementor widget).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function has_detail( \WP_Post $post ): bool {
		$content = (string) $post->post_content;
		if ( str_contains( $content, 'wp:videooptimizer/video-detail' ) || 1 === preg_match( '/layout\s*=\s*["\']?video-detail/i', $content ) ) {
			return true;
		}

		return str_contains( (string) get_post_meta( $post->ID, '_elementor_data', true ), '"video-detail"' );
	}

	/**
	 * Library the first detail element of a post is restricted to ('' = any library).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function library_of( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		$found   = '';
		if ( preg_match( '/<!--\s+wp:videooptimizer\/video-detail\s+(\{.*?\})\s+\/?-->/s', $content, $m ) ) {
			$attrs = json_decode( $m[1], true );
			$found = is_array( $attrs ) && Embed::is_uuid( $attrs['libraryId'] ?? null ) ? strtolower( (string) $attrs['libraryId'] ) : '';
		} elseif ( preg_match( '/\[videooptimizer\b[^\]]*layout\s*=\s*["\']?video-detail[^\]]*\]/i', $content, $m ) ) {
			$atts  = shortcode_parse_atts( trim( (string) preg_replace( '/^\[videooptimizer|\]$/i', '', $m[0] ) ) );
			$lib   = $atts['library'] ?? $atts['libraryid'] ?? $atts['library_id'] ?? '';
			$found = Embed::is_uuid( $lib ) ? strtolower( (string) $lib ) : '';
		} elseif ( str_contains( (string) get_post_meta( $post->ID, '_elementor_data', true ), '"video-detail"' ) ) {
			$found = self::elementor_library( json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true ) );
		}

		return $found;
	}

	/**
	 * Library of the first detail widget in Elementor data.
	 *
	 * @param mixed $nodes Decoded `_elementor_data`.
	 */
	private static function elementor_library( mixed $nodes ): string {
		foreach ( is_array( $nodes ) ? $nodes : array() as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
			if ( 'videooptimizer' === ( $node['widgetType'] ?? '' ) && 'video-detail' === ( $settings['layout'] ?? '' ) ) {
				return Embed::is_uuid( $settings['libraryId'] ?? null ) ? strtolower( (string) $settings['libraryId'] ) : '';
			}
			$inner = self::elementor_library( $node['elements'] ?? null );
			if ( '' !== $inner ) {
				return $inner;
			}
		}

		return '';
	}

	/**
	 * Resolves the `vo_video` argument: null without one, else the uuid, whether the video may be
	 * shown (Ownership::FOUND), and its embed.
	 *
	 * @param string $library_id Library the element is restricted to ('' = any).
	 * @return array{uuid: string, state: string, embed: ?array<string, mixed>}|null
	 */
	public function resolve( string $library_id = '' ): ?array {
		$arg = Renderer::request_arg( self::PARAM );
		if ( null === $arg ) {
			return null;
		}
		$uuid = Embed::is_uuid( $arg ) ? strtolower( $arg ) : '';
		$key  = $uuid . '|' . strtolower( $library_id );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}

		$state = '' === $uuid ? Ownership::NOT_FOUND : $this->ownership->check( $uuid, $library_id );
		$embed = null;
		if ( Ownership::FOUND === $state ) {
			$embed = $this->embeds->get( $uuid );
			if ( null === $embed ) {
				$failure = $this->embeds->failure( $uuid );
				$state   = 404 === ( $failure['status'] ?? 0 ) ? Ownership::NOT_FOUND : Ownership::UNAVAILABLE;
			} elseif ( ! $embed['playable'] ) {
				// Not ready yet: temporary (neutral notice, page kept out of caches), not a 404.
				$state = Ownership::UNAVAILABLE;
				$embed = null;
			}
		}
		$this->memo[ $key ] = array(
			'uuid'  => $uuid,
			'state' => $state,
			'embed' => $embed,
		);

		return $this->memo[ $key ];
	}

	/**
	 * The resolved request of the page being viewed: only for pages with a detail element.
	 *
	 * @return array{uuid: string, state: string, embed: ?array<string, mixed>}|null
	 */
	private function requested(): ?array {
		if ( ! is_singular() || null === Renderer::request_arg( self::PARAM ) ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! self::has_detail( $post ) ) {
			return null;
		}

		return $this->resolve( self::library_of( $post ) );
	}

	/**
	 * The video of the page when it may be shown, else null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function shown(): ?array {
		$request = $this->requested();

		return null !== $request && Ownership::FOUND === $request['state'] ? $request['embed'] : null;
	}

	/**
	 * An invalid, foreign or unknown video is a 404 (an unavailable API is not).
	 */
	public function maybe_not_found(): void {
		$request = $this->requested();
		if ( null === $request || Ownership::NOT_FOUND !== $request['state'] ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Document title from the video.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function title( array $parts ): array {
		$parts['title'] = $this->seo_title( $parts['title'] ?? '' );

		return $parts;
	}

	/**
	 * Title of the video for the page (SEO plugin filters and core).
	 *
	 * @param mixed $title Title so far.
	 * @return mixed
	 */
	public function seo_title( $title ) {
		$embed = $this->shown();

		return null !== $embed && '' !== $embed['title'] ? $embed['title'] : $title;
	}

	/**
	 * Description of the video for the page.
	 *
	 * @param mixed $description Description so far.
	 * @return mixed
	 */
	public function seo_description( $description ) {
		$embed = $this->shown();

		return null !== $embed && '' !== $embed['title'] ? $embed['title'] : $description;
	}

	/**
	 * Canonical URL of the page keeps the video (SEO plugin filters).
	 *
	 * @param mixed $url Canonical URL so far.
	 * @return mixed
	 */
	public function seo_canonical( $url ) {
		$request = $this->requested();
		if ( is_string( $url ) && '' !== $url && null !== $request && Ownership::FOUND === $request['state'] ) {
			return add_query_arg( array( self::PARAM => $request['uuid'] ), remove_query_arg( self::PARAM, $url ) );
		}

		return $url;
	}

	/**
	 * Core canonical URL (rel=canonical without an SEO plugin).
	 *
	 * @param string|false $url  Canonical URL.
	 * @param \WP_Post     $post Post.
	 * @return string|false
	 */
	public function canonical( $url, $post ) {
		if ( get_queried_object_id() !== $post->ID ) {
			return $url;
		}

		return $this->seo_canonical( $url );
	}

	/**
	 * Meta description from the video title (never next to an SEO plugin's own).
	 */
	public function description(): void {
		$embed = $this->shown();
		if ( null === $embed || '' === $embed['title'] || self::seo_plugin_active() ) {
			return;
		}

		/**
		 * Filters whether the video detail page prints a meta description (turn off with an SEO plugin).
		 *
		 * @param bool $print Default true.
		 */
		if ( apply_filters( 'videooptimizer_detail_meta', true ) ) {
			echo '<meta name="description" content="' . esc_attr( $embed['title'] ) . '">' . "\n";
		}
	}
}

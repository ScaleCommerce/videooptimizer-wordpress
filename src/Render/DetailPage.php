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

		// Temporary pages ("temporarily unavailable", not ready yet) must not be indexed.
		add_filter( 'wp_robots', array( $this, 'core_robots' ) );
		add_filter( 'wpseo_robots_array', array( $this, 'seo_robots' ) );
		add_filter( 'rank_math/frontend/robots', array( $this, 'seo_robots' ) );
		add_filter( 'rank_math/opengraph/facebook/og_url', array( $this, 'seo_canonical' ) );
	}

	/**
	 * Whether an SEO plugin owns title, description and canonical (then no meta description of ours).
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Whether a post shows the detail element (block, shortcode in any quoting, Elementor widget, or
	 * one of those inside a reusable block / Elementor template).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function has_detail( \WP_Post $post ): bool {
		return null !== self::find_element( (string) $post->post_content, (int) $post->ID, 0 );
	}

	/**
	 * Library the first detail element of a post is restricted to ('' = any library). The element's
	 * attributes go through the same normalization the renderer applies, and reusable blocks and
	 * Elementor templates are followed, so title/meta/canonical and the element agree.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function library_of( \WP_Post $post ): string {
		$raw = self::find_element( (string) $post->post_content, (int) $post->ID, 0 );

		return null === $raw ? '' : (string) Attributes::normalize( $raw )['libraryId'];
	}

	/**
	 * Raw attributes of the first detail element in content, a post's Elementor data or whatever
	 * they embed (reusable blocks, Elementor templates; depth limited).
	 *
	 * @param string $content Post content.
	 * @param int    $post_id Post id (for its Elementor data; 0 = none).
	 * @param int    $depth   Nesting depth.
	 * @return array<string, mixed>|null
	 */
	private static function find_element( string $content, int $post_id, int $depth ): ?array {
		if ( $depth > 4 ) {
			return null;
		}
		if ( '' !== $content ) {
			$found = self::find_in_blocks( parse_blocks( $content ), $depth );
			if ( null !== $found ) {
				return $found;
			}
			if ( preg_match( '/\[videooptimizer\b[^\]]*layout\s*=\s*["\']?video-detail[^\]]*\]/i', $content, $m ) ) {
				$atts = shortcode_parse_atts( trim( (string) preg_replace( '/^\[videooptimizer|\]$/i', '', $m[0] ) ) );

				return (array) $atts;
			}
		}
		if ( $post_id > 0 ) {
			$data = (string) get_post_meta( $post_id, '_elementor_data', true );
			if ( '' !== $data ) {
				return self::find_in_elementor( json_decode( $data, true ), $depth );
			}
		}

		return null;
	}

	/**
	 * Detail block in parsed blocks, following reusable blocks.
	 *
	 * @param mixed $blocks Parsed blocks.
	 * @param int   $depth  Nesting depth.
	 * @return array<string, mixed>|null
	 */
	private static function find_in_blocks( mixed $blocks, int $depth ): ?array {
		foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'videooptimizer/video-detail' === ( $block['blockName'] ?? '' ) ) {
				return $attrs;
			}
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && isset( $attrs['ref'] ) ) {
				$ref = get_post( (int) $attrs['ref'] );
				if ( $ref instanceof \WP_Post && 'publish' === $ref->post_status ) {
					$found = self::find_element( (string) $ref->post_content, 0, $depth + 1 );
					if ( null !== $found ) {
						return $found;
					}
				}
			}
			$found = self::find_in_blocks( $block['innerBlocks'] ?? null, $depth );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Detail widget in Elementor data, following template widgets.
	 *
	 * @param mixed $nodes Decoded `_elementor_data`.
	 * @param int   $depth Nesting depth.
	 * @return array<string, mixed>|null
	 */
	private static function find_in_elementor( mixed $nodes, int $depth ): ?array {
		foreach ( is_array( $nodes ) ? $nodes : array() as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
			if ( 'videooptimizer' === ( $node['widgetType'] ?? '' ) && 'video-detail' === ( $settings['layout'] ?? '' ) ) {
				return $settings;
			}
			if ( 'template' === ( $node['widgetType'] ?? '' ) && isset( $settings['template_id'] ) ) {
				$found = self::find_element( '', (int) $settings['template_id'], $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
			$found = self::find_in_elementor( $node['elements'] ?? null, $depth );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
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
	 * Whether the page shows a temporary state of its video (unavailable, rate limited, not ready).
	 */
	private function temporary(): bool {
		$request = $this->requested();

		return null !== $request && Ownership::UNAVAILABLE === $request['state'];
	}

	/**
	 * Core robots meta: noindex for temporary pages.
	 *
	 * @param array<string, mixed> $robots Directives.
	 * @return array<string, mixed>
	 */
	public function core_robots( array $robots ): array {
		if ( $this->temporary() ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/**
	 * Yoast SEO / Rank Math robots array: noindex for temporary pages.
	 *
	 * @param mixed $robots Directives.
	 * @return mixed
	 */
	public function seo_robots( $robots ) {
		if ( is_array( $robots ) && $this->temporary() ) {
			$robots['index'] = 'noindex';
		}

		return $robots;
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

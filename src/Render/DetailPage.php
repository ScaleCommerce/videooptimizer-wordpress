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
 * makes such a page behave like a page of its own: an invalid or unknown uuid answers 404 (theme
 * 404 template, never cached), the document title and meta description come from the video, and
 * the canonical URL keeps the video.
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
	 * Constructor.
	 *
	 * @param EmbedRepository $embeds Embed lookups.
	 */
	public function __construct( EmbedRepository $embeds ) {
		$this->embeds = $embeds;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_not_found' ), 1 );
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'description' ), 1 );
	}

	/**
	 * Whether a post contains the detail element (block, shortcode or Elementor widget).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function has_detail( \WP_Post $post ): bool {
		$content = (string) $post->post_content;
		if ( str_contains( $content, 'wp:videooptimizer/video-detail' ) || 1 === preg_match( '/layout=["\']?video-detail/i', $content ) ) {
			return true;
		}

		return str_contains( (string) get_post_meta( $post->ID, '_elementor_data', true ), '"video-detail"' );
	}

	/**
	 * The video to show on the current request: the `vo_video` argument of a page with a detail
	 * element, null for any other request or when no video was asked for.
	 *
	 * @return array{uuid: string, embed: ?array<string, mixed>, failure: ?array{status: int, outage: bool}}|null
	 */
	private function requested(): ?array {
		if ( ! is_singular() ) {
			return null;
		}
		$arg = Renderer::request_arg( self::PARAM );
		if ( null === $arg ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! self::has_detail( $post ) ) {
			return null;
		}
		if ( ! Embed::is_uuid( $arg ) ) {
			return array(
				'uuid'    => '',
				'embed'   => null,
				'failure' => array(
					'status' => 404,
					'outage' => false,
				),
			);
		}
		$uuid = strtolower( $arg );

		return array(
			'uuid'    => $uuid,
			'embed'   => $this->embeds->get( $uuid ),
			'failure' => $this->embeds->failure( $uuid ),
		);
	}

	/**
	 * An invalid uuid or a video that does not exist is a 404.
	 */
	public function maybe_not_found(): void {
		$request = $this->requested();
		if ( null === $request || null !== $request['embed'] || 404 !== ( $request['failure']['status'] ?? 0 ) ) {
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
		$request = $this->requested();
		if ( null !== $request && null !== $request['embed'] && '' !== $request['embed']['title'] ) {
			$parts['title'] = $request['embed']['title'];
		}

		return $parts;
	}

	/**
	 * Canonical URL of the page keeps the video.
	 *
	 * @param string|false $url  Canonical URL.
	 * @param \WP_Post     $post Post.
	 * @return string|false
	 */
	public function canonical( $url, $post ) {
		$request = $this->requested();
		if ( is_string( $url ) && null !== $request && null !== $request['embed'] && get_queried_object_id() === $post->ID ) {
			return add_query_arg( array( self::PARAM => $request['uuid'] ), $url );
		}

		return $url;
	}

	/**
	 * Meta description from the video title.
	 */
	public function description(): void {
		$request = $this->requested();
		if ( null === $request || null === $request['embed'] || '' === $request['embed']['title'] ) {
			return;
		}

		/**
		 * Filters whether the video detail page prints a meta description (turn off with an SEO plugin).
		 *
		 * @param bool $print Default true.
		 */
		if ( apply_filters( 'videooptimizer_detail_meta', true ) ) {
			echo '<meta name="description" content="' . esc_attr( $request['embed']['title'] ) . '">' . "\n";
		}
	}
}

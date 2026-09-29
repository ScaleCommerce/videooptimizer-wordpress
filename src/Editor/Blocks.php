<?php
/**
 * Gutenberg blocks.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor;

use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the five dynamic blocks from build/blocks/<layout>/block.json. They are rendered on the
 * server by the shared renderer; the editor preview uses the same output (ServerSideRender).
 */
class Blocks {

	public const LAYOUTS = array( 'video', 'media-split', 'background-hero', 'spotlight', 'video-grid' );

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	/**
	 * Registers the blocks.
	 */
	public function register(): void {
		foreach ( self::LAYOUTS as $layout ) {
			$dir = VIDEOOPTIMIZER_DIR . 'build/blocks/' . $layout;
			if ( ! is_readable( $dir . '/block.json' ) ) {
				continue;
			}
			$type = register_block_type(
				$dir,
				array(
					'render_callback' => function ( array $attributes, string $content ) use ( $layout ): string {
						return $this->renderer->render( $layout, $attributes, $content, get_block_wrapper_attributes() );
					},
				)
			);
			if ( $type instanceof \WP_Block_Type ) {
				foreach ( $type->editor_script_handles as $handle ) {
					wp_set_script_translations( $handle, 'videooptimizer', VIDEOOPTIMIZER_DIR . 'languages' );
				}
			}
		}
	}

	/**
	 * Adds the "VideoOptimizer" block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'videooptimizer',
				'title' => 'VideoOptimizer',
				'icon'  => null,
			)
		);

		return $categories;
	}
}

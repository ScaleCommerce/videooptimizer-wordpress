<?php
/**
 * Frontend assets.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the frontend CSS/JS and enqueues them only on pages that actually render a video.
 * hls.js (~300 kB) is never enqueued: the frontend script loads it on demand, and only in
 * browsers without native HLS.
 */
class Assets {

	public const HANDLE = 'videooptimizer-frontend';

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ) );
	}

	/**
	 * Registers the handles (also used as block "style"/"viewScript" and inside the editor).
	 */
	public function register(): void {
		$asset = self::asset_file( 'frontend/index' );

		wp_register_style( self::HANDLE, VIDEOOPTIMIZER_URL . 'build/frontend/index.css', array(), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_register_script(
			self::HANDLE,
			VIDEOOPTIMIZER_URL . 'build/frontend/index.js',
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.videooptimizerFrontend=' . wp_json_encode(
				array(
					'hlsUrl' => VIDEOOPTIMIZER_URL . 'build/vendor/hls.light.min.js?ver=' . rawurlencode( $asset['version'] ),
					'i18n'   => array(
						'close' => __( 'Close video', 'videooptimizer' ),
						'play'  => __( 'Play video', 'videooptimizer' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueues the assets (called by the renderer). Late calls still work: WordPress prints
	 * late styles in the footer, and maybe_enqueue_early() covers the common cases up front.
	 */
	public function enqueue(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			$this->register();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Classic themes render the content after wp_head, so detect videos up front to load the
	 * stylesheet in <head> and avoid a flash of unstyled content.
	 */
	public function maybe_enqueue_early(): void {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$content = (string) $post->post_content;
		$needs   = str_contains( $content, '<!-- wp:videooptimizer/' )
			|| has_shortcode( $content, 'videooptimizer' )
			|| str_contains( $content, '<!-- wp:video' ) // core/video may be replaced.
			|| ( 'product' === $post->post_type && '' !== (string) get_post_meta( $post->ID, '_videooptimizer_product', true ) );

		/**
		 * Filters whether the frontend assets are loaded in <head> for the current request.
		 *
		 * @param bool     $needs Detected need.
		 * @param \WP_Post $post  Queried post.
		 */
		if ( apply_filters( 'videooptimizer_enqueue_early', $needs, $post ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Reads a wp-scripts *.asset.php file (dependencies + content hash version).
	 *
	 * @param string $entry Entry path below build/, without extension.
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	public static function asset_file( string $entry ): array {
		$file = VIDEOOPTIMIZER_DIR . 'build/' . $entry . '.asset.php';
		if ( is_readable( $file ) ) {
			$asset = require $file;
			if ( is_array( $asset ) ) {
				return array(
					'dependencies' => array_values( (array) ( $asset['dependencies'] ?? array() ) ),
					'version'      => (string) ( $asset['version'] ?? VIDEOOPTIMIZER_VERSION ),
				);
			}
		}

		return array(
			'dependencies' => array(),
			'version'      => VIDEOOPTIMIZER_VERSION,
		);
	}
}

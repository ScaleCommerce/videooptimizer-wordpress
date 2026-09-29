<?php
/**
 * WooCommerce integration.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Woo;

use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\Embed;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\Renderer;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Product videos in the gallery, a "Video" tab and hover previews in product grids.
 */
class WooCommerce {

	public const ADMIN_SCRIPT = 'videooptimizer-product';
	public const NONCE        = 'videooptimizer_product_nonce';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	private EmbedRepository $embeds;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param Renderer        $renderer Renderer.
	 * @param EmbedRepository $embeds   Embed cache.
	 */
	public function __construct( Settings $settings, Renderer $renderer, EmbedRepository $embeds ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
		$this->embeds   = $embeds;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		// Admin: product data.
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 10, 3 );

		// Frontend.
		if ( $this->settings->flag( 'woo_gallery' ) ) {
			$priority = 'second' === $this->settings->string( 'woo_gallery_position' ) ? 5 : 40;
			add_action( 'woocommerce_product_thumbnails', array( $this, 'gallery_slides' ), $priority );
			add_filter( 'woocommerce_single_product_carousel_options', array( $this, 'carousel_options' ) );
			add_filter( 'woocommerce_single_product_image_gallery_classes', array( $this, 'gallery_classes' ) );
		}
		if ( $this->settings->flag( 'woo_tab' ) ) {
			add_filter( 'woocommerce_product_tabs', array( $this, 'add_tab' ) );
		}
		if ( $this->settings->flag( 'woo_hover' ) ) {
			add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'hover_open' ), 9 );
			add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'hover_close' ), 11 );
			add_filter( 'render_block_woocommerce/product-image', array( $this, 'hover_block' ), 10, 3 );
		}
	}

	/* ---------------------------------------------------------------- Admin */

	/**
	 * Adds the "Product videos" meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'videooptimizer-product', __( 'Product videos (VideoOptimizer)', 'videooptimizer' ), array( $this, 'render_meta_box' ), 'product', 'side', 'low' );
	}

	/**
	 * Meta box placeholder; the React UI (assets/src/woo) mounts into it.
	 *
	 * @param \WP_Post $post Product post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$videos = ProductVideos::for_product( $post->ID );
		wp_nonce_field( 'videooptimizer_product', self::NONCE );
		printf(
			'<input type="hidden" name="videooptimizer_product" id="videooptimizer-product-input" value="%1$s"><div id="videooptimizer-product-root" data-value="%1$s" data-block-theme="%3$s"><p class="description">%2$s</p></div>',
			esc_attr( (string) wp_json_encode( $videos ) ),
			esc_html__( 'Loading…', 'videooptimizer' ),
			function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? '1' : '0'
		);
	}

	/**
	 * Saves the meta box through the product CRUD.
	 *
	 * @param \WC_Product $product Product being saved.
	 */
	public function save( $product ): void {
		if ( ! isset( $_POST[ self::NONCE ], $_POST['videooptimizer_product'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'videooptimizer_product' ) ) {
			return;
		}
		$videos = ProductVideos::sanitize( wp_unslash( $_POST['videooptimizer_product'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated field by field in sanitize().
		if ( ProductVideos::is_empty( $videos ) ) {
			$product->delete_meta_data( ProductVideos::META );
		} else {
			$product->update_meta_data( ProductVideos::META, $videos );
		}
	}

	/**
	 * Loads the meta box UI on product edit screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function enqueue_admin( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		$asset = Assets::asset_file( 'woo/index' );
		wp_enqueue_script( self::ADMIN_SCRIPT, VIDEOOPTIMIZER_URL . 'build/woo/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::ADMIN_SCRIPT, VIDEOOPTIMIZER_URL . 'build/woo/index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::ADMIN_SCRIPT, 'rtl', 'replace' );
		wp_set_script_translations( self::ADMIN_SCRIPT, 'videooptimizer', VIDEOOPTIMIZER_DIR . 'languages' );
	}

	/**
	 * "Variation video" field in each variation (the React picker mounts into the placeholder).
	 *
	 * @param int   $loop           Variation index.
	 * @param mixed $variation_data Variation data (unused).
	 * @param mixed $variation      Variation post.
	 */
	public function variation_field( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		$uuid = $variation instanceof \WP_Post ? ProductVideos::for_variation( $variation->ID ) : '';
		printf(
			'<div class="form-row form-row-full videooptimizer-variation-row"><label>%1$s</label><input type="hidden" class="videooptimizer-variation-input" name="videooptimizer_variation[%2$d]" value="%3$s"><div class="videooptimizer-variation" data-value="%3$s"></div><p class="description">%4$s</p></div>',
			esc_html__( 'Variation video (VideoOptimizer)', 'videooptimizer' ),
			(int) $loop,
			esc_attr( $uuid ),
			esc_html__( 'Shown in the product gallery; the gallery jumps to it when shoppers select this variation.', 'videooptimizer' )
		);
	}

	/**
	 * Saves the variation video (WooCommerce verified the nonce of the variations request).
	 *
	 * @param mixed $variation Variation.
	 * @param int   $index     Variation index in the request.
	 */
	public function save_variation( $variation, $index ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce (save-variations / update-post).
		if ( ! $variation instanceof \WC_Product || ! isset( $_POST['videooptimizer_variation'] ) || ! is_array( $_POST['videooptimizer_variation'] ) ) {
			return;
		}
		$raw = isset( $_POST['videooptimizer_variation'][ $index ] ) ? sanitize_text_field( wp_unslash( $_POST['videooptimizer_variation'][ $index ] ) ) : null;
		// phpcs:enable
		if ( null === $raw ) {
			return;
		}
		if ( Embed::is_uuid( $raw ) ) {
			$variation->update_meta_data( ProductVideos::VARIATION_META, strtolower( $raw ) );
		} else {
			$variation->delete_meta_data( ProductVideos::VARIATION_META );
		}
	}

	/**
	 * Exposes the variation video to the frontend variation form.
	 *
	 * @param mixed $data      Variation data.
	 * @param mixed $product   Parent product.
	 * @param mixed $variation Variation.
	 * @return array<string, mixed>
	 */
	public function variation_data( $data, $product, $variation ): array {
		unset( $product );
		$data = is_array( $data ) ? $data : array();
		if ( $variation instanceof \WC_Product ) {
			$uuid = ProductVideos::for_variation( $variation->get_id() );
			if ( '' !== $uuid ) {
				$data['videooptimizer_video'] = $uuid;
			}
		}

		return $data;
	}

	/* ---------------------------------------------------------------- Gallery */

	/**
	 * Appends the video slides to the product gallery.
	 *
	 * Each slide is WooCommerce-compatible: it is a regular `.woocommerce-product-gallery__image`
	 * (FlexSlider slide + thumbnail from data-thumb) and carries a hidden
	 * `video[data-large_image]`, so the PhotoSwipe lightbox keeps its slide indexes aligned and
	 * shows the product video there too. The visible part is the regular VideoOptimizer surface.
	 */
	public function gallery_slides(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		foreach ( ProductVideos::gallery_uuids( $product ) as $uuid ) {
			echo $this->gallery_slide( $uuid, $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in gallery_slide().
		}
	}

	/**
	 * One gallery slide.
	 *
	 * @param string      $uuid    Video uuid.
	 * @param \WC_Product $product Product.
	 */
	public function gallery_slide( string $uuid, \WC_Product $product ): string {
		$embed = $this->embeds->get( $uuid );
		if ( null === $embed || null === $embed['poster'] ) {
			return '';
		}

		$surface = $this->renderer->surface_for(
			$uuid,
			array(
				'presentation' => 'facade',
				/* translators: %s: product name */
				'label'        => sprintf( __( 'Video: %s', 'videooptimizer' ), $product->get_name() ),
				'sizes'        => '(min-width: 768px) 50vw, 100vw',
			)
		);
		$mp4     = self::gallery_mp4( $embed['files'] );
		$width   = $embed['width'] ?? 1920;
		$height  = $embed['height'] ?? 1080;

		// Only for PhotoSwipe (never visible, never loaded in the slider).
		$lightbox = null === $mp4 ? '' : '<video class="videooptimizer-gallery__lightbox" hidden preload="none"' . Renderer::attrs(
			array(
				'src'                     => $mp4,
				'poster'                  => $embed['poster'],
				'data-large_image'        => $embed['poster'],
				'data-large_image_width'  => $width,
				'data-large_image_height' => $height,
				/* translators: %s: product name */
				'aria-label'              => sprintf( __( 'Video: %s', 'videooptimizer' ), $product->get_name() ),
			)
		) . '></video>';

		return sprintf(
			'<div data-thumb="%1$s" data-thumb-alt="%2$s" data-vo-uuid="%5$s" class="woocommerce-product-gallery__image woocommerce-product-gallery__video videooptimizer-gallery__slide">%3$s%4$s</div>',
			esc_url( $embed['poster'] ),
			/* translators: %s: product name */
			esc_attr( sprintf( __( 'Video: %s', 'videooptimizer' ), $product->get_name() ) ),
			$surface,
			$lightbox,
			esc_attr( $uuid )
		);
	}

	/**
	 * FlexSlider: allow touch swipes to start on our slides too (no change needed to the
	 * selector because slides use the WooCommerce class), keep heights smooth.
	 *
	 * @param array<string, mixed> $options Carousel options.
	 * @return array<string, mixed>
	 */
	public function carousel_options( array $options ): array {
		$options['smoothHeight'] = true;

		return $options;
	}

	/**
	 * Marks galleries with videos (styling hook).
	 *
	 * @param array<int, string> $classes Gallery classes.
	 * @return array<int, string>
	 */
	public function gallery_classes( array $classes ): array {
		global $product;
		if ( $product instanceof \WC_Product && array() !== ProductVideos::gallery_uuids( $product ) ) {
			$classes[] = 'videooptimizer-gallery';
			// A single image plus videos must still become a slider.
			$this->assets_enqueue();
		}

		return $classes;
	}

	/* ---------------------------------------------------------------- Tab */

	/**
	 * Adds the "Video" tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tab( array $tabs ): array {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return $tabs;
		}
		$uuid = ProductVideos::for_product( $product )['tab'];
		if ( '' === $uuid ) {
			return $tabs;
		}
		$title = $this->settings->string( 'woo_tab_title' );

		$tabs['videooptimizer'] = array(
			'title'    => '' !== $title ? $title : __( 'Video', 'videooptimizer' ),
			'priority' => 25,
			'callback' => function () use ( $uuid, $product ): void {
				$html = $this->renderer->render(
					'video',
					array(
						'uuid'         => $uuid,
						'title'        => $product->get_name(),
						'presentation' => 'facade',
					)
				);
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
			},
		);

		return $tabs;
	}

	/* ---------------------------------------------------------------- Hover preview */

	/**
	 * Opens the hover wrapper around the loop thumbnail (classic templates).
	 */
	public function hover_open(): void {
		global $product;
		$attrs = $product instanceof \WC_Product ? $this->hover_attrs( $product ) : null;
		if ( null !== $attrs ) {
			echo '<span class="videooptimizer-hover"' . Renderer::attrs( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Renderer::attrs().
		}
	}

	/**
	 * Closes the hover wrapper.
	 */
	public function hover_close(): void {
		global $product;
		if ( $product instanceof \WC_Product && null !== $this->hover_attrs( $product ) ) {
			echo '</span>';
		}
	}

	/**
	 * Product image block (block themes / product collection).
	 *
	 * @param string               $html   Block HTML.
	 * @param array<string, mixed> $block    Parsed block.
	 * @param mixed                $instance Block instance.
	 */
	public function hover_block( string $html, array $block, $instance ): string {
		unset( $block );
		$post_id = $instance instanceof \WP_Block ? (int) ( $instance->context['postId'] ?? 0 ) : 0;
		$product = $post_id > 0 ? wc_get_product( $post_id ) : null;
		// Only in grids — the single product image block has no postId context from a query loop.
		if ( ! $product instanceof \WC_Product || ! isset( $instance->context['queryId'] ) ) {
			return $html;
		}
		$attrs = $this->hover_attrs( $product );

		return null === $attrs ? $html : '<span class="videooptimizer-hover videooptimizer-hover--block"' . Renderer::attrs( $attrs ) . '>' . $html . '</span>';
	}

	/**
	 * Data attributes for a product's hover preview, or null.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>|null
	 */
	private function hover_attrs( \WC_Product $product ): ?array {
		static $memo = array();
		$id          = $product->get_id();
		if ( array_key_exists( $id, $memo ) ) {
			return $memo[ $id ];
		}

		$uuid  = ProductVideos::for_product( $product )['hover'];
		$embed = '' !== $uuid ? $this->embeds->get( $uuid ) : null;
		$mp4   = null !== $embed ? Embed::smallest_mp4( $embed ) : null;
		if ( null === $mp4 ) {
			$memo[ $id ] = null;

			return null;
		}

		$this->assets_enqueue();
		$memo[ $id ] = array( 'data-vo-hover' => $mp4 );

		return $memo[ $id ];
	}

	/**
	 * Enqueues frontend assets.
	 */
	private function assets_enqueue(): void {
		wp_enqueue_style( Assets::HANDLE );
		wp_enqueue_script( Assets::HANDLE );
	}

	/**
	 * MP4 for the PhotoSwipe lightbox: the largest rendition up to 1080p.
	 *
	 * @param array<int, array{src: string, type: string, label: string, size: ?int}> $files Files (smallest first).
	 */
	private static function gallery_mp4( array $files ): ?string {
		$pick = null;
		foreach ( $files as $file ) {
			if ( 'video/mp4' === $file['type'] && ( null === $pick || ! preg_match( '/(1440|2160)p/', $file['label'] ) ) ) {
				$pick = $file['src'];
			}
		}

		return $pick;
	}
}

<?php
/**
 * Product video data.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Woo;

use ScaleCommerce\VideoOptimizer\Render\Embed;

defined( 'ABSPATH' ) || exit;

/**
 * Per-product video assignment, stored as one meta value via the product CRUD (HPOS-safe):
 *   gallery: uuid[]  – extra slides in the product image gallery
 *   tab:     uuid    – video in the "Video" product tab
 *   hover:   uuid    – muted preview on hover in shop/category grids
 */
final class ProductVideos {

	public const META           = '_videooptimizer_product';
	public const VARIATION_META = '_videooptimizer_variation';

	/**
	 * Empty value.
	 *
	 * @return array{gallery: array<int, string>, tab: string, hover: string}
	 */
	public static function empty(): array {
		return array(
			'gallery' => array(),
			'tab'     => '',
			'hover'   => '',
		);
	}

	/**
	 * Validates a raw value (array or JSON string).
	 *
	 * @param mixed $raw Raw value.
	 * @return array{gallery: array<int, string>, tab: string, hover: string}
	 */
	public static function sanitize( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = self::empty();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( (array) ( $raw['gallery'] ?? array() ) as $uuid ) {
			if ( Embed::is_uuid( $uuid ) && ! in_array( strtolower( $uuid ), $out['gallery'], true ) ) {
				$out['gallery'][] = strtolower( $uuid );
			}
		}
		$out['gallery'] = array_slice( $out['gallery'], 0, 10 );
		foreach ( array( 'tab', 'hover' ) as $key ) {
			$out[ $key ] = Embed::is_uuid( $raw[ $key ] ?? null ) ? strtolower( (string) $raw[ $key ] ) : '';
		}

		return $out;
	}

	/**
	 * Videos of a product.
	 *
	 * @param \WC_Product|int|null $product Product or id.
	 * @return array{gallery: array<int, string>, tab: string, hover: string}
	 */
	public static function for_product( $product ): array {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product instanceof \WC_Product ) {
			return self::empty();
		}

		return self::sanitize( $product->get_meta( self::META, true ) );
	}

	/**
	 * Video uuid of one variation ('' when none).
	 *
	 * @param int $variation_id Variation id.
	 */
	public static function for_variation( int $variation_id ): string {
		$uuid = get_post_meta( $variation_id, self::VARIATION_META, true );

		return Embed::is_uuid( $uuid ) ? strtolower( (string) $uuid ) : '';
	}

	/**
	 * Variation videos of a variable product: [variation id => uuid].
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, string>
	 */
	public static function variation_videos( \WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$videos = array();
		foreach ( $product->get_children() as $variation_id ) {
			$uuid = self::for_variation( (int) $variation_id );
			if ( '' !== $uuid ) {
				$videos[ (int) $variation_id ] = $uuid;
			}
		}

		return $videos;
	}

	/**
	 * All uuids shown in the gallery: product gallery videos, then variation videos (deduplicated).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, string>
	 */
	public static function gallery_uuids( \WC_Product $product ): array {
		return array_values( array_unique( array_merge( self::for_product( $product )['gallery'], array_values( self::variation_videos( $product ) ) ) ) );
	}

	/**
	 * Whether the value holds anything.
	 *
	 * @param array{gallery: array<int, string>, tab: string, hover: string} $videos Videos.
	 */
	public static function is_empty( array $videos ): bool {
		return array() === $videos['gallery'] && '' === $videos['tab'] && '' === $videos['hover'];
	}
}

<?php
/**
 * Where a VideoOptimizer video is still used.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Media;

use ScaleCommerce\VideoOptimizer\Render\Embed;

defined( 'ABSPATH' ) || exit;

/**
 * Finds content that still references a video uuid: blocks and shortcodes in post content,
 * Elementor data, WooCommerce product/variation videos and other linked media library items.
 * Used before offering to delete a video in VideoOptimizer.
 */
class Usage {

	public const LIMIT = 20;

	/**
	 * References to a video.
	 *
	 * @param string $uuid              Video uuid.
	 * @param int    $ignore_attachment Attachment being deleted (not counted).
	 * @return array<int, array{id: int, title: string, type: string, edit_url: string}>
	 */
	public static function find( string $uuid, int $ignore_attachment = 0 ): array {
		if ( ! Embed::is_uuid( $uuid ) ) {
			return array();
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( strtolower( $uuid ) ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- ad-hoc lookup, uuid is validated.
		$in_content = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s LIMIT %d",
				$like,
				self::LIMIT
			)
		);
		$in_meta    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_data','_videooptimizer_product','_videooptimizer_variation','_videooptimizer_uuid') AND meta_value LIKE %s LIMIT %d",
				$like,
				self::LIMIT
			)
		);
		// phpcs:enable

		$ids   = array_unique( array_map( 'intval', array_merge( $in_content, $in_meta ) ) );
		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || $id === $ignore_attachment || 'trash' === $post->post_status || 'revision' === $post->post_type ) {
				continue;
			}
			$type = get_post_type_object( $post->post_type );
			if ( 'product_variation' === $post->post_type ) {
				$parent = get_post( $post->post_parent );
				$link   = $parent ? (string) get_edit_post_link( $parent->ID, 'raw' ) : '';
			} else {
				$link = (string) get_edit_post_link( $id, 'raw' );
			}
			$items[] = array(
				'id'       => $id,
				'title'    => '' !== get_the_title( $post ) ? get_the_title( $post ) : '#' . $id,
				'type'     => $type ? (string) $type->labels->singular_name : $post->post_type,
				'edit_url' => $link,
			);
		}

		return array_slice( $items, 0, self::LIMIT );
	}
}

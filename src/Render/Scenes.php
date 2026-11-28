<?php
/**
 * Scene helpers for the "video with chapters" element.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The encoder samples ten preview frames of every video at `duration / 11 * (i + 1)`; scenes are
 * those frames. Pure helpers, no WordPress calls.
 */
final class Scenes {

	/**
	 * Number of preview frames per video.
	 */
	public const FRAMES = 10;

	/**
	 * Evenly spread frame indices for a number of scenes (3–10).
	 *
	 * @param int $count Wanted scenes.
	 * @return array<int, int>
	 */
	public static function indices( int $count ): array {
		$count = max( 3, min( self::FRAMES, $count ) );
		$out   = array();
		for ( $k = 0; $k < $count; $k++ ) {
			$out[] = (int) round( $k * ( self::FRAMES - 1 ) / ( $count - 1 ) );
		}

		return $out;
	}

	/**
	 * Start of a preview frame in seconds.
	 *
	 * @param float $duration Exact video duration in seconds (never rounded).
	 * @param int   $index    Frame index 0–9.
	 */
	public static function start( float $duration, int $index ): int {
		return (int) floor( $duration / ( self::FRAMES + 1 ) * ( $index + 1 ) );
	}

	/**
	 * "m:ss" or "h:mm:ss".
	 *
	 * @param int $seconds Seconds.
	 */
	public static function clock( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$h       = intdiv( $seconds, 3600 );
		$m       = intdiv( $seconds % 3600, 60 );
		$s       = $seconds % 60;

		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%02d:%02d', $m, $s );
	}

	/**
	 * Public thumbnail URL (302 to the CDN, no token).
	 *
	 * @param string $api_base API base URL (already ends with /api/v1).
	 * @param string $uuid     Video uuid.
	 * @param int    $index    Frame index 0–9.
	 */
	public static function thumbnail_url( string $api_base, string $uuid, int $index ): string {
		return rtrim( $api_base, '/' ) . '/videos/' . rawurlencode( $uuid ) . '/thumbnails/' . $index;
	}

	/**
	 * Scene titles: one per line, empty lines kept as "no title" for that scene.
	 *
	 * @param string $titles Raw lines ("|" works as a separator too, for shortcodes).
	 * @return array<int, string>
	 */
	public static function titles( string $titles ): array {
		$lines = preg_split( '/\r\n|\r|\n|\|/', $titles );

		return array_map( 'trim', false === $lines ? array() : $lines );
	}
}

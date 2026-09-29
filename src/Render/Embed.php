<?php
/**
 * Normalized embed payload.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers turning the public GET /embed/{uuid} payload into what the renderer needs.
 * No WordPress calls here, so everything is unit-testable.
 */
final class Embed {

	public const HLS_TYPE = 'application/vnd.apple.mpegurl';

	/**
	 * Normalizes a raw embed payload.
	 *
	 * @param string               $uuid Video uuid.
	 * @param array<string, mixed> $data Raw `data` object of /embed/{uuid}.
	 * @return array{uuid: string, title: string, poster: ?string, srcset: ?string, width: ?int, height: ?int, orientation: ?string, duration: ?int, hls: ?string, files: array<int, array{src: string, type: string, label: string, size: ?int}>, theme: array<string, mixed>, playable: bool}
	 */
	public static function normalize( string $uuid, array $data ): array {
		$hls   = null;
		$files = array();

		foreach ( (array) ( $data['sources'] ?? array() ) as $source ) {
			if ( ! is_array( $source ) || ! is_string( $source['src'] ?? null ) || '' === $source['src'] ) {
				continue;
			}
			$type = is_string( $source['type'] ?? null ) ? $source['type'] : '';
			if ( self::HLS_TYPE === $type || 'hls' === ( $source['codec'] ?? '' ) ) {
				$hls ??= $source['src'];
				continue;
			}
			// A rendition that is not finished yet can carry the bare CDN root as src — skip it.
			if ( ! preg_match( '~\.(mp4|webm|mov|m4v)(\?.*)?$~i', $source['src'] ) ) {
				continue;
			}
			$files[] = array(
				'src'   => $source['src'],
				'type'  => '' !== $type ? $type : 'video/mp4',
				'label' => is_string( $source['label'] ?? null ) ? $source['label'] : '',
				'size'  => is_numeric( $source['size'] ?? null ) ? (int) $source['size'] : null,
			);
		}

		// Smallest resolution first: browsers pick the first playable <source>, and mobile wins.
		usort( $files, static fn ( array $a, array $b ): int => self::label_height( $a['label'] ) <=> self::label_height( $b['label'] ) );

		[ $width, $height ] = self::parse_resolution( $data['resolution'] ?? null );
		$poster             = is_string( $data['poster'] ?? null ) && '' !== $data['poster'] ? $data['poster'] : null;

		return array(
			'uuid'        => $uuid,
			'title'       => self::clean_title( $data['title'] ?? null ),
			'poster'      => $poster,
			'srcset'      => self::parse_poster_srcset( $data['posterSrcset'] ?? null ),
			'width'       => $width,
			'height'      => $height,
			'orientation' => self::orientation( $width, $height ),
			'duration'    => is_numeric( $data['duration'] ?? null ) ? (int) round( (float) $data['duration'] ) : null,
			'hls'         => $hls,
			'files'       => $files,
			'theme'       => is_array( $data['theme'] ?? null ) ? $data['theme'] : array(),
			'playable'    => null !== $hls || array() !== $files,
		);
	}

	/**
	 * The smallest MP4 rendition (used for lightweight hover previews).
	 *
	 * @param array<string, mixed> $embed Normalized embed (see normalize()).
	 */
	public static function smallest_mp4( array $embed ): ?string {
		foreach ( (array) ( $embed['files'] ?? array() ) as $file ) {
			if ( is_array( $file ) && 'video/mp4' === ( $file['type'] ?? '' ) && is_string( $file['src'] ?? null ) ) {
				return $file['src'];
			}
		}

		return null;
	}

	/**
	 * Builds an <img srcset> from posterSrcset [{width, height, url}]; ready-made "url 320w"
	 * strings are accepted too. Returns null when nothing usable is present.
	 *
	 * @param mixed $srcset Raw posterSrcset.
	 */
	public static function parse_poster_srcset( mixed $srcset ): ?string {
		if ( ! is_array( $srcset ) || array() === $srcset ) {
			return null;
		}

		$entries = array();
		foreach ( $srcset as $entry ) {
			if ( is_string( $entry ) && preg_match( '/\s\d+[wx]\s*$/', $entry ) ) {
				$entries[] = trim( $entry );
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url   = $entry['url'] ?? $entry['src'] ?? null;
			$width = $entry['width'] ?? null;
			if ( is_string( $url ) && '' !== $url && is_numeric( $width ) && (int) $width > 0 ) {
				$entries[] = $url . ' ' . (int) $width . 'w';
			}
		}

		return array() === $entries ? null : implode( ', ', $entries );
	}

	/**
	 * Parses "1920x1080" into [1920, 1080].
	 *
	 * @param mixed $resolution Raw resolution.
	 * @return array{0: ?int, 1: ?int}
	 */
	public static function parse_resolution( mixed $resolution ): array {
		if ( is_string( $resolution ) && preg_match( '/^\s*(\d+)\s*x\s*(\d+)\s*$/i', $resolution, $m ) && (int) $m[1] > 0 && (int) $m[2] > 0 ) {
			return array( (int) $m[1], (int) $m[2] );
		}

		return array( null, null );
	}

	/**
	 * Orientation from dimensions.
	 *
	 * @param int|null $width  Width.
	 * @param int|null $height Height.
	 */
	public static function orientation( ?int $width, ?int $height ): ?string {
		if ( null === $width || null === $height || $width <= 0 || $height <= 0 ) {
			return null;
		}
		if ( $width > $height ) {
			return 'landscape';
		}

		return $width < $height ? 'portrait' : 'square';
	}

	/**
	 * Seconds as ISO-8601 duration for schema.org (90 → PT1M30S).
	 *
	 * @param int|null $seconds Duration.
	 */
	public static function iso_duration( ?int $seconds ): ?string {
		if ( null === $seconds || $seconds <= 0 ) {
			return null;
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;

		return 'PT' . ( $h > 0 ? $h . 'H' : '' ) . ( $m > 0 ? $m . 'M' : '' ) . ( $s > 0 || ( 0 === $h && 0 === $m ) ? $s . 'S' : '' );
	}

	/**
	 * Display title: upload titles often are file names ("Clip_final.mp4") — drop the extension.
	 *
	 * @param mixed $title Raw title.
	 */
	public static function clean_title( mixed $title ): string {
		if ( ! is_string( $title ) ) {
			return '';
		}

		return trim( (string) preg_replace( '/\.(mp4|m4v|mov|webm|mkv|avi|wmv|mpe?g)$/i', '', $title ) );
	}

	/**
	 * Whether a string is a VideoOptimizer video uuid.
	 *
	 * @param mixed $uuid Candidate.
	 */
	public static function is_uuid( mixed $uuid ): bool {
		return is_string( $uuid ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid );
	}

	/**
	 * Numeric height from a rendition label like "720p" (unknown labels sort last).
	 *
	 * @param string $label Label.
	 */
	private static function label_height( string $label ): int {
		return preg_match( '/(\d{3,4})p/i', $label, $m ) ? (int) $m[1] : PHP_INT_MAX;
	}
}

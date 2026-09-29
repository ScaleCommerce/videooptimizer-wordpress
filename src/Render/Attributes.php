<?php
/**
 * Layout attribute schema + sanitizing.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Render;

defined( 'ABSPATH' ) || exit;

/**
 * One attribute schema shared by blocks, shortcode and Elementor, so every editor produces the
 * exact same output. Attribute names are camelCase (block.json convention); the shortcode accepts
 * the same names in lowercase or snake/kebab case.
 */
final class Attributes {

	public const LAYOUTS = array( 'video', 'media-split', 'background-hero', 'spotlight', 'video-grid' );

	/**
	 * Layout-specific default presentation ('' = use the global default setting).
	 */
	public const DEFAULT_PRESENTATION = array(
		'video'           => '',
		'media-split'     => 'facade',
		'background-hero' => 'direct',
		'spotlight'       => 'lightbox',
		'video-grid'      => 'lightbox',
	);

	/**
	 * Attribute schema: name => [type, default, allowed values?].
	 *
	 * @return array<string, array{0: string, 1: mixed, 2?: array<int, string>}>
	 */
	public static function schema(): array {
		$tri = array( '', '1', '0' );

		return array(
			'uuid'          => array( 'uuid', '' ),
			'title'         => array( 'text', '' ),
			'player'        => array( 'enum', '', array( '', 'hosted', 'native' ) ),
			'presentation'  => array( 'enum', '', array( '', 'facade', 'lightbox', 'direct' ) ),
			'autoplay'      => array( 'enum', '', $tri ),
			'muted'         => array( 'enum', '', $tri ),
			'loop'          => array( 'enum', '', $tri ),
			'controls'      => array( 'enum', '', $tri ),
			'priority'      => array( 'bool', false ),
			'caption'       => array( 'text', '' ),
			// media-split / hero / spotlight / grid.
			'side'          => array( 'enum', 'left', array( 'left', 'right' ) ),
			'eyebrow'       => array( 'text', '' ),
			'headline'      => array( 'text', '' ),
			'subline'       => array( 'text', '' ),
			'text'          => array( 'html', '' ),
			'intro'         => array( 'text', '' ),
			'ctaLabel'      => array( 'text', '' ),
			'ctaUrl'        => array( 'url', '' ),
			'height'        => array( 'enum', 'large', array( 'full', 'large', 'medium' ) ),
			'overlay'       => array( 'enum', 'gradient', array( 'gradient', 'dark', 'none' ) ),
			'headlineColor' => array( 'color', '' ),
			'textColor'     => array( 'color', '' ),
			'columns'       => array( 'int', 3 ),
			'items'         => array( 'items', array() ),
		);
	}

	/**
	 * Normalizes raw attributes: unknown keys are dropped, every value is validated.
	 *
	 * @param array<string, mixed> $raw Raw attributes.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$raw = self::canonical_keys( $raw );
		$out = array();

		foreach ( self::schema() as $name => $def ) {
			$value        = $raw[ $name ] ?? $def[1];
			$out[ $name ] = match ( $def[0] ) {
				'uuid'  => Embed::is_uuid( $value ) ? strtolower( (string) $value ) : '',
				'text'  => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
				'html'  => is_scalar( $value ) ? wp_kses_post( (string) $value ) : '',
				'url'   => self::safe_url( $value ),
				'color' => self::safe_color( $value ),
				'bool'  => self::to_bool( $value ),
				'int'   => max( 1, min( 4, is_numeric( $value ) ? (int) $value : (int) $def[1] ) ),
				'items' => self::items( $value ),
				default => self::enum( $value, $def[2] ?? array(), $def[1] ),
			};
		}

		return $out;
	}

	/**
	 * Accepts a URL only for http(s), mailto, tel or site-relative paths. Protocol-relative
	 * (//host) and script URLs are rejected.
	 *
	 * @param mixed $value Candidate URL.
	 */
	public static function safe_url( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) );
		if ( '' === $value || str_starts_with( $value, '//' ) || str_starts_with( $value, '\\' ) ) {
			return '';
		}
		if ( preg_match( '~^[a-z][a-z0-9+.-]*:~i', $value ) && ! preg_match( '~^(https?|mailto|tel):~i', $value ) ) {
			return '';
		}

		return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Accepts #rgb, #rgba, #rrggbb and #rrggbbaa only.
	 *
	 * @param mixed $value Candidate color.
	 */
	public static function safe_color( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ? $value : '';
	}

	/**
	 * Loose boolean parsing (true, "1", "true", "yes", "on").
	 *
	 * @param mixed $value Candidate.
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return is_scalar( $value ) && in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Grid items [{uuid, label}]; the shortcode may pass "uuid|Label, uuid|Label".
	 *
	 * @param mixed $value Raw items.
	 * @return array<int, array{uuid: string, label: string}>
	 */
	private static function items( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array_map(
				static function ( string $entry ): array {
					$parts = array_map( 'trim', explode( '|', $entry, 2 ) );

					return array(
						'uuid'  => $parts[0],
						'label' => $parts[1] ?? '',
					);
				},
				array_filter( array_map( 'trim', explode( ',', $value ) ) )
			);
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) && Embed::is_uuid( $item['uuid'] ?? null ) ) {
				$items[] = array(
					'uuid'  => strtolower( (string) $item['uuid'] ),
					'label' => is_scalar( $item['label'] ?? null ) ? sanitize_text_field( (string) $item['label'] ) : '',
				);
			}
		}

		return array_slice( $items, 0, 24 );
	}

	/**
	 * Enum value or default.
	 *
	 * @param mixed              $value   Candidate.
	 * @param array<int, string> $allowed Allowed values.
	 * @param mixed              $fallback Default.
	 */
	private static function enum( mixed $value, array $allowed, mixed $fallback ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		if ( in_array( $value, array( 'true', 'yes', 'on' ), true ) && in_array( '1', $allowed, true ) ) {
			$value = '1';
		}
		if ( in_array( $value, array( 'false', 'no', 'off' ), true ) && in_array( '0', $allowed, true ) ) {
			$value = '0';
		}
		if ( 'embed' === $value && in_array( 'hosted', $allowed, true ) ) {
			$value = 'hosted';
		}

		return in_array( $value, $allowed, true ) ? $value : (string) $fallback;
	}

	/**
	 * Maps lowercase / snake_case / kebab-case keys (shortcodes lowercase everything) to the
	 * camelCase schema names.
	 *
	 * @param array<string, mixed> $raw Raw attributes.
	 * @return array<string, mixed>
	 */
	private static function canonical_keys( array $raw ): array {
		$map = array();
		foreach ( array_keys( self::schema() ) as $name ) {
			$map[ strtolower( $name ) ] = $name;
		}
		$map['video'] = 'uuid';
		$map['id']    = 'uuid';

		$out = array();
		foreach ( $raw as $key => $value ) {
			$flat = strtolower( str_replace( array( '_', '-' ), '', $key ) );
			if ( isset( $map[ $flat ] ) ) {
				$out[ $map[ $flat ] ] = $value;
			} elseif ( array_key_exists( $key, self::schema() ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}
}

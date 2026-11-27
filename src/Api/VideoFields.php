<?php
/**
 * Validation of the user-editable video fields.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the API rules to tags and metadata before they are sent, so editors get a
 * clear message instead of a generic 400.
 */
final class VideoFields {

	public const MAX_TAGS      = 20;
	public const MAX_TAG_LEN   = 50;
	public const MAX_META_KEYS = 10;
	public const MAX_VALUE_LEN = 64;

	/**
	 * Tag names: trimmed, 1-50 characters, no commas, unique ignoring case, at most 20.
	 *
	 * @param array<int, mixed> $tags Tag names.
	 * @return array<int, string>
	 * @throws \InvalidArgumentException When a name or the count is invalid.
	 */
	public static function tags( array $tags ): array {
		$result = array();
		$seen   = array();
		foreach ( $tags as $tag ) {
			$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $tag ) );
			if ( '' === $name ) {
				continue;
			}
			if ( mb_strlen( $name ) > self::MAX_TAG_LEN || str_contains( $name, ',' ) ) {
				throw new \InvalidArgumentException( __( 'A tag has 1 to 50 characters and must not contain a comma.', 'videooptimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
			}
			$needle = mb_strtolower( $name );
			if ( isset( $seen[ $needle ] ) ) {
				continue;
			}
			$seen[ $needle ] = true;
			$result[]        = $name;
		}
		if ( count( $result ) > self::MAX_TAGS ) {
			throw new \InvalidArgumentException( __( 'A video can have at most 20 tags.', 'videooptimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}

		return $result;
	}

	/**
	 * Metadata key/value pairs. A null or empty value is kept as a null removal marker (PATCH merges:
	 * keys sent are set, null removes the key).
	 *
	 * @param array<string, mixed> $metadata Key => value.
	 * @return array<string, ?string>
	 * @throws \InvalidArgumentException When a key or value is invalid or there are too many keys.
	 */
	public static function metadata( array $metadata ): array {
		$result = array();
		foreach ( $metadata as $key => $value ) {
			$key = trim( (string) $key );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_.-]{0,39}$/', $key ) ) {
				throw new \InvalidArgumentException( __( 'A metadata key has 1 to 40 characters of a-z, 0-9, _, . or - and starts with a letter or digit.', 'videooptimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
			}
			if ( null === $value ) {
				$result[ $key ] = null;
				continue;
			}
			$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value ) );
			if ( mb_strlen( $value ) > self::MAX_VALUE_LEN ) {
				throw new \InvalidArgumentException( __( 'A metadata value has at most 64 characters.', 'videooptimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
			}
			$result[ $key ] = '' === $value ? null : $value;
		}
		if ( count( $result ) > self::MAX_META_KEYS ) {
			throw new \InvalidArgumentException( __( 'A video can have at most 10 metadata keys.', 'videooptimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON by the REST API, never echoed as HTML.
		}

		return $result;
	}
}

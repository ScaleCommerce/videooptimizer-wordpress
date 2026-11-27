<?php
/**
 * User-Agent sent to the VideoOptimizer API.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the User-Agent the plugin identifies itself with, so VideoOptimizer can show which plugin
 * versions an organization runs:
 *
 *     VideoOptimizer-WordPress/0.1.1 (WordPress 6.8.1; PHP 8.3.14; +https://www.example.com)
 *
 * The VideoOptimizer app parses this with a regex, so keep the format stable.
 */
final class UserAgent {

	public const PRODUCT = 'VideoOptimizer-WordPress';

	/**
	 * Maximum length of each value.
	 */
	public const MAX_PART_LENGTH = 100;

	/**
	 * Header computed for this request lifecycle.
	 *
	 * @var string|null
	 */
	private static ?string $cached = null;

	/**
	 * The User-Agent for this site, computed once per request.
	 */
	public static function get(): string {
		if ( null === self::$cached ) {
			self::$cached = self::build(
				defined( 'VIDEOOPTIMIZER_VERSION' ) ? (string) VIDEOOPTIMIZER_VERSION : '',
				(string) get_bloginfo( 'version' ),
				PHP_VERSION,
				(string) home_url()
			);
		}

		return self::$cached;
	}

	/**
	 * Formats the User-Agent. Every value is sanitized; empty parts are left out.
	 *
	 * @param string $plugin_version Plugin version.
	 * @param string $wp_version     WordPress version.
	 * @param string $php_version    PHP version.
	 * @param string $site_url       Site (home) URL.
	 */
	public static function build( string $plugin_version, string $wp_version, string $php_version, string $site_url ): string {
		$plugin_version = self::sanitize_part( $plugin_version );
		$wp_version     = self::sanitize_part( $wp_version );
		$php_version    = self::sanitize_part( $php_version );
		$site_url       = self::sanitize_part( $site_url );

		$parts = array();
		if ( '' !== $wp_version ) {
			$parts[] = 'WordPress ' . $wp_version;
		}
		if ( '' !== $php_version ) {
			$parts[] = 'PHP ' . $php_version;
		}
		if ( '' !== $site_url ) {
			$parts[] = '+' . $site_url;
		}

		$agent = self::PRODUCT . '/' . ( '' !== $plugin_version ? $plugin_version : 'dev' );

		return array() === $parts ? $agent : $agent . ' (' . implode( '; ', $parts ) . ')';
	}

	/**
	 * Reduces a value to printable ASCII without the separators `;`, `(` and `)` or any
	 * whitespace (versions and URLs never contain spaces), capped at MAX_PART_LENGTH characters.
	 *
	 * @param string $value Raw value.
	 */
	private static function sanitize_part( string $value ): string {
		$value = (string) preg_replace( '/[^\x21-\x7E]|[;()]/', '', $value );

		return substr( $value, 0, self::MAX_PART_LENGTH );
	}
}

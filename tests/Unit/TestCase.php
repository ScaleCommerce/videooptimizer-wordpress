<?php
/**
 * Base unit test case.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up Brain Monkey and stubs the pure WordPress helpers the plugin relies on.
 */
abstract class TestCase extends PolyfillTestCase {

	/**
	 * Options stored via the stubbed option API.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	/**
	 * Transients stored via the stubbed transient API.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transients = array();

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();

		Functions\stubs(
			array(
				'wp_json_encode'       => static fn ( $data, $flags = 0 ) => json_encode( $data, $flags ),
				'sanitize_text_field'  => static fn ( $v ) => trim( strip_tags( (string) $v ) ),
				'sanitize_key'         => static fn ( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ),
				'wp_kses_post'         => static fn ( $v ) => strip_tags( (string) $v, '<p><a><strong><em><br><ul><ol><li>' ),
				'esc_url_raw'          => static fn ( $v ) => (string) $v,
				'wpautop'              => static fn ( $v ) => '<p>' . $v . '</p>',
				'rest_sanitize_boolean' => static fn ( $v ) => filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
				'current_user_can'     => false,
				'get_post_time'        => false,
				'home_url'             => 'https://shop.example/',
				'wp_salt'              => static fn ( $scheme = 'auth' ) => 'salt-' . $scheme,
				'wp_parse_url'         => static fn ( $url, $component = -1 ) => parse_url( $url, $component ),
				'wp_get_environment_type' => 'production',
				'add_query_arg'        => static function ( array $args, string $url ): string {
					return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode(
						'&',
						array_map( static fn ( $k, $v ) => $k . '=' . $v, array_keys( $args ), $args )
					);
				},
				'is_wp_error'          => static fn ( $thing ) => $thing instanceof \WP_Error,
				'get_option'           => fn ( $name, $default = false ) => $this->options[ $name ] ?? $default,
				'update_option'        => function ( $name, $value ) {
					$this->options[ $name ] = $value;
					return true;
				},
				'get_transient'        => fn ( $name ) => $this->transients[ $name ] ?? false,
				'set_transient'        => function ( $name, $value ) {
					$this->transients[ $name ] = $value;
					return true;
				},
				'delete_transient'     => function ( $name ) {
					unset( $this->transients[ $name ] );
					return true;
				},
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Like core: esc_url() encodes ampersands.
		Functions\when( 'esc_url' )->alias( static fn ( $url ) => str_replace( '&', '&#038;', (string) $url ) );
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}

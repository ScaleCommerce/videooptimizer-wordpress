<?php
/**
 * User-Agent builder.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Tests\Unit;

use Brain\Monkey\Functions;
use ScaleCommerce\VideoOptimizer\Api\UserAgent;

final class UserAgentTest extends TestCase {

	/**
	 * The pattern the VideoOptimizer app parses the header with.
	 */
	private const APP_PATTERN = '#^VideoOptimizer-WordPress/([^\s(]+)(?: \(([^)]*)\))?$#';

	protected function set_up(): void {
		parent::set_up();
		self::reset_cache();
	}

	protected function tear_down(): void {
		self::reset_cache();
		parent::tear_down();
	}

	private static function reset_cache(): void {
		$cached = new \ReflectionProperty( UserAgent::class, 'cached' );
		$cached->setValue( null, null );
	}

	public function test_full_format(): void {
		$this->assertSame(
			'VideoOptimizer-WordPress/0.1.1 (WordPress 6.8.1; PHP 8.3.14; +https://www.example.com)',
			UserAgent::build( '0.1.1', '6.8.1', '8.3.14', 'https://www.example.com' )
		);
	}

	public function test_missing_site_url_omits_the_part(): void {
		$this->assertSame(
			'VideoOptimizer-WordPress/0.1.1 (WordPress 6.8.1; PHP 8.3.14)',
			UserAgent::build( '0.1.1', '6.8.1', '8.3.14', '' )
		);
	}

	public function test_empty_parts_are_omitted(): void {
		$this->assertSame(
			'VideoOptimizer-WordPress/0.1.1 (PHP 8.3.14; +https://shop.example)',
			UserAgent::build( '0.1.1', '', '8.3.14', 'https://shop.example' )
		);
		$this->assertSame( 'VideoOptimizer-WordPress/dev', UserAgent::build( '', '', '', '' ) );
	}

	public function test_values_are_sanitized(): void {
		$agent = UserAgent::build(
			"0.1.1 (beta)\r\n",
			'6.8;1',
			"8.3\x00.14",
			"https://evil.example/a;b(c)\r\nX-Injected: 1"
		);

		$this->assertSame(
			'VideoOptimizer-WordPress/0.1.1beta (WordPress 6.81; PHP 8.3.14; +https://evil.example/abcX-Injected:1)',
			$agent
		);
		$this->assertMatchesRegularExpression( self::APP_PATTERN, $agent );
	}

	public function test_values_are_capped(): void {
		$url   = 'https://example.com/' . str_repeat( 'a', 300 );
		$agent = UserAgent::build( '0.1.1', '6.8.1', '8.3.14', $url );

		$this->assertStringEndsWith( '; +' . substr( $url, 0, UserAgent::MAX_PART_LENGTH ) . ')', $agent );
	}

	public function test_get_uses_site_data_and_is_memoized(): void {
		$calls = 0;
		Functions\when( 'get_bloginfo' )->alias(
			static function ( string $show ) use ( &$calls ): string {
				++$calls;
				return 'version' === $show ? '6.8.1' : '';
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.example.com' );

		$agent = UserAgent::get();

		$this->assertSame( 'VideoOptimizer-WordPress/0.1.0-test (WordPress 6.8.1; PHP ' . PHP_VERSION . '; +https://www.example.com)', $agent );
		$this->assertSame( $agent, UserAgent::get() );
		$this->assertSame( 1, $calls );
		$this->assertMatchesRegularExpression( self::APP_PATTERN, $agent );
	}
}

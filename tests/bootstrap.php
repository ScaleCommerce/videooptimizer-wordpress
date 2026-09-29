<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests (default) run without WordPress, using Brain Monkey. Integration tests run inside
 * the Docker environment against a real WordPress + WooCommerce (see tests/bin/run-integration.sh,
 * which sets VIDEOOPTIMIZER_INTEGRATION=1).
 *
 * @package VideoOptimizer
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( getenv( 'VIDEOOPTIMIZER_INTEGRATION' ) ) {
	$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	require_once $tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			$woo = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
			if ( is_readable( $woo ) ) {
				require $woo;
			}
			require dirname( __DIR__ ) . '/videooptimizer.php';
		}
	);

	// Create the WooCommerce tables in the test database.
	tests_add_filter(
		'setup_theme',
		static function (): void {
			if ( class_exists( 'WC_Install' ) ) {
				\WC_Install::install();
			}
		}
	);

	require $tests_dir . '/includes/bootstrap.php';
	return;
}

// Unit tests: minimal WordPress constants; functions are mocked per test with Brain Monkey.
require_once __DIR__ . '/Unit/stubs.php';
define( 'ABSPATH', __DIR__ . '/' );
define( 'VIDEOOPTIMIZER_VERSION', '0.1.0-test' );
define( 'VIDEOOPTIMIZER_FILE', dirname( __DIR__ ) . '/videooptimizer.php' );
define( 'VIDEOOPTIMIZER_DIR', dirname( __DIR__ ) . '/' );
define( 'VIDEOOPTIMIZER_URL', 'https://example.test/wp-content/plugins/videooptimizer/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

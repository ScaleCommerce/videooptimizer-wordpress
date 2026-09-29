<?php
/**
 * Plugin Name:       VideoOptimizer
 * Plugin URI:        https://videooptimizer.eu
 * Description:       Fast, adaptive video from the VideoOptimizer CDN for WordPress and WooCommerce — Gutenberg blocks, shortcode, Elementor widget, media library integration and product videos.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            ScaleCommerce GmbH
 * Author URI:        https://scale.sc
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       videooptimizer
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package VideoOptimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'VIDEOOPTIMIZER_VERSION', '0.1.0' );
define( 'VIDEOOPTIMIZER_FILE', __FILE__ );
define( 'VIDEOOPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIDEOOPTIMIZER_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ScaleCommerce\\VideoOptimizer\\';
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$file = VIDEOOPTIMIZER_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Declare compatibility with WooCommerce HPOS and the Cart/Checkout blocks (the plugin touches neither).
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( \ScaleCommerce\VideoOptimizer\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ScaleCommerce\VideoOptimizer\Lifecycle::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \ScaleCommerce\VideoOptimizer\Plugin::class, 'boot' ) );

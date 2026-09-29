<?php
/**
 * Activation / deactivation hooks.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer;

use ScaleCommerce\VideoOptimizer\Media\StatusSync;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps activation side effects minimal: seed default settings and (un)schedule the status sync.
 */
final class Lifecycle {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings\Settings::OPTION ) ) {
			add_option( Settings\Settings::OPTION, Settings\Settings::defaults() );
		}
		StatusSync::schedule();
	}

	/**
	 * Runs on plugin deactivation. Settings stay until the plugin is deleted (see uninstall.php).
	 */
	public static function deactivate(): void {
		StatusSync::unschedule();
	}
}

<?php
/**
 * Removes all plugin data when the plugin is deleted in WP-Admin.
 * Videos in VideoOptimizer itself are never touched.
 *
 * @package VideoOptimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'videooptimizer_settings' );
delete_option( 'videooptimizer_webhook_status' );
wp_clear_scheduled_hook( 'videooptimizer_sync_status' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_videooptimizer\_%' OR option_name LIKE '\_transient\_timeout\_videooptimizer\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_videooptimizer\_%'" );
// phpcs:enable

<?php
/**
 * WP-CLI commands.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Cli;

use ScaleCommerce\VideoOptimizer\Api\ApiException;
use ScaleCommerce\VideoOptimizer\Media\Sender;
use ScaleCommerce\VideoOptimizer\Media\StatusSync;
use ScaleCommerce\VideoOptimizer\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage VideoOptimizer from the command line.
 *
 * ## EXAMPLES
 *
 *     wp videooptimizer status
 *     wp videooptimizer token set vp_xxx
 *     wp videooptimizer videos
 *     wp videooptimizer send 123
 *     wp videooptimizer sync
 */
class Command {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Shows the connection status.
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$source = $this->plugin->settings->token_source();
		\WP_CLI::log( 'Token source: ' . $source );
		if ( null === $this->plugin->settings->token() ) {
			\WP_CLI::warning( 'No usable API token configured.' );

			return;
		}
		try {
			$libraries = $this->plugin->client->list_libraries();
			\WP_CLI::success( sprintf( 'Connected. %d librar%s.', count( $libraries ), 1 === count( $libraries ) ? 'y' : 'ies' ) );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->user_message() );
		}
	}

	/**
	 * Sets or removes the API token.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : set|delete
	 *
	 * [<token>]
	 * : Organization API token (vp_…).
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function token( array $args, array $assoc ): void {
		unset( $assoc );
		$action = $args[0] ?? '';
		if ( 'delete' === $action ) {
			$this->plugin->settings->set_token( '' );
			\WP_CLI::success( 'Token removed.' );

			return;
		}
		if ( 'set' !== $action || empty( $args[1] ) ) {
			\WP_CLI::error( 'Usage: wp videooptimizer token set <token> | wp videooptimizer token delete' );
		}
		$this->plugin->settings->set_token( $args[1] );
		delete_transient( Sender::LIBRARY_CACHE );
		\WP_CLI::success( 'Token stored (encrypted).' );
	}

	/**
	 * Lists videos.
	 *
	 * ## OPTIONS
	 *
	 * [--library=<id>]
	 * : Only videos of this library.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function videos( array $args, array $assoc ): void {
		unset( $args );
		try {
			$videos = $this->plugin->client->list_videos( $assoc['library'] ?? null );
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->user_message() );
			return;
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $videos, array( 'uuid', 'title', 'status', 'duration', 'resolution', 'library_id' ) );
	}

	/**
	 * Sends media library videos to VideoOptimizer by URL (public sites only).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment ids.
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function send( array $args, array $assoc ): void {
		unset( $assoc );
		$sender = new Sender( $this->plugin->settings, $this->plugin->client, $this->plugin->attachments );
		foreach ( array_map( 'intval', $args ) as $id ) {
			try {
				$state = $sender->send_by_url( $id );
				\WP_CLI::success( sprintf( '#%d → %s (%s)', $id, $state['uuid'], $state['status'] ) );
			} catch ( ApiException $e ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $e->user_message() ) );
			}
		}
	}

	/**
	 * Refreshes the status of all processing media library videos now.
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function sync( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$count = ( new StatusSync( $this->plugin->attachments ) )->run();
		\WP_CLI::success( sprintf( '%d video(s) checked.', $count ) );
	}

	/**
	 * Flushes the cached embed data of one or all videos.
	 *
	 * ## OPTIONS
	 *
	 * [<uuid>]
	 * : Video uuid; all when omitted.
	 *
	 * @param array<int, string>    $args  Positional args.
	 * @param array<string, string> $assoc Assoc args.
	 */
	public function flush( array $args, array $assoc ): void {
		unset( $assoc );
		if ( ! empty( $args[0] ) ) {
			$this->plugin->embeds->forget( $args[0] );
		} else {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_videooptimizer\_embed\_%' OR option_name LIKE '\_transient\_timeout\_videooptimizer\_embed\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_flush();
		}
		\WP_CLI::success( 'Embed cache flushed.' );
	}
}

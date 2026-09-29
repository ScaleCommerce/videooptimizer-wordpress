<?php
/**
 * Admin menu + app.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Admin;

use ScaleCommerce\VideoOptimizer\Media\MediaLibrary;
use ScaleCommerce\VideoOptimizer\Plugin;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "VideoOptimizer" menu with the React admin app (videos, libraries, settings, transfers),
 * onboarding notice and plugin action links.
 */
class AdminPage {

	public const SLUG   = 'videooptimizer';
	public const SCRIPT = 'videooptimizer-admin';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'onboarding_notice' ) );
		add_action( 'admin_head', array( $this, 'print_globals' ) );
		add_action( 'admin_notices', array( $this, 'delete_offers_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_delete_offers' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VIDEOOPTIMIZER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Registers the menu. Sub pages are hash routes of the same app.
	 */
	public function menu(): void {
		add_menu_page(
			'VideoOptimizer',
			'VideoOptimizer',
			'upload_files',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-video-alt3',
			11
		);
		add_submenu_page( self::SLUG, __( 'Videos', 'videooptimizer' ), __( 'Videos', 'videooptimizer' ), 'upload_files', self::SLUG, array( $this, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Libraries', 'videooptimizer' ), __( 'Libraries', 'videooptimizer' ), 'manage_options', self::SLUG . '#/libraries', '__return_null' );
		add_submenu_page( self::SLUG, __( 'Settings', 'videooptimizer' ), __( 'Settings', 'videooptimizer' ), 'manage_options', self::SLUG . '#/settings', '__return_null' );
	}

	/**
	 * App root.
	 */
	public function render(): void {
		echo '<div class="wrap videooptimizer-wrap"><div id="videooptimizer-admin"><p>' . esc_html__( 'Loading…', 'videooptimizer' ) . '</p></div></div>';
	}

	/**
	 * Settings URL for "not connected" hints in editor UIs (block editor, media, Elementor).
	 */
	public function print_globals(): void {
		if ( current_user_can( 'upload_files' ) ) {
			wp_print_inline_script_tag( 'window.videooptimizerSettingsUrl=' . wp_json_encode( admin_url( 'admin.php?page=' . self::SLUG . '#/settings' ) ) . ';' );
		}
	}

	/**
	 * Loads the app on its page.
	 *
	 * @param string $hook Screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		$asset = Assets::asset_file( 'admin/index' );
		wp_enqueue_script( self::SCRIPT, VIDEOOPTIMIZER_URL . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::SCRIPT, VIDEOOPTIMIZER_URL . 'build/admin/index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::SCRIPT, 'rtl', 'replace' );
		wp_set_script_translations( self::SCRIPT, 'videooptimizer', VIDEOOPTIMIZER_DIR . 'languages' );
		wp_add_inline_script(
			self::SCRIPT,
			'window.videooptimizerAdmin=' . wp_json_encode(
				array(
					'canManage'  => current_user_can( 'manage_options' ),
					'canDelete'  => current_user_can( (string) apply_filters( 'videooptimizer_capability', 'delete_others_posts', 'delete_video' ) ),
					'configured' => null !== $this->settings->token(),
					'version'    => VIDEOOPTIMIZER_VERSION,
					'appUrl'     => 'https://videooptimizer.eu',
					'mediaUrl'   => admin_url( 'upload.php?mode=list' ),
					'woo'        => class_exists( 'WooCommerce' ),
					'elementor'  => did_action( 'elementor/loaded' ) > 0,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Onboarding notice until a token is configured (only for admins, not on our own page).
	 */
	public function onboarding_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || null !== $this->settings->token() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'toplevel_page_' . self::SLUG ), true ) ) {
			return;
		}
		if ( $screen && ! in_array( $screen->base, array( 'dashboard', 'plugins', 'upload' ), true ) ) {
			return;
		}

		$unreadable = 'option' === $this->settings->token_source();
		printf(
			'<div class="notice notice-info"><p><strong>VideoOptimizer:</strong> %1$s <a class="button button-primary" style="margin-left:8px" href="%2$s">%3$s</a></p></div>',
			esc_html(
				$unreadable
					? __( 'The stored API token can no longer be decrypted (the security keys in wp-config.php changed). Please enter it again.', 'videooptimizer' )
					: __( 'Almost done — connect your VideoOptimizer account to deliver fast, adaptive video.', 'videooptimizer' )
			),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#/settings' ) ),
			esc_html__( 'Connect now', 'videooptimizer' )
		);
	}

	/**
	 * Placeholder for "also delete in VideoOptimizer?" (rendered by the media script). Always
	 * present on the media library so offers created by deletions in the grid show up instantly.
	 */
	public function delete_offers_notice(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$media  = $screen && 'upload' === $screen->base;
		if ( ! $media && array() === Plugin::instance()->delete_offers->offers( get_current_user_id() ) ) {
			return;
		}
		echo '<div id="videooptimizer-delete-offers"></div>';
	}

	/**
	 * Loads the media script where the placeholder is printed.
	 *
	 * @param string $hook Screen hook.
	 */
	public function enqueue_delete_offers( string $hook ): void {
		$plugin = Plugin::instance();
		if ( 'upload.php' === $hook || array() !== $plugin->delete_offers->offers( get_current_user_id() ) ) {
			MediaLibrary::enqueue_script( $plugin->settings, $plugin->attachments );
		}
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#/settings' ) ) . '">' . esc_html__( 'Settings', 'videooptimizer' ) . '</a>' );

		return $links;
	}
}

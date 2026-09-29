<?php
/**
 * Plugin bootstrap / service container.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer;

use ScaleCommerce\VideoOptimizer\Admin\AdminPage;
use ScaleCommerce\VideoOptimizer\Api\Client;
use ScaleCommerce\VideoOptimizer\Editor\Blocks;
use ScaleCommerce\VideoOptimizer\Editor\CoreVideo;
use ScaleCommerce\VideoOptimizer\Editor\Elementor\Integration as Elementor;
use ScaleCommerce\VideoOptimizer\Editor\Shortcode;
use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Media\DeleteOffers;
use ScaleCommerce\VideoOptimizer\Media\MediaLibrary;
use ScaleCommerce\VideoOptimizer\Media\StatusSync;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\EmbedRepository;
use ScaleCommerce\VideoOptimizer\Render\Renderer;
use ScaleCommerce\VideoOptimizer\Rest\AdminController;
use ScaleCommerce\VideoOptimizer\Rest\WebhookController;
use ScaleCommerce\VideoOptimizer\Settings\Settings;
use ScaleCommerce\VideoOptimizer\Woo\NativeGallery;
use ScaleCommerce\VideoOptimizer\Woo\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the modules together. Every service is created once; modules only register hooks.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public readonly Settings $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	public readonly Client $client;

	/**
	 * Embed cache.
	 *
	 * @var EmbedRepository
	 */
	public readonly EmbedRepository $embeds;

	/**
	 * Frontend assets.
	 *
	 * @var Assets
	 */
	public readonly Assets $assets;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	public readonly Renderer $renderer;

	/**
	 * Attachment <-> video mapping.
	 *
	 * @var Attachments
	 */
	public readonly Attachments $attachments;

	/**
	 * Delete offers after media library deletions.
	 *
	 * @var DeleteOffers
	 */
	public readonly DeleteOffers $delete_offers;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings      = new Settings();
		$this->client        = new Client( fn (): ?string => $this->settings->token(), Settings::api_base_url() );
		$this->embeds        = new EmbedRepository( $this->client );
		$this->assets        = new Assets();
		$this->renderer      = new Renderer( $this->embeds, $this->settings, $this->assets );
		$this->attachments   = new Attachments( $this->client, $this->embeds );
		$this->delete_offers = new DeleteOffers( $this->settings, $this->client, $this->embeds, $this->attachments );
	}

	/**
	 * The plugin instance.
	 */
	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Entry point (plugins_loaded).
	 */
	public static function boot(): void {
		$plugin = self::instance();

		add_action( 'init', array( $plugin, 'load_textdomain' ), 0 );

		$plugin->assets->register_hooks();
		( new Blocks( $plugin->renderer ) )->register_hooks();
		( new Shortcode( $plugin->renderer ) )->register_hooks();
		( new AdminController( $plugin->settings, $plugin->client, $plugin->embeds, $plugin->attachments, $plugin->delete_offers ) )->register_hooks();
		$plugin->delete_offers->register_hooks();
		( new WebhookController( $plugin->settings, $plugin->embeds, $plugin->attachments ) )->register_hooks();
		( new StatusSync( $plugin->attachments ) )->register_hooks();

		if ( $plugin->settings->flag( 'replace_core_video' ) ) {
			( new CoreVideo( $plugin->renderer, $plugin->attachments ) )->register_hooks();
		}
		if ( $plugin->settings->flag( 'media_integration' ) ) {
			( new MediaLibrary( $plugin->settings, $plugin->attachments ) )->register_hooks();
		}
		if ( is_admin() ) {
			( new AdminPage( $plugin->settings ) )->register_hooks();
		}

		( new Elementor( $plugin->renderer, $plugin->attachments ) )->register_hooks();

		// All plugin main files are loaded before plugins_loaded, so WooCommerce is known by now.
		if ( did_action( 'woocommerce_loaded' ) || class_exists( 'WooCommerce' ) ) {
			( new WooCommerce( $plugin->settings, $plugin->renderer, $plugin->embeds ) )->register_hooks();
			if ( $plugin->settings->flag( 'replace_core_video' ) ) {
				( new NativeGallery( $plugin->attachments, $plugin->embeds ) )->register_hooks();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'videooptimizer', new Cli\Command( $plugin ) );
		}
	}

	/**
	 * Loads bundled translations (translate.wordpress.org language packs take precedence).
	 */
	public function load_textdomain(): void {
		// Bundled de_DE translations for installs outside wordpress.org (language packs still win).
		load_plugin_textdomain( 'videooptimizer', false, dirname( plugin_basename( VIDEOOPTIMIZER_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
}

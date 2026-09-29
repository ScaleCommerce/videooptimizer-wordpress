<?php
/**
 * Elementor integration.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor\Elementor;

use ScaleCommerce\VideoOptimizer\Media\Attachments;
use ScaleCommerce\VideoOptimizer\Render\Assets;
use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "VideoOptimizer" widget and its video picker control, and delivers self-hosted
 * Elementor video widgets via VideoOptimizer once the media library video is optimized.
 * Everything is hooked on Elementor's own actions, so nothing runs without Elementor.
 */
class Integration {

	public const EDITOR_SCRIPT = 'videooptimizer-elementor';

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Attachment mapping.
	 *
	 * @var Attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor.
	 *
	 * @param Renderer    $renderer    Renderer.
	 * @param Attachments $attachments Attachment mapping.
	 */
	public function __construct( Renderer $renderer, Attachments $attachments ) {
		$this->renderer    = $renderer;
		$this->attachments = $attachments;
	}

	/**
	 * Hooks registration.
	 */
	public function register_hooks(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		add_action( 'elementor/controls/register', array( $this, 'register_control' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor' ) );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'enqueue_preview' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'replace_video_widget' ), 10, 2 );
	}

	/**
	 * Registers the widget.
	 *
	 * @param mixed $manager Elementor Widgets_Manager.
	 */
	public function register_widget( $manager ): void {
		$manager->register( new Widget( array(), null, $this->renderer ) );
	}

	/**
	 * Registers the video picker control.
	 *
	 * @param mixed $manager Elementor Controls_Manager.
	 */
	public function register_control( $manager ): void {
		$manager->register( new VideoControl() );
	}

	/**
	 * Widget category.
	 *
	 * @param mixed $manager Elementor Elements_Manager.
	 */
	public function register_category( $manager ): void {
		$manager->add_category(
			'videooptimizer',
			array(
				'title' => 'VideoOptimizer',
				'icon'  => 'eicon-video-camera',
			)
		);
	}

	/**
	 * Editor panel script (video picker for the control).
	 */
	public function enqueue_editor(): void {
		$asset = Assets::asset_file( 'elementor/index' );
		wp_enqueue_script( self::EDITOR_SCRIPT, VIDEOOPTIMIZER_URL . 'build/elementor/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::EDITOR_SCRIPT, VIDEOOPTIMIZER_URL . 'build/elementor/index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( self::EDITOR_SCRIPT, 'rtl', 'replace' );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'videooptimizer', VIDEOOPTIMIZER_DIR . 'languages' );
	}

	/**
	 * Preview iframe: frontend assets are needed for widgets added after load.
	 */
	public function enqueue_preview(): void {
		wp_enqueue_style( Assets::HANDLE );
		wp_enqueue_script( Assets::HANDLE );
	}

	/**
	 * Delivers Elementor's own video widget via VideoOptimizer for optimized self-hosted videos.
	 *
	 * @param string $content Widget HTML.
	 * @param mixed  $widget  Elementor widget.
	 */
	public function replace_video_widget( $content, $widget ): string {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'video' !== $widget->get_name() || ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return (string) $content;
		}
		$settings = $widget->get_settings_for_display();
		if ( 'hosted' !== ( $settings['video_type'] ?? '' ) ) {
			return (string) $content;
		}
		$hosted = $settings['hosted_url'] ?? array();
		$id     = is_array( $hosted ) ? (int) ( $hosted['id'] ?? 0 ) : 0;
		$uuid   = $this->attachments->ready_uuid( $id );
		if ( null === $uuid ) {
			return (string) $content;
		}

		$yes     = static fn ( $value ): string => 'yes' === $value ? '1' : '0';
		$surface = $this->renderer->surface_for(
			$uuid,
			array(
				'presentation' => 'direct',
				'autoplay'     => $yes( $settings['autoplay'] ?? '' ),
				'muted'        => $yes( $settings['mute'] ?? '' ),
				'loop'         => $yes( $settings['loop'] ?? '' ),
				'controls'     => $yes( $settings['controls'] ?? 'yes' ),
			)
		);

		return '' === $surface || str_contains( $surface, 'vo-notice' ) ? (string) $content : '<div class="vo-blocks videooptimizer videooptimizer--elementor-video">' . $surface . '</div>';
	}
}

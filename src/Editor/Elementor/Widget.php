<?php
/**
 * Elementor widget.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor\Elementor;

use Elementor\Controls_Manager;
use ScaleCommerce\VideoOptimizer\Plugin;
use ScaleCommerce\VideoOptimizer\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * "VideoOptimizer" widget: every layout of the blocks, with the same options.
 */
class Widget extends \Elementor\Widget_Base {

	/**
	 * Renderer.
	 *
	 * @var Renderer|null
	 */
	private ?Renderer $renderer;

	/**
	 * Constructor (Elementor instantiates widgets itself, so the renderer is optional).
	 *
	 * @param array<string, mixed>      $data     Widget data.
	 * @param array<string, mixed>|null $args     Widget args.
	 * @param Renderer|null             $renderer Renderer.
	 */
	public function __construct( $data = array(), $args = null, ?Renderer $renderer = null ) {
		parent::__construct( $data, $args );
		$this->renderer = $renderer;
	}

	/**
	 * Widget name.
	 */
	public function get_name() {
		return 'videooptimizer';
	}

	/**
	 * Widget title.
	 */
	public function get_title() {
		return 'VideoOptimizer';
	}

	/**
	 * Widget icon.
	 */
	public function get_icon() {
		return 'eicon-youtube';
	}

	/**
	 * Widget categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( 'videooptimizer', 'basic' );
	}

	/**
	 * Search keywords.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return array( 'video', 'videooptimizer', 'hls', 'hero', 'lightbox', 'player' );
	}

	/**
	 * Frontend assets.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends() {
		return array( 'videooptimizer-frontend' );
	}

	/**
	 * Frontend assets.
	 *
	 * @return array<int, string>
	 */
	public function get_script_depends() {
		return array( 'videooptimizer-frontend' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$tri = array(
			''  => __( 'Default (VideoOptimizer player settings)', 'videooptimizer' ),
			'1' => __( 'On', 'videooptimizer' ),
			'0' => __( 'Off', 'videooptimizer' ),
		);

		$this->start_controls_section( 'section_video', array( 'label' => __( 'Video', 'videooptimizer' ) ) );
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'videooptimizer' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'video',
				'options' => array(
					'video'           => __( 'Video', 'videooptimizer' ),
					'media-split'     => __( 'Video + text', 'videooptimizer' ),
					'background-hero' => __( 'Background hero', 'videooptimizer' ),
					'spotlight'       => __( 'Spotlight', 'videooptimizer' ),
					'video-grid'      => __( 'Video grid', 'videooptimizer' ),
					'video-chapters'  => __( 'Video with chapters', 'videooptimizer' ),
					'video-list'      => __( 'Video list', 'videooptimizer' ),
					'video-detail'    => __( 'Video detail', 'videooptimizer' ),
				),
			)
		);
		$this->add_control(
			'uuid',
			array(
				'label'     => __( 'Video', 'videooptimizer' ),
				'type'      => VideoControl::TYPE,
				'condition' => array( 'layout!' => array( 'video-grid', 'video-list' ) ),
			)
		);

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			'uuid',
			array(
				'label' => __( 'Video', 'videooptimizer' ),
				'type'  => VideoControl::TYPE,
			)
		);
		$repeater->add_control(
			'label',
			array(
				'label' => __( 'Label', 'videooptimizer' ),
				'type'  => Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'items',
			array(
				'label'       => __( 'Videos', 'videooptimizer' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ label || uuid }}}',
				'condition'   => array( 'layout' => 'video-grid' ),
			)
		);
		$this->add_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'videooptimizer' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '3',
				'options'   => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'condition' => array( 'layout' => array( 'video-grid', 'video-list' ) ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_list',
			array(
				'label'     => __( 'Video list', 'videooptimizer' ),
				'condition' => array( 'layout' => 'video-list' ),
			)
		);
		$this->add_control(
			'libraryId',
			array(
				'label'       => __( 'Library', 'videooptimizer' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array( '' => __( 'All libraries', 'videooptimizer' ) ) + $this->library_options(),
				'description' => __( 'Lists the videos of this library that are ready to play.', 'videooptimizer' ),
			)
		);
		$this->add_control(
			'tag',
			array(
				'label'       => __( 'Tags', 'videooptimizer' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Optional: only videos with all of these tags, separated by commas.', 'videooptimizer' ),
			)
		);
		$this->add_control(
			'perPage',
			array(
				'label'   => __( 'Videos per page', 'videooptimizer' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 48,
				'default' => 12,
			)
		);
		$this->add_control(
			'detailPage',
			array(
				'label'       => __( 'Detail page', 'videooptimizer' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '0',
				'options'     => array( '0' => __( 'None (open in a lightbox)', 'videooptimizer' ) ) + $this->page_options(),
				'description' => __( 'The page with a "Video detail" element; a card opens it with the video in the URL. Without one, cards open the video in a lightbox.', 'videooptimizer' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'section_content', array( 'label' => __( 'Text', 'videooptimizer' ) ) );
		foreach (
			array(
				'eyebrow'  => array( __( 'Eyebrow', 'videooptimizer' ), Controls_Manager::TEXT, array( 'media-split', 'background-hero', 'spotlight' ) ),
				'headline' => array( __( 'Headline', 'videooptimizer' ), Controls_Manager::TEXT, array( 'media-split', 'background-hero', 'spotlight', 'video-grid', 'video-list' ) ),
				'subline'  => array( __( 'Subline', 'videooptimizer' ), Controls_Manager::TEXTAREA, array( 'background-hero' ) ),
				'text'     => array( __( 'Text', 'videooptimizer' ), Controls_Manager::WYSIWYG, array( 'media-split' ) ),
				'intro'    => array( __( 'Intro', 'videooptimizer' ), Controls_Manager::TEXTAREA, array( 'video-grid', 'video-list' ) ),
				'caption'  => array( __( 'Caption', 'videooptimizer' ), Controls_Manager::TEXT, array( 'video', 'spotlight', 'video-chapters', 'video-detail' ) ),
				'ctaLabel' => array( __( 'Button text', 'videooptimizer' ), Controls_Manager::TEXT, array( 'media-split', 'background-hero' ) ),
			) as $name => [ $label, $type, $layouts ]
		) {
			$this->add_control(
				$name,
				array(
					'label'     => $label,
					'type'      => $type,
					'condition' => array( 'layout' => $layouts ),
				)
			);
		}
		$this->add_control(
			'ctaUrl',
			array(
				'label'     => __( 'Button link', 'videooptimizer' ),
				'type'      => Controls_Manager::URL,
				'condition' => array( 'layout' => array( 'media-split', 'background-hero' ) ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_format',
			array(
				'label'     => __( 'Format', 'videooptimizer' ),
				'condition' => array( 'layout' => array( 'video', 'media-split' ) ),
			)
		);
		$this->add_control(
			'format',
			array(
				'label'       => __( 'Format', 'videooptimizer' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''     => __( 'Original (video ratio)', 'videooptimizer' ),
					'16:9' => '16:9',
					'9:16' => '9:16',
					'1:1'  => '1:1',
					'4:5'  => '4:5',
					'21:9' => '21:9',
				),
				'description' => __( 'Shows the same video in another ratio; the video fills the area and is cropped.', 'videooptimizer' ),
			)
		);
		foreach (
			array(
				'focalX' => __( 'Focal point, horizontal (%)', 'videooptimizer' ),
				'focalY' => __( 'Focal point, vertical (%)', 'videooptimizer' ),
			) as $name => $label
		) {
			$this->add_control(
				$name,
				array(
					'label'       => $label,
					'type'        => Controls_Manager::NUMBER,
					'min'         => 0,
					'max'         => 100,
					'default'     => 50,
					'condition'   => array( 'format!' => '' ),
					'description' => 'focalY' === $name ? __( 'Which part stays visible when the video is cropped. Not supported by the VideoOptimizer player (iframe): it always crops from the centre.', 'videooptimizer' ) : '',
				)
			);
		}
		$this->end_controls_section();

		$this->start_controls_section(
			'section_scenes',
			array(
				'label'     => __( 'Scenes', 'videooptimizer' ),
				'condition' => array( 'layout' => 'video-chapters' ),
			)
		);
		$this->add_control(
			'scenes',
			array(
				'label'       => __( 'Number of scenes', 'videooptimizer' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 3,
				'max'         => 10,
				'default'     => 5,
				'description' => __( 'The scenes are the preview frames VideoOptimizer creates for every video, evenly spread over its length.', 'videooptimizer' ),
			)
		);
		$this->add_control(
			'sceneTitles',
			array(
				'label'       => __( 'Scene titles', 'videooptimizer' ),
				'type'        => Controls_Manager::TEXTAREA,
				'description' => __( 'Optional, one per line in the order of the scenes. Without a title a scene is called "Scene 1", "Scene 2" …', 'videooptimizer' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'section_player', array( 'label' => __( 'Player', 'videooptimizer' ) ) );
		$this->add_control(
			'player',
			array(
				'label'   => __( 'Player', 'videooptimizer' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'Default (plugin settings)', 'videooptimizer' ),
					'hosted' => __( 'VideoOptimizer player (iframe)', 'videooptimizer' ),
					'native' => __( 'Native HTML5 player', 'videooptimizer' ),
				),
			)
		);
		$this->add_control(
			'presentation',
			array(
				'label'     => __( 'Presentation', 'videooptimizer' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''         => __( 'Default', 'videooptimizer' ),
					'facade'   => __( 'Poster, plays in place', 'videooptimizer' ),
					'lightbox' => __( 'Poster, plays in a lightbox', 'videooptimizer' ),
					'direct'   => __( 'Player directly', 'videooptimizer' ),
				),
				'condition' => array( 'layout!' => array( 'background-hero', 'video-chapters', 'video-list' ) ),
			)
		);
		foreach (
			array(
				'autoplay' => __( 'Autoplay (muted)', 'videooptimizer' ),
				'muted'    => __( 'Muted', 'videooptimizer' ),
				'loop'     => __( 'Loop', 'videooptimizer' ),
				'controls' => __( 'Controls', 'videooptimizer' ),
			) as $name => $label
		) {
			$this->add_control(
				$name,
				array(
					'label'     => $label,
					'type'      => Controls_Manager::SELECT,
					'default'   => '',
					'options'   => $tri,
					'condition' => array( 'layout!' => array( 'background-hero', 'video-list' ) ),
				)
			);
		}
		$this->add_control(
			'priority',
			array(
				'label'        => __( 'Above the fold (load immediately)', 'videooptimizer' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'scrollPlay',
			array(
				'label'        => __( 'Play while scrolling', 'videooptimizer' ),
				'description'  => __( 'Only for "Player directly": starts muted when the video is half visible and pauses when it leaves. Not for visitors who prefer reduced motion or save data.', 'videooptimizer' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'condition'    => array( 'layout' => array( 'video', 'media-split' ) ),
			)
		);
		$this->add_control(
			'miniPlayer',
			array(
				'label'        => __( 'Floating mini player', 'videooptimizer' ),
				'description'  => __( 'Only for "Player directly": once started, the video keeps playing as a small player in the bottom right when it scrolls out of view (screens from 600 px). Visitors can close it.', 'videooptimizer' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'condition'    => array( 'layout' => array( 'video', 'video-chapters' ) ),
			)
		);
		$this->add_control(
			'side',
			array(
				'label'     => __( 'Video position', 'videooptimizer' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'left',
				'options'   => array(
					'left'  => __( 'Left', 'videooptimizer' ),
					'right' => __( 'Right', 'videooptimizer' ),
				),
				'condition' => array( 'layout' => 'media-split' ),
			)
		);
		$this->add_control(
			'height',
			array(
				'label'     => __( 'Height', 'videooptimizer' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'large',
				'options'   => array(
					'full'   => __( 'Full screen', 'videooptimizer' ),
					'large'  => __( 'Large', 'videooptimizer' ),
					'medium' => __( 'Medium', 'videooptimizer' ),
				),
				'condition' => array( 'layout' => 'background-hero' ),
			)
		);
		$this->add_control(
			'overlay',
			array(
				'label'     => __( 'Overlay', 'videooptimizer' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'gradient',
				'options'   => array(
					'gradient' => __( 'Gradient', 'videooptimizer' ),
					'dark'     => __( 'Dark', 'videooptimizer' ),
					'none'     => __( 'None', 'videooptimizer' ),
				),
				'condition' => array( 'layout' => 'background-hero' ),
			)
		);
		$this->add_control(
			'headlineColor',
			array(
				'label'     => __( 'Headline color', 'videooptimizer' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array( 'layout' => 'background-hero' ),
			)
		);
		$this->add_control(
			'textColor',
			array(
				'label'     => __( 'Text color', 'videooptimizer' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array( 'layout' => 'background-hero' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Libraries for the list control; only fetched while editing (never on page views).
	 *
	 * @return array<string, string>
	 */
	private function library_options(): array {
		$editor = \Elementor\Plugin::$instance->editor ?? null;
		if ( ! $editor || ! $editor->is_edit_mode() ) {
			return array();
		}
		try {
			$options = array();
			foreach ( Plugin::instance()->client->list_libraries() as $library ) {
				if ( is_string( $library['id'] ?? null ) ) {
					$options[ $library['id'] ] = is_string( $library['name'] ?? null ) ? $library['name'] : $library['id'];
				}
			}

			return $options;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Published pages for the detail page control (editor only).
	 *
	 * @return array<string, string>
	 */
	private function page_options(): array {
		$editor = \Elementor\Plugin::$instance->editor ?? null;
		if ( ! $editor || ! $editor->is_edit_mode() ) {
			return array();
		}
		$options = array();
		foreach ( get_pages( array( 'number' => 200 ) ) as $page ) {
			$options[ (string) $page->ID ] = $page->post_title;
		}

		return $options;
	}

	/**
	 * The player markup depends on the video (changes in VideoOptimizer, token state): Elementor's
	 * element cache must not keep it.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Frontend + preview output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$layout   = is_string( $settings['layout'] ?? null ) ? $settings['layout'] : 'video';

		$attrs             = $settings;
		$attrs['priority'] = 'yes' === ( $settings['priority'] ?? '' );
		foreach ( array( 'scrollPlay', 'miniPlayer' ) as $flag ) {
			$attrs[ $flag ] = 'yes' === ( $settings[ $flag ] ?? '' );
		}
		$attrs['ctaUrl'] = is_array( $settings['ctaUrl'] ?? null ) ? (string) ( $settings['ctaUrl']['url'] ?? '' ) : '';
		if ( is_array( $settings['items'] ?? null ) ) {
			$attrs['items'] = array_map(
				static fn ( $item ): array => array(
					'uuid'  => is_array( $item ) ? (string) ( $item['uuid'] ?? '' ) : '',
					'label' => is_array( $item ) ? (string) ( $item['label'] ?? '' ) : '',
				),
				$settings['items']
			);
		}

		$renderer = $this->renderer ?? Plugin::instance()->renderer;
		echo $renderer->render( $layout, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes all output.
	}
}

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
				),
			)
		);
		$this->add_control(
			'uuid',
			array(
				'label'     => __( 'Video', 'videooptimizer' ),
				'type'      => VideoControl::TYPE,
				'condition' => array( 'layout!' => 'video-grid' ),
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
				'condition' => array( 'layout' => 'video-grid' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section( 'section_content', array( 'label' => __( 'Text', 'videooptimizer' ) ) );
		foreach (
			array(
				'eyebrow'  => array( __( 'Eyebrow', 'videooptimizer' ), Controls_Manager::TEXT, array( 'media-split', 'background-hero', 'spotlight' ) ),
				'headline' => array( __( 'Headline', 'videooptimizer' ), Controls_Manager::TEXT, array( 'media-split', 'background-hero', 'spotlight', 'video-grid' ) ),
				'subline'  => array( __( 'Subline', 'videooptimizer' ), Controls_Manager::TEXTAREA, array( 'background-hero' ) ),
				'text'     => array( __( 'Text', 'videooptimizer' ), Controls_Manager::WYSIWYG, array( 'media-split' ) ),
				'intro'    => array( __( 'Intro', 'videooptimizer' ), Controls_Manager::TEXTAREA, array( 'video-grid' ) ),
				'caption'  => array( __( 'Caption', 'videooptimizer' ), Controls_Manager::TEXT, array( 'video', 'spotlight' ) ),
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
				'condition' => array( 'layout!' => 'background-hero' ),
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
					'condition' => array( 'layout!' => 'background-hero' ),
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
	 * Frontend + preview output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$layout   = is_string( $settings['layout'] ?? null ) ? $settings['layout'] : 'video';

		$attrs             = $settings;
		$attrs['priority'] = 'yes' === ( $settings['priority'] ?? '' );
		$attrs['ctaUrl']   = is_array( $settings['ctaUrl'] ?? null ) ? (string) ( $settings['ctaUrl']['url'] ?? '' ) : '';
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

<?php
/**
 * Elementor video picker control.
 *
 * @package VideoOptimizer
 */

namespace ScaleCommerce\VideoOptimizer\Editor\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a video uuid. The editor script (assets/src/elementor) mounts the shared React video
 * picker into the placeholder, so editors pick videos exactly like in the block editor.
 */
class VideoControl extends \Elementor\Base_Data_Control {

	public const TYPE = 'videooptimizer_video';

	/**
	 * Control type.
	 */
	public function get_type() {
		return self::TYPE;
	}

	/**
	 * Default value.
	 */
	public function get_default_value() {
		return '';
	}

	/**
	 * Underscore template rendered in the Elementor panel.
	 */
	public function content_template() {
		$control_uid = $this->get_control_uid();
		?>
		<div class="elementor-control-field videooptimizer-elementor-control">
			<# if ( data.label ) { #>
				<label for="<?php echo esc_attr( $control_uid ); ?>" class="elementor-control-title">{{{ data.label }}}</label>
			<# } #>
			<div class="elementor-control-input-wrapper">
				<input id="<?php echo esc_attr( $control_uid ); ?>" type="hidden" data-setting="{{ data.name }}" />
				<div class="videooptimizer-elementor-picker"></div>
			</div>
		</div>
		<# if ( data.description ) { #>
			<div class="elementor-control-field-description">{{{ data.description }}}</div>
		<# } #>
		<?php
	}
}

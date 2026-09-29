/**
 * Video picker modal. Used by the blocks, the WooCommerce meta box and the Elementor control.
 */
import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import VideoBrowser from './VideoBrowser';

export { useLibraries, NotConfigured } from './VideoBrowser';

export default function VideoPicker( {
	onSelect,
	onClose,
	title = __( 'Choose a video', 'videooptimizer' ),
	selected = '',
} ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="vo-picker"
			size="large"
		>
			<VideoBrowser onSelect={ onSelect } selected={ selected } />
		</Modal>
	);
}

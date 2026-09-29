/**
 * Elementor editor: control view for the "videooptimizer_video" control type. Mounts the shared
 * React video picker into the control and stores the chosen uuid as the control value.
 */
import { createRoot } from '@wordpress/element';
import VideoSelectControl from '../components/VideoSelectControl';
import '../components/components.scss';
import './elementor.scss';

function register() {
	const elementor = window.elementor;
	if ( ! elementor || ! elementor.modules || register.done ) {
		return;
	}
	register.done = true;

	const View = elementor.modules.controls.BaseData.extend( {
		onReady() {
			const holder = this.el.querySelector(
				'.videooptimizer-elementor-picker'
			);
			if ( ! holder ) {
				return;
			}
			this.voRoot = createRoot( holder );
			this.voRender( this.getControlValue() || '' );
		},
		voRender( value ) {
			this.voRoot.render(
				<VideoSelectControl
					compact
					value={ value }
					onChange={ ( uuid ) => {
						this.setValue( uuid || '' );
						this.voRender( uuid || '' );
					} }
				/>
			);
		},
		onBeforeDestroy() {
			if ( this.voRoot ) {
				this.voRoot.unmount();
			}
		},
	} );

	elementor.addControlView( 'videooptimizer_video', View );
}

if ( window.elementor && window.elementor.modules ) {
	register();
}
if ( window.jQuery ) {
	window.jQuery( window ).on( 'elementor:init', register );
}

import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import metadata from './block.json';
import {
	PlayerPanel,
	VideoPanel,
	VideoPlaceholder,
	Preview,
} from '../shared/editor';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const text = ( key, label ) => (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			value={ attributes[ key ] }
			onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
		/>
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<VideoPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
				<PanelBody title={ __( 'Text', 'videooptimizer' ) }>
					{ text( 'eyebrow', __( 'Eyebrow', 'videooptimizer' ) ) }
					{ text( 'headline', __( 'Headline', 'videooptimizer' ) ) }
					{ text( 'caption', __( 'Caption', 'videooptimizer' ) ) }
				</PanelBody>
				<PlayerPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					initialOpen={ false }
				/>
			</InspectorControls>
			{ attributes.uuid ? (
				<Preview block={ metadata.name } attributes={ attributes } />
			) : (
				<VideoPlaceholder
					label={ __( 'VideoOptimizer Spotlight', 'videooptimizer' ) }
					instructions={ __(
						'A centered headline with a large video. Headline and caption are edited in the sidebar.',
						'videooptimizer'
					) }
					onChange={ ( uuid ) => setAttributes( { uuid } ) }
				/>
			) }
		</div>
	);
}

registerBlockType( metadata.name, {
	icon: videoIcon,
	edit: Edit,
	save: () => null,
} );

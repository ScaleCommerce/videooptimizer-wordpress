import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	BlockControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	TextareaControl,
	ToolbarButton,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import metadata from './block.json';
import {
	PlayerPanel,
	VideoPanel,
	VideoPlaceholder,
	Preview,
} from '../shared/editor';
import VideoPicker from '../../components/VideoPicker';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const [ picking, setPicking ] = useState( false );

	if ( ! attributes.uuid ) {
		return (
			<div { ...blockProps }>
				<VideoPlaceholder
					label={ __(
						'VideoOptimizer Video with Chapters',
						'videooptimizer'
					) }
					instructions={ __(
						'Choose a video: its preview frames become scenes that start the video at that point.',
						'videooptimizer'
					) }
					onChange={ ( uuid ) => setAttributes( { uuid } ) }
				/>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<BlockControls group="other">
				<ToolbarButton onClick={ () => setPicking( true ) }>
					{ __( 'Replace', 'videooptimizer' ) }
				</ToolbarButton>
			</BlockControls>
			<InspectorControls>
				<VideoPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
				<PanelBody title={ __( 'Scenes', 'videooptimizer' ) }>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Number of scenes', 'videooptimizer' ) }
						min={ 3 }
						max={ 10 }
						value={ attributes.scenes }
						onChange={ ( value ) =>
							setAttributes( { scenes: value ?? 5 } )
						}
						help={ __(
							'The scenes are the preview frames VideoOptimizer creates for every video, evenly spread over its length.',
							'videooptimizer'
						) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Scene titles', 'videooptimizer' ) }
						help={ __(
							'Optional, one per line in the order of the scenes. Without a title a scene is called "Scene 1", "Scene 2" …',
							'videooptimizer'
						) }
						value={ attributes.sceneTitles }
						onChange={ ( value ) =>
							setAttributes( { sceneTitles: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Text', 'videooptimizer' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Accessible title', 'videooptimizer' ) }
						help={ __(
							'Used for screen readers and search engines. Defaults to the video title.',
							'videooptimizer'
						) }
						value={ attributes.title }
						onChange={ ( title ) => setAttributes( { title } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Caption', 'videooptimizer' ) }
						value={ attributes.caption }
						onChange={ ( caption ) => setAttributes( { caption } ) }
					/>
				</PanelBody>
				<PlayerPanel
					presentation={ false }
					miniPlayer
					attributes={ attributes }
					setAttributes={ setAttributes }
					initialOpen={ false }
				/>
			</InspectorControls>
			<Preview block={ metadata.name } attributes={ attributes } />
			{ picking ? (
				<VideoPicker
					selected={ attributes.uuid }
					onClose={ () => setPicking( false ) }
					onSelect={ ( video ) => {
						setAttributes( { uuid: video.uuid } );
						setPicking( false );
					} }
				/>
			) : null }
		</div>
	);
}

registerBlockType( metadata.name, {
	icon: videoIcon,
	edit: Edit,
	save: () => null,
} );

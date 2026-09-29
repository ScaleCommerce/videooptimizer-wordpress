import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	InnerBlocks,
	useBlockProps,
	useInnerBlocksProps,
	PanelColorSettings,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import metadata from './block.json';
import { VideoPanel, VideoPlaceholder } from '../shared/editor';
import { useVideo } from '../../components/VideoSelectControl';
import { posterPreview } from '../../components/api';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

const TEMPLATE = [
	[
		'core/heading',
		{ level: 1, placeholder: __( 'Big headline', 'videooptimizer' ) },
	],
	[
		'core/paragraph',
		{ placeholder: __( 'A short subline…', 'videooptimizer' ) },
	],
	[
		'core/buttons',
		{},
		[
			[
				'core/button',
				{ placeholder: __( 'Call to action', 'videooptimizer' ) },
			],
		],
	],
];

function Edit( { attributes, setAttributes } ) {
	const { uuid, height, overlay, headlineColor, textColor, priority } =
		attributes;
	const { video } = useVideo( uuid );
	const poster = video ? posterPreview( video ) : null;

	const style = {};
	if ( poster ) {
		style.backgroundImage = `url(${ poster })`;
	}
	if ( headlineColor ) {
		style[ '--vo-hero-heading' ] = headlineColor;
	}
	if ( textColor ) {
		style[ '--vo-hero-text' ] = textColor;
	}

	const blockProps = useBlockProps( {
		className: `vo-editor-hero vo-editor-hero--${ height } vo-editor-hero--overlay-${ overlay }`,
		style,
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'vo-editor-hero__content' },
		{ template: TEMPLATE, templateLock: false }
	);

	return (
		<>
			<InspectorControls>
				<VideoPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					title={ __( 'Background video', 'videooptimizer' ) }
				/>
				<PanelBody title={ __( 'Layout', 'videooptimizer' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Height', 'videooptimizer' ) }
						value={ height }
						onChange={ ( value ) =>
							setAttributes( { height: value } )
						}
						options={ [
							{
								label: __( 'Full screen', 'videooptimizer' ),
								value: 'full',
							},
							{
								label: __( 'Large', 'videooptimizer' ),
								value: 'large',
							},
							{
								label: __( 'Medium', 'videooptimizer' ),
								value: 'medium',
							},
						] }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Overlay', 'videooptimizer' ) }
						value={ overlay }
						onChange={ ( value ) =>
							setAttributes( { overlay: value } )
						}
						options={ [
							{
								label: __( 'Gradient', 'videooptimizer' ),
								value: 'gradient',
							},
							{
								label: __( 'Dark', 'videooptimizer' ),
								value: 'dark',
							},
							{
								label: __( 'None', 'videooptimizer' ),
								value: 'none',
							},
						] }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Above the fold', 'videooptimizer' ) }
						help={ __(
							'Loads the video immediately. Recommended when the hero is the first block.',
							'videooptimizer'
						) }
						checked={ !! priority }
						onChange={ ( value ) =>
							setAttributes( { priority: value } )
						}
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Colors', 'videooptimizer' ) }
					initialOpen={ false }
					colorSettings={ [
						{
							label: __( 'Headline', 'videooptimizer' ),
							value: headlineColor,
							onChange: ( value ) =>
								setAttributes( { headlineColor: value || '' } ),
						},
						{
							label: __( 'Text', 'videooptimizer' ),
							value: textColor,
							onChange: ( value ) =>
								setAttributes( { textColor: value || '' } ),
						},
					] }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				{ uuid ? (
					<span className="vo-editor-hero__badge">
						{ __(
							'Background video plays on the website',
							'videooptimizer'
						) }
					</span>
				) : (
					<VideoPlaceholder
						label={ __( 'Background video', 'videooptimizer' ) }
						instructions={ __(
							'Short, muted clips without important audio work best.',
							'videooptimizer'
						) }
						onChange={ ( value ) =>
							setAttributes( { uuid: value } )
						}
					/>
				) }
				<div { ...innerBlocksProps } />
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	icon: videoIcon,
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );

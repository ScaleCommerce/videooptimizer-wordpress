import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	InnerBlocks,
	useBlockProps,
	useInnerBlocksProps,
	BlockControls,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToolbarButton } from '@wordpress/components';
import metadata from './block.json';
import {
	PlayerPanel,
	VideoPanel,
	VideoPlaceholder,
	Preview,
} from '../shared/editor';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

const TEMPLATE = [
	[
		'core/heading',
		{ level: 2, placeholder: __( 'Headline', 'videooptimizer' ) },
	],
	[
		'core/paragraph',
		{ placeholder: __( 'Tell your story…', 'videooptimizer' ) },
	],
	[
		'core/buttons',
		{},
		[
			[
				'core/button',
				{ placeholder: __( 'Button', 'videooptimizer' ) },
			],
		],
	],
];

function Edit( { attributes, setAttributes } ) {
	const { uuid, side } = attributes;
	const blockProps = useBlockProps( { className: 'vo-blocks' } );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'vo-editor-split__body' },
		{ template: TEMPLATE, templateLock: false }
	);

	return (
		<div { ...blockProps }>
			<BlockControls>
				<ToolbarButton
					icon={
						side === 'right'
							? 'align-pull-right'
							: 'align-pull-left'
					}
					label={ __( 'Switch video side', 'videooptimizer' ) }
					onClick={ () =>
						setAttributes( {
							side: side === 'right' ? 'left' : 'right',
						} )
					}
				/>
			</BlockControls>
			<InspectorControls>
				<VideoPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
				<PanelBody title={ __( 'Layout', 'videooptimizer' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Video position', 'videooptimizer' ) }
						value={ side }
						onChange={ ( value ) =>
							setAttributes( { side: value } )
						}
						options={ [
							{
								label: __( 'Left', 'videooptimizer' ),
								value: 'left',
							},
							{
								label: __( 'Right', 'videooptimizer' ),
								value: 'right',
							},
						] }
					/>
				</PanelBody>
				<PlayerPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					initialOpen={ false }
				/>
			</InspectorControls>
			<div className={ `vo-editor-split vo-editor-split--${ side }` }>
				<div className="vo-editor-split__media">
					{ uuid ? (
						<Preview
							block="videooptimizer/video"
							attributes={ {
								uuid,
								player: attributes.player,
								presentation:
									attributes.presentation || 'facade',
							} }
						/>
					) : (
						<VideoPlaceholder
							label={ __( 'Video', 'videooptimizer' ) }
							onChange={ ( value ) =>
								setAttributes( { uuid: value } )
							}
						/>
					) }
				</div>
				<div { ...innerBlocksProps } />
			</div>
		</div>
	);
}

registerBlockType( metadata.name, {
	icon: videoIcon,
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );

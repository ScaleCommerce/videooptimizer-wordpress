import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import metadata from './block.json';
import { LibrarySelect, PageSelect, Preview } from '../shared/editor';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const {
		libraryId,
		tag,
		perPage,
		detailPage,
		headline,
		intro,
		columns,
		priority,
	} = attributes;

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Videos', 'videooptimizer' ) }>
					<LibrarySelect
						value={ libraryId }
						onChange={ ( value ) =>
							setAttributes( { libraryId: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Tags', 'videooptimizer' ) }
						help={ __(
							'Optional: only videos with all of these tags, separated by commas.',
							'videooptimizer'
						) }
						value={ tag }
						onChange={ ( value ) =>
							setAttributes( { tag: value } )
						}
					/>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Videos per page', 'videooptimizer' ) }
						min={ 1 }
						max={ 48 }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value ?? 12 } )
						}
					/>
					<PageSelect
						label={ __( 'Detail page', 'videooptimizer' ) }
						help={ __(
							'The page with a "VideoOptimizer Video Detail" block; a card opens it with the video in the URL. Without one, cards open the video in a lightbox.',
							'videooptimizer'
						) }
						value={ detailPage }
						onChange={ ( value ) =>
							setAttributes( { detailPage: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Text & layout', 'videooptimizer' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Headline', 'videooptimizer' ) }
						value={ headline }
						onChange={ ( value ) =>
							setAttributes( { headline: value } )
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Intro', 'videooptimizer' ) }
						value={ intro }
						onChange={ ( value ) =>
							setAttributes( { intro: value } )
						}
					/>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Columns', 'videooptimizer' ) }
						min={ 1 }
						max={ 4 }
						value={ columns }
						onChange={ ( value ) =>
							setAttributes( { columns: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Above the fold', 'videooptimizer' ) }
						help={ __(
							'Loads the poster of the first card with high priority.',
							'videooptimizer'
						) }
						checked={ !! priority }
						onChange={ ( value ) =>
							setAttributes( { priority: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Preview block={ metadata.name } attributes={ attributes } />
		</div>
	);
}

registerBlockType( metadata.name, {
	icon: videoIcon,
	edit: Edit,
	save: () => null,
} );

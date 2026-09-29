import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	RangeControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import metadata from './block.json';
import { PlayerPanel, Preview, usePluginStatus } from '../shared/editor';
import VideoSelectControl from '../../components/VideoSelectControl';
import VideoPicker from '../../components/VideoPicker';
import { videoTitle } from '../../components/api';
import { videoIcon } from '../shared/icon';
import '../shared/editor.scss';

function Edit( { attributes, setAttributes } ) {
	const { items = [], headline, intro, columns } = attributes;
	const blockProps = useBlockProps();
	const [ adding, setAdding ] = useState( false );
	const status = usePluginStatus();

	const update = ( next ) => setAttributes( { items: next } );
	const move = ( index, delta ) => {
		const next = [ ...items ];
		const [ item ] = next.splice( index, 1 );
		next.splice( index + delta, 0, item );
		update( next );
	};

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Videos', 'videooptimizer' ) }>
					<div className="vo-grid-items-editor">
						{ items.map( ( item, index ) => (
							<div
								className="vo-grid-items-editor__item"
								key={ item.uuid + index }
							>
								<VideoSelectControl
									compact
									value={ item.uuid }
									onChange={ ( uuid ) =>
										uuid
											? update(
													items.map( ( it, i ) =>
														i === index
															? { ...it, uuid }
															: it
													)
											  )
											: update(
													items.filter(
														( _it, i ) =>
															i !== index
													)
											  )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Label', 'videooptimizer' ) }
									value={ item.label || '' }
									onChange={ ( label ) =>
										update(
											items.map( ( it, i ) =>
												i === index
													? { ...it, label }
													: it
											)
										)
									}
								/>
								<div className="vo-grid-items-editor__row">
									<Button
										size="small"
										icon="arrow-up-alt2"
										label={ __(
											'Move up',
											'videooptimizer'
										) }
										disabled={ index === 0 }
										onClick={ () => move( index, -1 ) }
									/>
									<Button
										size="small"
										icon="arrow-down-alt2"
										label={ __(
											'Move down',
											'videooptimizer'
										) }
										disabled={ index === items.length - 1 }
										onClick={ () => move( index, 1 ) }
									/>
									<Button
										size="small"
										icon="trash"
										isDestructive
										label={ __(
											'Remove',
											'videooptimizer'
										) }
										onClick={ () =>
											update(
												items.filter(
													( _it, i ) => i !== index
												)
											)
										}
									/>
								</div>
							</div>
						) ) }
						<Button
							variant="secondary"
							onClick={ () => setAdding( true ) }
						>
							{ __( 'Add video', 'videooptimizer' ) }
						</Button>
					</div>
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
				</PanelBody>
				<PlayerPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					initialOpen={ false }
				/>
			</InspectorControls>

			{ items.length ? (
				<Preview block={ metadata.name } attributes={ attributes } />
			) : (
				<div className="components-placeholder vo-block-placeholder">
					<div className="components-placeholder__label">
						{ videoIcon }{ ' ' }
						{ __( 'VideoOptimizer Video Grid', 'videooptimizer' ) }
					</div>
					<div className="components-placeholder__instructions">
						{ __(
							'Add a few videos — visitors open them in a lightbox.',
							'videooptimizer'
						) }
					</div>
					{ status && status.configured ? (
						<Button
							variant="primary"
							onClick={ () => setAdding( true ) }
						>
							{ __( 'Add video', 'videooptimizer' ) }
						</Button>
					) : null }
				</div>
			) }

			{ adding ? (
				<VideoPicker
					title={ __( 'Add a video to the grid', 'videooptimizer' ) }
					onClose={ () => setAdding( false ) }
					onSelect={ ( video ) => {
						update( [
							...items,
							{ uuid: video.uuid, label: videoTitle( video ) },
						] );
						setAdding( false );
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

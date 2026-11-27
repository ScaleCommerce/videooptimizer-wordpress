/**
 * Tags and metadata cards of the video detail page.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormTokenField,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../../components/api';
import {
	MAX_METADATA_KEYS,
	MAX_METADATA_VALUE,
	MAX_TAGS,
	MAX_TAG_LENGTH,
	isValidMetadataKey,
	metadataPatch,
	metadataRows,
	normalizeTags,
} from '../../components/video-fields';
import { resetTags } from '../../components/VideoBrowser';
import { useNotify } from '../notices';

export function TagsCard( { video, onSaved } ) {
	const stored = video.tags || [];
	const [ tags, setTags ] = useState( stored );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const notify = useNotify();

	useEffect( () => setTags( video.tags || [] ), [ video.tags ] );
	useEffect( () => {
		api.tags().then(
			( list ) => setSuggestions( list.map( ( t ) => t.name ) ),
			() => {}
		);
	}, [] );

	const changed = normalizeTags( tags ).join( '\n' ) !== stored.join( '\n' );

	const save = () => {
		setSaving( true );
		api.updateVideo( video.uuid, { tags: normalizeTags( tags ) } )
			.then( ( v ) => {
				resetTags();
				onSaved( v );
				notify( __( 'Tags saved.', 'videooptimizer' ) );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Tags', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<FormTokenField
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Tags', 'videooptimizer' ) }
					value={ tags }
					suggestions={ suggestions }
					maxLength={ MAX_TAGS }
					onChange={ ( next ) => setTags( normalizeTags( next ) ) }
					tokenizeOnBlur
					__experimentalShowHowTo={ false }
				/>
				<p className="description">
					{ sprintf(
						/* translators: 1: max tags, 2: max characters */
						__(
							'Up to %1$d tags of %2$d characters, shared by all libraries. Use them to find videos in the list.',
							'videooptimizer'
						),
						MAX_TAGS,
						MAX_TAG_LENGTH
					) }
				</p>
				<Button
					variant="secondary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving || ! changed }
				>
					{ __( 'Save tags', 'videooptimizer' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

export function MetadataCard( { video, onSaved } ) {
	const [ rows, setRows ] = useState( metadataRows( video.metadata ) );
	const [ saving, setSaving ] = useState( false );
	const notify = useNotify();

	useEffect(
		() => setRows( metadataRows( video.metadata ) ),
		[ video.metadata ]
	);

	const setRow = ( index, patch ) =>
		setRows( ( list ) =>
			list.map( ( row, i ) =>
				i === index ? { ...row, ...patch } : row
			)
		);

	const keys = rows.map( ( r ) => r.key.trim() ).filter( Boolean );
	const keyError = ( row ) => {
		const key = row.key.trim();
		if ( ! key ) {
			return row.value.trim()
				? __( 'Enter a key.', 'videooptimizer' )
				: '';
		}
		if ( ! isValidMetadataKey( key ) ) {
			return __(
				'1–40 characters: a–z, 0–9, _ . - (lower case).',
				'videooptimizer'
			);
		}
		return keys.filter( ( k ) => k === key ).length > 1
			? __( 'Duplicate key.', 'videooptimizer' )
			: '';
	};
	const valueError = ( row ) =>
		row.value.trim().length > MAX_METADATA_VALUE
			? __( 'At most 64 characters.', 'videooptimizer' )
			: '';

	const patch = metadataPatch( video.metadata, rows );
	const invalid = rows.some( ( r ) => keyError( r ) || valueError( r ) );

	const save = () => {
		setSaving( true );
		api.updateVideo( video.uuid, { metadata: patch } )
			.then( ( v ) => {
				onSaved( v );
				notify( __( 'Metadata saved.', 'videooptimizer' ) );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Metadata', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Your own key/value data on the video, for example the ID of a CMS entry. Plugins and integrations can find videos by it.',
						'videooptimizer'
					) }
				</p>
				{ rows.map( ( row, index ) => (
					<div className="vo-meta__row" key={ index }>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Key', 'videooptimizer' ) }
							value={ row.key }
							onChange={ ( key ) => setRow( index, { key } ) }
							help={ keyError( row ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Value', 'videooptimizer' ) }
							value={ row.value }
							onChange={ ( value ) => setRow( index, { value } ) }
							help={ valueError( row ) }
						/>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setRows( ( list ) =>
									list.filter( ( r, i ) => i !== index )
								)
							}
						>
							{ __( 'Remove', 'videooptimizer' ) }
						</Button>
					</div>
				) ) }
				<div className="vo-meta__actions">
					<Button
						variant="tertiary"
						onClick={ () =>
							setRows( ( list ) => [
								...list,
								{ key: '', value: '' },
							] )
						}
						disabled={ rows.length >= MAX_METADATA_KEYS }
					>
						{ __( 'Add field', 'videooptimizer' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving || ! patch || invalid }
					>
						{ __( 'Save metadata', 'videooptimizer' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

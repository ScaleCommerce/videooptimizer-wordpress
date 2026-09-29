import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api, formatBytes } from '../../components/api';
import { resetLibraries } from '../../components/VideoBrowser';
import { ConfirmModal } from '../../components/ui';
import { useNotify } from '../notices';

const csv = ( value ) =>
	String( value || '' )
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( Boolean );

/**
 * An option can be enabled when it is on the library allowlist (available_*), or — when that list
 * is empty — when the organization has it available. Options already on the library stay.
 *
 * @param {Object} option  Encoding option {key, available}.
 * @param {Object} library Library.
 * @param {string} kind    'codecs' | 'resolutions'.
 * @return {boolean} Whether the option may be enabled.
 */
function selectable( option, library, kind ) {
	const allow = library[ `available_${ kind }` ] || [];
	if ( allow.length ) {
		return allow.includes( option.key );
	}
	return option.available !== false;
}

function Ladder( { library, encodings, onSaved } ) {
	const [ codecs, setCodecs ] = useState( csv( library.codec ) );
	const [ resolutions, setResolutions ] = useState(
		csv( library.resolutions )
	);
	const [ saving, setSaving ] = useState( false );
	const [ changed, setChanged ] = useState( false );
	const [ offerReprocess, setOfferReprocess ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const notify = useNotify();
	const readOnly = library.media_managed === false;

	const toggle = ( list, setList, key, on ) => {
		setList( on ? [ ...list, key ] : list.filter( ( k ) => k !== key ) );
		setChanged( true );
	};

	// Keep the API's order when joining.
	const ordered = ( keys, options ) =>
		options
			.map( ( o ) => o.key )
			.filter( ( k ) => keys.includes( k ) )
			.join( ',' );

	const reprocess = () =>
		api.reprocessLibrary( library.id ).then( ( r ) => {
			notify(
				sprintf(
					/* translators: %d: number of videos */
					_n(
						'Encoding settings saved — %d video is being re-encoded in the background.',
						'Encoding settings saved — %d videos are being re-encoded in the background.',
						( r && r.queued ) || 0,
						'videooptimizer'
					),
					( r && r.queued ) || 0
				)
			);
			setOfferReprocess( false );
		} );

	/**
	 * Saves the ladder; existing videos only get the new renditions when they are re-encoded.
	 *
	 * @param {boolean} reencode Re-encode the existing videos right away.
	 */
	const save = ( reencode ) => {
		setConfirming( false );
		setSaving( true );
		api.updateLibrary( library.id, {
			codec: ordered( codecs, encodings.codecs ),
			resolutions: ordered( resolutions, encodings.resolutions ),
		} )
			.then( ( updated ) => {
				setChanged( false );
				onSaved( updated );
				if ( reencode ) {
					return reprocess();
				}
				notify( __( 'Encoding settings saved.', 'videooptimizer' ) );
				setOfferReprocess( library.video_count > 0 );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	const group = ( kind, list, setList, options ) => (
		<fieldset className="vo-ladder__group">
			<legend>
				{ kind === 'codecs'
					? __( 'Codecs', 'videooptimizer' )
					: __( 'Resolutions', 'videooptimizer' ) }
			</legend>
			{ options.map( ( option ) => {
				const on = list.includes( option.key );
				const can = on || selectable( option, library, kind );
				return (
					<div className="vo-ladder__option" key={ option.key }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ option.label || option.key }
							checked={ on }
							disabled={
								readOnly || ! can || ( on && list.length === 1 )
							}
							onChange={ ( value ) =>
								toggle( list, setList, option.key, value )
							}
						/>
						{ option.access === 'addon' ? (
							<span className="vo-badge">
								{ __( 'Add-on', 'videooptimizer' ) }
							</span>
						) : null }
					</div>
				);
			} ) }
		</fieldset>
	);

	return (
		<div className="vo-ladder">
			{ readOnly ? (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This library is delivery-only (managed outside VideoOptimizer media) — encoding settings are read-only and uploads go to other libraries.',
						'videooptimizer'
					) }
				</Notice>
			) : null }
			<div className="vo-ladder__groups">
				{ group( 'codecs', codecs, setCodecs, encodings.codecs ) }
				{ group(
					'resolutions',
					resolutions,
					setResolutions,
					encodings.resolutions
				) }
			</div>
			{ ! readOnly ? (
				<Button
					variant="primary"
					onClick={ () =>
						library.video_count > 0
							? setConfirming( true )
							: save( false )
					}
					isBusy={ saving }
					disabled={ ! changed || saving }
				>
					{ __( 'Save encoding settings', 'videooptimizer' ) }
				</Button>
			) : null }
			{ offerReprocess ? (
				<Notice
					status="info"
					onRemove={ () => setOfferReprocess( false ) }
				>
					{ __(
						'New uploads use the new settings. Re-encode the existing videos of this library too?',
						'videooptimizer'
					) }{ ' ' }
					<Button
						variant="link"
						onClick={ () =>
							reprocess().catch( ( e ) =>
								notify( e.message, 'error' )
							)
						}
					>
						{ __( 'Re-encode now', 'videooptimizer' ) }
					</Button>
				</Notice>
			) : null }
			{ confirming ? (
				<Modal
					title={ __(
						'Re-encode existing videos?',
						'videooptimizer'
					) }
					onRequestClose={ () => setConfirming( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %d: number of videos */
							_n(
								'The new settings apply to new uploads right away. %d existing video of this library only gets the new codecs and resolutions when it is re-encoded. This runs in the background and can take a while.',
								'The new settings apply to new uploads right away. The %d existing videos of this library only get the new codecs and resolutions when they are re-encoded. This runs in the background and can take a while, depending on the number and length of the videos.',
								library.video_count,
								'videooptimizer'
							),
							library.video_count
						) }
					</p>
					<div className="vo-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( false ) }
						>
							{ __( 'Cancel', 'videooptimizer' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => save( false ) }
						>
							{ __( 'Only save', 'videooptimizer' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => save( true ) }
						>
							{ sprintf(
								/* translators: %d: number of videos */
								_n(
									'Save & re-encode %d video',
									'Save & re-encode %d videos',
									library.video_count,
									'videooptimizer'
								),
								library.video_count
							) }
						</Button>
					</div>
				</Modal>
			) : null }
		</div>
	);
}

function LibraryModal( { library, onClose, onSaved } ) {
	const [ name, setName ] = useState( library ? library.name : '' );
	const [ description, setDescription ] = useState(
		( library && library.description ) || ''
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = () => {
		setBusy( true );
		setError( '' );
		const request = library
			? api.updateLibrary( library.id, { name, description } )
			: api.createLibrary( { name, description } );
		request
			.then( onSaved )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			title={
				library
					? __( 'Edit library', 'videooptimizer' )
					: __( 'New library', 'videooptimizer' )
			}
			onRequestClose={ onClose }
		>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Name', 'videooptimizer' ) }
				value={ name }
				onChange={ setName }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Description', 'videooptimizer' ) }
				value={ description }
				onChange={ setDescription }
			/>
			<div className="vo-modal-actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'videooptimizer' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy || ! name.trim() }
				>
					{ library
						? __( 'Save', 'videooptimizer' )
						: __( 'Create library', 'videooptimizer' ) }
				</Button>
			</div>
		</Modal>
	);
}

export default function LibrariesPage() {
	const [ libraries, setLibraries ] = useState( null );
	const [ encodings, setEncodings ] = useState( {
		codecs: [],
		resolutions: [],
	} );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ open, setOpen ] = useState( '' );
	const notify = useNotify();

	const load = () => {
		resetLibraries();
		return api.libraries().then( setLibraries, ( e ) => {
			setError( e );
			setLibraries( [] );
		} );
	};

	useEffect( () => {
		load();
		api.encodings().then(
			( e ) =>
				setEncodings( {
					codecs: e.codecs || [],
					resolutions: e.resolutions || [],
				} ),
			() => {}
		);
	}, [] );

	const remove = () => {
		setBusy( true );
		api.deleteLibrary( deleting.id )
			.then( () => {
				notify( __( 'Library deleted.', 'videooptimizer' ) );
				setDeleting( null );
				load();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	if ( libraries === null ) {
		return <Spinner />;
	}

	return (
		<div className="vo-libraries">
			<div className="vo-libraries__head">
				<p className="vo-admin__intro">
					{ __(
						'Libraries group your videos and define how they are encoded (codecs and resolutions). New uploads go to the default library from the settings.',
						'videooptimizer'
					) }
				</p>
				<Button variant="primary" onClick={ () => setEditing( 'new' ) }>
					{ __( 'New library', 'videooptimizer' ) }
				</Button>
			</div>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) : null }
			{ libraries.map( ( library ) => (
				<Card key={ library.id } className="vo-library">
					<CardHeader>
						<div>
							<h2>{ library.name }</h2>
							{ library.description ? (
								<p className="description">
									{ library.description }
								</p>
							) : null }
						</div>
						<div className="vo-library__stats">
							<span>
								{ sprintf(
									/* translators: %d: number of videos */ __(
										'%d videos',
										'videooptimizer'
									),
									library.video_count || 0
								) }
							</span>
							<span>
								{ formatBytes( library.storage_usage ) }
							</span>
						</div>
					</CardHeader>
					<CardBody>
						<div className="vo-library__actions">
							<Button
								variant="secondary"
								onClick={ () =>
									setOpen(
										open === library.id ? '' : library.id
									)
								}
								aria-expanded={ open === library.id }
							>
								{ __( 'Encoding settings', 'videooptimizer' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setEditing( library ) }
							>
								{ __( 'Rename', 'videooptimizer' ) }
							</Button>
							<Button
								variant="tertiary"
								href={
									'#/library/' +
									encodeURIComponent( library.id )
								}
							>
								{ __( 'Show videos', 'videooptimizer' ) }
							</Button>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => setDeleting( library ) }
							>
								{ __( 'Delete', 'videooptimizer' ) }
							</Button>
						</div>
						{ open === library.id ? (
							<Ladder
								library={ library }
								encodings={ encodings }
								onSaved={ ( updated ) =>
									setLibraries(
										libraries.map( ( l ) =>
											l.id === library.id
												? { ...l, ...updated }
												: l
										)
									)
								}
							/>
						) : null }
					</CardBody>
				</Card>
			) ) }

			{ editing ? (
				<LibraryModal
					library={ editing === 'new' ? null : editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						notify( __( 'Library saved.', 'videooptimizer' ) );
						setEditing( null );
						load();
					} }
				/>
			) : null }

			{ deleting ? (
				<ConfirmModal
					title={ sprintf(
						/* translators: %s: library name */ __(
							'Delete "%s"?',
							'videooptimizer'
						),
						deleting.name
					) }
					confirmLabel={ __(
						'Delete library and all its videos',
						'videooptimizer'
					) }
					onConfirm={ remove }
					onCancel={ () => setDeleting( null ) }
					busy={ busy }
				>
					<p>
						{ sprintf(
							/* translators: %d: number of videos */
							__(
								'All %d videos of this library are deleted permanently and disappear from every page that embeds them.',
								'videooptimizer'
							),
							deleting.video_count || 0
						) }
					</p>
				</ConfirmModal>
			) : null }
		</div>
	);
}

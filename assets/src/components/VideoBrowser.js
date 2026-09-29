/**
 * Video browser: browse / search / filter all videos, upload new ones (drag & drop, multiple) or
 * import by URL. Used inside the picker modal and on the admin "Videos" page.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	FormFileUpload,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
	DropZone,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { api, loadAllVideos, primeVideoCache } from './api';
import { uploadVideo, pollVideo, createSignal } from './uploader';
import { ProgressBar, VideoCard } from './ui';

let libraryPromise = null;
const loadLibraries = () => {
	if ( ! libraryPromise ) {
		libraryPromise = api.libraries().catch( ( error ) => {
			libraryPromise = null;
			throw error;
		} );
	}
	return libraryPromise;
};

/** Drops the cached library list (after creating/editing libraries). */
export function resetLibraries() {
	libraryPromise = null;
}

export function useLibraries() {
	const [ state, setState ] = useState( { libraries: null, error: null } );
	useEffect( () => {
		let live = true;
		loadLibraries().then(
			( libraries ) => live && setState( { libraries, error: null } ),
			( error ) => live && setState( { libraries: [], error } )
		);
		return () => {
			live = false;
		};
	}, [] );
	return state;
}

export function NotConfigured( { error } ) {
	const status = error && error.upstreamStatus;
	const settingsUrl =
		window.videooptimizerSettingsUrl ||
		'/wp-admin/admin.php?page=videooptimizer#/settings';
	if ( status === 428 ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'VideoOptimizer is not connected yet.',
					'videooptimizer'
				) }{ ' ' }
				<a href={ settingsUrl }>
					{ __( 'Enter your API token', 'videooptimizer' ) }
				</a>
			</Notice>
		);
	}
	return (
		<Notice status="error" isDismissible={ false }>
			{ error ? error.message : '' }
		</Notice>
	);
}

function uploadLabel( upload ) {
	if ( upload.status === 'processing' ) {
		return __( 'Uploaded – processing…', 'videooptimizer' );
	}
	if ( upload.status === 'ready' ) {
		return __( 'Ready', 'videooptimizer' );
	}
	return upload.error || __( 'Failed', 'videooptimizer' );
}

export default function VideoBrowser( {
	onSelect,
	selected = '',
	initialLibrary = '',
} ) {
	const { libraries, error: libError } = useLibraries();
	const [ libraryId, setLibraryId ] = useState( initialLibrary );
	const [ videos, setVideos ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ readyOnly, setReadyOnly ] = useState( false );
	const [ uploads, setUploads ] = useState( [] );
	const [ showUrl, setShowUrl ] = useState( false );
	const [ url, setUrl ] = useState( '' );
	const [ urlBusy, setUrlBusy ] = useState( false );
	const signals = useRef( [] );

	const uploadTarget = useMemo( () => {
		if ( ! libraries ) {
			return '';
		}
		const managed = libraries.filter( ( l ) => l.media_managed !== false );
		if ( libraryId && managed.some( ( l ) => l.id === libraryId ) ) {
			return libraryId;
		}
		return managed.length ? managed[ 0 ].id : '';
	}, [ libraries, libraryId ] );

	const reload = () => {
		setError( null );
		loadAllVideos( {
			libraryId,
			onPage: ( items ) => setVideos( items ),
		} ).catch( ( e ) => {
			setError( e );
			setVideos( [] );
		} );
	};

	useEffect( () => {
		setVideos( null );
		reload();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ libraryId ] );

	useEffect( () => () => signals.current.forEach( ( s ) => s.abort() ), [] );

	// Refresh while videos are still processing (e.g. uploaded elsewhere or just now).
	const processing = ( videos || [] ).some(
		( v ) => v.status === 'processing'
	);
	useEffect( () => {
		if ( ! processing ) {
			return undefined;
		}
		const timer = setInterval( reload, 10000 );
		return () => clearInterval( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ processing, libraryId ] );

	const filtered = useMemo( () => {
		const q = search.trim().toLowerCase();
		return ( videos || [] ).filter(
			( v ) =>
				( ! readyOnly || v.status === 'ready' ) &&
				( ! q ||
					( v.title || '' ).toLowerCase().includes( q ) ||
					( v.uuid || '' ).startsWith( q ) )
		);
	}, [ videos, search, readyOnly ] );

	const updateUpload = ( id, patch ) =>
		setUploads( ( list ) =>
			list.map( ( u ) => ( u.id === id ? { ...u, ...patch } : u ) )
		);

	const startUploads = ( files ) => {
		Array.from( files || [] ).forEach( ( file ) => {
			const id = Math.random().toString( 36 ).slice( 2 );
			const signal = createSignal();
			signals.current.push( signal );
			setUploads( ( list ) => [
				...list,
				{ id, name: file.name, progress: 0, status: 'uploading' },
			] );
			uploadVideo( file, {
				libraryId: uploadTarget,
				signal,
				onProgress: ( progress ) => updateUpload( id, { progress } ),
			} )
				.then( ( { uuid } ) => {
					updateUpload( id, { status: 'processing', uuid } );
					reload();
					return pollVideo( uuid, {
						signal,
						onTick: ( video ) =>
							setVideos( ( list ) =>
								( list || [] ).map( ( v ) =>
									v.uuid === uuid ? video : v
								)
							),
					} );
				} )
				.then( ( video ) => {
					if ( video ) {
						updateUpload( id, { status: video.status } );
					}
				} )
				.catch( ( e ) =>
					updateUpload( id, { status: 'failed', error: e.message } )
				);
		} );
	};

	const importUrl = () => {
		setUrlBusy( true );
		api.ingest( { library_id: uploadTarget, source_url: url } )
			.then( () => {
				setUrl( '' );
				setShowUrl( false );
				reload();
			} )
			.catch( ( e ) => setError( e ) )
			.finally( () => setUrlBusy( false ) );
	};

	const choose = ( video ) => {
		primeVideoCache( video );
		onSelect( video );
	};

	const configError = libError || error;

	return (
		<div className="vo-browser">
			{ configError ? <NotConfigured error={ configError } /> : null }

			<div className="vo-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Library', 'videooptimizer' ) }
					hideLabelFromVision
					value={ libraryId }
					onChange={ setLibraryId }
					options={ [
						{
							label: __( 'All libraries', 'videooptimizer' ),
							value: '',
						},
						...( libraries || [] ).map( ( l ) => ( {
							label: l.name,
							value: l.id,
						} ) ),
					] }
				/>
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					placeholder={ __( 'Search videos', 'videooptimizer' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Ready only', 'videooptimizer' ) }
					checked={ readyOnly }
					onChange={ setReadyOnly }
				/>
				<div className="vo-toolbar__spacer" />
				{ uploadTarget ? (
					<>
						<FormFileUpload
							accept="video/*"
							multiple
							onChange={ ( e ) => startUploads( e.target.files ) }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="primary"
									icon="upload"
									onClick={ openFileDialog }
								>
									{ __( 'Upload', 'videooptimizer' ) }
								</Button>
							) }
						/>
						<Button
							variant="secondary"
							icon="admin-links"
							onClick={ () => setShowUrl( ! showUrl ) }
						>
							{ __( 'Import URL', 'videooptimizer' ) }
						</Button>
					</>
				) : null }
			</div>

			{ showUrl ? (
				<div className="vo-url-import">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="url"
						label={ __(
							'Public https:// URL of a video file',
							'videooptimizer'
						) }
						value={ url }
						onChange={ setUrl }
						placeholder="https://…/video.mp4"
					/>
					<Button
						variant="primary"
						onClick={ importUrl }
						isBusy={ urlBusy }
						disabled={ urlBusy || ! /^https:\/\/.+/i.test( url ) }
					>
						{ __( 'Import', 'videooptimizer' ) }
					</Button>
				</div>
			) : null }

			{ uploads.length ? (
				<ul className="vo-uploads">
					{ uploads.map( ( u ) => (
						<li key={ u.id }>
							<span className="vo-uploads__name">{ u.name }</span>
							{ u.status === 'uploading' ? (
								<ProgressBar value={ u.progress } />
							) : (
								<span
									className={ `vo-badge vo-badge--${ u.status }` }
								>
									{ uploadLabel( u ) }
								</span>
							) }
						</li>
					) ) }
				</ul>
			) : null }

			<div className="vo-browser__grid-wrap">
				{ uploadTarget ? (
					<DropZone onFilesDrop={ startUploads } />
				) : null }
				{ videos === null ? (
					<div className="vo-center">
						<Spinner />
					</div>
				) : null }
				{ videos !== null && ! filtered.length && ! configError ? (
					<p className="vo-empty">
						{ videos.length
							? __(
									'No videos match your filter.',
									'videooptimizer'
							  )
							: __(
									'No videos yet. Upload your first video or drop a file here.',
									'videooptimizer'
							  ) }
					</p>
				) : null }
				<div className="vo-grid-cards">
					{ filtered.map( ( video ) => (
						<VideoCard
							key={ video.uuid }
							video={ video }
							selected={ video.uuid === selected }
							onClick={ () => choose( video ) }
						/>
					) ) }
				</div>
				{ videos && videos.length ? (
					<p className="vo-count">
						{ sprintf(
							/* translators: 1: shown videos, 2: total videos */
							__( '%1$d of %2$d videos', 'videooptimizer' ),
							filtered.length,
							videos.length
						) }
					</p>
				) : null }
			</div>
		</div>
	);
}

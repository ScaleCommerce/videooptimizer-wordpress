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
import { api, isVideoPlatformUrl, primeVideoCache } from './api';
import { appendPage, mergeFirstPage, replaceVideo } from './paging';
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

let tagPromise = null;
const loadTags = () => {
	if ( ! tagPromise ) {
		tagPromise = api.tags().catch( () => {
			tagPromise = null;
			return [];
		} );
	}
	return tagPromise;
};

/** Drops the cached tag list (after editing tags). */
export function resetTags() {
	tagPromise = null;
}

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
	if ( upload.status === 'canceled' ) {
		return __( 'Canceled', 'videooptimizer' );
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
	const [ tag, setTag ] = useState( '' );
	const [ tags, setTags ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loadingMore, setLoadingMore ] = useState( false );
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

	const requestId = useRef( 0 );
	const cursorRef = useRef( null );

	/**
	 * Loads the first page (replacing the list) or the next one (appending).
	 *
	 * @param {boolean} more Load the next page instead of starting over.
	 */
	const loadPage = ( more = false ) => {
		const id = more ? requestId.current : ++requestId.current;
		if ( more ) {
			setLoadingMore( true );
		} else {
			setError( null );
		}
		return api
			.videosPage( {
				libraryId,
				tags: tag ? [ tag ] : [],
				cursor: more ? cursorRef.current : '',
			} )
			.then( ( res ) => {
				if ( id !== requestId.current ) {
					return;
				}
				const items = ( res && res.items ) || [];
				cursorRef.current = ( res && res.next_cursor ) || null;
				setHasMore( !! cursorRef.current );
				setVideos( ( list ) =>
					more ? appendPage( list || [], items ) : items
				);
			} )
			.catch( ( e ) => {
				if ( id !== requestId.current ) {
					return;
				}
				setError( e );
				setVideos( ( list ) => list || [] );
			} )
			.finally(
				() => id === requestId.current && setLoadingMore( false )
			);
	};

	/**
	 * Picks up new and changed videos without losing the pages that were loaded after the first.
	 */
	const refresh = () => {
		const id = requestId.current;
		return api
			.videosPage( { libraryId, tags: tag ? [ tag ] : [] } )
			.then( ( res ) => {
				if ( id !== requestId.current ) {
					return;
				}
				const items = ( res && res.items ) || [];
				setVideos( ( list ) => mergeFirstPage( list || [], items ) );
				if ( ! cursorRef.current && res && res.next_cursor ) {
					cursorRef.current = res.next_cursor;
					setHasMore( true );
				}
			} )
			.catch( ( e ) => setError( e ) );
	};

	useEffect( () => {
		setVideos( null );
		setHasMore( false );
		cursorRef.current = null;
		loadPage();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ libraryId, tag ] );

	useEffect( () => {
		let live = true;
		loadTags().then( ( list ) => live && setTags( list ) );
		return () => {
			live = false;
		};
	}, [] );

	useEffect( () => () => signals.current.forEach( ( s ) => s.abort() ), [] );

	// Follow videos that are still processing (uploaded elsewhere or just now), on any loaded page.
	const processingIds = ( videos || [] )
		.filter( ( v ) => v.status === 'processing' )
		.slice( 0, 12 )
		.map( ( v ) => v.uuid )
		.join( ',' );
	useEffect( () => {
		if ( ! processingIds ) {
			return undefined;
		}
		const timer = setInterval( () => {
			processingIds.split( ',' ).forEach( ( uuid ) =>
				api.video( uuid ).then(
					( video ) =>
						setVideos( ( list ) =>
							replaceVideo( list || [], video )
						),
					() => {}
				)
			);
		}, 10000 );
		return () => clearInterval( timer );
	}, [ processingIds ] );

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
				{
					id,
					name: file.name,
					progress: 0,
					status: 'uploading',
					signal,
				},
			] );
			uploadVideo( file, {
				libraryId: uploadTarget,
				signal,
				onProgress: ( progress ) => updateUpload( id, { progress } ),
			} )
				.then( ( { uuid } ) => {
					updateUpload( id, { status: 'processing', uuid } );
					refresh();
					return pollVideo( uuid, {
						signal,
						onTick: ( video ) =>
							setVideos( ( list ) =>
								replaceVideo( list || [], video )
							),
					} );
				} )
				.then( ( video ) => {
					if ( video ) {
						updateUpload( id, {
							status: video.status,
							error: video.error,
						} );
					}
				} )
				.catch( ( e ) =>
					updateUpload( id, {
						status: signal.aborted ? 'canceled' : 'failed',
						error: e.message,
					} )
				);
		} );
	};

	const importUrl = () => {
		setUrlBusy( true );
		api.ingest( { library_id: uploadTarget, source_url: url } )
			.then( () => {
				setUrl( '' );
				setShowUrl( false );
				refresh();
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
				{ tags.length ? (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Tag', 'videooptimizer' ) }
						hideLabelFromVision
						value={ tag }
						onChange={ setTag }
						options={ [
							{
								label: __( 'All tags', 'videooptimizer' ),
								value: '',
							},
							...tags.map( ( t ) => ( {
								label: t.name,
								value: t.name,
							} ) ),
						] }
					/>
				) : null }
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
						help={ __(
							'Link to the video file itself, not to a video page such as YouTube.',
							'videooptimizer'
						) }
						value={ url }
						onChange={ setUrl }
						placeholder="https://…/video.mp4"
					/>
					{ isVideoPlatformUrl( url ) ? (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Link to a video file (e.g. https://…/video.mp4), not to a video page such as YouTube.',
								'videooptimizer'
							) }
						</Notice>
					) : null }
					<Button
						variant="primary"
						onClick={ importUrl }
						isBusy={ urlBusy }
						disabled={
							urlBusy ||
							! /^https:\/\/.+/i.test( url ) ||
							isVideoPlatformUrl( url )
						}
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
								<>
									<ProgressBar value={ u.progress } />
									<Button
										variant="link"
										isDestructive
										onClick={ () => u.signal.abort() }
									>
										{ __( 'Cancel', 'videooptimizer' ) }
									</Button>
								</>
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
					<div className="vo-load-more">
						<p className="vo-count">
							{ hasMore
								? sprintf(
										/* translators: 1: shown videos, 2: loaded videos */
										__(
											'%1$d of %2$d loaded videos',
											'videooptimizer'
										),
										filtered.length,
										videos.length
								  )
								: sprintf(
										/* translators: 1: shown videos, 2: total videos */
										__(
											'%1$d of %2$d videos',
											'videooptimizer'
										),
										filtered.length,
										videos.length
								  ) }
						</p>
						{ hasMore ? (
							<Button
								variant="secondary"
								onClick={ () => loadPage( true ) }
								isBusy={ loadingMore }
								disabled={ loadingMore }
							>
								{ __( 'Load more', 'videooptimizer' ) }
							</Button>
						) : null }
					</div>
				) : null }
			</div>
		</div>
	);
}

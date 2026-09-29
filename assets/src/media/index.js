/**
 * Media library integration: "VideoOptimizer" panel in the attachment details (grid, modal and
 * edit screen), live status badges in the list view and the auto-send queue.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { sendAttachment, watchAttachment } from '../components/transfer';
import { api } from '../components/api';
import { createSignal } from '../components/uploader';
import { ProgressBar, StatusBadge } from '../components/ui';
import DeleteOffers from './DeleteOffers';
import '../components/components.scss';
import './media.scss';

const config = window.videooptimizerMedia || {};

const PHASES = {
	server: __( 'Starting transfer…', 'videooptimizer' ),
	download: __( 'Reading file…', 'videooptimizer' ),
	upload: __( 'Uploading to VideoOptimizer…', 'videooptimizer' ),
	linking: __( 'Finishing…', 'videooptimizer' ),
};

function AttachmentPanel( { id, url, title, initial } ) {
	const [ state, setState ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	const [ phase, setPhase ] = useState( '' );
	const [ progress, setProgress ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const signal = useRef( createSignal() );

	useEffect( () => () => signal.current.abort(), [] );

	useEffect( () => {
		if ( state.status === 'processing' ) {
			const s = { aborted: false };
			watchAttachment( id, setState, s );
			return () => {
				s.aborted = true;
			};
		}
		return undefined;
	}, [ id, state.status ] );

	if ( ! config.configured ) {
		return (
			<p className="vo-media__hint">
				{ __(
					'Connect VideoOptimizer to deliver this video adaptively.',
					'videooptimizer'
				) }{ ' ' }
				<a href={ config.adminUrl + '#/settings' }>
					{ __( 'Settings', 'videooptimizer' ) }
				</a>
			</p>
		);
	}

	const send = () => {
		setBusy( true );
		setError( '' );
		setProgress( 0 );
		const filename = url.split( '/' ).pop();
		sendAttachment(
			{
				id,
				url,
				filename,
				title: title || filename.replace( /\.[^.]+$/, '' ),
			},
			{
				onPhase: setPhase,
				onProgress: setProgress,
				signal: signal.current,
			}
		)
			.then( setState )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => {
				setBusy( false );
				setPhase( '' );
			} );
	};

	const unlink = () => api.unlinkAttachment( id ).then( setState );

	return (
		<div className="vo-media">
			{ busy ? (
				<div className="vo-media__busy">
					<span>{ PHASES[ phase ] || '' }</span>
					{ phase === 'upload' ? (
						<ProgressBar value={ progress } />
					) : (
						<Spinner />
					) }
				</div>
			) : (
				<>
					<div className="vo-media__status">
						<StatusBadge
							status={ state.pending ? 'pending' : state.status }
						/>
						{ state.status === 'processing' ? <Spinner /> : null }
					</div>
					{ state.status === 'ready' ? (
						<p className="vo-media__hint">
							{ __(
								'Delivered via VideoOptimizer wherever this video is used (video block, [video] shortcode, Elementor).',
								'videooptimizer'
							) }
						</p>
					) : null }
					{ state.status === 'processing' ? (
						<p className="vo-media__hint">
							{ __(
								'VideoOptimizer is encoding the video. Until it is ready, the original file is used.',
								'videooptimizer'
							) }
						</p>
					) : null }
					{ state.status === 'failed' && state.error ? (
						<p className="vo-media__error">{ state.error }</p>
					) : null }
					<div className="vo-media__actions">
						{ state.status === 'none' ||
						state.status === 'failed' ? (
							<Button
								variant="secondary"
								size="small"
								onClick={ send }
							>
								{ state.status === 'failed'
									? __( 'Send again', 'videooptimizer' )
									: __(
											'Send to VideoOptimizer',
											'videooptimizer'
									  ) }
							</Button>
						) : null }
						{ state.uuid ? (
							<>
								<Button
									variant="link"
									href={
										config.adminUrl +
										'#/video/' +
										state.uuid
									}
								>
									{ __(
										'Poster & details',
										'videooptimizer'
									) }
								</Button>
								<Button
									variant="link"
									isDestructive
									onClick={ unlink }
								>
									{ __( 'Unlink', 'videooptimizer' ) }
								</Button>
							</>
						) : null }
					</div>
				</>
			) }
			{ error ? <p className="vo-media__error">{ error }</p> : null }
		</div>
	);
}

function mountPanels( root = document ) {
	root.querySelectorAll(
		'.videooptimizer-attachment:not([data-vo-mounted])'
	).forEach( ( el ) => {
		el.setAttribute( 'data-vo-mounted', '' );
		let initial = { status: 'none' };
		try {
			initial = JSON.parse( el.getAttribute( 'data-state' ) || '{}' );
		} catch ( e ) {}
		createRoot( el ).render(
			<AttachmentPanel
				id={ Number( el.getAttribute( 'data-id' ) ) }
				url={ el.getAttribute( 'data-url' ) }
				title={ el.getAttribute( 'data-title' ) || '' }
				initial={ initial }
			/>
		);
	} );
}

// List view: refresh "processing" badges.
function watchBadges() {
	document
		.querySelectorAll( '[data-videooptimizer-status]' )
		.forEach( ( badge ) => {
			if (
				! badge.classList.contains( 'videooptimizer-badge--processing' )
			) {
				return;
			}
			const id = Number(
				badge.getAttribute( 'data-videooptimizer-status' )
			);
			watchAttachment(
				id,
				( state ) => {
					badge.className =
						'videooptimizer-badge videooptimizer-badge--' +
						state.status;
					badge.textContent =
						{
							ready: __( 'Optimized', 'videooptimizer' ),
							failed: __( 'Failed', 'videooptimizer' ),
							processing: __( 'Processing', 'videooptimizer' ),
						}[ state.status ] || state.status;
				},
				{},
				15000
			);
		} );
}

/* ---------------------------------------------------------------- Auto-send queue */

const queue = [];
let running = false;
let toast = null;

function showToast( text ) {
	if ( ! toast ) {
		toast = document.createElement( 'div' );
		toast.className = 'vo-media-toast';
		toast.setAttribute( 'role', 'status' );
		document.body.appendChild( toast );
	}
	toast.textContent = text;
	toast.hidden = ! text;
}

async function runQueue() {
	if ( running ) {
		return;
	}
	running = true;
	while ( queue.length ) {
		const id = queue.shift();
		try {
			const media = await apiFetch( {
				path:
					'/wp/v2/media/' +
					id +
					'?context=edit&_fields=id,source_url,title,media_details,mime_type',
			} );
			const url = media.source_url;
			const filename = url.split( '/' ).pop();
			await sendAttachment(
				{
					id,
					url,
					filename,
					title: ( media.title && media.title.raw ) || filename,
				},
				{
					onProgress: ( p ) =>
						showToast(
							sprintf(
								/* translators: 1: file name, 2: percent, 3: remaining videos */
								__(
									'VideoOptimizer: uploading %1$s (%2$d%%) — %3$d more in queue. Please keep this page open.',
									'videooptimizer'
								),
								filename,
								Math.round( p * 100 ),
								queue.length
							)
						),
				}
			);
		} catch ( e ) {
			showToast(
				sprintf(
					/* translators: %s: error */ __(
						'VideoOptimizer: transfer failed — %s',
						'videooptimizer'
					),
					e.message
				)
			);
			await new Promise( ( r ) => setTimeout( r, 4000 ) );
		}
	}
	showToast( '' );
	running = false;
}

function enqueue( id ) {
	if ( ! queue.includes( id ) ) {
		queue.push( id );
		runQueue();
	}
}

function watchNewUploads() {
	const wp = window.wp;
	if ( ! config.autoSend || ! wp || ! wp.Uploader || ! wp.Uploader.queue ) {
		return;
	}
	wp.Uploader.queue.on( 'add', ( attachment ) => {
		attachment.once( 'change:uploading', () => {
			const id = attachment.get( 'id' );
			const type = attachment.get( 'type' );
			if ( id && type === 'video' ) {
				api.attachment( id )
					.then( ( state ) => state.pending && enqueue( id ) )
					.catch( () => {} );
			}
		} );
	} );
}

/* ---------------------------------------------------------------- Delete offers */

let refreshOffers = () => {};

function mountDeleteOffers() {
	const root = document.getElementById( 'videooptimizer-delete-offers' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render(
		<DeleteOffers
			registerRefresh={ ( fn ) => {
				refreshOffers = fn;
			} }
		/>
	);

	// Media grid deletes without a page load: ask right away when a linked video is deleted.
	const wp = window.wp;
	const all =
		wp && wp.media && wp.media.model && wp.media.model.Attachments
			? wp.media.model.Attachments.all
			: null;
	if ( all ) {
		all.on( 'destroy', ( model ) => {
			const state = model.get( 'videooptimizer' );
			if ( state && state.uuid ) {
				window.setTimeout( () => refreshOffers(), 300 );
			}
		} );
	}
}

function init() {
	mountDeleteOffers();
	if ( config.integration === false ) {
		return;
	}
	mountPanels();
	watchBadges();
	( config.pending || [] ).forEach( enqueue );
	watchNewUploads();
	if ( 'MutationObserver' in window ) {
		new window.MutationObserver( () => mountPanels() ).observe(
			document.body,
			{
				childList: true,
				subtree: true,
			}
		);
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

/**
 * Sends a media library video to VideoOptimizer.
 *
 * 1. Public https sites: the server hands the file URL to VideoOptimizer (no browser traffic).
 * 2. Otherwise (local/staging/protected sites): the browser reads the file from the media library
 *    and uploads it straight to VideoOptimizer storage (presigned multipart), then links it.
 */
import { __ } from '@wordpress/i18n';
import { api } from './api';
import { uploadVideo, createSignal } from './uploader';

/**
 * @param {Object}   attachment
 * @param {number}   attachment.id       Attachment id.
 * @param {string}   attachment.url      File URL.
 * @param {string}   attachment.title    Title.
 * @param {string}   attachment.filename File name.
 * @param {Object}   options
 * @param {Function} options.onProgress  0..1 during a browser upload.
 * @param {Function} options.onPhase     'server' | 'download' | 'upload' | 'linking'.
 * @param {Object}   options.signal      createSignal() holder.
 * @return {Promise<Object>} Attachment state.
 */
export async function sendAttachment( attachment, options = {} ) {
	const {
		onProgress = () => {},
		onPhase = () => {},
		signal = createSignal(),
	} = options;

	onPhase( 'server' );
	try {
		return await api.sendAttachmentUrl( attachment.id );
	} catch ( error ) {
		// Only "site not public" falls back to the browser; real API errors (plan limit,
		// missing library, …) would fail the browser upload the same way.
		if ( error.code !== 'videooptimizer_not_public' ) {
			throw error;
		}
	}

	onPhase( 'download' );
	const response = await window.fetch( attachment.url, {
		credentials: 'same-origin',
	} );
	if ( ! response.ok ) {
		throw new Error(
			__(
				'The video file could not be read from the media library.',
				'videooptimizer'
			)
		);
	}
	const blob = await response.blob();
	const file =
		blob.type && blob.type.startsWith( 'video/' )
			? blob
			: new window.Blob( [ blob ], { type: 'video/mp4' } );

	const { id: libraryId } = await api.uploadLibrary();
	onPhase( 'upload' );
	const { uuid } = await uploadVideo( file, {
		libraryId,
		title: attachment.title,
		filename: attachment.filename || attachment.url.split( '/' ).pop(),
		onProgress,
		signal,
	} );

	onPhase( 'linking' );
	return api.linkAttachment( attachment.id, { uuid, library: libraryId } );
}

/**
 * Polls the attachment state until it is ready or failed.
 *
 * @param {number}   id       Attachment id.
 * @param {Function} onState  Called with each state.
 * @param {Object}   signal   { aborted }.
 * @param {number}   interval ms.
 */
export function watchAttachment( id, onState, signal = {}, interval = 8000 ) {
	const tick = () => {
		if ( signal.aborted ) {
			return;
		}
		api.attachment( id )
			.then( ( state ) => {
				onState( state );
				if ( state.status === 'processing' ) {
					setTimeout( tick, interval );
				}
			} )
			.catch( () => setTimeout( tick, interval * 2 ) );
	};
	setTimeout( tick, interval );
}

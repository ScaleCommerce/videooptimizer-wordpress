/**
 * Presigned multipart upload straight from the browser to VideoOptimizer storage:
 * initiate (via the WP proxy) → PUT every part to its presigned URL → complete (via proxy).
 * No PHP upload limits apply and the API token stays on the server.
 */
import { __, sprintf } from '@wordpress/i18n';
import { api } from './api';

const MAX_PART_RETRIES = 3;

/**
 * PUTs one blob with upload progress (fetch() has no upload progress events).
 *
 * @param {string}   url        Presigned URL.
 * @param {Blob}     blob       Part data.
 * @param {Function} onProgress Called with uploaded bytes of this part.
 * @param {Object}   signal     { aborted, xhr } holder for cancelation.
 * @param {string}   type       Optional Content-Type header.
 * @return {Promise<string>} ETag of the part (quoted, passed on unchanged).
 */
function putBlob( url, blob, onProgress, signal, type ) {
	return new Promise( ( resolve, reject ) => {
		const xhr = new window.XMLHttpRequest();
		signal.xhr = xhr;
		xhr.open( 'PUT', url );
		if ( type ) {
			xhr.setRequestHeader( 'Content-Type', type );
		}
		xhr.upload.onprogress = ( event ) => {
			if ( event.lengthComputable ) {
				onProgress( event.loaded );
			}
		};
		xhr.onload = () => {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				resolve( xhr.getResponseHeader( 'ETag' ) || '' );
			} else {
				reject(
					new Error(
						sprintf(
							/* translators: %d: HTTP status code */
							__( 'Upload failed (HTTP %d).', 'videooptimizer' ),
							xhr.status
						)
					)
				);
			}
		};
		xhr.onerror = () =>
			reject(
				new Error(
					__(
						'Network error while uploading. Please check your connection.',
						'videooptimizer'
					)
				)
			);
		xhr.onabort = () =>
			reject( new Error( __( 'Upload canceled.', 'videooptimizer' ) ) );
		xhr.send( blob );
	} );
}

/**
 * Uploads a video file.
 *
 * @param {File|Blob} file               File (a Blob needs options.filename).
 * @param {Object}    options
 * @param {string}    options.libraryId  Target library.
 * @param {string}    options.title      Optional title.
 * @param {string}    options.filename   File name for Blobs.
 * @param {Function}  options.onProgress Called with 0..1.
 * @param {Object}    options.signal     Cancelation holder from createSignal().
 * @return {Promise<{uuid: string}>} The new video.
 */
export async function uploadVideo( file, options ) {
	const {
		libraryId,
		title,
		onProgress = () => {},
		signal = createSignal(),
	} = options;
	const filename = options.filename || file.name || 'video.mp4';
	const contentType = file.type || 'video/mp4';

	if ( ! contentType.startsWith( 'video/' ) ) {
		throw new Error(
			__( 'Please choose a video file.', 'videooptimizer' )
		);
	}

	const init = await api.initiateUpload( {
		libraryId,
		filename,
		contentType,
		fileSize: file.size,
	} );
	const partSize = Number( init.partSize ) || file.size;
	const parts = init.parts || [];
	const loaded = new Array( parts.length ).fill( 0 );
	const report = () =>
		onProgress(
			Math.min( 1, loaded.reduce( ( a, b ) => a + b, 0 ) / file.size )
		);

	const etags = [];
	for ( let i = 0; i < parts.length; i++ ) {
		const part = parts[ i ];
		const start = ( part.partNumber - 1 ) * partSize;
		const blob = file.slice( start, start + partSize );
		let etag = '';
		for ( let attempt = 1; ; attempt++ ) {
			if ( signal.aborted ) {
				throw new Error( __( 'Upload canceled.', 'videooptimizer' ) );
			}
			try {
				etag = await putBlob(
					part.url,
					blob,
					( bytes ) => {
						loaded[ i ] = bytes;
						report();
					},
					signal
				);
				break;
			} catch ( error ) {
				if ( signal.aborted || attempt >= MAX_PART_RETRIES ) {
					throw error;
				}
				loaded[ i ] = 0;
				await new Promise( ( r ) => setTimeout( r, 1000 * attempt ) );
			}
		}
		if ( ! etag ) {
			throw new Error(
				__(
					'The storage did not return an ETag (CORS must expose the ETag header). Please contact VideoOptimizer support.',
					'videooptimizer'
				)
			);
		}
		loaded[ i ] = blob.size;
		report();
		etags.push( { partNumber: part.partNumber, etag } );
	}

	await api.completeUpload( {
		libraryId,
		uuid: init.uuid,
		key: init.key,
		uploadId: init.uploadId,
		title: title || filename.replace( /\.[^.]+$/, '' ),
		parts: etags,
	} );
	onProgress( 1 );

	return { uuid: init.uuid };
}

/**
 * Uploads a custom poster image (single presigned PUT) and activates it.
 *
 * @param {string}    uuid Video uuid.
 * @param {File|Blob} file JPEG, PNG or WebP.
 * @return {Promise<void>}
 */
export async function uploadPoster( uuid, file ) {
	const type = file.type;
	if ( ! [ 'image/jpeg', 'image/png', 'image/webp' ].includes( type ) ) {
		throw new Error(
			__( 'Please choose a JPEG, PNG or WebP image.', 'videooptimizer' )
		);
	}
	const init = await api.initiatePoster( uuid, {
		contentType: type,
		fileSize: file.size,
	} );
	await putBlob( init.uploadUrl, file, () => {}, createSignal(), type );
	await api.completePoster( uuid, init.key );
}

export function createSignal() {
	const signal = {
		aborted: false,
		xhr: null,
		abort() {
			signal.aborted = true;
			if ( signal.xhr ) {
				signal.xhr.abort();
			}
		},
	};
	return signal;
}

/**
 * Polls a video until predicate(video) is true or it failed. Resolves with the last video.
 *
 * @param {string}   uuid
 * @param {Object}   options
 * @param {Function} options.until    Predicate, default: status is ready or failed.
 * @param {number}   options.interval ms between polls.
 * @param {number}   options.max      Max attempts.
 * @param {Function} options.onTick   Called with each video.
 * @param {Object}   options.signal   { aborted } to stop polling.
 * @return {Promise<Object>} Final video.
 */
export function pollVideo( uuid, options = {} ) {
	const {
		until = ( v ) => v.status === 'ready' || v.status === 'failed',
		interval = 5000,
		max = 360,
		onTick,
		signal = {},
	} = options;
	return new Promise( ( resolve, reject ) => {
		let attempts = 0;
		const tick = () => {
			if ( signal.aborted ) {
				return;
			}
			attempts++;
			api.video( uuid )
				.then( ( video ) => {
					if ( onTick ) {
						onTick( video );
					}
					if ( until( video ) || attempts >= max ) {
						resolve( video );
					} else {
						setTimeout( tick, interval );
					}
				} )
				.catch( ( error ) => {
					if ( attempts >= max || error.status === 404 ) {
						reject( error );
					} else {
						setTimeout( tick, interval * 2 );
					}
				} );
		};
		tick();
	} );
}

/**
 * Client for the plugin's REST proxy (/wp-json/videooptimizer/v1). The API token never reaches
 * the browser; only presigned storage URLs do (for direct uploads).
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BASE = '/videooptimizer/v1';
const enc = encodeURIComponent;

function request( path, options = {} ) {
	return apiFetch( { path: BASE + path, ...options } ).catch( ( error ) => {
		const err = new Error(
			( error && error.message ) ||
				__( 'The request failed.', 'videooptimizer' )
		);
		err.code = error && error.code;
		err.status = error && error.data ? error.data.status : null;
		err.upstreamStatus =
			error && error.data ? error.data.upstream_status : null;
		throw err;
	} );
}

const get = ( path ) => request( path );
const post = ( path, data = {} ) => request( path, { method: 'POST', data } );
const patch = ( path, data = {} ) => request( path, { method: 'PATCH', data } );
const del = ( path ) => request( path, { method: 'DELETE' } );

export const api = {
	status: () => get( '/status' ),
	getSettings: () => get( '/settings' ),
	saveSettings: ( data ) => post( '/settings', data ),
	deleteToken: () => del( '/settings/token' ),
	testConnection: () => post( '/settings/test' ),

	libraries: () => get( '/libraries' ),
	createLibrary: ( data ) => post( '/libraries', data ),
	updateLibrary: ( id, data ) => patch( '/libraries/' + enc( id ), data ),
	deleteLibrary: ( id ) => del( '/libraries/' + enc( id ) ),
	reprocessLibrary: ( id ) =>
		post( '/libraries/' + enc( id ) + '/reprocess' ),
	encodings: () => get( '/encodings' ),
	uploadLibrary: () => get( '/upload-library' ),

	videosPage: ( { libraryId = '', cursor = '', limit = 100 } = {} ) => {
		const q = new URLSearchParams( { limit: String( limit ) } );
		if ( libraryId ) {
			q.set( 'library_id', libraryId );
		}
		if ( cursor ) {
			q.set( 'cursor', cursor );
		}
		return get( '/videos?' + q.toString() );
	},
	video: ( uuid ) => get( '/videos/' + enc( uuid ) ),
	updateVideo: ( uuid, data ) => patch( '/videos/' + enc( uuid ), data ),
	deleteVideo: ( uuid ) => del( '/videos/' + enc( uuid ) ),
	ingest: ( data ) => post( '/videos/ingest', data ),
	initiateUpload: ( data ) => post( '/videos/upload/initiate', data ),
	completeUpload: ( data ) => post( '/videos/upload/complete', data ),

	thumbnails: ( uuid ) =>
		get( '/videos/' + enc( uuid ) + '/thumbnails' ).then(
			( r ) => ( r && r.thumbnails ) || []
		),
	selectThumbnail: ( uuid, index ) =>
		post( '/videos/' + enc( uuid ) + '/thumbnail', {
			thumbnailIndex: index,
		} ),
	initiatePoster: ( uuid, data ) =>
		post( '/videos/' + enc( uuid ) + '/poster/initiate', data ),
	completePoster: ( uuid, key ) =>
		post( '/videos/' + enc( uuid ) + '/poster/complete', { key } ),
	selectPoster: ( uuid, data ) =>
		post( '/videos/' + enc( uuid ) + '/poster/select', data ),
	deletePoster: ( uuid ) => del( '/videos/' + enc( uuid ) + '/poster' ),

	videoUsage: ( uuid ) => get( '/videos/' + enc( uuid ) + '/usage' ),
	deleteOffers: () => get( '/delete-offers' ),
	resolveDeleteOffer: ( uuid, remove ) =>
		post( '/delete-offers/' + enc( uuid ), { delete: remove } ),

	attachment: ( id ) => get( '/attachments/' + id ),
	linkAttachment: ( id, data ) =>
		post( '/attachments/' + id + '/link', data ),
	unlinkAttachment: ( id ) => del( '/attachments/' + id + '/link' ),
	sendAttachmentUrl: ( id ) => post( '/attachments/' + id + '/send-url' ),
};

/**
 * Loads every video (following the cursor), calling onPage after each page so lists can render
 * progressively.
 *
 * @param {Object}   args
 * @param {string}   args.libraryId Optional library filter.
 * @param {Function} args.onPage    Called with the items loaded so far.
 * @return {Promise<Array>} All videos.
 */
export async function loadAllVideos( { libraryId = '', onPage } = {} ) {
	let cursor = '';
	let items = [];
	for ( let page = 0; page < 100; page++ ) {
		const res = await api.videosPage( { libraryId, cursor } );
		items = items.concat( ( res && res.items ) || [] );
		if ( onPage ) {
			onPage( items );
		}
		if ( ! res || ! res.next_cursor || res.next_cursor === cursor ) {
			break;
		}
		cursor = res.next_cursor;
	}
	return items;
}

// Per-page cache for single video lookups (block editor, pickers).
const videoCache = new Map();

export function getVideoCached( uuid ) {
	if ( ! uuid ) {
		return Promise.resolve( null );
	}
	if ( ! videoCache.has( uuid ) ) {
		videoCache.set(
			uuid,
			api.video( uuid ).catch( ( error ) => {
				videoCache.delete( uuid );
				throw error;
			} )
		);
	}
	return videoCache.get( uuid );
}

export function primeVideoCache( video ) {
	if ( video && video.uuid ) {
		videoCache.set( video.uuid, Promise.resolve( video ) );
	}
}

export function forgetVideo( uuid ) {
	videoCache.delete( uuid );
}

/**
 * poster_url is a stable CDN URL cached for ~30 days whose image changes in place, so admin
 * previews add a cache-busting token that changes after every poster edit. Never store it.
 */
let bust = Date.now();
export const bumpPosterCache = () => {
	bust = Date.now();
};
export function posterPreview( video ) {
	const url =
		video && ( video.poster_url || video.thumbnail_url || video.poster );
	if ( ! url ) {
		return null;
	}
	return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + '_vo=' + bust;
}

/**
 * Display title without a trailing file extension.
 *
 * @param {Object} video Video.
 * @return {string} Title.
 */
export function videoTitle( video ) {
	const title = ( video && video.title ) || '';
	return (
		title.replace( /\.(mp4|m4v|mov|webm|mkv|avi|wmv|mpe?g)$/i, '' ) ||
		__( 'Untitled video', 'videooptimizer' )
	);
}

export function formatDuration( seconds ) {
	const s = Math.round( Number( seconds ) || 0 );
	if ( ! s ) {
		return '';
	}
	const h = Math.floor( s / 3600 );
	const m = Math.floor( ( s % 3600 ) / 60 );
	const sec = String( s % 60 ).padStart( 2, '0' );
	return h
		? `${ h }:${ String( m ).padStart( 2, '0' ) }:${ sec }`
		: `${ m }:${ sec }`;
}

export function formatBytes( bytes ) {
	const b = Number( bytes ) || 0;
	if ( b < 1024 ) {
		return b + ' B';
	}
	const units = [ 'KB', 'MB', 'GB', 'TB' ];
	let value = b / 1024;
	let i = 0;
	while ( value >= 1024 && i < units.length - 1 ) {
		value /= 1024;
		i++;
	}
	return value.toFixed( value < 10 ? 1 : 0 ) + ' ' + units[ i ];
}

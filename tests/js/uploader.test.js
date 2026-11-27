/**
 * Browser uploads: poster flow relies on the API's automatic activation.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	pollVideo,
	uploadCustomPoster,
	uploadVideo,
	createSignal,
} from '../../assets/src/components/uploader';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

/**
 * Fake XMLHttpRequest for presigned PUTs.
 *
 * @param {Function} statusFor Returns the status for the nth PUT (0-based).
 */
function fakeXhr( statusFor = () => 200 ) {
	const puts = [];
	window.XMLHttpRequest = class {
		constructor() {
			this.upload = {};
			this.status = 0;
		}
		open( method, url ) {
			this.url = url;
			this.index = puts.length;
			puts.push( url );
		}
		setRequestHeader() {}
		getResponseHeader() {
			return '"etag-' + this.index + '"';
		}
		send() {
			this.status = statusFor( this.index );
			setTimeout( () => this.onload(), 0 );
		}
		abort() {
			this.onabort();
		}
	};
	return puts;
}

const calls = () =>
	apiFetch.mock.calls.map(
		( [ o ] ) => ( o.method || 'GET' ) + ' ' + o.path
	);

beforeEach( () => {
	apiFetch.mockReset();
	jest.useFakeTimers( { doNotFake: [ 'setTimeout' ] } );
} );
afterEach( () => jest.useRealTimers() );

describe( 'uploadCustomPoster', () => {
	it( 'uploads, completes and waits for the API to activate the poster without selecting it', async () => {
		fakeXhr();
		const videos = [
			{
				uuid: UUID,
				poster: { custom_status: 'processing', source: 'thumbnail' },
			},
			{
				uuid: UUID,
				poster: { custom_status: 'ready', source: 'custom' },
			},
		];
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path.endsWith( '/poster/initiate' ) ) {
				return Promise.resolve( {
					key: 'k1',
					uploadUrl: 'https://s3/put',
				} );
			}
			if ( path.endsWith( '/poster/complete' ) ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( videos.shift() );
		} );

		const video = await uploadCustomPoster(
			UUID,
			new window.File( [ 'x' ], 'p.png', { type: 'image/png' } ),
			{ interval: 1 }
		);

		expect( video.poster.source ).toBe( 'custom' );
		expect( calls().join( '\n' ) ).not.toContain( 'poster/select' );
		expect(
			calls().filter( ( c ) => c.includes( 'poster/complete' ) )
		).toHaveLength( 1 );
	} );

	it( 'reports a custom poster that failed processing', async () => {
		fakeXhr();
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path.endsWith( '/poster/initiate' ) ) {
				return Promise.resolve( {
					key: 'k1',
					uploadUrl: 'https://s3/put',
				} );
			}
			if ( path.endsWith( '/poster/complete' ) ) {
				return Promise.resolve( { success: true } );
			}
			return Promise.resolve( {
				uuid: UUID,
				poster: { custom_status: 'failed' },
			} );
		} );

		await expect(
			uploadCustomPoster(
				UUID,
				new window.File( [ 'x' ], 'p.png', { type: 'image/png' } ),
				{ interval: 1 }
			)
		).rejects.toThrow( 'could not be processed' );
	} );

	it( 'rejects non-image files before calling the API', async () => {
		await expect(
			uploadCustomPoster(
				UUID,
				new window.File( [ 'x' ], 'a.gif', { type: 'image/gif' } )
			)
		).rejects.toThrow( 'JPEG, PNG or WebP' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'uploadVideo', () => {
	const init = {
		uuid: UUID,
		key: 'k',
		uploadId: 'u',
		partSize: 2,
		parts: [
			{ partNumber: 1, url: 'https://s3/1' },
			{ partNumber: 2, url: 'https://s3/2' },
		],
	};

	it( 'retries a failed part and completes with the ETags', async () => {
		// The first PUT fails, then every PUT succeeds.
		const puts = fakeXhr( ( i ) => ( i === 0 ? 500 : 200 ) );
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve(
				path.endsWith( '/upload/initiate' ) ? init : { uuid: UUID }
			)
		);
		const progress = [];

		const result = await uploadVideo(
			new window.File( [ 'abcd' ], 'a.mp4', { type: 'video/mp4' } ),
			{ libraryId: 'lib', onProgress: ( p ) => progress.push( p ) }
		);

		expect( result.uuid ).toBe( UUID );
		expect( puts ).toHaveLength( 3 );
		const complete = apiFetch.mock.calls.find( ( [ o ] ) =>
			o.path.endsWith( '/upload/complete' )
		)[ 0 ];
		expect( complete.data.parts.map( ( p ) => p.partNumber ) ).toEqual( [
			1, 2,
		] );
		expect( progress[ progress.length - 1 ] ).toBe( 1 );
	} );

	it( 'does not complete when canceled after the last part', async () => {
		fakeXhr();
		apiFetch.mockImplementation( () => Promise.resolve( init ) );
		const signal = createSignal();

		await expect(
			uploadVideo(
				new window.File( [ 'abcd' ], 'a.mp4', { type: 'video/mp4' } ),
				{
					libraryId: 'lib',
					signal,
					onProgress: ( p ) => p >= 1 && signal.abort(),
				}
			)
		).rejects.toThrow( 'canceled' );
		expect( calls().join( '\n' ) ).not.toContain( 'upload/complete' );
	} );

	it( 'reports when the complete call starts, after which cancel is too late', async () => {
		fakeXhr();
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve( path.endsWith( '/upload/initiate' ) ? init : {} )
		);
		const onCompleting = jest.fn();

		await uploadVideo(
			new window.File( [ 'abcd' ], 'a.mp4', { type: 'video/mp4' } ),
			{ libraryId: 'lib', onCompleting }
		);

		expect( onCompleting ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops without completing when canceled', async () => {
		fakeXhr();
		apiFetch.mockImplementation( () => Promise.resolve( init ) );
		const signal = createSignal();
		signal.abort();

		await expect(
			uploadVideo(
				new window.File( [ 'abcd' ], 'a.mp4', { type: 'video/mp4' } ),
				{
					libraryId: 'lib',
					signal,
				}
			)
		).rejects.toThrow( 'canceled' );
		expect( calls().join( '\n' ) ).not.toContain( 'upload/complete' );
	} );
} );

describe( 'pollVideo', () => {
	it( 'rejects when aborted instead of hanging', async () => {
		apiFetch.mockResolvedValue( { uuid: UUID, status: 'processing' } );
		const signal = { aborted: false };
		const promise = pollVideo( UUID, { interval: 1, signal } );
		signal.aborted = true;
		await expect( promise ).rejects.toThrow( 'canceled' );
	} );

	it( 'resolves with the failed video so the API error can be shown', async () => {
		apiFetch.mockResolvedValue( {
			uuid: UUID,
			status: 'failed',
			error: 'Unsupported codec',
		} );
		const video = await pollVideo( UUID, { interval: 1 } );
		expect( video.error ).toBe( 'Unsupported codec' );
	} );
} );

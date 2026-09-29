/**
 * Pure helpers of the shared admin/editor API module.
 */
import {
	formatBytes,
	formatDuration,
	posterPreview,
	videoTitle,
} from '../../assets/src/components/api';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'videoTitle', () => {
	it( 'drops video file extensions', () => {
		expect( videoTitle( { title: 'Clip_final.MP4' } ) ).toBe(
			'Clip_final'
		);
		expect( videoTitle( { title: 'Keynote 2026' } ) ).toBe(
			'Keynote 2026'
		);
	} );

	it( 'falls back for empty titles', () => {
		expect( videoTitle( { title: '' } ) ).toBe( 'Untitled video' );
	} );
} );

describe( 'formatDuration', () => {
	it.each( [
		[ 0, '' ],
		[ 5, '0:05' ],
		[ 64.6, '1:05' ],
		[ 3725, '1:02:05' ],
	] )( '%s seconds → %s', ( seconds, expected ) => {
		expect( formatDuration( seconds ) ).toBe( expected );
	} );
} );

describe( 'formatBytes', () => {
	it( 'uses binary units', () => {
		expect( formatBytes( 512 ) ).toBe( '512 B' );
		expect( formatBytes( 1536 ) ).toBe( '1.5 KB' );
		expect( formatBytes( 150 * 1024 * 1024 ) ).toBe( '150 MB' );
	} );
} );

describe( 'posterPreview', () => {
	it( 'adds a cache-busting parameter', () => {
		expect( posterPreview( { poster_url: 'https://cdn/p.jpg' } ) ).toMatch(
			/^https:\/\/cdn\/p\.jpg\?_vo=\d+$/
		);
		expect(
			posterPreview( { poster_url: 'https://cdn/p.jpg?w=640' } )
		).toMatch( /^https:\/\/cdn\/p\.jpg\?w=640&_vo=\d+$/ );
		expect( posterPreview( {} ) ).toBeNull();
	} );
} );

/**
 * Paging helpers of the video browser.
 */
import {
	PAGE_SIZE,
	appendPage,
	mergeFirstPage,
	replaceVideo,
} from '../../assets/src/components/paging';

const v = ( uuid, extra = {} ) => ( { uuid, ...extra } );

describe( 'PAGE_SIZE', () => {
	it( 'loads 60 videos per page', () => {
		expect( PAGE_SIZE ).toBe( 60 );
	} );
} );

describe( 'appendPage', () => {
	it( 'appends the next page and skips videos already shown', () => {
		const result = appendPage(
			[ v( 'a' ), v( 'b' ) ],
			[ v( 'b' ), v( 'c' ) ]
		);
		expect( result.map( ( x ) => x.uuid ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'mergeFirstPage', () => {
	it( 'keeps loaded later pages, refreshes the first page and puts new videos on top', () => {
		const current = [ v( 'a' ), v( 'b' ), v( 'c' ), v( 'd' ) ];
		const first = [ v( 'new' ), v( 'a', { status: 'ready' } ), v( 'b' ) ];

		const result = mergeFirstPage( current, first );

		expect( result.map( ( x ) => x.uuid ) ).toEqual( [
			'new',
			'a',
			'b',
			'c',
			'd',
		] );
		expect( result[ 1 ].status ).toBe( 'ready' );
	} );

	it( 'works on an empty list', () => {
		expect( mergeFirstPage( [], [ v( 'a' ) ] ) ).toEqual( [ v( 'a' ) ] );
	} );
} );

describe( 'replaceVideo', () => {
	it( 'replaces one video in place and leaves the others alone', () => {
		const list = [ v( 'a' ), v( 'b' ) ];
		const result = replaceVideo( list, v( 'b', { title: 'New' } ) );
		expect( result[ 1 ].title ).toBe( 'New' );
		expect( result[ 0 ] ).toBe( list[ 0 ] );
	} );

	it( 'ignores unknown videos', () => {
		const list = [ v( 'a' ) ];
		expect( replaceVideo( list, v( 'x' ) ) ).toEqual( list );
	} );
} );

/**
 * Pure helpers for tags and metadata editing.
 */
import {
	MAX_METADATA_KEYS,
	isValidMetadataKey,
	metadataPatch,
	metadataRows,
	normalizeTags,
} from '../../assets/src/components/video-fields';
import apiFetch from '@wordpress/api-fetch';
import { api } from '../../assets/src/components/api';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'normalizeTags', () => {
	it( 'trims, drops empties and duplicates ignoring case', () => {
		expect( normalizeTags( [ ' Promo ', 'promo', '', '2026' ] ) ).toEqual( [
			'Promo',
			'2026',
		] );
	} );

	it( 'splits pasted comma lists', () => {
		expect( normalizeTags( [ 'a, b', 'c' ] ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'isValidMetadataKey', () => {
	it.each( [ 'cms_id', 'a', 'x.y-z', '1abc' ] )( '%s is valid', ( key ) => {
		expect( isValidMetadataKey( key ) ).toBe( true );
	} );
	it.each( [ '', 'Upper', '-a', 'a b', 'a'.repeat( 41 ) ] )(
		'%j is invalid',
		( key ) => {
			expect( isValidMetadataKey( key ) ).toBe( false );
		}
	);
} );

describe( 'metadataRows', () => {
	it( 'lists the metadata as rows', () => {
		expect( metadataRows( { a: '1', b: '2' } ) ).toEqual( [
			{ key: 'a', value: '1' },
			{ key: 'b', value: '2' },
		] );
		expect( metadataRows( null ) ).toEqual( [] );
	} );
} );

describe( 'metadataPatch', () => {
	const original = { keep: '1', change: '2', drop: '3' };

	it( 'sends only changes: new and changed keys, null for removed ones', () => {
		expect(
			metadataPatch( original, [
				{ key: 'keep', value: '1' },
				{ key: 'change', value: 'two' },
				{ key: 'added', value: 'x' },
			] )
		).toEqual( { change: 'two', added: 'x', drop: null } );
	} );

	it( 'removes a key whose value was emptied and ignores blank rows', () => {
		expect(
			metadataPatch( { a: '1' }, [
				{ key: 'a', value: '  ' },
				{ key: '', value: '' },
			] )
		).toEqual( { a: null } );
	} );

	it( 'returns null when nothing changed', () => {
		expect(
			metadataPatch( { a: '1' }, [ { key: 'a', value: '1' } ] )
		).toBeNull();
	} );

	it( 'allows at most ten keys', () => {
		expect( MAX_METADATA_KEYS ).toBe( 10 );
	} );
} );

describe( 'tags and filters in the api client', () => {
	beforeEach( () => apiFetch.mockReset().mockResolvedValue( {} ) );

	it( 'filters the video list by tags and metadata', async () => {
		await api.videosPage( {
			tags: [ 'promo', 'summer 26' ],
			metadata: { cms_id: '42' },
		} );
		const { path } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( path ).toContain( 'tags=promo%2Csummer+26' );
		expect( path ).toContain( 'metadata%5Bcms_id%5D=42' );
	} );

	it( 'loads the tags', async () => {
		await api.tags();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/videooptimizer/v1/tags',
		} );
	} );
} );

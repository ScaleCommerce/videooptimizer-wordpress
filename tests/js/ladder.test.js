/**
 * Add-on gating of codecs and resolutions.
 */
import { optionState } from '../../assets/src/components/ladder';

const library = {
	available_codecs: [ 'h264', 'h265' ],
	available_resolutions: [ '360p', '720p' ],
};

describe( 'optionState', () => {
	it( 'allows what the library lists as available', () => {
		expect(
			optionState(
				{ key: 'h265', access: 'addon', available: true },
				library,
				'codecs'
			)
		).toEqual( { selectable: true, locked: false } );
	} );

	it( 'locks add-on options the organization has not unlocked', () => {
		expect(
			optionState(
				{ key: 'av1', access: 'addon', available: false },
				library,
				'codecs'
			)
		).toEqual( { selectable: false, locked: true } );
	} );

	it( 'does not lock an included option that the library does not list', () => {
		expect(
			optionState(
				{ key: '1080p', access: 'included', available: true },
				library,
				'resolutions'
			)
		).toEqual( { selectable: false, locked: false } );
	} );

	it( 'falls back to the organization catalog when the library lists nothing', () => {
		const bare = { available_codecs: [] };
		expect(
			optionState(
				{ key: 'av1', access: 'addon', available: false },
				bare,
				'codecs'
			)
		).toEqual( { selectable: false, locked: true } );
		expect(
			optionState( { key: 'h264', available: true }, bare, 'codecs' )
		).toEqual( { selectable: true, locked: false } );
		expect( optionState( { key: 'h264' }, {}, 'codecs' ).selectable ).toBe(
			true
		);
	} );

	it( 'treats options without add-on access as never locked', () => {
		expect(
			optionState(
				{ key: 'x', access: 'included', available: false },
				{},
				'codecs'
			).locked
		).toBe( false );
	} );
} );

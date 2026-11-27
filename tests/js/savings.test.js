/**
 * Savings summary helpers.
 */
import apiFetch from '@wordpress/api-fetch';
import { api } from '../../assets/src/components/api';
import {
	lastDaysRange,
	summarizeSavings,
} from '../../assets/src/components/savings';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'lastDaysRange', () => {
	it( 'ends today (UTC) and starts days-1 earlier', () => {
		expect(
			lastDaysRange( 30, new Date( '2026-11-27T23:30:00Z' ) )
		).toEqual( {
			from: '2026-10-29',
			to: '2026-11-27',
		} );
	} );

	it( 'works across a year boundary', () => {
		expect(
			lastDaysRange( 7, new Date( '2026-01-03T00:10:00Z' ) )
		).toEqual( {
			from: '2025-12-28',
			to: '2026-01-03',
		} );
	} );
} );

describe( 'summarizeSavings', () => {
	const data = {
		traffic_available: true,
		compression: {
			videos: 12,
			original_bytes: 1000,
			encoded_bytes: 200,
			reduced_percent: 80,
		},
		range: { saved_bytes: 50, saved_percent: 70, delivered_bytes: 20 },
		all_time: { saved_bytes: 900, saved_percent: 75, delivered_bytes: 300 },
	};

	it( 'condenses the API payload', () => {
		expect( summarizeSavings( data ) ).toEqual( {
			videos: 12,
			originalBytes: 1000,
			encodedBytes: 200,
			reducedPercent: 80,
			allTime: { savedBytes: 900, savedPercent: 75 },
			range: { savedBytes: 50, savedPercent: 70 },
		} );
	} );

	it( 'leaves out bandwidth figures without CDN traffic data', () => {
		const summary = summarizeSavings( {
			...data,
			traffic_available: false,
			range: null,
			all_time: null,
		} );
		expect( summary.allTime ).toBeNull();
		expect( summary.range ).toBeNull();
	} );

	it( 'returns null when there is nothing to show', () => {
		expect( summarizeSavings( null ) ).toBeNull();
		expect( summarizeSavings( {} ) ).toBeNull();
		expect(
			summarizeSavings( {
				compression: { videos: 0 },
				traffic_available: false,
			} )
		).toBeNull();
	} );
} );

describe( 'savings api call', () => {
	it( 'asks for a library and a date range', async () => {
		apiFetch.mockReset().mockResolvedValue( {} );
		await api.savings( {
			libraryId: 'lib-1',
			from: '2026-11-01',
			to: '2026-11-27',
		} );
		const { path } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( path ).toBe(
			'/videooptimizer/v1/savings?library_id=lib-1&from=2026-11-01&to=2026-11-27'
		);
	} );

	it( 'needs no parameters for the whole organization', async () => {
		apiFetch.mockReset().mockResolvedValue( {} );
		await api.savings();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
			'/videooptimizer/v1/savings'
		);
	} );
} );

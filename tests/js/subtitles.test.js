/**
 * Subtitle helpers and API calls.
 */
import apiFetch from '@wordpress/api-fetch';
import { api } from '../../assets/src/components/api';
import {
	SUBTITLE_MAX_BYTES,
	checkSubtitleFile,
	downloadTextFile,
	languageLabel,
	validLanguageCode,
} from '../../assets/src/components/subtitles';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
const file = ( name, size = 10 ) => ( { name, size } );

describe( 'checkSubtitleFile', () => {
	it( 'accepts .vtt and .srt files up to 2 MB', () => {
		expect( checkSubtitleFile( file( 'de.vtt' ) ) ).toBe( '' );
		expect(
			checkSubtitleFile( file( 'DE.SRT', SUBTITLE_MAX_BYTES ) )
		).toBe( '' );
	} );

	it( 'explains other types and oversized files', () => {
		expect( checkSubtitleFile( file( 'de.txt' ) ) ).toContain( '.vtt' );
		expect(
			checkSubtitleFile( file( 'de.vtt', SUBTITLE_MAX_BYTES + 1 ) )
		).toContain( '2 MB' );
		expect( checkSubtitleFile( null ) ).toContain( '.vtt' );
	} );
} );

describe( 'validLanguageCode', () => {
	it.each( [ 'de', 'en-US', 'de-x-simple' ] )( '%s is valid', ( code ) => {
		expect( validLanguageCode( code ) ).toBe( true );
	} );
	it.each( [ '', 'd', 'de_DE', 'de en', 'a'.repeat( 40 ) ] )(
		'%j is not',
		( code ) => {
			expect( validLanguageCode( code ) ).toBe( false );
		}
	);
} );

describe( 'languageLabel', () => {
	it( 'uses the organization label and falls back to the code', () => {
		const languages = [ { code: 'de', label: 'Deutsch' } ];
		expect( languageLabel( languages, 'de' ) ).toBe( 'Deutsch' );
		expect( languageLabel( languages, 'fr' ) ).toBe( 'fr' );
	} );
} );

describe( 'downloadTextFile', () => {
	it( 'offers the content as a file download', () => {
		window.URL.createObjectURL = jest.fn( () => 'blob:x' );
		window.URL.revokeObjectURL = jest.fn();
		const click = jest
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( () => {} );

		downloadTextFile( 'de.vtt', 'WEBVTT\n', 'text/vtt' );

		expect( window.URL.createObjectURL.mock.calls[ 0 ][ 0 ].type ).toBe(
			'text/vtt'
		);
		expect( click ).toHaveBeenCalledTimes( 1 );
		expect( window.URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:x' );
		click.mockRestore();
	} );
} );

describe( 'subtitle api calls', () => {
	beforeEach( () => apiFetch.mockReset().mockResolvedValue( {} ) );

	it( 'uploads the file as multipart form data', async () => {
		const f = new window.File( [ 'x' ], 'de.vtt' );
		await api.uploadSubtitle( UUID, f, {
			language: 'de',
			kind: 'captions',
			isDefault: true,
		} );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.path ).toBe(
			`/videooptimizer/v1/videos/${ UUID }/subtitles`
		);
		expect( call.method ).toBe( 'POST' );
		expect( call.body instanceof window.FormData ).toBe( true );
		expect( call.body.get( 'language' ) ).toBe( 'de' );
		expect( call.body.get( 'kind' ) ).toBe( 'captions' );
		expect( call.body.get( 'is_default' ) ).toBe( 'true' );
		expect( call.body.get( 'file' ).name ).toBe( 'de.vtt' );
	} );

	it( 'omits the kind when not chosen', async () => {
		await api.uploadSubtitle( UUID, new window.File( [ 'x' ], 'a.srt' ), {
			language: 'de',
		} );
		const body = apiFetch.mock.calls[ 0 ][ 0 ].body;
		expect( body.has( 'kind' ) ).toBe( false );
		expect( body.has( 'is_default' ) ).toBe( false );
	} );

	it( 'sends the default flag only when set explicitly', async () => {
		const f = new window.File( [ 'x' ], 'a.vtt' );
		await api.uploadSubtitle( UUID, f, {
			language: 'de',
			isDefault: false,
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].body.get( 'is_default' ) ).toBe(
			'false'
		);
	} );

	it( 'lists, downloads and deletes by language', async () => {
		await api.subtitles( UUID );
		await api.downloadSubtitle( UUID, 'en-US' );
		await api.deleteSubtitle( UUID, 'en-US' );
		await api.subtitleLanguages();
		await api.createSubtitleLanguage( { code: 'fr', label: 'Français' } );
		await api.deleteSubtitleLanguage( 'fr' );

		expect(
			apiFetch.mock.calls.map(
				( [ o ] ) => ( o.method || 'GET' ) + ' ' + o.path
			)
		).toEqual( [
			`GET /videooptimizer/v1/videos/${ UUID }/subtitles`,
			`GET /videooptimizer/v1/videos/${ UUID }/subtitles/en-US`,
			`DELETE /videooptimizer/v1/videos/${ UUID }/subtitles/en-US`,
			'GET /videooptimizer/v1/subtitle-languages',
			'POST /videooptimizer/v1/subtitle-languages',
			'DELETE /videooptimizer/v1/subtitle-languages/fr',
		] );
	} );
} );

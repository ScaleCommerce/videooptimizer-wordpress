/**
 * Helpers for subtitle files and languages.
 */
import { __ } from '@wordpress/i18n';

export const SUBTITLE_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Checks a subtitle file before it is uploaded.
 *
 * @param {File|null} file Chosen file.
 * @return {string} An error message, or an empty string when the file is fine.
 */
export function checkSubtitleFile( file ) {
	if ( ! file || ! /\.(vtt|srt)$/i.test( file.name || '' ) ) {
		return __(
			'Please choose a .vtt or .srt file (up to 2 MB).',
			'videooptimizer'
		);
	}
	if ( file.size > SUBTITLE_MAX_BYTES ) {
		return __( 'The subtitle file is larger than 2 MB.', 'videooptimizer' );
	}
	return '';
}

/**
 * Whether a string is a BCP 47 language code such as de, en-US or de-x-simple.
 *
 * @param {string} code Language code.
 * @return {boolean} Valid.
 */
export const validLanguageCode = ( code ) =>
	code.length <= 35 && /^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/.test( code );

/**
 * The label of a language, falling back to its code.
 *
 * @param {Array<{code: string, label: string}>} languages Organization languages.
 * @param {string}                               code      Language code.
 * @return {string} Label.
 */
export function languageLabel( languages, code ) {
	const found = ( languages || [] ).find( ( l ) => l.code === code );
	return ( found && found.label ) || code;
}

/**
 * Offers text as a file download in the browser.
 *
 * @param {string} filename File name.
 * @param {string} content  Text.
 * @param {string} type     MIME type.
 */
export function downloadTextFile( filename, content, type = 'text/plain' ) {
	const url = window.URL.createObjectURL(
		new window.Blob( [ content ], { type } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
}

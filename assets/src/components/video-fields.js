/**
 * Pure helpers for editing tags and metadata of a video (rules of the VideoOptimizer API).
 */

export const MAX_TAGS = 20;
export const MAX_TAG_LENGTH = 50;
export const MAX_METADATA_KEYS = 10;
export const MAX_METADATA_VALUE = 64;

/**
 * Trims tag names, splits pasted comma lists and drops empty and duplicate names (ignoring case).
 *
 * @param {Array<string>} tokens Raw tag names.
 * @return {Array<string>} Tag names.
 */
export function normalizeTags( tokens ) {
	const seen = new Set();
	const result = [];
	tokens
		.flatMap( ( token ) => String( token ).split( ',' ) )
		.map( ( name ) => name.trim() )
		.forEach( ( name ) => {
			const needle = name.toLowerCase();
			if ( name && ! seen.has( needle ) ) {
				seen.add( needle );
				result.push( name );
			}
		} );
	return result;
}

/**
 * Whether a metadata key follows the API rule (1-40 characters of a-z 0-9 _ . -, lower case).
 *
 * @param {string} key Key.
 * @return {boolean} Valid.
 */
export const isValidMetadataKey = ( key ) =>
	/^[a-z0-9][a-z0-9_.-]{0,39}$/.test( key );

/**
 * Metadata object to editable rows.
 *
 * @param {Object|null} metadata Metadata of a video.
 * @return {Array<{key: string, value: string}>} Rows.
 */
export const metadataRows = ( metadata ) =>
	Object.entries( metadata || {} ).map( ( [ key, value ] ) => ( {
		key,
		value,
	} ) );

/**
 * The PATCH payload for edited rows: new or changed keys are set, removed keys and keys with an
 * emptied value become null (the API merges and deletes null keys).
 *
 * @param {Object|null}                         original Metadata before editing.
 * @param {Array<{key: string, value: string}>} rows     Rows after editing.
 * @return {Object|null} Patch, or null when nothing changed.
 */
export function metadataPatch( original, rows ) {
	const before = original || {};
	const after = {};
	rows.forEach( ( { key, value } ) => {
		const k = String( key ).trim();
		if ( k ) {
			after[ k ] = String( value ).trim();
		}
	} );

	const patch = {};
	Object.keys( before ).forEach( ( key ) => {
		if ( ! ( key in after ) || after[ key ] === '' ) {
			patch[ key ] = null;
		}
	} );
	Object.entries( after ).forEach( ( [ key, value ] ) => {
		if ( value !== '' && value !== before[ key ] ) {
			patch[ key ] = value;
		}
	} );

	return Object.keys( patch ).length ? patch : null;
}

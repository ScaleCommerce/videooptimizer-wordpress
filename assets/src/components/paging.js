/**
 * Pure helpers for paging through the video list with the API cursor.
 */

/** Videos requested per page. */
export const PAGE_SIZE = 60;

/**
 * Appends a freshly loaded page, skipping videos that are already in the list (a video added in
 * between shifts the page boundary).
 *
 * @param {Array} current Videos shown so far.
 * @param {Array} page    The next page.
 * @return {Array} Videos.
 */
export function appendPage( current, page ) {
	const seen = new Set( current.map( ( v ) => v.uuid ) );
	return current.concat( page.filter( ( v ) => ! seen.has( v.uuid ) ) );
}

/**
 * Refreshes the first page without dropping the pages loaded after it: the fresh first page comes
 * first, then every loaded video that is not part of it.
 *
 * @param {Array} current   Videos shown so far.
 * @param {Array} firstPage Freshly loaded first page.
 * @return {Array} Videos.
 */
export function mergeFirstPage( current, firstPage ) {
	const fresh = new Set( firstPage.map( ( v ) => v.uuid ) );
	return firstPage.concat( current.filter( ( v ) => ! fresh.has( v.uuid ) ) );
}

/**
 * Replaces one video in place.
 *
 * @param {Array}  list  Videos.
 * @param {Object} video Updated video.
 * @return {Array} Videos.
 */
export function replaceVideo( list, video ) {
	return list.map( ( v ) => ( v.uuid === video.uuid ? video : v ) );
}

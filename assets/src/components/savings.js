/**
 * Helpers for the savings summary (GET /savings).
 */

/**
 * The last `days` days up to today in UTC as YYYY-MM-DD (the API counts UTC days and refuses
 * future dates).
 *
 * @param {number} days Number of days, including today.
 * @param {Date}   now  Current time.
 * @return {{from: string, to: string}} Range.
 */
export function lastDaysRange( days, now = new Date() ) {
	const day = ( date ) => date.toISOString().slice( 0, 10 );
	const start = new Date( now.getTime() - ( days - 1 ) * 86400000 );
	return { from: day( start ), to: day( now ) };
}

/**
 * Condenses the API payload for display.
 *
 * @param {Object|null} data Savings payload.
 * @return {Object|null} Summary, or null when there is nothing to show yet.
 */
export function summarizeSavings( data ) {
	const compression = ( data && data.compression ) || {};
	if ( ! compression.videos ) {
		return null;
	}
	const traffic = ( part ) =>
		data.traffic_available && part
			? {
					savedBytes: part.saved_bytes || 0,
					savedPercent: part.saved_percent || 0,
			  }
			: null;

	return {
		videos: compression.videos,
		originalBytes: compression.original_bytes || 0,
		encodedBytes: compression.encoded_bytes || 0,
		reducedPercent: compression.reduced_percent || 0,
		allTime: traffic( data.all_time ),
		range: traffic( data.range ),
	};
}

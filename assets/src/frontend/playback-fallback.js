/**
 * MP4 fallback after an HLS failure. Playback resumes when the visitor or the page asked for it
 * (autoplay attribute, play() call, play event), so heroes and scroll-autoload players do not
 * stay on the poster.
 */

/**
 * Remembers that playback was requested for this video.
 *
 * @param {HTMLVideoElement} video
 */
export function markPlayIntent( video ) {
	video.voWantsPlay = true;
}

/**
 * Pauses a video on behalf of the page (e.g. a hero scrolled out of view): the visitor did not
 * ask for it, so the play intent stays.
 *
 * @param {HTMLVideoElement} video
 */
export function pauseBySystem( video ) {
	video.voSystemPause = true;
	video.pause();
}

/**
 * Tracks play and pause events as play intent. A pause the visitor causes clears it.
 * Idempotent.
 *
 * @param {HTMLVideoElement} video
 */
export function trackPlayIntent( video ) {
	if ( video.voIntentTracked ) {
		return;
	}
	video.voIntentTracked = true;
	video.addEventListener( 'play', () => markPlayIntent( video ) );
	video.addEventListener( 'pause', () => {
		if ( video.voSystemPause ) {
			video.voSystemPause = false;
		} else if ( ! video.ended ) {
			video.voWantsPlay = false;
		}
	} );
}

/**
 * @param {HTMLVideoElement} video
 * @return {boolean} Whether playback should continue after a fallback.
 */
export function wantsPlayback( video ) {
	return !! ( video.autoplay || video.voWantsPlay || ! video.paused );
}

/**
 * Drops the HLS source (or swaps to an MP4), reloads and resumes if wanted. The MP4 is the given
 * URL, else `data-mp4` (heroes), else the `<source>` children / `src` attribute.
 *
 * @param {HTMLVideoElement} video
 * @param {string}           hlsSrc   The failed HLS master URL.
 * @param {string|null}      fallback Optional MP4 URL.
 * @param {boolean}          resume   Whether to resume; defaults to the current intent (pass it
 *                                    when computed before the HLS teardown).
 */
export function fallbackToMp4(
	video,
	hlsSrc,
	fallback = null,
	resume = wantsPlayback( video )
) {
	const mp4 =
		fallback ||
		video.getAttribute( 'data-mp4' ) ||
		video.getAttribute( 'src' );
	if ( mp4 && mp4 !== hlsSrc ) {
		video.src = mp4;
	} else {
		video.removeAttribute( 'src' );
	}
	video.load();
	if ( resume ) {
		const result = video.play();
		if ( result && result.catch ) {
			result.catch( () => {} );
		}
	}
}

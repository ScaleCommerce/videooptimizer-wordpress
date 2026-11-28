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
		expectEvent( video, 'play' );
		const result = video.play();
		if ( result && result.catch ) {
			result.catch( () => clearExpected( video, 'play' ) );
		}
	}
}

/**
 * Marks an event that the plugin itself is about to cause (its own play(), seek or unmute), so
 * listeners can tell it from a visitor's action. The mark is consumed by the first matching event
 * and expires, because a call that changes nothing fires no event.
 *
 * @param {Object} target  Video element or hosted frame.
 * @param {string} type    Event name: 'play', 'volumechange' or 'seeking'.
 * @param {number} timeout Milliseconds the mark stays valid.
 */
export function expectEvent( target, type, timeout = 1000 ) {
	target.voExpected = target.voExpected || {};
	target.voExpected[ type ] = Date.now() + timeout;
}

/**
 * @param {Object} target Video element or hosted frame.
 * @param {string} type   Event name.
 * @return {boolean} True when the event was expected (and consumes the mark).
 */
export function consumeExpected( target, type ) {
	const until = target.voExpected && target.voExpected[ type ];
	if ( until && until >= Date.now() ) {
		delete target.voExpected[ type ];
		return true;
	}
	return false;
}

/**
 * Drops an expectation (the call that should have caused the event was rejected, e.g. autoplay
 * blocked), so the visitor's next real event is not mistaken for the plugin's.
 *
 * @param {Object} target Video element or hosted frame.
 * @param {string} type   Event name.
 */
export function clearExpected( target, type ) {
	if ( target.voExpected ) {
		delete target.voExpected[ type ];
	}
}

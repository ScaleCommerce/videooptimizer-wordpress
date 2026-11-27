/**
 * Play while scrolling: a direct player starts muted once it is at least half visible and pauses
 * when it leaves. A pause by the visitor sticks; reduced motion and Save-Data never autoplay.
 */
import { pauseBySystem } from './playback-fallback';
import { sendCommand, watchHosted } from './hosted';

const THRESHOLD = 0.5;

const saveData = () =>
	!! (
		window.navigator &&
		window.navigator.connection &&
		window.navigator.connection.saveData
	);

/**
 * Whether the floating mini player takes care of a player that scrolls away.
 *
 * @param {Element} element Player element.
 * @return {boolean} True when it is wrapped by an active mini player.
 */
function miniTakesOver( element ) {
	return (
		!! element.closest( '.vo-mini' ) &&
		!! window.matchMedia &&
		window.matchMedia( '(min-width: 600px)' ).matches
	);
}

function observe( elements, callback ) {
	if ( ! elements.length ) {
		return;
	}
	if ( ! ( 'IntersectionObserver' in window ) ) {
		elements.forEach( ( el ) => callback( el, true ) );
		return;
	}
	const io = new IntersectionObserver(
		( entries ) => {
			entries.forEach( ( entry ) =>
				callback(
					entry.target,
					entry.isIntersecting &&
						entry.intersectionRatio >= THRESHOLD - 0.001
				)
			);
		},
		{ threshold: [ 0, THRESHOLD ] }
	);
	elements.forEach( ( el ) => io.observe( el ) );
}

/**
 * @param {Document|Element} root
 * @param {Object}           deps
 * @param {Function}         deps.play          Attaches the source and plays a native video.
 * @param {Function}         deps.attachHls     Wires HLS without loading segments.
 * @param {Function}         deps.embedIframe   Creates a hosted player iframe.
 * @param {Function}         deps.reducedMotion Whether reduced motion is requested.
 */
export function initScrollPlay(
	root,
	{ play, attachHls, embedIframe, reducedMotion }
) {
	const blocked = () => reducedMotion() || saveData();

	/* Native players */
	const videos = Array.from(
		root.querySelectorAll(
			'video.vo-native[data-vo-scroll-play]:not([data-vo-sp])'
		)
	);
	videos.forEach( ( video ) => {
		video.setAttribute( 'data-vo-sp', '' );
		video.addEventListener( 'play', () => {
			video.voUserPaused = false;
		} );
		video.addEventListener( 'pause', () => {
			if ( video.voScrollPaused ) {
				video.voScrollPaused = false;
			} else if ( ! video.ended ) {
				video.voUserPaused = true;
			}
		} );
		if ( blocked() ) {
			// No motion on its own: a normal player the visitor starts.
			video.controls = true;
			video.removeAttribute( 'data-vo-tap' );
		}
	} );
	observe( videos, ( video, active ) => {
		if ( blocked() ) {
			if ( active ) {
				attachHls( video, { startLoad: false } );
			}
			return;
		}
		if ( active ) {
			if ( video.paused && ! video.voUserPaused ) {
				play( video );
			}
		} else if ( ! video.paused && ! miniTakesOver( video ) ) {
			video.voScrollPaused = true;
			pauseBySystem( video );
		}
	} );

	/* Hosted players */
	const frames = Array.from(
		root.querySelectorAll(
			'.vo-frame[data-vo-scroll-url]:not([data-vo-sp])'
		)
	);
	frames.forEach( ( frame ) => {
		frame.setAttribute( 'data-vo-sp', '' );
		watchHosted( frame, ( event ) => {
			if ( event === 'play' ) {
				frame.voPlaying = true;
				frame.voUserPaused = false;
			} else if ( event === 'pause' || event === 'ended' ) {
				frame.voPlaying = false;
				if ( frame.voScrollPaused ) {
					frame.voScrollPaused = false;
				} else if ( event === 'pause' ) {
					frame.voUserPaused = true;
				}
			}
		} );
	} );
	observe( frames, ( frame, active ) => {
		let iframe = frame.querySelector( 'iframe' );
		if ( active ) {
			if ( ! iframe ) {
				const poster = frame.querySelector( '.vo-poster__img' );
				const url = blocked()
					? frame.getAttribute( 'data-vo-scroll-fallback' )
					: frame.getAttribute( 'data-vo-scroll-url' );
				if ( url ) {
					iframe = embedIframe( url, poster ? poster.alt : '' );
					frame.replaceChildren( iframe );
				}
			} else if (
				! blocked() &&
				! frame.voPlaying &&
				! frame.voUserPaused
			) {
				sendCommand( iframe, 'play' );
			}
		} else if ( iframe && frame.voPlaying && ! miniTakesOver( frame ) ) {
			frame.voScrollPaused = true;
			sendCommand( iframe, 'pause' );
		}
	} );
}

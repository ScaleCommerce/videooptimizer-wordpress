/**
 * Chapters and scenes: a click on a scene preview starts the video at that time. Native players
 * get `currentTime` (after the source is attached), hosted players a new iframe `src` with
 * `t=<seconds>&autoplay=1`. The scene that is playing is marked with aria-current.
 */
import {
	clearExpected,
	expectEvent,
	markPlayIntent,
} from './playback-fallback';
import { watchHosted } from './hosted';

/**
 * @param {Document|Element} root
 * @param {Object}           deps
 * @param {Function}         deps.attachHls   Wires HLS and resolves when the video can be seeked.
 * @param {Function}         deps.embedIframe Creates a hosted player iframe.
 */
export function initChapters( root, { attachHls, embedIframe } ) {
	root.querySelectorAll( '[data-vo-chapters]:not([data-vo-wired])' ).forEach(
		( chapters ) => {
			chapters.setAttribute( 'data-vo-wired', '' );
			const frame = chapters.querySelector( '.vo-frame' );
			const scenes = Array.from(
				chapters.querySelectorAll( '[data-vo-seek]' )
			);
			if ( ! frame || ! scenes.length ) {
				return;
			}
			const starts = scenes.map( ( scene ) =>
				Number( scene.getAttribute( 'data-vo-seek' ) )
			);

			const mark = ( index ) =>
				scenes.forEach( ( scene, i ) => {
					if ( i === index ) {
						scene.setAttribute( 'aria-current', 'true' );
					} else {
						scene.removeAttribute( 'aria-current' );
					}
				} );
			// The last scene that started at or before the time.
			const sceneAt = ( time ) => {
				let found = -1;
				starts.forEach( ( start, i ) => {
					if ( start <= time + 0.5 ) {
						found = i;
					}
				} );
				return found;
			};

			const video = frame.querySelector( 'video' );
			if ( video ) {
				video.addEventListener( 'timeupdate', () => {
					if ( ! video.paused ) {
						mark( sceneAt( video.currentTime ) );
					}
				} );
				video.addEventListener( 'ended', () => mark( -1 ) );
			}
			watchHosted( frame, ( event, data ) => {
				if ( event === 'ended' ) {
					mark( -1 );
				} else if (
					event === 'play' &&
					Number( data.currentTime ) >= 1 // A just reloaded player reports 0 before it seeks.
				) {
					mark( sceneAt( Number( data.currentTime ) ) );
				}
			} );

			const seekNative = ( time ) => {
				markPlayIntent( video );
				video.voUserPaused = false;
				attachHls( video, { startLoad: true } ).then( () => {
					expectEvent( video, 'seeking' );
					video.currentTime = time;
					if ( video.voHls ) {
						video.voHls.startLoad( time );
					}
					expectEvent( video, 'play' );
					const result = video.play();
					if ( result && result.catch ) {
						result.catch( () => clearExpected( video, 'play' ) );
					}
				} );
			};

			const seekHosted = ( time ) => {
				const base = chapters.getAttribute( 'data-vo-embed-base' );
				if ( ! base ) {
					return;
				}
				const url = new URL( base, window.location.href );
				url.searchParams.set( 't', String( time ) );
				url.searchParams.set( 'autoplay', '1' );
				let iframe = frame.querySelector( 'iframe' );
				if ( iframe ) {
					iframe.src = url.href;
					return;
				}
				// Not injected yet (lazy): drop the pending injection and create the player now.
				frame.removeAttribute( 'data-vo-autoload' );
				const poster = frame.querySelector( '.vo-poster__img' );
				iframe = embedIframe( url.href, poster ? poster.alt : '' );
				frame.replaceChildren( iframe );
			};

			const mini = chapters.querySelector( '[data-vo-mini]' );
			scenes.forEach( ( scene, index ) => {
				scene.addEventListener( 'click', () => {
					// A scene click is the visitor starting playback: the mini player may pin.
					if ( mini ) {
						mini.dispatchEvent( new Event( 'vo:visitor-start' ) );
					}
					const time = starts[ index ];
					if ( video ) {
						seekNative( time );
					} else {
						seekHosted( time );
					}
					mark( index );
				} );
			} );
		}
	);
}

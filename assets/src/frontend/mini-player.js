/**
 * Floating mini player: once the VISITOR started playback and the player scrolls out of view, its
 * box is pinned to the bottom right (CSS only: neither <video> nor <iframe> is ever moved in the
 * DOM). A placeholder keeps the page height. Close button / Escape unpin and pause; scrolling back
 * unpins. Inactive below 600px and while a lightbox is open; opening a lightbox stops the player.
 *
 * "Started by the visitor": the plugin marks everything it does itself (its play() for autoplay,
 * scroll play and scenes, its seek, the autoplay attribute at load) as expected; any other play,
 * volumechange (unmute) or larger seek of a native video is the visitor's, because the browser's
 * own control bar sends no pointer or key events into the page. Scene clicks count; hosted players
 * count when their iframe has the focus (the visitor clicked into it) while they report play or
 * volumechange. Not used: userActivation, touch, "plays with sound".
 */
import {
	clearExpected,
	consumeExpected,
	expectEvent,
} from './playback-fallback';
import { sendCommand, watchHosted } from './hosted';

const MIN_WIDTH = '(min-width: 600px)';
const SEEK_JUMP = 1.5; // Seconds: smaller jumps are hls.js nudging, not a visitor.
const AUTOPLAY_GRACE = 5000;

/**
 * @param {Document|Element} root
 */
export function initMiniPlayers( root ) {
	root.querySelectorAll( '[data-vo-mini]:not([data-vo-wired])' ).forEach(
		( mini ) => {
			mini.setAttribute( 'data-vo-wired', '' );
			const box = mini.querySelector( '.vo-mini__box' );
			const frame = mini.querySelector( '.vo-frame' );
			if ( ! box || ! frame ) {
				return;
			}
			const close = mini.querySelector( '.vo-mini__close' );
			const state = {
				playing: false,
				started: false, // Started by the visitor.
				visible: true,
				dismissed: false,
				pinned: false,
			};

			const wide = () =>
				!! window.matchMedia && window.matchMedia( MIN_WIDTH ).matches;
			const lightboxOpen = () =>
				document.documentElement.classList.contains(
					'vo-lightbox-open'
				);

			const setPinned = ( pinned ) => {
				if ( pinned === state.pinned ) {
					return;
				}
				state.pinned = pinned;
				// The placeholder keeps the height, so the page does not jump.
				mini.style.height = pinned
					? box.getBoundingClientRect().height + 'px'
					: '';
				mini.classList.toggle( 'is-pinned', pinned );
			};

			const update = () => {
				mini.voVisitorStarted = state.started;
				if ( state.pinned ) {
					if (
						state.visible ||
						state.dismissed ||
						! wide() ||
						lightboxOpen()
					) {
						setPinned( false );
					}
					return;
				}
				if (
					state.started &&
					state.playing &&
					! state.visible &&
					! state.dismissed &&
					wide() &&
					! lightboxOpen()
				) {
					setPinned( true );
				}
			};

			const visitor = () => {
				state.started = true;
				state.dismissed = false;
			};

			const pauseAll = () => {
				const video = frame.querySelector( 'video' );
				const iframe = frame.querySelector( 'iframe' );
				if ( video ) {
					video.pause();
				}
				if ( iframe ) {
					sendCommand( iframe, 'pause' );
				}
				state.playing = false;
			};

			// The visitor closed it (button or Escape): unpin, pause, focus the player.
			const stop = () => {
				state.dismissed = true;
				state.started = false;
				pauseAll();
				update();
				const target =
					frame.querySelector( 'video' ) ||
					frame.querySelector( 'iframe' ) ||
					box;
				target.focus( { preventScroll: true } );
				if ( box.ownerDocument.activeElement !== target ) {
					box.focus( { preventScroll: true } );
				}
			};

			// A lightbox opened: stop everything so there is no double audio.
			document.addEventListener( 'vo:lightbox-open', () => {
				if ( ! state.pinned && ! state.playing ) {
					return;
				}
				const iframe = frame.querySelector( 'iframe' );
				if ( iframe && iframe.src ) {
					// A hosted player cannot be trusted to obey a command: reload it without autoplay.
					const url = new URL( iframe.src, window.location.href );
					url.searchParams.set( 'autoplay', '0' );
					url.searchParams.delete( 't' );
					iframe.src = url.href;
				}
				pauseAll();
				state.started = false;
				update();
				setPinned( false );
			} );

			const video = frame.querySelector( 'video' );
			if ( video ) {
				if ( video.autoplay ) {
					// The browser's autoplay at load is not the visitor. When the browser blocked it
					// the video is still paused once it could play: the mark must not swallow the
					// visitor's own play.
					expectEvent( video, 'play', AUTOPLAY_GRACE );
					video.addEventListener(
						'canplay',
						() =>
							window.setTimeout( () => {
								if ( video.paused ) {
									clearExpected( video, 'play' );
								}
							}, 500 ),
						{ once: true }
					);
				}
				let lastTime = 0;
				video.addEventListener( 'timeupdate', () => {
					lastTime = video.currentTime;
				} );
				video.addEventListener( 'play', () => {
					if ( ! consumeExpected( video, 'play' ) ) {
						visitor();
					}
					state.playing = true;
					update();
				} );
				video.addEventListener( 'volumechange', () => {
					if ( ! consumeExpected( video, 'volumechange' ) ) {
						visitor();
						update();
					}
				} );
				video.addEventListener( 'seeking', () => {
					const own = consumeExpected( video, 'seeking' );
					if (
						! own &&
						Math.abs( video.currentTime - lastTime ) > SEEK_JUMP
					) {
						visitor();
						update();
					}
					lastTime = video.currentTime;
				} );
				video.addEventListener( 'pause', () => {
					state.playing = false;
				} );
				video.addEventListener( 'ended', () => {
					state.playing = false;
					state.started = false;
					update();
					setPinned( false );
				} );
			}
			watchHosted( frame, ( event ) => {
				const iframe = frame.querySelector( 'iframe' );
				if ( event === 'play' || event === 'volumechange' ) {
					const own = consumeExpected( frame, event );
					if (
						! own &&
						iframe &&
						box.ownerDocument.activeElement === iframe
					) {
						visitor();
					}
					if ( event === 'play' ) {
						state.playing = true;
					}
					update();
				} else if ( event === 'pause' ) {
					state.playing = false;
				} else if ( event === 'ended' ) {
					state.playing = false;
					state.started = false;
					update();
					setPinned( false );
				}
			} );

			// A scene click (chapters) is the visitor starting playback.
			mini.addEventListener( 'vo:visitor-start', () => {
				visitor();
				update();
			} );

			if ( close ) {
				close.addEventListener( 'click', stop );
			}
			mini.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' && state.pinned ) {
					event.stopPropagation();
					stop();
				}
			} );

			if ( 'IntersectionObserver' in window ) {
				new IntersectionObserver(
					( entries ) => {
						const entry = entries[ entries.length - 1 ];
						state.visible =
							entry.isIntersecting &&
							entry.intersectionRatio >= 0.01;
						update();
					},
					{ threshold: [ 0, 0.01 ] }
				).observe( mini );
			}
			if ( window.matchMedia ) {
				const query = window.matchMedia( MIN_WIDTH );
				if ( query.addEventListener ) {
					query.addEventListener( 'change', update );
				}
			}
		}
	);
}

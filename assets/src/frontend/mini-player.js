/**
 * Floating mini player: once playback started and the player scrolls out of view, its box is
 * pinned to the bottom right (CSS only: neither <video> nor <iframe> is ever moved in the DOM).
 * A placeholder keeps the page height. Close button / Escape unpin and pause; scrolling back
 * unpins. Inactive below 600px and while the lightbox is open.
 */
import { sendCommand, watchHosted } from './hosted';

const MIN_WIDTH = '(min-width: 600px)';

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
				if ( pinned ) {
					// The placeholder keeps the height, so the page does not jump.
					mini.style.height =
						box.getBoundingClientRect().height + 'px';
				} else {
					mini.style.height = '';
				}
				mini.classList.toggle( 'is-pinned', pinned );
			};

			const update = () => {
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
					state.playing &&
					! state.visible &&
					! state.dismissed &&
					wide() &&
					! lightboxOpen()
				) {
					setPinned( true );
				}
			};

			const stop = () => {
				state.dismissed = true;
				const video = frame.querySelector( 'video' );
				const iframe = frame.querySelector( 'iframe' );
				if ( video ) {
					video.pause();
				}
				if ( iframe ) {
					sendCommand( iframe, 'pause' );
				}
				state.playing = false;
				update();
			};

			const played = () => {
				state.playing = true;
				state.dismissed = false;
				update();
			};
			const paused = () => {
				state.playing = false;
			};

			const video = frame.querySelector( 'video' );
			if ( video ) {
				video.addEventListener( 'play', played );
				video.addEventListener( 'pause', paused );
				video.addEventListener( 'ended', () => {
					paused();
					update();
					setPinned( false );
				} );
			}
			watchHosted( frame, ( event ) => {
				if ( event === 'play' ) {
					played();
				} else if ( event === 'pause' ) {
					paused();
				} else if ( event === 'ended' ) {
					paused();
					setPinned( false );
				}
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

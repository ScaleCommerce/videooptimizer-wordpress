/**
 * VideoOptimizer frontend: facade / lightbox / lazy players, HLS wiring, background heroes,
 * WooCommerce gallery + hover previews. Vanilla JS, no dependencies; hls.js is loaded on demand
 * and only in browsers without native HLS.
 */
import './frontend.css';
import {
	fallbackToMp4,
	markPlayIntent,
	pauseBySystem,
	trackPlayIntent,
	wantsPlayback,
} from './playback-fallback';

const config = window.videooptimizerFrontend || {};
const i18n = config.i18n || {};
const reducedMotion = () =>
	!! window.matchMedia &&
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
const HLS_TYPE = 'application/vnd.apple.mpegurl';

/* ------------------------------------------------------------------ HLS */

let hlsPromise = null;

/**
 * Native HLS is used on Apple devices (best battery/AirPlay) and wherever MediaSource is missing.
 * Everywhere else hls.js is preferred: newer Chromium builds report native HLS support, but it is
 * not yet reliable for every stream.
 *
 * @param {HTMLVideoElement} video
 * @return {boolean} Whether to use the browser's own HLS playback.
 */
function preferNativeHls( video ) {
	if ( ! video.canPlayType( HLS_TYPE ) ) {
		return false;
	}
	const nav = window.navigator;
	const apple =
		/Apple/.test( nav.vendor || '' ) ||
		/iPad|iPhone|iPod/.test( nav.userAgent || '' );
	const mse = 'MediaSource' in window || 'ManagedMediaSource' in window;
	return apple || ! mse;
}

/**
 * Plays the HLS master natively; if the browser rejects it, falls back to the MP4 (src attribute
 * or <source> children).
 *
 * @param {HTMLVideoElement} video
 * @param {string}           src   HLS master URL.
 */
function playNativeHls( video, src ) {
	const fallback = video.getAttribute( 'src' );
	video.addEventListener(
		'error',
		() => fallbackToMp4( video, src, fallback ),
		{ once: true }
	);
	trackPlayIntent( video );
	video.src = src;
}

function loadHls() {
	if ( window.Hls ) {
		return Promise.resolve( window.Hls );
	}
	if ( ! hlsPromise ) {
		hlsPromise = new Promise( ( resolve, reject ) => {
			const script = document.createElement( 'script' );
			script.src = config.hlsUrl;
			script.async = true;
			script.onload = () => resolve( window.Hls );
			script.onerror = reject;
			document.head.appendChild( script );
		} );
	}
	return hlsPromise;
}

/**
 * Wires the HLS master in data-hls to a <video>: native HLS in Safari/iOS, hls.js elsewhere.
 * Without HLS support the <source> MP4 children play as usual. Idempotent.
 *
 * @param {HTMLVideoElement} video
 * @param {Object}           options
 * @param {boolean}          options.startLoad Start fetching segments right away.
 * @return {Promise<void>} Resolves once the video can be played.
 */
function attachHls( video, { startLoad = true } = {} ) {
	trackPlayIntent( video );
	if ( video.voReady ) {
		return video.voReady;
	}
	const src = video.getAttribute( 'data-hls' );
	if ( ! src ) {
		video.voReady = Promise.resolve();
		return video.voReady;
	}
	if ( preferNativeHls( video ) ) {
		playNativeHls( video, src );
		video.voReady = Promise.resolve();
		return video.voReady;
	}
	video.voReady = loadHls()
		.then( ( Hls ) => {
			if ( ! Hls || ! Hls.isSupported() ) {
				if ( video.canPlayType( HLS_TYPE ) ) {
					playNativeHls( video, src );
				}
				return;
			}
			const hls = new Hls( {
				autoStartLoad: startLoad,
				capLevelToPlayerSize: true,
			} );
			hls.loadSource( src );
			hls.attachMedia( video );
			video.voHls = hls;
			if ( ! startLoad ) {
				video.addEventListener( 'play', () => hls.startLoad(), {
					once: true,
				} );
			}
			return new Promise( ( resolve ) => {
				hls.on( Hls.Events.MANIFEST_PARSED, () => resolve() );
				hls.on( Hls.Events.ERROR, ( _event, data ) => {
					if ( data && data.fatal ) {
						// Fall back to the MP4 (data-mp4 or <source> children).
						const resume = wantsPlayback( video );
						hls.destroy();
						video.voHls = null;
						fallbackToMp4( video, src, null, resume );
						resolve();
					}
				} );
			} );
		} )
		.catch( () => {} );
	return video.voReady;
}

function play( video ) {
	markPlayIntent( video );
	return attachHls( video ).then( () => {
		const result = video.play();
		return result && result.catch ? result.catch( () => {} ) : result;
	} );
}

/* ------------------------------------------------------------------ Helpers */

function onVisible( elements, callback, rootMargin = '200px 0px' ) {
	if ( ! elements.length ) {
		return;
	}
	if ( ! ( 'IntersectionObserver' in window ) ) {
		elements.forEach( callback );
		return;
	}
	const io = new IntersectionObserver(
		( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					io.unobserve( entry.target );
					callback( entry.target );
				}
			} );
		},
		{ rootMargin }
	);
	elements.forEach( ( el ) => io.observe( el ) );
}

function embedIframe( url, title ) {
	const iframe = document.createElement( 'iframe' );
	iframe.src = url;
	iframe.title = title || i18n.play || 'Video';
	iframe.allow = 'autoplay; fullscreen; picture-in-picture';
	iframe.allowFullscreen = true;
	iframe.referrerPolicy = 'strict-origin-when-cross-origin';
	return iframe;
}

function pauseIframe( iframe ) {
	try {
		iframe.contentWindow.postMessage(
			{ type: 'videooptimizer:command', command: 'pause' },
			'*'
		);
	} catch ( e ) {}
}

/**
 * Pauses every VideoOptimizer player inside an element.
 *
 * @param {Element} root Container.
 */
function pauseWithin( root ) {
	root.querySelectorAll( 'video.vo-native' ).forEach( pauseBySystem );
	root.querySelectorAll( '.vo-frame iframe' ).forEach( pauseIframe );
}

/* ------------------------------------------------------------------ Native players */

function initNativePlayers( root ) {
	const videos = Array.from(
		root.querySelectorAll( 'video.vo-native:not([data-vo-wired])' )
	).filter( ( v ) => ! v.closest( '.vo-native-holder' ) );

	videos.forEach( ( video ) => {
		video.setAttribute( 'data-vo-wired', '' );
		if ( video.hasAttribute( 'data-vo-tap' ) ) {
			video.addEventListener( 'click', () =>
				video.paused ? play( video ) : video.pause()
			);
		}
	} );

	const eager = videos.filter(
		( v ) => v.getAttribute( 'preload' ) !== 'none'
	);
	eager.forEach( ( video ) => {
		if ( reducedMotion() ) {
			video.removeAttribute( 'autoplay' );
			video.pause();
		}
		attachHls( video );
	} );

	// Deferred players: wire HLS shortly before they become visible (no segments yet), so a
	// click on the native play button uses the adaptive stream instead of the first MP4.
	const deferred = videos.filter(
		( v ) =>
			v.getAttribute( 'preload' ) === 'none' &&
			! v.hasAttribute( 'data-vo-native-autoload' )
	);
	onVisible( deferred, ( video ) =>
		attachHls( video, { startLoad: false } )
	);

	const autoload = videos.filter( ( v ) =>
		v.hasAttribute( 'data-vo-native-autoload' )
	);
	if ( ! reducedMotion() ) {
		onVisible(
			autoload,
			( video ) => {
				video.removeAttribute( 'data-vo-native-autoload' );
				play( video );
			},
			'0px'
		);
	} else {
		onVisible( autoload, ( video ) =>
			attachHls( video, { startLoad: false } )
		);
	}
}

/* ------------------------------------------------------------------ Hosted player */

function initAutoload( root ) {
	const frames = Array.from(
		root.querySelectorAll( '.vo-frame[data-vo-autoload]' )
	);
	onVisible( frames, ( frame ) => {
		const url = frame.getAttribute( 'data-vo-autoload' );
		frame.removeAttribute( 'data-vo-autoload' );
		if ( url ) {
			const img = frame.querySelector( '.vo-poster__img' );
			frame.replaceChildren( embedIframe( url, img ? img.alt : '' ) );
		}
	} );
}

/* ------------------------------------------------------------------ Facade */

function revealNative( frame ) {
	const holder = frame.querySelector( '.vo-native-holder' );
	const video = holder && holder.querySelector( 'video' );
	if ( ! video ) {
		return false;
	}
	holder.hidden = false;
	video.setAttribute( 'preload', 'auto' );
	play( video );
	return true;
}

function initFacades( root ) {
	root.querySelectorAll( '[data-vo-embed]:not([data-vo-wired])' ).forEach(
		( trigger ) => {
			trigger.setAttribute( 'data-vo-wired', '' );
			trigger.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				const frame = trigger.closest( '.vo-frame' );
				if ( ! frame ) {
					return;
				}
				if (
					trigger.getAttribute( 'data-vo-player' ) === 'native' &&
					revealNative( frame )
				) {
					trigger.hidden = true;
					const video = frame.querySelector( 'video' );
					if ( video ) {
						video.focus();
					}
					return;
				}
				const iframe = embedIframe(
					trigger.getAttribute( 'data-vo-embed' ),
					trigger.getAttribute( 'aria-label' )
				);
				frame.replaceChildren( iframe );
				iframe.focus();
			} );
		}
	);
}

/* ------------------------------------------------------------------ Lightbox */

let lightbox = null;

function getLightbox() {
	if ( lightbox ) {
		return lightbox;
	}
	// A native modal dialog: focus stays inside, Escape closes it and the browser makes the rest
	// of the page inert. Browsers without showModal() get the same by hand.
	const box = document.createElement( 'dialog' );
	box.className = 'vo-lightbox vo-blocks';
	box.setAttribute( 'aria-label', i18n.play || 'Video' );
	box.innerHTML =
		'<div class="vo-lightbox__inner"><div class="vo-lightbox__slot"></div></div>';
	const close = document.createElement( 'button' );
	close.type = 'button';
	close.className = 'vo-lightbox__close';
	close.setAttribute( 'aria-label', i18n.close || 'Close' );
	close.innerHTML = '&times;';
	box.querySelector( '.vo-lightbox__inner' ).appendChild( close );
	document.body.appendChild( box );

	const slot = box.querySelector( '.vo-lightbox__slot' );
	const inner = box.querySelector( '.vo-lightbox__inner' );
	const state = { lastFocus: null, holder: null, inerted: [] };
	const modal = typeof box.showModal === 'function';

	const restoreInert = () => {
		state.inerted.forEach( ( el ) => el.removeAttribute( 'inert' ) );
		state.inerted = [];
	};

	// Single clean-up path: runs for the close button, Escape, the backdrop and `close` events.
	const cleanup = () => {
		if ( ! box.hasAttribute( 'open' ) && ! state.lastFocus ) {
			return;
		}
		box.removeAttribute( 'open' );
		restoreInert();
		document.documentElement.classList.remove( 'vo-lightbox-open' );
		if ( state.holder ) {
			const video = slot.querySelector( 'video' );
			if ( video ) {
				video.pause();
				state.holder.appendChild( video ); // Keep it wired for the next open.
			}
			state.holder = null;
		}
		slot.replaceChildren(); // Stops iframe playback.
		const focus = state.lastFocus;
		state.lastFocus = null;
		if ( focus ) {
			focus.focus();
		}
	};
	const doClose = () => {
		if ( modal && box.hasAttribute( 'open' ) ) {
			box.close(); // Fires `close`, which cleans up.
		} else {
			cleanup();
		}
	};

	box.addEventListener( 'close', cleanup );
	box.addEventListener( 'cancel', ( event ) => {
		event.preventDefault();
		doClose();
	} );
	box.addEventListener( 'click', ( event ) => {
		if ( event.target === box || event.target === close ) {
			doClose();
		}
	} );
	document.addEventListener( 'keydown', ( event ) => {
		if ( ! box.hasAttribute( 'open' ) ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			doClose();
			return;
		}
		if ( event.key === 'Tab' && ! modal ) {
			const focusable = box.querySelectorAll(
				'button, iframe, video[controls], a[href], [tabindex]:not([tabindex="-1"])'
			);
			if ( ! focusable.length ) {
				return;
			}
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			if ( event.shiftKey && box.ownerDocument.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if (
				! event.shiftKey &&
				box.ownerDocument.activeElement === last
			) {
				event.preventDefault();
				first.focus();
			}
		}
	} );

	lightbox = {
		open( trigger ) {
			state.lastFocus = box.ownerDocument.activeElement || trigger;
			state.holder = null;
			const frame = trigger.closest( '.vo-frame' );
			// Keep the real aspect ratio (portrait videos stay tall, not letterboxed).
			const ratio = frame && frame.style.aspectRatio;
			inner.style.aspectRatio = ratio || '';
			inner.classList.toggle(
				'vo-lightbox__inner--portrait',
				!! frame && frame.classList.contains( 'vo-frame--portrait' )
			);
			if (
				frame &&
				frame.style.getPropertyValue( '--vo-player-accent' )
			) {
				box.style.setProperty(
					'--vo-player-accent',
					frame.style.getPropertyValue( '--vo-player-accent' )
				);
			}

			const holder =
				trigger.getAttribute( 'data-vo-player' ) === 'native' && frame
					? frame.querySelector( '.vo-native-holder' )
					: null;
			const video = holder && holder.querySelector( 'video' );
			if ( video ) {
				state.holder = holder;
				slot.replaceChildren( video );
				video.setAttribute( 'preload', 'auto' );
				play( video );
			} else {
				slot.replaceChildren(
					embedIframe(
						trigger.getAttribute( 'data-vo-lightbox' ),
						trigger.getAttribute( 'aria-label' )
					)
				);
			}
			if ( modal ) {
				box.showModal();
			} else {
				box.setAttribute( 'open', '' );
				Array.from( document.body.children ).forEach( ( el ) => {
					if ( el !== box && ! el.hasAttribute( 'inert' ) ) {
						el.setAttribute( 'inert', '' );
						state.inerted.push( el );
					}
				} );
			}
			document.documentElement.classList.add( 'vo-lightbox-open' );
			close.focus();
		},
	};
	return lightbox;
}

function initLightbox( root ) {
	root.querySelectorAll( '[data-vo-lightbox]:not([data-vo-wired])' ).forEach(
		( trigger ) => {
			trigger.setAttribute( 'data-vo-wired', '' );
			trigger.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				getLightbox().open( trigger );
			} );
		}
	);
}

/* ------------------------------------------------------------------ Background hero */

/**
 * Pause/play button of a looping background video (WCAG 2.2.2). The label follows the state;
 * a pause by the visitor sticks, scrolling the hero out of view and back does not undo it.
 *
 * @param {HTMLVideoElement} video
 * @param {Function}         start   Starts playback from scratch (first start, e.g. reduced motion).
 * @param {boolean}          playing Initial state.
 */
function wireHeroToggle( video, start, playing ) {
	const hero = video.closest( '.vo-bg-hero' );
	const button = hero && hero.querySelector( '[data-vo-hero-toggle]' );
	if ( ! button ) {
		return;
	}
	const show = ( isPlaying ) => {
		const label = button.getAttribute(
			isPlaying ? 'data-label-pause' : 'data-label-play'
		);
		if ( label ) {
			button.setAttribute( 'aria-label', label );
		}
		button.setAttribute( 'data-state', isPlaying ? 'playing' : 'paused' );
	};
	show( playing );
	button.hidden = false;
	button.addEventListener( 'click', () => {
		if ( button.getAttribute( 'data-state' ) === 'playing' ) {
			video.voUserPaused = true;
			video.pause();
			show( false );
			return;
		}
		video.voUserPaused = false;
		if ( video.currentSrc || video.voHls ) {
			video.play().catch( () => {} );
		} else {
			start( video );
		}
		show( true );
	} );
}

function initBackgrounds( root ) {
	const videos = Array.from(
		root.querySelectorAll( 'video.vo-bg-hero__video:not([data-vo-wired])' )
	);
	videos.forEach( ( video ) => video.setAttribute( 'data-vo-wired', '' ) );

	const start = ( video ) => {
		markPlayIntent( video );
		const mp4 = video.getAttribute( 'data-mp4' );
		const src = video.getAttribute( 'data-hls' );
		const canNative = src && preferNativeHls( video );
		const fallback = () => {
			if ( mp4 && ! video.voHls && ! video.src ) {
				video.src = mp4;
			}
		};
		if ( ! src || canNative ) {
			if ( canNative ) {
				playNativeHls( video, src );
			} else {
				fallback();
			}
			video.play().catch( () => {} );
			return;
		}
		loadHls()
			.then( ( Hls ) => {
				if ( Hls && Hls.isSupported() ) {
					attachHls( video ).then( () =>
						video.play().catch( () => {} )
					);
				} else {
					fallback();
					video.play().catch( () => {} );
				}
			} )
			.catch( () => {
				fallback();
				video.play().catch( () => {} );
			} );
	};

	const reduced = reducedMotion();
	videos.forEach( ( video ) => wireHeroToggle( video, start, ! reduced ) );
	if ( reduced ) {
		return; // Poster only, until the visitor presses play.
	}

	onVisible( videos, start, '100px 0px' );

	// Pause heroes that scroll out of view (saves CPU/battery), resume when back.
	if ( 'IntersectionObserver' in window ) {
		const io = new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				const video = entry.target;
				if ( ! video.currentSrc && ! video.voHls ) {
					return;
				}
				if ( entry.isIntersecting ) {
					if ( ! video.voUserPaused ) {
						video.play().catch( () => {} );
					}
				} else {
					pauseBySystem( video );
				}
			} );
		} );
		videos.forEach( ( v ) => io.observe( v ) );
	}
}

/* ------------------------------------------------------------------ WooCommerce */

function initWooGallery( root ) {
	root.querySelectorAll(
		'.woocommerce-product-gallery:not([data-vo-wired])'
	).forEach( ( gallery ) => {
		const slides = gallery.querySelectorAll(
			'.videooptimizer-gallery__slide'
		);
		if ( ! slides.length ) {
			return;
		}
		gallery.setAttribute( 'data-vo-wired', '' );

		// Pause a video as soon as its slide is swiped away.
		if ( 'IntersectionObserver' in window ) {
			const io = new IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						if ( ! entry.isIntersecting ) {
							pauseWithin( entry.target );
						}
					} );
				},
				{ threshold: 0.5 }
			);
			slides.forEach( ( slide ) => io.observe( slide ) );
		}

		// Mark the thumbnails of video slides once FlexSlider has built them.
		const markThumbs = () => {
			const thumbs = gallery.querySelectorAll(
				'.flex-control-thumbs li'
			);
			if ( ! thumbs.length ) {
				return false;
			}
			const all = gallery.querySelectorAll(
				'.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image'
			);
			all.forEach( ( slide, index ) => {
				if (
					slide.classList.contains(
						'videooptimizer-gallery__slide'
					) &&
					thumbs[ index ]
				) {
					thumbs[ index ].classList.add( 'videooptimizer-thumb' );
				}
			} );
			return true;
		};
		if ( ! markThumbs() && 'MutationObserver' in window ) {
			const mo = new MutationObserver( () => {
				if ( markThumbs() ) {
					mo.disconnect();
				}
			} );
			mo.observe( gallery, { childList: true, subtree: true } );
		}

		// Variation videos: jump to the video slide when shoppers select that variation.
		// WooCommerce resets the slider to the first slide on variation changes, so jump afterwards.
		const $ = window.jQuery;
		const form = gallery
			.closest( '.product, .wp-block-group, body' )
			.querySelector( 'form.variations_form' );
		if ( $ && form ) {
			$( form ).on( 'found_variation', ( _event, variation ) => {
				const uuid = variation && variation.videooptimizer_video;
				if ( ! uuid ) {
					return;
				}
				const all = Array.from(
					gallery.querySelectorAll(
						'.woocommerce-product-gallery__wrapper > .woocommerce-product-gallery__image'
					)
				);
				const index = all.findIndex(
					( slide ) => slide.getAttribute( 'data-vo-uuid' ) === uuid
				);
				if ( index < 0 ) {
					return;
				}
				window.setTimeout( () => {
					const slider = $( gallery ).data( 'flexslider' );
					if ( slider ) {
						slider.flexAnimate( index );
					}
				}, 150 );
			} );
		}

		// The PhotoSwipe trigger zooms images; hide it while a video slide is active.
		const syncTrigger = () => {
			const active = gallery.querySelector( '.flex-active-slide' );
			gallery.classList.toggle(
				'videooptimizer-gallery--video-active',
				!! active &&
					active.classList.contains( 'videooptimizer-gallery__slide' )
			);
		};
		if ( 'MutationObserver' in window ) {
			new MutationObserver( syncTrigger ).observe( gallery, {
				attributes: true,
				attributeFilter: [ 'class' ],
				subtree: true,
			} );
		}
	} );
}

/**
 * WooCommerce's own gallery videos (media library) delivered via VideoOptimizer: the server set
 * the VideoOptimizer MP4 as src and the HLS master as data-hls. Switch to adaptive streaming once
 * visible. In the classic gallery only with native HLS, because WooCommerce's PhotoSwipe
 * lightbox re-uses video.currentSrc (a blob: URL under hls.js would not play there).
 *
 * @param {Document|Element} root Container.
 */
function initWooNativeVideos( root ) {
	const videos = Array.from(
		root.querySelectorAll(
			'video[data-vo-wc][data-hls]:not([data-vo-wired])'
		)
	).filter(
		// Thumbnail previews stay on the lightweight MP4.
		( video ) =>
			! video.closest(
				'.wc-block-product-gallery-thumbnails, .flex-control-thumbs'
			)
	);
	videos.forEach( ( video ) => video.setAttribute( 'data-vo-wired', '' ) );
	const upgrade = ( video ) => {
		const classic = !! video.closest( '.woocommerce-product-gallery' );
		if ( classic && ! preferNativeHls( video ) ) {
			return; // Keep the MP4.
		}
		const wasPlaying = ! video.paused;
		const time = video.currentTime;
		attachHls( video ).then( () => {
			if ( time ) {
				video.currentTime = time;
			}
			if ( wasPlaying || video.autoplay ) {
				video.play().catch( () => {} );
			}
		} );
	};
	onVisible( videos, upgrade, '100px 0px' );
}

function initHover( root ) {
	const canHover =
		window.matchMedia &&
		window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
	if ( ! canHover || reducedMotion() ) {
		return;
	}
	root.querySelectorAll( '[data-vo-hover]:not([data-vo-wired])' ).forEach(
		( wrapper ) => {
			wrapper.setAttribute( 'data-vo-wired', '' );
			const item =
				wrapper.closest( 'li, .wc-block-product, .product' ) || wrapper;
			let video = null;
			let timer = null;

			// Cover exactly the product image (themes add margins/radius below and around it).
			const fit = () => {
				const img = wrapper.querySelector( 'img' );
				if ( video && img ) {
					video.style.top = img.offsetTop + 'px';
					video.style.left = img.offsetLeft + 'px';
					video.style.width = img.offsetWidth + 'px';
					video.style.height = img.offsetHeight + 'px';
					video.style.borderRadius =
						window.getComputedStyle( img ).borderRadius;
				}
			};
			const enter = () => {
				timer = window.setTimeout( () => {
					if ( ! video ) {
						video = document.createElement( 'video' );
						video.className = 'videooptimizer-hover__video';
						video.muted = true;
						video.loop = true;
						video.playsInline = true;
						video.setAttribute( 'aria-hidden', 'true' );
						video.preload = 'auto';
						video.src = wrapper.getAttribute( 'data-vo-hover' );
						video.addEventListener( 'playing', () =>
							wrapper.classList.add( 'is-playing' )
						);
						wrapper.appendChild( video );
					}
					fit();
					video.play().catch( () => {} );
				}, 120 ); // Ignore quick fly-overs.
			};
			const leave = () => {
				window.clearTimeout( timer );
				wrapper.classList.remove( 'is-playing' );
				if ( video ) {
					video.pause();
				}
			};
			item.addEventListener( 'mouseenter', enter );
			item.addEventListener( 'mouseleave', leave );
			item.addEventListener( 'focusin', enter );
			item.addEventListener( 'focusout', leave );
		}
	);
}

/* ------------------------------------------------------------------ Misc */

function initReveal( root ) {
	const els = Array.from( root.querySelectorAll( '.vo-reveal:not(.vo-in)' ) );
	if ( ! ( 'IntersectionObserver' in window ) || reducedMotion() ) {
		els.forEach( ( el ) => el.classList.add( 'vo-in' ) );
		return;
	}
	const io = new IntersectionObserver(
		( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'vo-in' );
					io.unobserve( entry.target );
				}
			} );
		},
		{ threshold: 0.15 }
	);
	els.forEach( ( el ) => io.observe( el ) );
}

// Only one native player plays at a time.
document.addEventListener(
	'play',
	( event ) => {
		const target = event.target;
		if (
			! ( target instanceof HTMLVideoElement ) ||
			! target.classList.contains( 'vo-native' )
		) {
			return;
		}
		document.querySelectorAll( 'video.vo-native' ).forEach( ( other ) => {
			if ( other !== target && ! other.paused ) {
				other.pause();
			}
		} );
	},
	true
);

/**
 * Initializes everything inside root (idempotent — safe to call again for content added
 * later, e.g. by Elementor or AJAX filters).
 *
 * @param {Document|Element} root
 */
export function init( root = document ) {
	document.documentElement.classList.add( 'vo-js' );
	initNativePlayers( root );
	initAutoload( root );
	initFacades( root );
	initLightbox( root );
	initBackgrounds( root );
	initWooGallery( root );
	initWooNativeVideos( root );
	initHover( root );
	initReveal( root );
}

window.videooptimizer = { init, pauseWithin };

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => init() );
} else {
	init();
}

// Elementor preview: widgets are (re)rendered without a page load.
window.addEventListener( 'elementor/frontend/init', () => {
	const elementorFrontend = window.elementorFrontend;
	if ( elementorFrontend && elementorFrontend.hooks ) {
		elementorFrontend.hooks.addAction(
			'frontend/element_ready/videooptimizer.default',
			( $scope ) => init( $scope[ 0 ] )
		);
	}
} );

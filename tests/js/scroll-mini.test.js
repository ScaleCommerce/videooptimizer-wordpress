/**
 * Play while scrolling and the floating mini player (assets/src/frontend/scroll-play.js,
 * mini-player.js), exercised through the frontend entry.
 */
const MP4 = 'https://cdn.example/encoded/u/h264/720p.mp4';
const HLS = 'https://cdn.example/encoded/u/hls/master.m3u8';

let observers;

class FakeIO {
	constructor( callback, options ) {
		this.callback = callback;
		this.options = options;
		this.targets = [];
		observers.push( this );
	}
	observe( el ) {
		this.targets.push( el );
	}
	unobserve() {}
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/**
 * Fires the observers that watch the element.
 *
 * @param {Element} el    Observed element.
 * @param {number}  ratio Intersection ratio.
 */
function intersect( el, ratio ) {
	observers
		.filter( ( io ) => io.targets.includes( el ) )
		.forEach( ( io ) =>
			io.callback( [
				{
					target: el,
					isIntersecting: ratio > 0,
					intersectionRatio: ratio,
				},
			] )
		);
}

function setViewport( { reduced = false, wide = true } = {} ) {
	window.matchMedia = jest.fn( ( query ) => ( {
		matches: query.includes( 'reduce' ) ? reduced : wide,
		addEventListener: () => {},
	} ) );
}

function load( html ) {
	jest.resetModules();
	document.body.innerHTML = html;
	document.documentElement.className = '';
	window.videooptimizerFrontend = { hlsUrl: 'x', i18n: {} };
	require( '../../assets/src/frontend/index.js' );
	// A controllable paused state for every video.
	document.querySelectorAll( 'video' ).forEach( ( video ) => {
		let paused = true;
		Object.defineProperty( video, 'paused', { get: () => paused } );
		video.play = jest.fn( () => {
			paused = false;
			video.dispatchEvent( new Event( 'play' ) );
			return Promise.resolve();
		} );
		video.pause = jest.fn( () => {
			paused = true;
			video.dispatchEvent( new Event( 'pause' ) );
		} );
	} );
}

const scrollVideo = ( extra = '' ) =>
	`<video class="vo-native" muted playsinline preload="none" controls data-vo-scroll-play data-mp4="${ MP4 }" src="${ MP4 }" ${ extra }></video>`;

beforeEach( () => {
	observers = [];
	window.IntersectionObserver = FakeIO;
	setViewport();
	delete window.Hls;
	delete window.MediaSource;
	delete window.navigator.connection;
	jest.spyOn( window.navigator, 'vendor', 'get' ).mockReturnValue( '' );
} );

describe( 'play while scrolling (native)', () => {
	it( 'observes with a 50 % threshold, plays muted when half visible and pauses on leave', async () => {
		load( scrollVideo() );
		const video = document.querySelector( 'video' );
		const io = observers.find(
			( o ) => o.targets.includes( video ) && o.options.threshold
		);
		expect( io.options.threshold ).toContain( 0.5 );

		intersect( video, 0.3 );
		await flush();
		expect( video.play ).not.toHaveBeenCalled();

		intersect( video, 0.6 );
		await flush();
		expect( video.play ).toHaveBeenCalledTimes( 1 );
		expect( video.hasAttribute( 'muted' ) ).toBe( true );

		intersect( video, 0.2 );
		expect( video.pause ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not restart a video the visitor paused', async () => {
		load( scrollVideo() );
		const video = document.querySelector( 'video' );
		intersect( video, 1 );
		await flush();

		video.pause(); // The visitor.
		intersect( video, 0.1 );
		intersect( video, 1 );
		await flush();

		expect( video.play ).toHaveBeenCalledTimes( 1 );

		video.play(); // The visitor presses play again: scroll play is back.
		intersect( video, 0.1 );
		expect( video.pause ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'resumes after its own pause when it scrolls back', async () => {
		load( scrollVideo() );
		const video = document.querySelector( 'video' );
		intersect( video, 1 );
		await flush();
		intersect( video, 0 );
		intersect( video, 1 );
		await flush();

		expect( video.play ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'never autoplays for visitors who prefer reduced motion and keeps the controls', async () => {
		setViewport( { reduced: true } );
		load(
			`<video class="vo-native" muted preload="none" data-vo-scroll-play data-vo-tap src="${ MP4 }"></video>`
		);
		const video = document.querySelector( 'video' );
		intersect( video, 1 );
		await flush();

		expect( video.play ).not.toHaveBeenCalled();
		expect( video.controls ).toBe( true );
	} );

	it( 'never autoplays with Save-Data', async () => {
		window.navigator.connection = { saveData: true };
		load( scrollVideo() );
		const video = document.querySelector( 'video' );
		intersect( video, 1 );
		await flush();

		expect( video.play ).not.toHaveBeenCalled();
	} );

	it( 'leaves the pause to the mini player on wide screens', async () => {
		load(
			`<div class="vo-mini" data-vo-mini><div class="vo-mini__box"><div class="vo-frame">${ scrollVideo() }</div></div></div>`
		);
		const video = document.querySelector( 'video' );
		// The visitor took over (e.g. clicked a scene); an untouched autoplay is paused like any other.
		document
			.querySelector( '[data-vo-mini]' )
			.dispatchEvent( new Event( 'vo:visitor-start' ) );
		intersect( video, 1 );
		await flush();
		intersect( video, 0.2 );

		expect( video.pause ).not.toHaveBeenCalled();
	} );

	it( 'attaches hls.js only when playing starts', async () => {
		const hls = [];
		window.Hls = class {
			static isSupported() {
				return true;
			}
			static Events = { MANIFEST_PARSED: 'p', ERROR: 'e' };
			constructor( options ) {
				this.options = options;
				this.startLoad = jest.fn();
				hls.push( this );
			}
			loadSource() {}
			attachMedia() {}
			on() {}
			destroy() {}
		};
		window.MediaSource = function MediaSource() {};
		load(
			`<video class="vo-native" muted preload="none" data-vo-scroll-play data-hls="${ HLS }" src="${ MP4 }"></video>`
		);
		await flush();

		// Wired shortly before it is visible, but segments are not requested until it plays.
		expect( hls.every( ( h ) => h.options.autoStartLoad === false ) ).toBe(
			true
		);
	} );
} );

describe( 'play while scrolling (hosted)', () => {
	const hosted = `<div class="vo-frame" data-vo-scroll-url="https://player.example/embed/u?autoplay=1&muted=1" data-vo-scroll-fallback="https://player.example/embed/u"><img class="vo-poster__img" alt="Clip"></div>`;

	it( 'injects the muted autoplaying player when half visible, then pauses and plays by command', async () => {
		load( hosted );
		const frame = document.querySelector( '.vo-frame' );

		intersect( frame, 0.3 );
		expect( frame.querySelector( 'iframe' ) ).toBeNull();

		intersect( frame, 0.7 );
		const iframe = frame.querySelector( 'iframe' );
		expect( iframe.src ).toBe(
			'https://player.example/embed/u?autoplay=1&muted=1'
		);
		iframe.contentWindow.postMessage = jest.fn();
		window.dispatchEvent(
			new MessageEvent( 'message', {
				data: { type: 'videooptimizer:event', event: 'play' },
				source: iframe.contentWindow,
			} )
		);

		intersect( frame, 0.1 );
		expect( iframe.contentWindow.postMessage ).toHaveBeenLastCalledWith(
			{ type: 'videooptimizer:command', command: 'pause' },
			'*'
		);
		window.dispatchEvent(
			new MessageEvent( 'message', {
				data: { type: 'videooptimizer:event', event: 'pause' },
				source: iframe.contentWindow,
			} )
		);

		intersect( frame, 1 );
		expect( iframe.contentWindow.postMessage ).toHaveBeenLastCalledWith(
			{ type: 'videooptimizer:command', command: 'play' },
			'*'
		);
	} );

	it( 'does not play again after the visitor paused inside the player', () => {
		load( hosted );
		const frame = document.querySelector( '.vo-frame' );
		intersect( frame, 1 );
		const iframe = frame.querySelector( 'iframe' );
		iframe.contentWindow.postMessage = jest.fn();
		const send = ( event ) =>
			window.dispatchEvent(
				new MessageEvent( 'message', {
					data: { type: 'videooptimizer:event', event },
					source: iframe.contentWindow,
				} )
			);
		send( 'play' );
		send( 'pause' ); // Not caused by the page.

		intersect( frame, 0.1 );
		intersect( frame, 1 );

		expect( iframe.contentWindow.postMessage ).not.toHaveBeenCalled();
	} );

	it( 'injects a plain player (no autoplay) for reduced motion', () => {
		setViewport( { reduced: true } );
		load( hosted );
		const frame = document.querySelector( '.vo-frame' );
		intersect( frame, 1 );

		expect( frame.querySelector( 'iframe' ).src ).toBe(
			'https://player.example/embed/u'
		);
	} );
} );

describe( 'floating mini player', () => {
	const mini = `<div class="vo-mini" data-vo-mini><div class="vo-mini__box"><div class="vo-frame">${ '<video class="vo-native" controls></video>' }</div><button type="button" class="vo-mini__close" aria-label="Close mini player">x</button></div></div>`;

	function setup( html = mini ) {
		load( html );
		const el = document.querySelector( '[data-vo-mini]' );
		const video = el.querySelector( 'video' );
		return { el, video };
	}

	it( 'pins once playing and scrolled out, keeps the height and unpins when back', () => {
		const { el, video } = setup();
		video.play();

		intersect( el, 0.005 );
		expect( el.classList ).toContain( 'is-pinned' );
		expect( el.style.height ).not.toBe( '' );
		expect( el.contains( video ) ).toBe( true );

		intersect( el, 0.5 );
		expect( el.classList ).not.toContain( 'is-pinned' );
		expect( el.style.height ).toBe( '' );
	} );

	it( 'does not pin before playback started or while paused', () => {
		const { el, video } = setup();
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );

		intersect( el, 1 );
		video.play();
		video.pause();
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );
	} );

	it( 'does not pin below 600px or while the lightbox is open', () => {
		setViewport( { wide: false } );
		let { el, video } = setup();
		video.play();
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );

		setViewport( { wide: true } );
		( { el, video } = setup() );
		document.documentElement.classList.add( 'vo-lightbox-open' );
		video.play();
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );
	} );

	it( 'closes with the button: unpins and pauses; it stays closed until playback starts again', () => {
		const { el, video } = setup();
		video.play();
		intersect( el, 0 );
		expect( el.classList ).toContain( 'is-pinned' );

		el.querySelector( '.vo-mini__close' ).click();
		expect( el.classList ).not.toContain( 'is-pinned' );
		expect( video.pause ).toHaveBeenCalled();

		intersect( el, 0.9 );
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );

		video.play();
		intersect( el, 0 );
		expect( el.classList ).toContain( 'is-pinned' );
	} );

	it( 'closes with Escape from inside the mini player', () => {
		const { el, video } = setup();
		video.play();
		intersect( el, 0 );

		el.querySelector( '.vo-mini__close' ).dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);

		expect( el.classList ).not.toContain( 'is-pinned' );
		expect( video.pause ).toHaveBeenCalled();
	} );

	it( 'unpins when playback ends', () => {
		const { el, video } = setup();
		video.play();
		intersect( el, 0 );
		video.dispatchEvent( new Event( 'ended' ) );

		expect( el.classList ).not.toContain( 'is-pinned' );
	} );

	it( 'does not pin for autoplay, scroll play or any play the plugin starts itself', async () => {
		const { el, video } = setup(
			`<div class="vo-mini" data-vo-mini><div class="vo-mini__box" tabindex="-1"><div class="vo-frame"><video class="vo-native" muted autoplay data-vo-scroll-play preload="none" src="${ MP4 }"></video></div><button type="button" class="vo-mini__close">x</button></div></div>`
		);
		// The browser's autoplay at load.
		video.play();
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );

		// Play while scrolling: the plugin calls play() when the video is half visible.
		video.pause();
		intersect( video, 1 );
		await flush();
		intersect( el, 1 );
		intersect( el, 0 );
		expect( el.classList ).not.toContain( 'is-pinned' );
		expect( video.pause ).toHaveBeenCalled(); // Not taken over: scroll play pauses it as usual.
	} );

	it( "clears its expectation when its own play() is rejected, so the visitor's next play counts", async () => {
		const { el, video } = setup(
			`<div class="vo-mini" data-vo-mini><div class="vo-mini__box"><div class="vo-frame"><video class="vo-native" muted data-vo-scroll-play preload="none" src="${ MP4 }"></video></div><button type="button" class="vo-mini__close">x</button></div></div>`
		);
		// Autoplay is blocked: play() rejects and fires no event.
		video.play = jest.fn( () => Promise.reject( new Error( 'blocked' ) ) );
		intersect( video, 1 );
		await flush();
		await flush();

		// The visitor presses the native play button.
		video.dispatchEvent( new Event( 'play' ) );
		intersect( el, 0 );

		expect( el.classList ).toContain( 'is-pinned' );
	} );

	it( 'counts a visitor interaction with an autoplaying video: unmute takes over', async () => {
		const { el, video } = setup(
			`<div class="vo-mini" data-vo-mini><div class="vo-mini__box"><div class="vo-frame"><video class="vo-native" muted autoplay data-vo-scroll-play preload="none" src="${ MP4 }"></video></div><button type="button" class="vo-mini__close">x</button></div></div>`
		);
		video.play(); // Autoplay.
		video.dispatchEvent( new Event( 'volumechange' ) ); // The visitor unmutes.
		intersect( video, 0.1 );
		expect( video.pause ).not.toHaveBeenCalled(); // The mini player took over.
		intersect( el, 0 );

		expect( el.classList ).toContain( 'is-pinned' );
	} );

	it( 'ignores small seeks (hls.js nudges) but counts a visitor jump', () => {
		const { el, video } = setup();
		video.currentTime = 0;
		video.dispatchEvent( new Event( 'timeupdate' ) );
		video.dispatchEvent( new Event( 'seeking' ) ); // Same position.
		video.pause();
		video.play = jest.fn(); // Playing happens without a visitor event in this test.
		expect( el.voVisitorStarted ).toBeFalsy();

		video.currentTime = 30;
		video.dispatchEvent( new Event( 'seeking' ) );
		expect( el.voVisitorStarted ).toBe( true );
	} );

	it( 'a scene click is the visitor starting playback', () => {
		const { el, video } = setup();
		el.dispatchEvent( new Event( 'vo:visitor-start' ) );
		// Started by the plugin from here on (e.g. the scene seek).
		video.dispatchEvent( new Event( 'pause' ) );
		window.dispatchEvent( new Event( 'resize' ) );
		expect( el.voVisitorStarted ).toBe( true );
	} );

	it( 'a lightbox opening stops the pinned player (native paused, hosted reloaded without autoplay)', () => {
		const { el, video } = setup();
		video.play();
		intersect( el, 0 );
		expect( el.classList ).toContain( 'is-pinned' );

		document.dispatchEvent( new CustomEvent( 'vo:lightbox-open' ) );

		expect( el.classList ).not.toContain( 'is-pinned' );
		expect( video.pause ).toHaveBeenCalled();
	} );

	it( 'moves the focus to the player after closing, never to the body', () => {
		const { el, video } = setup();
		video.focus = jest.fn();
		video.play();
		intersect( el, 0 );

		el.querySelector( '.vo-mini__close' ).click();

		expect( video.focus ).toHaveBeenCalledWith( { preventScroll: true } );
	} );

	describe( 'hosted', () => {
		const hosted = `<div class="vo-mini" data-vo-mini><div class="vo-mini__box"><div class="vo-frame"><iframe src="https://player.example/embed/u?autoplay=1"></iframe></div><button type="button" class="vo-mini__close">x</button></div></div>`;
		const send = ( iframe, event ) =>
			window.dispatchEvent(
				new MessageEvent( 'message', {
					data: { type: 'videooptimizer:event', event },
					source: iframe.contentWindow,
				} )
			);

		it( 'does not pin an autoplaying hosted player the visitor never touched', () => {
			const { el } = setup( hosted );
			const iframe = el.querySelector( 'iframe' );
			send( iframe, 'play' );
			intersect( el, 0 );

			expect( el.classList ).not.toContain( 'is-pinned' );
		} );

		it( 'pins once the visitor clicked into it (iframe focused) and it plays; close pauses by command', () => {
			const { el } = setup( hosted );
			const iframe = el.querySelector( 'iframe' );
			iframe.contentWindow.postMessage = jest.fn();
			iframe.focus();
			send( iframe, 'play' );
			intersect( el, 0 );

			expect( el.classList ).toContain( 'is-pinned' );
			expect( el.contains( iframe ) ).toBe( true );

			el.querySelector( '.vo-mini__close' ).click();
			expect( el.classList ).not.toContain( 'is-pinned' );
			expect( iframe.contentWindow.postMessage ).toHaveBeenCalledWith(
				{ type: 'videooptimizer:command', command: 'pause' },
				'*'
			);
		} );

		it( 'a lightbox stops a pinned hosted player by reloading it without autoplay', () => {
			const { el } = setup( hosted );
			const iframe = el.querySelector( 'iframe' );
			iframe.focus();
			send( iframe, 'play' );
			intersect( el, 0 );

			document.dispatchEvent( new CustomEvent( 'vo:lightbox-open' ) );

			expect( new URL( iframe.src ).searchParams.get( 'autoplay' ) ).toBe(
				'0'
			);
			expect( el.classList ).not.toContain( 'is-pinned' );
		} );
	} );
} );

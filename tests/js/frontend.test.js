/**
 * Frontend player (assets/src/frontend/index.js): facades, lightbox, HLS wiring, heroes.
 * The module wires itself on import, so every test loads a fresh copy against its own markup.
 */

const HLS_MASTER = 'https://cdn.example/encoded/u/hls/master.m3u8';
const MP4 = 'https://cdn.example/encoded/u/h264/720p.mp4';

/** Hls.js stand-in that records what the player does with it. */
class FakeHls {
	static supported = true;
	static instances = [];
	static Events = { MANIFEST_PARSED: 'parsed', ERROR: 'error' };
	static isSupported() {
		return FakeHls.supported;
	}
	constructor( options ) {
		this.options = options;
		this.handlers = {};
		this.destroyed = false;
		this.startLoad = jest.fn();
		FakeHls.instances.push( this );
	}
	loadSource( src ) {
		this.source = src;
	}
	attachMedia( video ) {
		this.media = video;
	}
	on( name, handler ) {
		this.handlers[ name ] = handler;
	}
	destroy() {
		this.destroyed = true;
	}
	emit( name, ...args ) {
		this.handlers[ name ]( ...args );
	}
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

function setMatchMedia( reduced ) {
	window.matchMedia = jest.fn( () => ( { matches: reduced } ) );
}

/**
 * Loads a fresh frontend module against the given markup.
 *
 * @param {string} html Page markup.
 */
function loadFrontend( html ) {
	jest.resetModules();
	document.body.innerHTML = html;
	document.documentElement.className = '';
	window.videooptimizerFrontend = {
		hlsUrl: 'https://example.test/hls.min.js',
		i18n: { play: 'Play video', close: 'Close' },
	};
	require( '../../assets/src/frontend/index.js' );
}

const nativeVideo = ( attrs = '' ) =>
	`<video class="vo-native" data-hls="${ HLS_MASTER }" data-mp4="${ MP4 }" src="${ MP4 }" ${ attrs }></video>`;

let canPlayHls;
let vendor;

beforeEach( () => {
	FakeHls.instances = [];
	FakeHls.supported = true;
	window.Hls = FakeHls;
	delete window.IntersectionObserver;
	delete window.MediaSource;
	delete window.ManagedMediaSource;
	canPlayHls = '';
	setMatchMedia( false );
	// jsdom reports an Apple vendor; tests opt in to that explicitly.
	vendor = jest
		.spyOn( window.navigator, 'vendor', 'get' )
		.mockReturnValue( 'Google Inc.' );
	window.HTMLMediaElement.prototype.play = jest.fn( () => Promise.resolve() );
	window.HTMLMediaElement.prototype.pause = jest.fn();
	window.HTMLMediaElement.prototype.load = jest.fn();
	window.HTMLMediaElement.prototype.canPlayType = jest.fn( () => canPlayHls );
} );

afterEach( () => {
	vendor.mockRestore();
	delete window.Hls;
	delete window.videooptimizer;
} );

describe( 'initialization', () => {
	it( 'marks the page, exposes init and is idempotent', () => {
		loadFrontend(
			`<div class="vo-frame"><button data-vo-embed="https://player.example/embed/u" aria-label="Play">x</button></div>`
		);
		expect( document.documentElement.classList ).toContain( 'vo-js' );
		expect( typeof window.videooptimizer.init ).toBe( 'function' );

		window.videooptimizer.init();
		window.videooptimizer.init();
		document.querySelector( 'button' ).click();

		expect( document.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );
	} );
} );

describe( 'hosted facade', () => {
	it( 'replaces the poster with the hosted player iframe on click', () => {
		loadFrontend(
			`<div class="vo-frame"><button data-vo-embed="https://player.example/embed/u" aria-label="Play Imagefilm"><img class="vo-poster__img" alt=""></button></div>`
		);

		document.querySelector( 'button' ).click();

		const iframe = document.querySelector( '.vo-frame iframe' );
		expect( iframe.src ).toBe( 'https://player.example/embed/u' );
		expect( iframe.title ).toBe( 'Play Imagefilm' );
		expect( iframe.allow ).toContain( 'fullscreen' );
		expect( iframe.referrerPolicy ).toBe(
			'strict-origin-when-cross-origin'
		);
		expect( document.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'loads an autoload frame as soon as it is visible', () => {
		loadFrontend(
			`<div class="vo-frame" data-vo-autoload="https://player.example/embed/u"><img class="vo-poster__img" alt="Hero clip"></div>`
		);

		const iframe = document.querySelector( '.vo-frame iframe' );
		expect( iframe.src ).toBe( 'https://player.example/embed/u' );
		expect( iframe.title ).toBe( 'Hero clip' );
	} );

	it( 'waits for the intersection observer when there is one', () => {
		const observed = [];
		window.IntersectionObserver = class {
			constructor( callback ) {
				this.callback = callback;
			}
			observe( el ) {
				observed.push( [ this, el ] );
			}
			unobserve() {}
		};
		loadFrontend(
			`<div class="vo-frame" data-vo-autoload="https://player.example/embed/u"><img class="vo-poster__img" alt=""></div>`
		);
		expect( document.querySelector( 'iframe' ) ).toBeNull();

		const [ io, el ] = observed[ 0 ];
		io.callback( [ { isIntersecting: true, target: el } ] );

		expect( document.querySelector( 'iframe' ) ).not.toBeNull();
	} );
} );

describe( 'native facade', () => {
	const facade = `<div class="vo-frame">
		<button data-vo-embed="https://player.example/embed/u" data-vo-player="native" aria-label="Play">x</button>
		<div class="vo-native-holder" hidden><video class="vo-native" preload="none" data-hls="${ HLS_MASTER }" data-mp4="${ MP4 }"></video></div>
	</div>`;

	it( 'reveals the native player, plays it through hls.js and hides the trigger', async () => {
		loadFrontend( facade );
		const video = document.querySelector( 'video' );

		document.querySelector( 'button' ).click();
		await flush();

		expect( document.querySelector( '.vo-native-holder' ).hidden ).toBe(
			false
		);
		expect( document.querySelector( 'button' ).hidden ).toBe( true );
		expect( video.getAttribute( 'preload' ) ).toBe( 'auto' );
		expect( FakeHls.instances ).toHaveLength( 1 );
		expect( FakeHls.instances[ 0 ].source ).toBe( HLS_MASTER );
		expect( FakeHls.instances[ 0 ].media ).toBe( video );
		FakeHls.instances[ 0 ].emit( FakeHls.Events.MANIFEST_PARSED );
		await flush();
		expect( video.play ).toHaveBeenCalled();
		expect( document.querySelector( 'iframe' ) ).toBeNull();
	} );

	it( 'falls back to the hosted iframe when there is no native player', () => {
		loadFrontend(
			`<div class="vo-frame"><button data-vo-embed="https://player.example/embed/u" data-vo-player="native" aria-label="Play">x</button></div>`
		);

		document.querySelector( 'button' ).click();

		expect( document.querySelector( 'iframe' ) ).not.toBeNull();
	} );
} );

describe( 'HLS wiring', () => {
	it( 'uses hls.js on browsers with Media Source Extensions, even if they claim native HLS', async () => {
		window.MediaSource = function MediaSource() {};
		canPlayHls = 'maybe';
		loadFrontend( nativeVideo() );
		await flush();

		const video = document.querySelector( 'video' );
		expect( FakeHls.instances ).toHaveLength( 1 );
		expect( FakeHls.instances[ 0 ].options ).toMatchObject( {
			autoStartLoad: true,
			capLevelToPlayerSize: true,
		} );
		expect( video.getAttribute( 'src' ) ).toBe( MP4 );
	} );

	it( 'plays HLS natively without Media Source Extensions and falls back to the MP4 on an error', () => {
		canPlayHls = 'maybe';
		loadFrontend( nativeVideo() );

		const video = document.querySelector( 'video' );
		expect( video.src ).toBe( HLS_MASTER );
		expect( FakeHls.instances ).toHaveLength( 0 );

		video.dispatchEvent( new Event( 'error' ) );

		expect( video.src ).toBe( MP4 );
		expect( video.load ).toHaveBeenCalled();
	} );

	it( 'plays HLS natively on Apple devices even with Media Source Extensions', () => {
		window.MediaSource = function MediaSource() {};
		canPlayHls = 'maybe';
		vendor.mockReturnValue( 'Apple Computer, Inc.' );
		loadFrontend( nativeVideo() );

		expect( document.querySelector( 'video' ).src ).toBe( HLS_MASTER );
		expect( FakeHls.instances ).toHaveLength( 0 );
	} );

	it( 'falls back to the MP4 and resumes autoplay after a fatal hls.js error', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo( 'autoplay' ) );
		await flush();

		const hls = FakeHls.instances[ 0 ];
		const video = document.querySelector( 'video' );
		hls.emit( FakeHls.Events.ERROR, {}, { fatal: false } );
		expect( hls.destroyed ).toBe( false );

		hls.emit( FakeHls.Events.ERROR, {}, { fatal: true } );

		expect( hls.destroyed ).toBe( true );
		expect( video.src ).toBe( MP4 );
		expect( video.play ).toHaveBeenCalled();
	} );

	it( 'leaves the MP4 sources in place when hls.js is not supported', async () => {
		window.MediaSource = function MediaSource() {};
		FakeHls.supported = false;
		loadFrontend( nativeVideo() );
		await flush();

		expect( FakeHls.instances ).toHaveLength( 0 );
		expect( document.querySelector( 'video' ).getAttribute( 'src' ) ).toBe(
			MP4
		);
	} );

	it( 'loads hls.js on demand when it is not on the page yet', async () => {
		delete window.Hls;
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo() );

		const script = document.head.querySelector(
			'script[src="https://example.test/hls.min.js"]'
		);
		expect( script ).not.toBeNull();
		window.Hls = FakeHls;
		script.onload();
		await flush();

		expect( FakeHls.instances ).toHaveLength( 1 );
	} );

	it( 'does not fetch segments for a deferred player until it plays', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo( 'preload="none"' ) );
		await flush();

		const hls = FakeHls.instances[ 0 ];
		expect( hls.options.autoStartLoad ).toBe( false );
		expect( hls.startLoad ).not.toHaveBeenCalled();

		document.querySelector( 'video' ).dispatchEvent( new Event( 'play' ) );

		expect( hls.startLoad ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'wires a video only once', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo() );
		window.videooptimizer.init();
		window.videooptimizer.init();
		await flush();

		expect( FakeHls.instances ).toHaveLength( 1 );
	} );

	it( 'stops autoplay for visitors who prefer reduced motion', () => {
		setMatchMedia( true );
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo( 'autoplay' ) );

		const video = document.querySelector( 'video' );
		expect( video.hasAttribute( 'autoplay' ) ).toBe( false );
		expect( video.pause ).toHaveBeenCalled();
	} );
} );

describe( 'tap to play', () => {
	it( 'plays and pauses a video with data-vo-tap', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo( 'data-vo-tap' ) );
		const video = document.querySelector( 'video' );
		await flush();
		FakeHls.instances[ 0 ].emit( FakeHls.Events.MANIFEST_PARSED );
		await flush();

		Object.defineProperty( video, 'paused', {
			value: true,
			configurable: true,
		} );
		video.click();
		await flush();
		expect( video.play ).toHaveBeenCalledTimes( 1 );

		Object.defineProperty( video, 'paused', {
			value: false,
			configurable: true,
		} );
		video.click();
		expect( video.pause ).toHaveBeenCalled();
	} );
} );

describe( 'one player at a time', () => {
	it( 'pauses other native players when one starts', () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( nativeVideo() + nativeVideo() );
		const [ first, second ] = document.querySelectorAll( 'video' );
		Object.defineProperty( first, 'paused', {
			value: false,
			configurable: true,
		} );
		Object.defineProperty( second, 'paused', {
			value: true,
			configurable: true,
		} );
		first.pause = jest.fn();
		second.pause = jest.fn();

		second.dispatchEvent( new Event( 'play' ) );

		expect( first.pause ).toHaveBeenCalled();
		expect( second.pause ).not.toHaveBeenCalled();
	} );
} );

describe( 'lightbox', () => {
	const markup = `<div class="vo-frame vo-frame--portrait" style="aspect-ratio: 9 / 16">
		<button id="open" data-vo-lightbox="https://player.example/embed/u" aria-label="Play in lightbox">x</button>
	</div>`;

	it( 'opens the hosted player in a dialog and closes it with Escape, restoring focus', () => {
		loadFrontend( markup );
		const trigger = document.getElementById( 'open' );
		trigger.focus();

		trigger.click();

		const box = document.querySelector( '.vo-lightbox' );
		expect( box.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( box.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( box.getAttribute( 'data-open' ) ).toBe( 'true' );
		expect( box.querySelector( 'iframe' ).src ).toBe(
			'https://player.example/embed/u'
		);
		expect(
			box.querySelector( '.vo-lightbox__inner--portrait' )
		).not.toBeNull();
		expect( document.documentElement.classList ).toContain(
			'vo-lightbox-open'
		);
		expect( document.activeElement ).toBe(
			box.querySelector( '.vo-lightbox__close' )
		);

		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape' } )
		);

		expect( box.hasAttribute( 'data-open' ) ).toBe( false );
		expect( box.querySelector( 'iframe' ) ).toBeNull();
		expect( document.documentElement.classList ).not.toContain(
			'vo-lightbox-open'
		);
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'closes with the close button and the backdrop', () => {
		loadFrontend( markup );
		const trigger = document.getElementById( 'open' );

		trigger.click();
		document.querySelector( '.vo-lightbox__close' ).click();
		expect(
			document.querySelector( '.vo-lightbox' ).hasAttribute( 'data-open' )
		).toBe( false );

		trigger.click();
		document.querySelector( '.vo-lightbox' ).click();
		expect(
			document.querySelector( '.vo-lightbox' ).hasAttribute( 'data-open' )
		).toBe( false );
	} );

	it( 'keeps Tab inside the dialog', () => {
		loadFrontend( markup );
		document.getElementById( 'open' ).click();
		const close = document.querySelector( '.vo-lightbox__close' );
		const iframe = document.querySelector( '.vo-lightbox iframe' );

		iframe.focus();
		const event = new KeyboardEvent( 'keydown', {
			key: 'Tab',
			cancelable: true,
		} );
		document.dispatchEvent( event );
		// Iframe is the first focusable element; close is the last: Tab from the last wraps.
		close.focus();
		const wrap = new KeyboardEvent( 'keydown', {
			key: 'Tab',
			cancelable: true,
		} );
		document.dispatchEvent( wrap );

		expect( wrap.defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( iframe );
	} );

	it( 'plays the native video inside the dialog and returns it to its holder', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend( `<div class="vo-frame">
			<button id="open" data-vo-lightbox="https://player.example/embed/u" data-vo-player="native" aria-label="Play">x</button>
			<div class="vo-native-holder" hidden><video class="vo-native" preload="none" data-hls="${ HLS_MASTER }" data-mp4="${ MP4 }"></video></div>
		</div>` );
		const video = document.querySelector( 'video' );

		document.getElementById( 'open' ).click();
		await flush();

		expect(
			document.querySelector( '.vo-lightbox__slot' ).contains( video )
		).toBe( true );
		expect( document.querySelector( '.vo-lightbox iframe' ) ).toBeNull();

		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape' } )
		);

		expect( video.pause ).toHaveBeenCalled();
		expect(
			document.querySelector( '.vo-native-holder' ).contains( video )
		).toBe( true );
	} );
} );

describe( 'pauseWithin', () => {
	it( 'pauses native videos and asks hosted players to pause', () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend(
			`<div id="root">${ nativeVideo() }<div class="vo-frame"><iframe src="about:blank"></iframe></div></div>`
		);
		const root = document.getElementById( 'root' );
		const iframe = root.querySelector( 'iframe' );
		iframe.contentWindow.postMessage = jest.fn();

		window.videooptimizer.pauseWithin( root );

		expect( root.querySelector( 'video' ).pause ).toHaveBeenCalled();
		expect( iframe.contentWindow.postMessage ).toHaveBeenCalledWith(
			{ type: 'videooptimizer:command', command: 'pause' },
			'*'
		);
	} );
} );

describe( 'background hero', () => {
	const hero = `<video class="vo-bg-hero__video" muted playsinline data-mp4="${ MP4 }"></video>`;

	it( 'starts the MP4 when it becomes visible', () => {
		loadFrontend( hero );

		const video = document.querySelector( 'video' );
		expect( video.src ).toBe( MP4 );
		expect( video.play ).toHaveBeenCalled();
	} );

	it( 'stays on the poster for visitors who prefer reduced motion', () => {
		setMatchMedia( true );
		loadFrontend( hero );

		const video = document.querySelector( 'video' );
		expect( video.getAttribute( 'src' ) ).toBeNull();
		expect( video.play ).not.toHaveBeenCalled();
	} );

	it( 'plays the adaptive stream through hls.js', async () => {
		window.MediaSource = function MediaSource() {};
		loadFrontend(
			`<video class="vo-bg-hero__video" muted data-hls="${ HLS_MASTER }" data-mp4="${ MP4 }"></video>`
		);
		await flush();

		expect( FakeHls.instances ).toHaveLength( 1 );
		expect( FakeHls.instances[ 0 ].source ).toBe( HLS_MASTER );
	} );
} );

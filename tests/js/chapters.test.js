/**
 * Chapters and scenes (assets/src/frontend/chapters.js), exercised through the frontend entry.
 */
const MP4 = 'https://cdn.example/encoded/u/h264/720p.mp4';

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const scenes = `<ul class="vo-scenes">
	<li><button class="vo-scene" data-vo-seek="10" id="s1">1</button></li>
	<li><button class="vo-scene" data-vo-seek="40" id="s2">2</button></li>
	<li><button class="vo-scene" data-vo-seek="80" id="s3">3</button></li>
</ul>`;

function load( html ) {
	jest.resetModules();
	document.body.innerHTML = html;
	document.documentElement.className = '';
	window.videooptimizerFrontend = { hlsUrl: 'x', i18n: {} };
	delete window.IntersectionObserver;
	window.matchMedia = jest.fn( () => ( { matches: false } ) );
	window.HTMLMediaElement.prototype.play = jest.fn( () => Promise.resolve() );
	window.HTMLMediaElement.prototype.pause = jest.fn();
	window.HTMLMediaElement.prototype.load = jest.fn();
	window.HTMLMediaElement.prototype.canPlayType = jest.fn( () => '' );
	require( '../../assets/src/frontend/index.js' );
}

const current = () =>
	Array.from( document.querySelectorAll( '[aria-current="true"]' ) ).map(
		( el ) => el.id
	);

describe( 'native chapters', () => {
	const markup = `<figure data-vo-chapters><div class="vo-frame"><video class="vo-native" preload="none" src="${ MP4 }"></video></div>${ scenes }</figure>`;

	it( 'seeks to the scene start and plays', async () => {
		load( markup );
		const video = document.querySelector( 'video' );

		document.getElementById( 's2' ).click();
		await flush();

		expect( video.currentTime ).toBe( 40 );
		expect( video.play ).toHaveBeenCalled();
		expect( current() ).toEqual( [ 's2' ] );
	} );

	it( 'marks the scene that is playing and clears the mark at the end', () => {
		load( markup );
		const video = document.querySelector( 'video' );
		Object.defineProperty( video, 'paused', { value: false } );

		video.currentTime = 45;
		video.dispatchEvent( new Event( 'timeupdate' ) );
		expect( current() ).toEqual( [ 's2' ] );

		video.currentTime = 85;
		video.dispatchEvent( new Event( 'timeupdate' ) );
		expect( current() ).toEqual( [ 's3' ] );

		video.dispatchEvent( new Event( 'ended' ) );
		expect( current() ).toEqual( [] );
	} );

	it( 'marks no scene before the first one', () => {
		load( markup );
		const video = document.querySelector( 'video' );
		Object.defineProperty( video, 'paused', { value: false } );
		video.currentTime = 2;
		video.dispatchEvent( new Event( 'timeupdate' ) );

		expect( current() ).toEqual( [] );
	} );
} );

describe( 'hosted chapters', () => {
	const base = 'https://player.example/embed/u?controls=0';

	it( 'sets the iframe src with t and autoplay', () => {
		load(
			`<figure data-vo-chapters data-vo-embed-base="${ base }"><div class="vo-frame"><iframe src="${ base }"></iframe></div>${ scenes }</figure>`
		);

		document.getElementById( 's3' ).click();

		const url = new URL( document.querySelector( 'iframe' ).src );
		expect( url.searchParams.get( 't' ) ).toBe( '80' );
		expect( url.searchParams.get( 'autoplay' ) ).toBe( '1' );
		expect( url.searchParams.get( 'controls' ) ).toBe( '0' );
		expect( current() ).toEqual( [ 's3' ] );
	} );

	it( 'creates the player when it was not injected yet', () => {
		load(
			`<figure data-vo-chapters data-vo-embed-base="${ base }"><div class="vo-frame" data-vo-autoload="${ base }"><img class="vo-poster__img" alt="Clip"></div>${ scenes }</figure>`
		);

		document.getElementById( 's1' ).click();

		const frame = document.querySelector( '.vo-frame' );
		expect( frame.hasAttribute( 'data-vo-autoload' ) ).toBe( false );
		const url = new URL( frame.querySelector( 'iframe' ).src );
		expect( url.searchParams.get( 't' ) ).toBe( '10' );
		expect( frame.querySelectorAll( 'iframe' ) ).toHaveLength( 1 );
	} );

	it( 'follows the player: a playing event moves the mark, ended clears it', () => {
		load(
			`<figure data-vo-chapters data-vo-embed-base="${ base }"><div class="vo-frame"><iframe src="${ base }"></iframe></div>${ scenes }</figure>`
		);
		const iframe = document.querySelector( 'iframe' );
		const send = ( event, currentTime ) =>
			window.dispatchEvent(
				new MessageEvent( 'message', {
					data: {
						type: 'videooptimizer:event',
						event,
						currentTime,
					},
					source: iframe.contentWindow,
				} )
			);

		document.getElementById( 's3' ).click();
		send( 'play', 0 ); // The reloaded player starts from 0 before it seeks.
		expect( current() ).toEqual( [ 's3' ] );

		send( 'play', 50 );
		expect( current() ).toEqual( [ 's2' ] );
		send( 'ended', 90 );
		expect( current() ).toEqual( [] );
	} );
} );

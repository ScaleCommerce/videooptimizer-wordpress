import {
	fallbackToMp4,
	markPlayIntent,
	pauseBySystem,
	trackPlayIntent,
	wantsPlayback,
} from '../../assets/src/frontend/playback-fallback';

function makeVideo( props = {} ) {
	const video = document.createElement( 'video' );
	video.load = jest.fn();
	video.play = jest.fn( () => Promise.resolve() );
	Object.defineProperty( video, 'paused', { value: true } );
	Object.assign( video, props );
	return video;
}

describe( 'fallbackToMp4', () => {
	it( 'resumes playback for a video without autoplay once play was requested', () => {
		const video = makeVideo();
		markPlayIntent( video );
		fallbackToMp4( video, 'https://x/master.m3u8' );
		expect( video.load ).toHaveBeenCalled();
		expect( video.play ).toHaveBeenCalled();
	} );

	it( 'resumes autoplay videos', () => {
		const video = makeVideo( { autoplay: true } );
		fallbackToMp4( video, 'https://x/master.m3u8' );
		expect( video.play ).toHaveBeenCalled();
	} );

	it( 'stays paused when nobody asked for playback', () => {
		const video = makeVideo();
		fallbackToMp4( video, 'https://x/master.m3u8' );
		expect( video.load ).toHaveBeenCalled();
		expect( video.play ).not.toHaveBeenCalled();
		expect( wantsPlayback( video ) ).toBe( false );
	} );

	it( 'swaps to the MP4 src fallback and swallows play rejections', () => {
		const video = makeVideo( { autoplay: true } );
		video.play = jest.fn( () => Promise.reject( new Error( 'blocked' ) ) );
		fallbackToMp4( video, 'https://x/master.m3u8', 'https://x/a.mp4' );
		expect( video.src ).toBe( 'https://x/a.mp4' );
		expect( video.play ).toHaveBeenCalled();
	} );
} );

describe( 'hero-shaped video', () => {
	it( 'falls back to data-mp4 and resumes', () => {
		const video = makeVideo();
		video.setAttribute( 'data-mp4', 'https://x/hero.mp4' );
		markPlayIntent( video );
		fallbackToMp4( video, 'https://x/master.m3u8' );
		expect( video.src ).toBe( 'https://x/hero.mp4' );
		expect( video.play ).toHaveBeenCalled();
	} );

	it( 'honours a resume decision made before the teardown', () => {
		const video = makeVideo();
		fallbackToMp4( video, 'https://x/master.m3u8', null, true );
		expect( video.play ).toHaveBeenCalled();
	} );
} );

describe( 'play intent tracking', () => {
	it( 'is set by play and cleared by a visitor pause', () => {
		const video = makeVideo();
		trackPlayIntent( video );
		video.dispatchEvent( new Event( 'play' ) );
		expect( wantsPlayback( video ) ).toBe( true );
		video.dispatchEvent( new Event( 'pause' ) );
		expect( wantsPlayback( video ) ).toBe( false );
	} );

	it( 'survives a pause made by the page', () => {
		const video = makeVideo();
		video.pause = jest.fn();
		trackPlayIntent( video );
		video.dispatchEvent( new Event( 'play' ) );
		pauseBySystem( video );
		video.dispatchEvent( new Event( 'pause' ) );
		expect( wantsPlayback( video ) ).toBe( true );
	} );
} );

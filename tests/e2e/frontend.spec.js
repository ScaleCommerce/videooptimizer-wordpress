/**
 * Frontend smoke tests against sites with the demo content (all blocks on the front page,
 * Hoodie/Beanie products with videos). URLs via WP_URL / WOO_URL.
 */
const { test, expect } = require( '@playwright/test' );

const WP = process.env.WP_URL || 'http://localhost:8081';
const WOO = process.env.WOO_URL || 'http://localhost:8082';

test( 'demo page renders every layout and loads no video before interaction', async ( {
	page,
} ) => {
	const media = [];
	page.on( 'request', ( r ) => {
		if ( /\.(m3u8|mp4|ts|m4s)(\?|$)/.test( r.url() ) ) {
			media.push( r.url() );
		}
	} );
	await page.goto( WP + '/' );

	await expect( page.locator( '.vo-bg-hero' ).first() ).toBeVisible();
	await expect( page.locator( '.vo-media-split' ).first() ).toBeVisible();
	await expect( page.locator( '.vo-spotlight' ) ).toHaveCount( 1 );
	await expect( page.locator( '.vo-grid__item' ) ).toHaveCount( 3 );
	await expect(
		page.locator( 'script[type="application/ld+json"]' ).first()
	).toBeAttached();

	// Only the (above-the-fold) background hero may stream right away.
	const nonHero = media.filter(
		( url ) => ! url.includes( 'master.m3u8' ) && ! url.includes( '/hls/' )
	);
	expect( nonHero ).toEqual( [] );
} );

test( 'facade swaps the poster for the hosted player', async ( { page } ) => {
	await page.goto( WP + '/' );
	const frame = page
		.locator( '.vo-media-split .vo-frame[data-vo-player="hosted"]' )
		.first();
	await frame.locator( '.vo-facade' ).click();
	await expect( frame.locator( 'iframe' ) ).toHaveAttribute(
		'src',
		/\/embed\/[0-9a-f-]{36}\?autoplay=1&muted=0/
	);
} );

test( 'lightbox opens, traps focus and closes with Escape', async ( {
	page,
} ) => {
	await page.goto( WP + '/' );
	await page.locator( '.vo-spotlight [data-vo-lightbox]' ).click();
	const box = page.locator( '.vo-lightbox' );
	await expect( box ).toHaveAttribute( 'open', '' );
	// A native modal dialog: the browser makes the rest of the page inert.
	expect( await box.evaluate( ( el ) => el.matches( ':modal' ) ) ).toBe( true );
	await expect( box.locator( 'iframe' ) ).toBeAttached();
	await expect( box.locator( '.vo-lightbox__close' ) ).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( box ).not.toHaveAttribute( 'open', '' );
	await expect( box.locator( 'iframe' ) ).toHaveCount( 0 );
} );

test( 'background hero has a pause button that pauses and resumes the video', async ( {
	page,
} ) => {
	await page.goto( WP + '/' );
	const hero = page.locator( '.vo-bg-hero' ).first();
	const toggle = hero.locator( '[data-vo-hero-toggle]' );
	const video = hero.locator( 'video' );
	await expect( toggle ).toBeVisible();
	await expect( toggle ).toHaveAttribute( 'data-state', 'playing' );
	await expect
		.poll( () => video.evaluate( ( v ) => v.paused ), { timeout: 20_000 } )
		.toBe( false );

	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'data-state', 'paused' );
	expect( await video.evaluate( ( v ) => v.paused ) ).toBe( true );

	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'data-state', 'playing' );
	await expect
		.poll( () => video.evaluate( ( v ) => v.paused ) )
		.toBe( false );
} );

test( 'native player plays the adaptive stream', async ( { page } ) => {
	await page.goto( WP + '/' );
	const frame = page
		.locator( '.vo-frame[data-vo-player="native"]' )
		.filter( { has: page.locator( '[data-vo-embed]' ) } )
		.first();
	await frame.locator( '.vo-facade' ).click();
	const video = frame.locator( 'video.vo-native' );
	await expect
		.poll( () => video.evaluate( ( v ) => v.currentTime ), {
			timeout: 20_000,
		} )
		.toBeGreaterThan( 0 );
} );

test( 'WooCommerce: product gallery video slide, tab and hover preview', async ( {
	page,
} ) => {
	await page.goto( WOO + '/product/hoodie/' );
	const gallery = page.locator( '.woocommerce-product-gallery' );
	// The demo variation uses the same video as the gallery: deduplicated into one slide.
	await expect(
		gallery.locator( '.videooptimizer-gallery__slide' )
	).toHaveCount( 1 );
	await expect(
		gallery.locator( '.flex-control-thumbs li.videooptimizer-thumb' )
	).toHaveCount( 1 );
	await gallery
		.locator( '.flex-control-thumbs li.videooptimizer-thumb img' )
		.first()
		.click();
	await expect(
		gallery.locator( '.videooptimizer-gallery__slide' ).first()
	).toHaveClass( /flex-active-slide/ );
	await expect( page.locator( '#tab-title-videooptimizer' ) ).toBeVisible();

	await page.goto( WOO + '/shop/' );
	const hover = page.locator( '[data-vo-hover]' ).first();
	await hover.hover();
	await expect(
		hover.locator( 'video.videooptimizer-hover__video' )
	).toBeAttached();
	await expect( hover ).toHaveClass( /is-playing/, { timeout: 15_000 } );
} );

test( 'WooCommerce: selecting a variation with a video jumps to it', async ( {
	page,
} ) => {
	await page.goto( WOO + '/product/hoodie/' );
	const gallery = page.locator( '.woocommerce-product-gallery' );
	await page.selectOption( '#pa_color', 'blue' );
	await page.selectOption( '#logo', 'Yes' );
	await expect( gallery.locator( '.flex-active-slide' ) ).toHaveClass(
		/videooptimizer-gallery__slide/
	);
	await page.locator( '.reset_variations' ).click();
	await expect( gallery.locator( '.flex-active-slide' ) ).not.toHaveClass(
		/videooptimizer-gallery__slide/
	);
} );

test.describe( 'content elements page', () => {
	const PAGE = WP + '/videooptimizer-content-elements/';

	test( 'a scene starts the native video at that time and is marked', async ( {
		page,
	} ) => {
		await page.goto( PAGE );
		const chapters = page.locator( '.vo-chapters' ).first();
		const scene = chapters.locator( '.vo-scene' ).nth( 2 );
		const start = Number( await scene.getAttribute( 'data-vo-seek' ) );
		await scene.scrollIntoViewIfNeeded();
		await scene.click();

		await expect( scene ).toHaveAttribute( 'aria-current', 'true' );
		await expect
			.poll( () =>
				chapters.locator( 'video' ).evaluate( ( v ) => v.currentTime )
			)
			.toBeGreaterThanOrEqual( start - 1 );
	} );

	test( 'a hosted scene reloads the player with t and autoplay', async ( {
		page,
	} ) => {
		await page.goto( PAGE );
		const chapters = page
			.locator( '.vo-chapters:has(.vo-frame[data-vo-player="hosted"])' )
			.first();
		await chapters.locator( '.vo-scene' ).nth( 1 ).scrollIntoViewIfNeeded();
		await chapters.locator( '.vo-scene' ).nth( 1 ).click();

		await expect( chapters.locator( 'iframe' ) ).toHaveAttribute(
			'src',
			/[?&]t=\d+/
		);
		await expect( chapters.locator( 'iframe' ) ).toHaveAttribute(
			'src',
			/autoplay=1/
		);
	} );

	test( 'formats set the ratio of the frame', async ( { page } ) => {
		await page.goto( PAGE );
		const frame = page.locator( '.vo-frame--fit' ).first();
		const box = await frame.boundingBox();
		expect( box.height / box.width ).toBeCloseTo( 16 / 9, 1 );
		await expect( frame ).toHaveClass( /vo-frame--narrow/ );
	} );

	test( 'play while scrolling starts muted at half visibility and pauses when it leaves', async ( {
		page,
	} ) => {
		await page.goto( PAGE );
		const video = page.locator( 'video[data-vo-scroll-play]' );
		await video.scrollIntoViewIfNeeded();
		await expect
			.poll( () => video.evaluate( ( v ) => ! v.paused ) )
			.toBe( true );
		expect( await video.evaluate( ( v ) => v.muted ) ).toBe( true );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await expect
			.poll( () => video.evaluate( ( v ) => v.paused ) )
			.toBe( true );
	} );

	test( 'the mini player pins when the playing video scrolls away and closes', async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 1280, height: 800 } );
		await page.goto( PAGE );
		const mini = page.locator( '.vo-mini' ).first();
		const video = mini.locator( 'video' );
		await mini.scrollIntoViewIfNeeded();
		await page.waitForTimeout( 1500 ); // HLS is wired shortly before the video is visible.
		await video.evaluate( ( v ) => {
			v.muted = true;
			return v.play().catch( () => v.play() );
		} );
		await page.mouse.wheel( 0, 1600 );

		await expect( mini ).toHaveClass( /is-pinned/ );
		const box = await mini.locator( '.vo-mini__box' ).boundingBox();
		expect( box.width ).toBeCloseTo( 320, 0 );
		await mini.locator( '.vo-mini__close' ).click();
		await expect( mini ).not.toHaveClass( /is-pinned/ );
		expect( await video.evaluate( ( v ) => v.paused ) ).toBe( true );
	} );

	test( 'a list card opens the video in a lightbox', async ( { page } ) => {
		await page.goto( PAGE );
		await page.locator( 'button.vo-card__link' ).first().click();
		await expect( page.locator( 'dialog.vo-lightbox' ) ).toHaveAttribute(
			'open',
			''
		);
		await page.keyboard.press( 'Escape' );
	} );

	test( 'a list card links to the detail page, which shows that video', async ( {
		page,
	} ) => {
		await page.goto( PAGE );
		await page.locator( 'a.vo-card__link' ).first().click();

		await expect( page ).toHaveURL( /vo_video=[0-9a-f-]{36}/ );
		await expect( page.locator( '.vo-detail__title' ) ).toBeVisible();
		await expect( page.locator( '.vo-detail iframe' ) ).toHaveCount( 1 );
	} );

	test( 'the detail page answers 404 for an unknown or invalid video', async ( {
		page,
	} ) => {
		for ( const value of [
			'nope',
			'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		] ) {
			const response = await page.goto(
				WP + '/videooptimizer-video-detail/?vo_video=' + value
			);
			expect( response.status() ).toBe( 404 );
		}
		const ok = await page.goto(
			WP +
				'/videooptimizer-video-detail/?vo_video=e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8'
		);
		expect( ok.status() ).toBe( 200 );
	} );
} );

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
	await expect( box ).toHaveAttribute( 'data-open', 'true' );
	await expect( box.locator( 'iframe' ) ).toBeAttached();
	await expect( box.locator( '.vo-lightbox__close' ) ).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( box ).not.toHaveAttribute( 'data-open', 'true' );
	await expect( box.locator( 'iframe' ) ).toHaveCount( 0 );
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

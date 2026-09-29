/**
 * WooCommerce product edit screen: "Product videos" meta box.
 * Writes JSON into the hidden input that is saved with the product.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import VideoSelectControl from '../components/VideoSelectControl';
import VideoPicker from '../components/VideoPicker';
import '../components/components.scss';
import './woo.scss';

function ProductVideos( { initial, input, blockTheme } ) {
	const [ value, setValue ] = useState( initial );
	const [ adding, setAdding ] = useState( false );

	useEffect( () => {
		input.value = JSON.stringify( value );
	}, [ value, input ] );

	const gallery = value.gallery || [];
	const setGallery = ( next ) => setValue( { ...value, gallery: next } );
	const move = ( index, delta ) => {
		const next = [ ...gallery ];
		const [ item ] = next.splice( index, 1 );
		next.splice( index + delta, 0, item );
		setGallery( next );
	};

	return (
		<div className="vo-product">
			<section>
				<h4>{ __( 'Gallery videos', 'videooptimizer' ) }</h4>
				<p className="description">
					{ __(
						'Shown as extra slides in the product image gallery.',
						'videooptimizer'
					) }
				</p>
				{ blockTheme ? (
					<p className="description vo-product__hint">
						{ __(
							'Using the new "Product Gallery" block? It only shows WooCommerce\'s own gallery videos: add the video from the media library in the product gallery and send it to VideoOptimizer — it is then delivered from VideoOptimizer (adaptive HLS) automatically.',
							'videooptimizer'
						) }
					</p>
				) : null }
				{ gallery.map( ( uuid, index ) => (
					<div className="vo-product__gallery-item" key={ uuid }>
						<VideoSelectControl
							compact
							value={ uuid }
							onChange={ ( next ) =>
								setGallery(
									next
										? gallery.map( ( u, i ) =>
												i === index ? next : u
										  )
										: gallery.filter(
												( _u, i ) => i !== index
										  )
								)
							}
						/>
						<div className="vo-product__order">
							<Button
								size="small"
								icon="arrow-up-alt2"
								label={ __( 'Move up', 'videooptimizer' ) }
								disabled={ index === 0 }
								onClick={ () => move( index, -1 ) }
							/>
							<Button
								size="small"
								icon="arrow-down-alt2"
								label={ __( 'Move down', 'videooptimizer' ) }
								disabled={ index === gallery.length - 1 }
								onClick={ () => move( index, 1 ) }
							/>
						</div>
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => setAdding( true ) }>
					{ __( 'Add gallery video', 'videooptimizer' ) }
				</Button>
			</section>

			<section>
				<h4>{ __( 'Video tab', 'videooptimizer' ) }</h4>
				<VideoSelectControl
					compact
					value={ value.tab }
					onChange={ ( tab ) => setValue( { ...value, tab } ) }
					help={ __(
						'Adds a "Video" tab next to the description.',
						'videooptimizer'
					) }
				/>
			</section>

			<section>
				<h4>{ __( 'Hover preview', 'videooptimizer' ) }</h4>
				<VideoSelectControl
					compact
					value={ value.hover }
					onChange={ ( hover ) => setValue( { ...value, hover } ) }
					help={ __(
						'Plays muted when shoppers hover the product in the shop and category pages. Short clips (3–10 s) work best.',
						'videooptimizer'
					) }
				/>
			</section>

			{ adding ? (
				<VideoPicker
					title={ __( 'Add a gallery video', 'videooptimizer' ) }
					onClose={ () => setAdding( false ) }
					onSelect={ ( video ) => {
						if ( ! gallery.includes( video.uuid ) ) {
							setGallery( [ ...gallery, video.uuid ] );
						}
						setAdding( false );
					} }
				/>
			) : null }
		</div>
	);
}

/**
 * "Variation video" picker inside each variation (variations are loaded via AJAX).
 *
 * @param {Element} el Placeholder.
 */
function mountVariation( el ) {
	el.setAttribute( 'data-vo-mounted', '' );
	const input = el.parentElement.querySelector(
		'.videooptimizer-variation-input'
	);
	const root = createRoot( el );
	const render = ( value ) =>
		root.render(
			<VideoSelectControl
				compact
				value={ value }
				onChange={ ( uuid ) => {
					input.value = uuid || '';
					// Let WooCommerce mark the variation as changed (enables "Save changes").
					if ( window.jQuery ) {
						window.jQuery( input ).trigger( 'change' );
					}
					render( uuid || '' );
				} }
			/>
		);
	render( el.getAttribute( 'data-value' ) || '' );
}

function watchVariations() {
	const scan = () =>
		document
			.querySelectorAll(
				'.videooptimizer-variation:not([data-vo-mounted])'
			)
			.forEach( mountVariation );
	scan();
	const container = document.getElementById( 'variable_product_options' );
	if ( container && 'MutationObserver' in window ) {
		new window.MutationObserver( scan ).observe( container, {
			childList: true,
			subtree: true,
		} );
	}
}

function mount() {
	watchVariations();
	const root = document.getElementById( 'videooptimizer-product-root' );
	const input = document.getElementById( 'videooptimizer-product-input' );
	if ( ! root || ! input ) {
		return;
	}
	let initial = { gallery: [], tab: '', hover: '' };
	try {
		initial = {
			...initial,
			...JSON.parse( root.getAttribute( 'data-value' ) || '{}' ),
		};
	} catch ( e ) {}
	createRoot( root ).render(
		<ProductVideos
			initial={ initial }
			input={ input }
			blockTheme={ root.getAttribute( 'data-block-theme' ) === '1' }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}

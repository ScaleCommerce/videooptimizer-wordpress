/**
 * Shared block editor building blocks.
 */
import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	ToggleControl,
	Disabled,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';
import VideoSelectControl from '../../components/VideoSelectControl';
import { api } from '../../components/api';
import { videoIcon } from './icon';

let statusPromise = null;
export function usePluginStatus() {
	const [ status, setStatus ] = useState( null );
	useEffect( () => {
		if ( ! statusPromise ) {
			statusPromise = api
				.status()
				.catch( () => ( { configured: false } ) );
		}
		statusPromise.then( setStatus );
	}, [] );
	return status;
}

const TRI = [
	{
		label: __(
			'Default (VideoOptimizer player settings)',
			'videooptimizer'
		),
		value: '',
	},
	{ label: __( 'On', 'videooptimizer' ), value: '1' },
	{ label: __( 'Off', 'videooptimizer' ), value: '0' },
];

/**
 * Player / presentation / behaviour settings.
 *
 * @param {Object}  props
 * @param {Object}  props.attributes
 * @param {Object}  props.setAttributes
 * @param {boolean} props.presentation  Show the presentation select.
 * @param {boolean} props.scrollPlay    Offer "Play while scrolling".
 * @param {boolean} props.miniPlayer    Offer the floating mini player.
 * @param {boolean} props.initialOpen   Panel initially open.
 */
export function PlayerPanel( {
	attributes,
	setAttributes,
	presentation = true,
	scrollPlay = false,
	miniPlayer = false,
	initialOpen = true,
} ) {
	const { player, autoplay, muted, loop, controls, priority } = attributes;
	return (
		<PanelBody
			title={ __( 'Player', 'videooptimizer' ) }
			initialOpen={ initialOpen }
		>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Player', 'videooptimizer' ) }
				value={ player }
				onChange={ ( value ) => setAttributes( { player: value } ) }
				options={ [
					{
						label: __(
							'Default (plugin settings)',
							'videooptimizer'
						),
						value: '',
					},
					{
						label: __(
							'VideoOptimizer player (iframe)',
							'videooptimizer'
						),
						value: 'hosted',
					},
					{
						label: __( 'Native HTML5 player', 'videooptimizer' ),
						value: 'native',
					},
				] }
				help={ __(
					'The VideoOptimizer player uses the design configured in your VideoOptimizer account. The native player is part of your page and styled by the browser.',
					'videooptimizer'
				) }
			/>
			{ presentation ? (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Presentation', 'videooptimizer' ) }
					value={ attributes.presentation }
					onChange={ ( value ) =>
						setAttributes( { presentation: value } )
					}
					options={ [
						{ label: __( 'Default', 'videooptimizer' ), value: '' },
						{
							label: __(
								'Poster, plays in place',
								'videooptimizer'
							),
							value: 'facade',
						},
						{
							label: __(
								'Poster, plays in a lightbox',
								'videooptimizer'
							),
							value: 'lightbox',
						},
						{
							label: __( 'Player directly', 'videooptimizer' ),
							value: 'direct',
						},
					] }
					help={ __(
						'"Poster" loads no video data until visitors click play — the fastest option.',
						'videooptimizer'
					) }
				/>
			) : null }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Autoplay (muted)', 'videooptimizer' ) }
				value={ autoplay }
				onChange={ ( value ) => setAttributes( { autoplay: value } ) }
				options={ TRI }
				help={ __(
					'Only for "Player directly": starts muted once the video is visible. Browsers never autoplay with sound.',
					'videooptimizer'
				) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Muted', 'videooptimizer' ) }
				value={ muted }
				onChange={ ( value ) => setAttributes( { muted: value } ) }
				options={ TRI }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Loop', 'videooptimizer' ) }
				value={ loop }
				onChange={ ( value ) => setAttributes( { loop: value } ) }
				options={ TRI }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Controls', 'videooptimizer' ) }
				value={ controls }
				onChange={ ( value ) => setAttributes( { controls: value } ) }
				options={ TRI }
			/>
			{ scrollPlay ? (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Play while scrolling', 'videooptimizer' ) }
					help={ __(
						'Only for "Player directly": starts muted when the video is half visible and pauses when it leaves. Not for visitors who prefer reduced motion or save data.',
						'videooptimizer'
					) }
					checked={ !! attributes.scrollPlay }
					onChange={ ( value ) =>
						setAttributes( { scrollPlay: value } )
					}
				/>
			) : null }
			{ miniPlayer ? (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Floating mini player', 'videooptimizer' ) }
					help={ __(
						'Only for "Player directly": once started, the video keeps playing as a small player in the bottom right when it scrolls out of view (screens from 600 px). Visitors can close it.',
						'videooptimizer'
					) }
					checked={ !! attributes.miniPlayer }
					onChange={ ( value ) =>
						setAttributes( { miniPlayer: value } )
					}
				/>
			) : null }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Above the fold', 'videooptimizer' ) }
				help={ __(
					'Loads the poster with high priority and the player immediately. Use only for the first visible video of a page.',
					'videooptimizer'
				) }
				checked={ !! priority }
				onChange={ ( value ) => setAttributes( { priority: value } ) }
			/>
		</PanelBody>
	);
}

/**
 * "Every format from one video": display ratio plus focal point.
 *
 * @param {Object} props
 * @param {Object} props.attributes
 * @param {Object} props.setAttributes
 */
export function FormatPanel( { attributes, setAttributes } ) {
	const { format, focalX, focalY } = attributes;
	return (
		<PanelBody
			title={ __( 'Format', 'videooptimizer' ) }
			initialOpen={ !! format }
		>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Format', 'videooptimizer' ) }
				value={ format }
				onChange={ ( value ) => setAttributes( { format: value } ) }
				options={ [
					{
						label: __( 'Original (video ratio)', 'videooptimizer' ),
						value: '',
					},
					{ label: '16:9', value: '16:9' },
					{ label: '9:16', value: '9:16' },
					{ label: '1:1', value: '1:1' },
					{ label: '4:5', value: '4:5' },
					{ label: '21:9', value: '21:9' },
				] }
				help={ __(
					'Shows the same video in another ratio; the video fills the area and is cropped.',
					'videooptimizer'
				) }
			/>
			{ format ? (
				<>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Focal point, horizontal (%)',
							'videooptimizer'
						) }
						min={ 0 }
						max={ 100 }
						value={ focalX }
						onChange={ ( value ) =>
							setAttributes( { focalX: value ?? 50 } )
						}
					/>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Focal point, vertical (%)',
							'videooptimizer'
						) }
						min={ 0 }
						max={ 100 }
						value={ focalY }
						onChange={ ( value ) =>
							setAttributes( { focalY: value ?? 50 } )
						}
						help={ __(
							'Which part stays visible when the video is cropped. Not supported by the VideoOptimizer player (iframe): it always crops from the centre.',
							'videooptimizer'
						) }
					/>
				</>
			) : null }
		</PanelBody>
	);
}

/**
 * Library of a video list (every library of the account when empty).
 *
 * @param {Object}   props
 * @param {string}   props.value    Library uuid.
 * @param {Function} props.onChange Receives the uuid.
 * @param {string}   props.help     Optional help text.
 */
export function LibrarySelect( { value, onChange, help } ) {
	const [ libraries, setLibraries ] = useState( null );
	useEffect( () => {
		let current = true;
		api.libraries().then(
			( list ) => current && setLibraries( list ),
			() => current && setLibraries( [] )
		);
		return () => {
			current = false;
		};
	}, [] );
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ __( 'Library', 'videooptimizer' ) }
			value={ value }
			onChange={ onChange }
			disabled={ libraries === null }
			options={ [
				{
					label: __( 'All libraries', 'videooptimizer' ),
					value: '',
				},
				...( libraries || [] ).map( ( library ) => ( {
					label: library.name,
					value: library.id,
				} ) ),
			] }
			help={
				help ||
				__(
					'Lists the videos of this library that are ready to play.',
					'videooptimizer'
				)
			}
		/>
	);
}

/**
 * Page picker (published pages).
 *
 * @param {Object}   props
 * @param {number}   props.value    Page id (0 = none).
 * @param {Function} props.onChange Receives the id.
 * @param {string}   props.label    Field label.
 * @param {string}   props.help     Field help.
 */
export function PageSelect( { value, onChange, label, help } ) {
	const pages = useSelect( ( select ) =>
		select( coreStore ).getEntityRecords( 'postType', 'page', {
			per_page: 100,
			status: 'publish',
			orderby: 'title',
			order: 'asc',
		} )
	);
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			value={ String( value || 0 ) }
			onChange={ ( id ) => onChange( Number( id ) ) }
			disabled={ pages === null }
			options={ [
				{
					label: __( 'None (open in a lightbox)', 'videooptimizer' ),
					value: '0',
				},
				...( pages || [] ).map( ( page ) => ( {
					label: page.title?.rendered || `#${ page.id }`,
					value: String( page.id ),
				} ) ),
			] }
			help={ help }
		/>
	);
}

export function VideoPanel( { attributes, setAttributes, title } ) {
	return (
		<PanelBody title={ title || __( 'Video', 'videooptimizer' ) }>
			<VideoSelectControl
				value={ attributes.uuid }
				onChange={ ( uuid ) => setAttributes( { uuid } ) }
			/>
		</PanelBody>
	);
}

export function VideoPlaceholder( { label, instructions, onChange } ) {
	const status = usePluginStatus();
	return (
		<Placeholder
			icon={ videoIcon }
			label={ label }
			instructions={ instructions }
			className="vo-block-placeholder"
		>
			{ status === null ? <Spinner /> : null }
			{ status && ! status.configured ? (
				<p>
					{ __(
						'VideoOptimizer is not connected yet.',
						'videooptimizer'
					) }{ ' ' }
					{ status.can_manage ? (
						<a href={ status.settings_url }>
							{ __( 'Enter your API token', 'videooptimizer' ) }
						</a>
					) : (
						__(
							'Please ask an administrator to connect it.',
							'videooptimizer'
						)
					) }
				</p>
			) : null }
			{ status && status.configured ? (
				<VideoSelectControl value="" onChange={ onChange } />
			) : null }
		</Placeholder>
	);
}

export function Preview( { block, attributes } ) {
	return (
		<Disabled>
			<ServerSideRender
				block={ block }
				attributes={ attributes }
				skipBlockSupportAttributes
				LoadingResponsePlaceholder={ () => (
					<div className="vo-block-loading">
						<Spinner />
					</div>
				) }
			/>
		</Disabled>
	);
}

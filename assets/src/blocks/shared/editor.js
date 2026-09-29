/**
 * Shared block editor building blocks.
 */
import { __ } from '@wordpress/i18n';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	ToggleControl,
	Disabled,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
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
 * @param {boolean} props.initialOpen   Panel initially open.
 */
export function PlayerPanel( {
	attributes,
	setAttributes,
	presentation = true,
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

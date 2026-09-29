import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../../components/api';
import { resetLibraries } from '../../components/VideoBrowser';
import { copyToClipboard } from '../../components/ui';
import { useNotify } from '../notices';

const config = window.videooptimizerAdmin || {};

function Connection( { settings, onChange } ) {
	const [ token, setToken ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const notify = useNotify();
	const locked = settings.token_source === 'constant';

	const test = () =>
		api.testConnection().then(
			( r ) => {
				setResult( { ok: true, ...r } );
				config.configured = true;
			},
			( e ) => setResult( { ok: false, message: e.message } )
		);

	const save = () => {
		setBusy( true );
		setResult( null );
		api.saveSettings( { token } )
			.then( ( s ) => {
				onChange( s );
				setToken( '' );
				resetLibraries();
				return test();
			} )
			.catch( ( e ) => setResult( { ok: false, message: e.message } ) )
			.finally( () => setBusy( false ) );
	};

	const remove = () => {
		setBusy( true );
		api.deleteToken()
			.then( ( s ) => {
				onChange( s );
				setResult( null );
				config.configured = false;
				notify( __( 'Token removed.', 'videooptimizer' ) );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Connection', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				{ settings.token_unreadable ? (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The stored token can no longer be decrypted (the security keys in wp-config.php changed). Please enter it again.',
							'videooptimizer'
						) }
					</Notice>
				) : null }
				<p>
					{ settings.token_configured ? (
						<span className="vo-badge vo-badge--ready">
							{ __( 'Connected', 'videooptimizer' ) }
						</span>
					) : (
						<span className="vo-badge vo-badge--failed">
							{ __( 'Not connected', 'videooptimizer' ) }
						</span>
					) }{ ' ' }
					{ locked
						? __(
								'The token is defined in wp-config.php (VIDEOOPTIMIZER_API_TOKEN).',
								'videooptimizer'
						  )
						: null }
				</p>
				{ ! locked ? (
					<>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="password"
							autoComplete="off"
							label={
								settings.token_configured
									? __(
											'Replace API token',
											'videooptimizer'
									  )
									: __( 'API token', 'videooptimizer' )
							}
							placeholder="vp_…"
							value={ token }
							onChange={ setToken }
							help={
								<>
									{ __(
										'Create an organization token in VideoOptimizer under Organization → API tokens. It is stored encrypted and never shown again.',
										'videooptimizer'
									) }{ ' ' }
									<ExternalLink href={ config.appUrl }>
										videooptimizer.eu
									</ExternalLink>
								</>
							}
						/>
						<div className="vo-row">
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ busy }
								disabled={ busy || token.trim().length < 10 }
							>
								{ __( 'Save & test', 'videooptimizer' ) }
							</Button>
							{ settings.token_configured ? (
								<>
									<Button
										variant="secondary"
										onClick={ () => {
											setBusy( true );
											test().finally( () =>
												setBusy( false )
											);
										} }
										disabled={ busy }
									>
										{ __(
											'Test connection',
											'videooptimizer'
										) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ remove }
										disabled={ busy }
									>
										{ __(
											'Remove token',
											'videooptimizer'
										) }
									</Button>
								</>
							) : null }
						</div>
					</>
				) : (
					<Button
						variant="secondary"
						onClick={ () => {
							setBusy( true );
							test().finally( () => setBusy( false ) );
						} }
						disabled={ busy }
					>
						{ __( 'Test connection', 'videooptimizer' ) }
					</Button>
				) }
				{ result ? (
					<Notice
						status={ result.ok ? 'success' : 'error' }
						isDismissible={ false }
						className="vo-result"
					>
						{ result.ok
							? sprintf(
									/* translators: 1: number of libraries, 2: number of media-managed libraries */
									__(
										'Connection works. %1$d libraries found (%2$d accept uploads).',
										'videooptimizer'
									),
									result.libraries,
									result.media_managed
							  )
							: result.message }
						{ result.ok && ! result.libraries ? (
							<>
								{ ' ' }
								{ __(
									'Create a library under "Libraries" to start uploading.',
									'videooptimizer'
								) }
							</>
						) : null }
					</Notice>
				) : null }
			</CardBody>
		</Card>
	);
}

function webhookStatusText( settings ) {
	const status = settings.webhook_status;
	if ( ! settings.webhook_configured ) {
		return __( 'Not configured.', 'videooptimizer' );
	}
	if ( ! status || ! status.time ) {
		return __(
			'Active. No delivery received yet — use "Send test" in the VideoOptimizer app.',
			'videooptimizer'
		);
	}
	return sprintf(
		/* translators: 1: event name, 2: date/time */
		__( 'Last delivery: %1$s at %2$s.', 'videooptimizer' ),
		String( status.event ).replace( '_', '.' ),
		new Date( status.time * 1000 ).toLocaleString()
	);
}

function Webhook( { settings, onChange } ) {
	const [ secret, setSecret ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const notify = useNotify();

	const save = ( value ) => {
		setBusy( true );
		api.saveSettings( { webhook_secret: value } )
			.then( ( s ) => {
				onChange( s );
				setSecret( '' );
				notify(
					value
						? __( 'Webhook secret saved.', 'videooptimizer' )
						: __( 'Webhook disabled.', 'videooptimizer' )
				);
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Webhook (optional)', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Without a webhook, WordPress checks every five minutes whether media library videos are ready. With a webhook, VideoOptimizer reports it instantly. Webhooks are created in the VideoOptimizer app under Organization → Webhooks (events video.ready and video.failed). Your site must be publicly reachable.',
						'videooptimizer'
					) }
				</p>
				<div className="vo-row vo-row--end">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Webhook URL', 'videooptimizer' ) }
						value={ settings.webhook_url }
						readOnly
						onChange={ () => {} }
						onFocus={ ( e ) => e.target.select() }
					/>
					<Button
						variant="secondary"
						onClick={ () =>
							copyToClipboard( settings.webhook_url ).then( () =>
								notify( __( 'Copied.', 'videooptimizer' ) )
							)
						}
					>
						{ __( 'Copy', 'videooptimizer' ) }
					</Button>
				</div>
				<div className="vo-row vo-row--end">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="password"
						autoComplete="off"
						label={
							settings.webhook_configured
								? __(
										'Replace signing secret',
										'videooptimizer'
								  )
								: __( 'Signing secret', 'videooptimizer' )
						}
						placeholder="whsec_…"
						value={ secret }
						onChange={ setSecret }
					/>
					<Button
						variant="primary"
						onClick={ () => save( secret ) }
						disabled={ busy || ! secret.trim() }
					>
						{ __( 'Save', 'videooptimizer' ) }
					</Button>
					{ settings.webhook_configured ? (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => save( '' ) }
							disabled={ busy }
						>
							{ __( 'Disable', 'videooptimizer' ) }
						</Button>
					) : null }
				</div>
				<p className="description">{ webhookStatusText( settings ) }</p>
			</CardBody>
		</Card>
	);
}

export default function SettingsPage() {
	const [ settings, setSettings ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ libraries, setLibraries ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const notify = useNotify();

	useEffect( () => {
		api.getSettings().then( setSettings, setError );
	}, [] );

	useEffect( () => {
		if ( settings && settings.token_configured ) {
			api.libraries().then( setLibraries, () => setLibraries( [] ) );
		}
	}, [ settings && settings.token_configured ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error.message }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}

	const value = ( key ) => ( key in draft ? draft[ key ] : settings[ key ] );
	const set = ( key ) => ( v ) => setDraft( { ...draft, [ key ]: v } );
	const dirty = Object.keys( draft ).length > 0;

	const save = () => {
		setSaving( true );
		api.saveSettings( draft )
			.then( ( s ) => {
				setSettings( s );
				setDraft( {} );
				notify( __( 'Settings saved.', 'videooptimizer' ) );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	const toggle = ( key, label, help ) => (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			checked={ !! value( key ) }
			onChange={ set( key ) }
		/>
	);

	return (
		<div className="vo-settings">
			<Connection settings={ settings } onChange={ setSettings } />

			<Card>
				<CardHeader>
					<h2>{ __( 'Defaults', 'videooptimizer' ) }</h2>
				</CardHeader>
				<CardBody>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Library for new uploads',
							'videooptimizer'
						) }
						value={ value( 'default_library' ) }
						onChange={ set( 'default_library' ) }
						options={ [
							{
								label: __(
									'Automatic (first library that accepts uploads)',
									'videooptimizer'
								),
								value: '',
							},
							...libraries
								.filter( ( l ) => l.media_managed !== false )
								.map( ( l ) => ( {
									label: l.name,
									value: l.id,
								} ) ),
						] }
						help={ __(
							'Used for media library transfers. In the upload dialogs you can pick any library.',
							'videooptimizer'
						) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Default player', 'videooptimizer' ) }
						value={ value( 'default_player' ) }
						onChange={ set( 'default_player' ) }
						options={ [
							{
								label: __(
									'VideoOptimizer player (iframe, design from your VideoOptimizer account)',
									'videooptimizer'
								),
								value: 'hosted',
							},
							{
								label: __(
									'Native HTML5 player (part of the page)',
									'videooptimizer'
								),
								value: 'native',
							},
						] }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Default presentation', 'videooptimizer' ) }
						value={ value( 'default_presentation' ) }
						onChange={ set( 'default_presentation' ) }
						options={ [
							{
								label: __(
									'Poster, plays in place (fastest)',
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
								label: __(
									'Player directly',
									'videooptimizer'
								),
								value: 'direct',
							},
						] }
					/>
					{ toggle(
						'schema',
						__(
							'Add schema.org VideoObject data (SEO)',
							'videooptimizer'
						),
						__(
							'Helps search engines show your videos in results.',
							'videooptimizer'
						)
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Media library', 'videooptimizer' ) }</h2>
				</CardHeader>
				<CardBody>
					{ toggle(
						'media_integration',
						__(
							'Show "Send to VideoOptimizer" in the media library',
							'videooptimizer'
						)
					) }
					{ toggle(
						'replace_core_video',
						__(
							'Deliver optimized media library videos via VideoOptimizer',
							'videooptimizer'
						),
						__(
							'Video blocks, [video] shortcodes and Elementor video widgets automatically use VideoOptimizer once the video is ready. Nothing changes in your content.',
							'videooptimizer'
						)
					) }
					{ toggle(
						'auto_send',
						__(
							'Send new video uploads automatically',
							'videooptimizer'
						),
						__(
							'On public sites the transfer runs on the server; otherwise it runs in the background of your browser while WP-Admin is open.',
							'videooptimizer'
						)
					) }
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Transfer method', 'videooptimizer' ) }
						value={ value( 'transfer' ) }
						onChange={ set( 'transfer' ) }
						options={ [
							{
								label: __(
									'Automatic (server for public sites, otherwise browser)',
									'videooptimizer'
								),
								value: 'auto',
							},
							{
								label: __(
									'Always upload from the browser',
									'videooptimizer'
								),
								value: 'browser',
							},
						] }
						help={ __(
							'Choose "browser" if your site is protected (password, maintenance mode, IP allowlist).',
							'videooptimizer'
						) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'When a linked media library video is deleted',
							'videooptimizer'
						) }
						value={ value( 'delete_behavior' ) }
						onChange={ set( 'delete_behavior' ) }
						options={ [
							{
								label: __(
									'Ask whether to delete it in VideoOptimizer too',
									'videooptimizer'
								),
								value: 'ask',
							},
							{
								label: __(
									'Keep the video in VideoOptimizer',
									'videooptimizer'
								),
								value: 'keep',
							},
							{
								label: __(
									'Delete it in VideoOptimizer too (only if unused)',
									'videooptimizer'
								),
								value: 'delete',
							},
						] }
						help={ __(
							'Videos that are still used in pages, products or other media files are never deleted without asking.',
							'videooptimizer'
						) }
					/>
				</CardBody>
			</Card>

			{ config.woo ? (
				<Card>
					<CardHeader>
						<h2>WooCommerce</h2>
					</CardHeader>
					<CardBody>
						{ toggle(
							'woo_gallery',
							__(
								'Product videos in the product gallery',
								'videooptimizer'
							)
						) }
						{ value( 'woo_gallery' ) ? (
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Position in the gallery',
									'videooptimizer'
								) }
								value={ value( 'woo_gallery_position' ) }
								onChange={ set( 'woo_gallery_position' ) }
								options={ [
									{
										label: __(
											'After the product images',
											'videooptimizer'
										),
										value: 'end',
									},
									{
										label: __(
											'Right after the main image',
											'videooptimizer'
										),
										value: 'second',
									},
								] }
							/>
						) : null }
						{ toggle(
							'woo_tab',
							__( '"Video" product tab', 'videooptimizer' )
						) }
						{ value( 'woo_tab' ) ? (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Tab title', 'videooptimizer' ) }
								placeholder={ __( 'Video', 'videooptimizer' ) }
								value={ value( 'woo_tab_title' ) }
								onChange={ set( 'woo_tab_title' ) }
							/>
						) : null }
						{ toggle(
							'woo_hover',
							__(
								'Hover preview in shop and category grids',
								'videooptimizer'
							),
							__(
								'Only on devices with a mouse; respects "reduce motion".',
								'videooptimizer'
							)
						) }
					</CardBody>
				</Card>
			) : null }

			<div className="vo-settings__save">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ ! dirty || saving }
				>
					{ __( 'Save settings', 'videooptimizer' ) }
				</Button>
			</div>

			<Webhook settings={ settings } onChange={ setSettings } />

			<p className="vo-version">
				VideoOptimizer for WordPress { config.version }
			</p>
		</div>
	);
}

import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import {
	api,
	bumpPosterCache,
	forgetVideo,
	formatBytes,
	formatDuration,
	posterPreview,
	videoTitle,
} from '../../components/api';
import { pollVideo, uploadPoster } from '../../components/uploader';
import {
	ConfirmModal,
	StatusBadge,
	copyToClipboard,
} from '../../components/ui';
import { useNotify } from '../notices';

const config = window.videooptimizerAdmin || {};

function Frames( { video, onChanged } ) {
	const [ frames, setFrames ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	const notify = useNotify();

	useEffect( () => {
		api.thumbnails( video.uuid ).then( setFrames, () => setFrames( [] ) );
	}, [ video.uuid ] );

	const pick = ( index ) => {
		setBusy( index );
		api.selectThumbnail( video.uuid, index )
			.then( () => {
				bumpPosterCache();
				notify( __( 'Poster updated.', 'videooptimizer' ) );
				onChanged();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( null ) );
	};

	if ( frames === null ) {
		return <Spinner />;
	}
	if ( ! frames.length ) {
		return (
			<p>
				{ __(
					'Frames are available once the video has been processed.',
					'videooptimizer'
				) }
			</p>
		);
	}
	return (
		<div className="vo-frames">
			{ frames.map( ( frame ) => (
				<button
					type="button"
					key={ frame.index }
					className={
						'vo-frames__item' +
						( video.poster &&
						video.poster.source !== 'custom' &&
						busy === null &&
						frame.url === video.thumbnail_url
							? ' is-selected'
							: '' )
					}
					onClick={ () => pick( frame.index ) }
					disabled={ busy !== null }
					aria-label={ sprintf(
						/* translators: %d: frame number */ __(
							'Use frame %d as poster',
							'videooptimizer'
						),
						frame.index + 1
					) }
				>
					<img src={ frame.url } alt="" loading="lazy" />
					{ busy === frame.index ? <Spinner /> : null }
				</button>
			) ) }
		</div>
	);
}

function CustomPoster( { video, onChanged } ) {
	const [ busy, setBusy ] = useState( false );
	const notify = useNotify();
	const custom = video.poster || {};

	const upload = ( file ) => {
		setBusy( true );
		uploadPoster( video.uuid, file )
			.then( () =>
				pollVideo( video.uuid, {
					interval: 2000,
					max: 40,
					until: ( v ) =>
						v.poster &&
						[ 'ready', 'failed' ].includes(
							v.poster.custom_status
						),
				} )
			)
			.then( ( v ) => {
				if ( v.poster && v.poster.custom_status === 'failed' ) {
					throw new Error(
						__(
							'The poster image could not be processed.',
							'videooptimizer'
						)
					);
				}
				bumpPosterCache();
				notify( __( 'Custom poster is active.', 'videooptimizer' ) );
				onChanged();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	const fromMedia = () => {
		const frame = window.wp.media( {
			title: __( 'Choose a poster image', 'videooptimizer' ),
			library: { type: [ 'image/jpeg', 'image/png', 'image/webp' ] },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			setBusy( true );
			window
				.fetch( attachment.url, { credentials: 'same-origin' } )
				.then( ( r ) => r.blob() )
				.then( ( blob ) =>
					upload(
						new window.File( [ blob ], attachment.filename, {
							type: attachment.mime,
						} )
					)
				)
				.catch( ( e ) => {
					setBusy( false );
					notify( e.message, 'error' );
				} );
		} );
		frame.open();
	};

	const run = ( promise, message ) => {
		setBusy( true );
		promise
			.then( () => {
				bumpPosterCache();
				notify( message );
				onChanged();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="vo-custom-poster">
			<div className="vo-custom-poster__actions">
				<FormFileUpload
					accept="image/jpeg,image/png,image/webp"
					onChange={ ( e ) =>
						e.target.files[ 0 ] && upload( e.target.files[ 0 ] )
					}
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							onClick={ openFileDialog }
							disabled={ busy }
						>
							{ __( 'Upload image', 'videooptimizer' ) }
						</Button>
					) }
				/>
				{ window.wp && window.wp.media ? (
					<Button
						variant="secondary"
						onClick={ fromMedia }
						disabled={ busy }
					>
						{ __( 'From media library', 'videooptimizer' ) }
					</Button>
				) : null }
				{ custom.custom_status === 'ready' &&
				custom.source !== 'custom' ? (
					<Button
						variant="tertiary"
						disabled={ busy }
						onClick={ () =>
							run(
								api.selectPoster( video.uuid, {
									source: 'custom',
								} ),
								__(
									'Custom poster is active.',
									'videooptimizer'
								)
							)
						}
					>
						{ __( 'Use uploaded poster', 'videooptimizer' ) }
					</Button>
				) : null }
				{ custom.custom_status && custom.custom_status !== 'none' ? (
					<Button
						variant="tertiary"
						isDestructive
						disabled={ busy }
						onClick={ () =>
							run(
								api.deletePoster( video.uuid ),
								__( 'Custom poster removed.', 'videooptimizer' )
							)
						}
					>
						{ __( 'Remove uploaded poster', 'videooptimizer' ) }
					</Button>
				) : null }
				{ busy ? <Spinner /> : null }
			</div>
			<p className="description">
				{ __(
					'JPEG, PNG or WebP. Use the same aspect ratio as the video for best results.',
					'videooptimizer'
				) }
			</p>
		</div>
	);
}

function EmbedCodes( { video } ) {
	const notify = useNotify();
	const codes = [
		{
			label: __( 'Shortcode', 'videooptimizer' ),
			value: `[videooptimizer uuid="${ video.uuid }"]`,
		},
		{
			label: __(
				'Shortcode (lightbox, native player)',
				'videooptimizer'
			),
			value: `[videooptimizer uuid="${ video.uuid }" presentation="lightbox" player="native"]`,
		},
		{
			label: __(
				'Video ID (blocks, Elementor, WooCommerce)',
				'videooptimizer'
			),
			value: video.uuid,
		},
	];
	return (
		<div className="vo-codes">
			{ codes.map( ( c ) => (
				<div className="vo-codes__row" key={ c.label }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ c.label }
						value={ c.value }
						readOnly
						onFocus={ ( e ) => e.target.select() }
						onChange={ () => {} }
					/>
					<Button
						variant="secondary"
						onClick={ () =>
							copyToClipboard( c.value ).then( () =>
								notify( __( 'Copied.', 'videooptimizer' ) )
							)
						}
					>
						{ __( 'Copy', 'videooptimizer' ) }
					</Button>
				</div>
			) ) }
		</div>
	);
}

export default function VideoDetail( { uuid } ) {
	const [ video, setVideo ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const [ deleting, setDeleting ] = useState( false );
	const notify = useNotify();

	const load = () =>
		api.video( uuid ).then( ( v ) => {
			forgetVideo( uuid );
			setVideo( v );
			setTitle( videoTitle( v ) );
		}, setError );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ uuid ] );

	useEffect( () => {
		if ( ! video || video.status !== 'processing' ) {
			return undefined;
		}
		const signal = { aborted: false };
		pollVideo( uuid, { signal, onTick: setVideo } ).catch( () => {} );
		return () => {
			signal.aborted = true;
		};
	}, [ uuid, video && video.status ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return (
			<>
				<p>
					<a href="#/">← { __( 'All videos', 'videooptimizer' ) }</a>
				</p>
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			</>
		);
	}
	if ( ! video ) {
		return <Spinner />;
	}

	const options = video.options || {};
	const setOption = ( key, value ) => {
		api.updateVideo( uuid, { option: { [ key ]: value } } )
			.then( ( v ) => {
				setVideo( v );
				notify( __( 'Saved.', 'videooptimizer' ) );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) );
	};

	const saveTitle = () => {
		setSaving( true );
		api.updateVideo( uuid, { title } )
			.then( ( v ) => {
				setVideo( v );
				notify( __( 'Title saved.', 'videooptimizer' ) );
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setSaving( false ) );
	};

	const remove = () => {
		setDeleting( true );
		api.deleteVideo( uuid )
			.then( () => {
				notify( __( 'Video deleted.', 'videooptimizer' ) );
				window.location.hash = '#/';
			} )
			.catch( ( e ) => {
				notify( e.message, 'error' );
				setDeleting( false );
				setConfirmDelete( false );
			} );
	};

	const renditions = video.renditions || [];

	return (
		<div className="vo-detail">
			<p>
				<a href="#/">← { __( 'All videos', 'videooptimizer' ) }</a>
			</p>
			<div className="vo-detail__grid">
				<div className="vo-detail__main">
					<div className="vo-detail__player">
						{ video.status === 'ready' ? (
							<iframe
								src={
									video.embed_url ||
									`https://videooptimizer.eu/embed/${ uuid }`
								}
								title={ videoTitle( video ) }
								allow="autoplay; fullscreen; picture-in-picture"
								allowFullScreen
							/>
						) : (
							<div className="vo-detail__placeholder">
								{ posterPreview( video ) ? (
									<img
										src={ posterPreview( video ) }
										alt=""
									/>
								) : null }
								<div className="vo-detail__placeholder-text">
									<StatusBadge status={ video.status } />
									{ video.status === 'processing' ? (
										<p>
											{ __(
												'VideoOptimizer is encoding this video. This page updates automatically.',
												'videooptimizer'
											) }
										</p>
									) : null }
									{ video.status === 'failed' ? (
										<p>{ video.error }</p>
									) : null }
								</div>
							</div>
						) }
					</div>

					<Card>
						<CardHeader>
							<h2>{ __( 'Poster', 'videooptimizer' ) }</h2>
						</CardHeader>
						<CardBody>
							<p className="description">
								{ __(
									'Pick one of the frames or upload your own image. The change is live everywhere within seconds.',
									'videooptimizer'
								) }
							</p>
							<Frames video={ video } onChanged={ load } />
							<h3>{ __( 'Custom poster', 'videooptimizer' ) }</h3>
							<CustomPoster video={ video } onChanged={ load } />
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<h2>{ __( 'Embed', 'videooptimizer' ) }</h2>
						</CardHeader>
						<CardBody>
							<EmbedCodes video={ video } />
						</CardBody>
					</Card>
				</div>

				<div className="vo-detail__side">
					<Card>
						<CardHeader>
							<h2>{ __( 'Details', 'videooptimizer' ) }</h2>
						</CardHeader>
						<CardBody>
							<div className="vo-detail__title">
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Title', 'videooptimizer' ) }
									value={ title }
									onChange={ setTitle }
								/>
								<Button
									variant="secondary"
									onClick={ saveTitle }
									isBusy={ saving }
									disabled={
										saving || title === videoTitle( video )
									}
								>
									{ __( 'Save', 'videooptimizer' ) }
								</Button>
							</div>
							<dl className="vo-detail__facts">
								<dt>{ __( 'Status', 'videooptimizer' ) }</dt>
								<dd>
									<StatusBadge status={ video.status } />
								</dd>
								{ video.duration ? (
									<>
										<dt>
											{ __(
												'Duration',
												'videooptimizer'
											) }
										</dt>
										<dd>
											{ formatDuration( video.duration ) }
										</dd>
									</>
								) : null }
								{ video.resolution ? (
									<>
										<dt>
											{ __(
												'Resolution',
												'videooptimizer'
											) }
										</dt>
										<dd>{ video.resolution }</dd>
									</>
								) : null }
								{ renditions.length ? (
									<>
										<dt>
											{ __(
												'Renditions',
												'videooptimizer'
											) }
										</dt>
										<dd>
											{ renditions
												.map(
													( r ) =>
														`${ r.quality } ${ r.codec }`
												)
												.join( ', ' ) }
										</dd>
									</>
								) : null }
								{ typeof video.views === 'number' ? (
									<>
										<dt>
											{ __( 'Views', 'videooptimizer' ) }
										</dt>
										<dd>{ video.views }</dd>
									</>
								) : null }
								{ video.size ? (
									<>
										<dt>
											{ __( 'Size', 'videooptimizer' ) }
										</dt>
										<dd>{ formatBytes( video.size ) }</dd>
									</>
								) : null }
								<dt>ID</dt>
								<dd>
									<code>{ uuid }</code>
								</dd>
							</dl>
						</CardBody>
					</Card>

					<Card>
						<CardHeader>
							<h2>
								{ __( 'Player defaults', 'videooptimizer' ) }
							</h2>
						</CardHeader>
						<CardBody>
							<p className="description">
								{ __(
									'Used by the VideoOptimizer player unless a block overrides them.',
									'videooptimizer'
								) }
							</p>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Autoplay (muted)',
									'videooptimizer'
								) }
								checked={ !! options.autoplay }
								onChange={ ( v ) => setOption( 'autoplay', v ) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Loop', 'videooptimizer' ) }
								checked={ !! options.loop }
								onChange={ ( v ) => setOption( 'loop', v ) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Start muted', 'videooptimizer' ) }
								checked={ !! options.muted }
								onChange={ ( v ) => setOption( 'muted', v ) }
							/>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Preload', 'videooptimizer' ) }
								checked={ !! options.preload }
								onChange={ ( v ) => setOption( 'preload', v ) }
							/>
						</CardBody>
					</Card>

					{ config.canDelete ? (
						<Card>
							<CardBody>
								<Button
									variant="secondary"
									isDestructive
									onClick={ () => setConfirmDelete( true ) }
								>
									{ __( 'Delete video', 'videooptimizer' ) }
								</Button>
							</CardBody>
						</Card>
					) : null }
				</div>
			</div>

			{ confirmDelete ? (
				<ConfirmModal
					title={ __( 'Delete this video?', 'videooptimizer' ) }
					confirmLabel={ __(
						'Delete permanently',
						'videooptimizer'
					) }
					onConfirm={ remove }
					onCancel={ () => setConfirmDelete( false ) }
					busy={ deleting }
				>
					<p>
						{ __(
							'The video is removed from VideoOptimizer and the CDN. Pages that embed it will no longer show it. Media library files are not touched.',
							'videooptimizer'
						) }
					</p>
				</ConfirmModal>
			) : null }
		</div>
	);
}

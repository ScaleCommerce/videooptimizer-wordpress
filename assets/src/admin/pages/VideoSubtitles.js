/**
 * Subtitles card of the video detail page: list, upload (.vtt / .srt), download and delete.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../../components/api';
import {
	checkSubtitleFile,
	downloadTextFile,
	languageLabel,
} from '../../components/subtitles';
import { ConfirmModal } from '../../components/ui';
import { useNotify } from '../notices';

const config = window.videooptimizerAdmin || {};

export default function VideoSubtitles( { video } ) {
	const [ subtitles, setSubtitles ] = useState( null );
	const [ languages, setLanguages ] = useState( null );
	const [ language, setLanguage ] = useState( '' );
	const [ kind, setKind ] = useState( '' );
	const [ isDefault, setIsDefault ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ removing, setRemoving ] = useState( null );
	const notify = useNotify();

	const load = () =>
		api.subtitles( video.uuid ).then( setSubtitles, ( e ) => {
			setSubtitles( [] );
			notify( e.message, 'error' );
		} );

	useEffect( () => {
		load();
		api.subtitleLanguages().then( setLanguages, () => setLanguages( [] ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ video.uuid ] );

	if ( subtitles === null || languages === null ) {
		return (
			<Card>
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	const existing = subtitles.find( ( s ) => s.language === language );

	const upload = ( file ) => {
		const problem = checkSubtitleFile( file );
		if ( problem ) {
			notify( problem, 'error' );
			return;
		}
		setBusy( true );
		api.uploadSubtitle( video.uuid, file, { language, kind, isDefault } )
			.then( ( result ) => {
				const skipped = ( result && result.skipped_cues ) || 0;
				notify(
					skipped
						? sprintf(
								/* translators: %d: number of skipped cues */
								_n(
									'Subtitle saved; %d malformed cue was skipped.',
									'Subtitle saved; %d malformed cues were skipped.',
									skipped,
									'videooptimizer'
								),
								skipped
						  )
						: __( 'Subtitle saved.', 'videooptimizer' )
				);
				return load();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	const download = ( code ) =>
		api.downloadSubtitle( video.uuid, code ).then(
			( r ) =>
				downloadTextFile(
					`${ video.uuid }.${ code }.vtt`,
					r.content,
					'text/vtt'
				),
			( e ) => notify( e.message, 'error' )
		);

	const remove = () => {
		setBusy( true );
		api.deleteSubtitle( video.uuid, removing )
			.then( () => {
				notify( __( 'Subtitle deleted.', 'videooptimizer' ) );
				setRemoving( null );
				return load();
			} )
			.catch( ( e ) => notify( e.message, 'error' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Subtitles', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				{ subtitles.length ? (
					<ul className="vo-subtitles">
						{ subtitles.map( ( s ) => (
							<li key={ s.language }>
								<span className="vo-subtitles__name">
									{ languageLabel( languages, s.language ) }
									<code>{ s.language }</code>
								</span>
								<span className="vo-subtitles__facts">
									{ s.kind === 'captions'
										? __( 'Captions', 'videooptimizer' )
										: __( 'Subtitles', 'videooptimizer' ) }
									{ s.is_default ? (
										<span className="vo-badge vo-badge--ready">
											{ __(
												'Default',
												'videooptimizer'
											) }
										</span>
									) : null }
									{ s.origin === 'embedded' ? (
										<span
											className="vo-badge"
											title={ __(
												'Taken over automatically from a subtitle track inside the video file.',
												'videooptimizer'
											) }
										>
											{ __(
												'From video file',
												'videooptimizer'
											) }
										</span>
									) : null }
									{ typeof s.cue_count === 'number'
										? sprintf(
												/* translators: %d: number of subtitle cues */
												_n(
													'%d cue',
													'%d cues',
													s.cue_count,
													'videooptimizer'
												),
												s.cue_count
										  )
										: null }
								</span>
								<span className="vo-subtitles__actions">
									<Button
										variant="tertiary"
										onClick={ () => download( s.language ) }
									>
										{ __( 'Download', 'videooptimizer' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setRemoving( s.language )
										}
									>
										{ __( 'Delete', 'videooptimizer' ) }
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="description">
						{ __(
							'No subtitles yet. Upload a .vtt or .srt file per language; the player offers them in its menu.',
							'videooptimizer'
						) }
					</p>
				) }

				{ languages.length ? (
					<div className="vo-subtitles__form">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Language', 'videooptimizer' ) }
							value={ language }
							onChange={ setLanguage }
							options={ [
								{
									label: __(
										'Choose a language',
										'videooptimizer'
									),
									value: '',
								},
								...languages.map( ( l ) => ( {
									label: `${ l.label } (${ l.code })`,
									value: l.code,
								} ) ),
							] }
							help={
								existing
									? __(
											'This language already has a subtitle; uploading replaces it.',
											'videooptimizer'
									  )
									: ''
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Type', 'videooptimizer' ) }
							value={ kind }
							onChange={ setKind }
							options={ [
								{
									label: __(
										'Keep or default (subtitles)',
										'videooptimizer'
									),
									value: '',
								},
								{
									label: __( 'Subtitles', 'videooptimizer' ),
									value: 'subtitles',
								},
								{
									label: __(
										'Captions (for deaf and hard-of-hearing viewers)',
										'videooptimizer'
									),
									value: 'captions',
								},
							] }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Show automatically when playback starts',
								'videooptimizer'
							) }
							checked={ isDefault }
							onChange={ setIsDefault }
						/>
						<FormFileUpload
							accept=".vtt,.srt,text/vtt,application/x-subrip"
							onChange={ ( e ) =>
								e.target.files[ 0 ] &&
								upload( e.target.files[ 0 ] )
							}
							render={ ( { openFileDialog } ) => (
								<Button
									variant="secondary"
									onClick={ openFileDialog }
									disabled={ ! language || busy }
									isBusy={ busy }
								>
									{ __(
										'Upload subtitle file',
										'videooptimizer'
									) }
								</Button>
							) }
						/>
					</div>
				) : (
					<p className="description">
						{ config.canManage ? (
							<>
								{ __(
									'Add the languages you need first.',
									'videooptimizer'
								) }{ ' ' }
								<a href="#/settings">
									{ __(
										'Subtitle languages',
										'videooptimizer'
									) }
								</a>
							</>
						) : (
							__(
								'Ask an administrator to add subtitle languages first.',
								'videooptimizer'
							)
						) }
					</p>
				) }
			</CardBody>
			{ removing ? (
				<ConfirmModal
					title={ __( 'Delete this subtitle?', 'videooptimizer' ) }
					confirmLabel={ __( 'Delete subtitle', 'videooptimizer' ) }
					onConfirm={ remove }
					onCancel={ () => setRemoving( null ) }
					busy={ busy }
				>
					<p>
						{ sprintf(
							/* translators: %s: language name */
							__(
								'The %s subtitle is removed from the video and the CDN.',
								'videooptimizer'
							),
							languageLabel( languages, removing )
						) }
					</p>
				</ConfirmModal>
			) : null }
		</Card>
	);
}

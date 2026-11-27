/**
 * Subtitle languages of the organization (Settings page): the languages editors can upload
 * subtitles in. A language that videos still use cannot be removed.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../../components/api';
import { validLanguageCode } from '../../components/subtitles';
import { useGuardedAction } from '../../components/busy';
import { ConfirmModal } from '../../components/ui';
import { useNotify } from '../notices';

export default function SubtitleLanguages() {
	const [ languages, setLanguages ] = useState( null );
	const [ code, setCode ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ busy, run ] = useGuardedAction();
	const [ removing, setRemoving ] = useState( null );
	const notify = useNotify();

	const load = () =>
		api.subtitleLanguages().then( setLanguages, ( e ) => {
			setLanguages( [] );
			notify( e.message, 'error' );
		} );

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const add = () =>
		run( () =>
			api
				.createSubtitleLanguage( {
					code: code.trim(),
					label: label.trim(),
				} )
				.then( () => {
					setCode( '' );
					setLabel( '' );
					notify( __( 'Language added.', 'videooptimizer' ) );
					return load();
				} )
				.catch( ( e ) => notify( e.message, 'error' ) )
		);

	const remove = () =>
		run( () =>
			api
				.deleteSubtitleLanguage( removing.code )
				.then( () => {
					notify( __( 'Language removed.', 'videooptimizer' ) );
					setRemoving( null );
					return load();
				} )
				.catch( ( e ) => {
					setRemoving( null );
					notify( e.message, 'error' );
				} )
		);

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Subtitle languages', 'videooptimizer' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Subtitles can only be uploaded in these languages. The label is what viewers see in the player menu.',
						'videooptimizer'
					) }
				</p>
				{ languages === null ? <Spinner /> : null }
				{ languages && languages.length ? (
					<ul className="vo-subtitles">
						{ languages.map( ( l ) => (
							<li key={ l.code }>
								<span className="vo-subtitles__name">
									{ l.label }
									<code>{ l.code }</code>
								</span>
								<span className="vo-subtitles__facts">
									{ sprintf(
										/* translators: %d: number of videos */
										_n(
											'%d video',
											'%d videos',
											l.video_count || 0,
											'videooptimizer'
										),
										l.video_count || 0
									) }
								</span>
								<span className="vo-subtitles__actions">
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () => setRemoving( l ) }
									>
										{ __( 'Remove', 'videooptimizer' ) }
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				) : null }
				<div className="vo-subtitles__form vo-subtitles__form--row">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Language code', 'videooptimizer' ) }
						placeholder="de, en-US"
						value={ code }
						onChange={ setCode }
						help={
							code && ! validLanguageCode( code.trim() )
								? __(
										'Use a BCP 47 code such as de or en-US.',
										'videooptimizer'
								  )
								: ''
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Label (optional)', 'videooptimizer' ) }
						placeholder="Deutsch"
						maxLength={ 60 }
						value={ label }
						onChange={ setLabel }
					/>
					<Button
						variant="secondary"
						onClick={ add }
						isBusy={ busy }
						disabled={ busy || ! validLanguageCode( code.trim() ) }
					>
						{ __( 'Add language', 'videooptimizer' ) }
					</Button>
				</div>
			</CardBody>
			{ removing ? (
				<ConfirmModal
					title={ __( 'Remove this language?', 'videooptimizer' ) }
					confirmLabel={ __( 'Remove language', 'videooptimizer' ) }
					onConfirm={ remove }
					onCancel={ () => setRemoving( null ) }
					busy={ busy }
				>
					<p>
						{ sprintf(
							/* translators: %s: language name */
							__(
								'%s can no longer be used for new subtitles. It cannot be removed while videos still have subtitles in it.',
								'videooptimizer'
							),
							removing.label
						) }
					</p>
				</ConfirmModal>
			) : null }
		</Card>
	);
}

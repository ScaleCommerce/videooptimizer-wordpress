import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { sendAttachment, watchAttachment } from '../../components/transfer';
import { createSignal } from '../../components/uploader';
import { ProgressBar, StatusBadge } from '../../components/ui';

const config = window.videooptimizerAdmin || {};

/**
 * Transfer screen for media library videos (bulk / row action). Runs the uploads one after the
 * other in the browser with progress and keeps watching until VideoOptimizer is done.
 *
 * @param {Object}        props
 * @param {Array<number>} props.ids Attachment ids.
 */
export default function SendPage( { ids } ) {
	const [ rows, setRows ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( null );
	const signal = useRef( createSignal() );

	const update = ( id, patch ) =>
		setRows( ( list ) =>
			list.map( ( r ) => ( r.id === id ? { ...r, ...patch } : r ) )
		);

	useEffect( () => {
		if ( ! ids.length ) {
			setRows( [] );
			return;
		}
		apiFetch( {
			path:
				'/wp/v2/media?context=edit&per_page=100&include=' +
				ids.join( ',' ) +
				'&_fields=id,source_url,title,mime_type,videooptimizer',
		} )
			.then( ( media ) =>
				setRows(
					media
						.filter( ( m ) =>
							String( m.mime_type ).startsWith( 'video/' )
						)
						.map( ( m ) => ( {
							id: m.id,
							url: m.source_url,
							title:
								( m.title && m.title.raw ) ||
								m.source_url.split( '/' ).pop(),
							state: m.videooptimizer || { status: 'none' },
							phase: '',
							progress: 0,
							error: '',
						} ) )
				)
			)
			.catch( setError );
		const current = signal.current;
		return () => current.abort();
	}, [ ids.join( ',' ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const start = async () => {
		setRunning( true );
		for ( const row of rows ) {
			if (
				row.state.status === 'ready' ||
				row.state.status === 'processing'
			) {
				continue;
			}
			update( row.id, { phase: 'server', error: '' } );
			try {
				const state = await sendAttachment(
					{
						id: row.id,
						url: row.url,
						title: row.title,
						filename: row.url.split( '/' ).pop(),
					},
					{
						signal: signal.current,
						onPhase: ( phase ) => update( row.id, { phase } ),
						onProgress: ( progress ) =>
							update( row.id, { progress } ),
					}
				);
				update( row.id, { state, phase: '' } );
				watchAttachment(
					row.id,
					( s ) => update( row.id, { state: s } ),
					signal.current
				);
			} catch ( e ) {
				update( row.id, { phase: '', error: e.message } );
			}
		}
		setRunning( false );
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error.message }
			</Notice>
		);
	}
	if ( rows === null ) {
		return <Spinner />;
	}

	const todo = rows.filter(
		( r ) => ! [ 'ready', 'processing' ].includes( r.state.status )
	).length;

	return (
		<Card className="vo-send">
			<CardBody>
				<h2>
					{ __(
						'Send media library videos to VideoOptimizer',
						'videooptimizer'
					) }
				</h2>
				<p>
					{ __(
						'The original files stay in your media library. As soon as VideoOptimizer has optimized a video, it is delivered adaptively wherever it is used — no content changes needed.',
						'videooptimizer'
					) }
				</p>
				{ ! config.configured ? (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Please connect VideoOptimizer first.',
							'videooptimizer'
						) }{ ' ' }
						<a href="#/settings">
							{ __( 'Settings', 'videooptimizer' ) }
						</a>
					</Notice>
				) : null }
				{ ! rows.length ? (
					<p>{ __( 'No videos selected.', 'videooptimizer' ) }</p>
				) : null }
				<table className="widefat striped vo-send__table">
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td className="vo-send__title">
									{ row.title }
								</td>
								<td className="vo-send__status">
									{ row.phase === 'upload' ? (
										<ProgressBar value={ row.progress } />
									) : null }
									{ row.phase && row.phase !== 'upload' ? (
										<Spinner />
									) : null }
									{ ! row.phase ? (
										<StatusBadge
											status={ row.state.status }
										/>
									) : null }
									{ row.error ||
									( row.state.status === 'failed' &&
										row.state.error ) ? (
										<span className="vo-send__error">
											{ row.error || row.state.error }
										</span>
									) : null }
								</td>
								<td>
									{ row.state.uuid ? (
										<a href={ '#/video/' + row.state.uuid }>
											{ __(
												'Details',
												'videooptimizer'
											) }
										</a>
									) : null }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<div className="vo-row">
					<Button
						variant="primary"
						onClick={ start }
						isBusy={ running }
						disabled={ running || ! todo || ! config.configured }
					>
						{ running
							? __(
									'Transferring… please keep this page open',
									'videooptimizer'
							  )
							: sprintf(
									/* translators: %d: number of videos */ __(
										'Send %d videos',
										'videooptimizer'
									),
									todo
							  ) }
					</Button>
					<Button variant="tertiary" href={ config.mediaUrl }>
						{ __( 'Back to the media library', 'videooptimizer' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

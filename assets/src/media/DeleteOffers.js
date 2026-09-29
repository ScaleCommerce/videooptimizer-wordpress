/**
 * "The deleted media file was delivered via VideoOptimizer — delete it there too?"
 * Shown after deleting a linked media library video (WordPress offers no way to extend its own
 * delete confirmation). Warns when the video is still used somewhere else.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { api } from '../components/api';

export default function DeleteOffers( { registerRefresh } ) {
	const [ offers, setOffers ] = useState( [] );
	const [ canDelete, setCanDelete ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const apply = ( data ) => {
		setOffers( ( data && data.offers ) || [] );
		setCanDelete( !! ( data && data.can_delete ) );
	};

	const refresh = useCallback(
		() => api.deleteOffers().then( apply, () => {} ),
		[]
	);

	useEffect( () => {
		refresh();
		registerRefresh( refresh );
	}, [ refresh, registerRefresh ] );

	const resolve = ( uuid, remove ) => {
		setBusy( uuid );
		setError( '' );
		api.resolveDeleteOffer( uuid, remove )
			.then( apply )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! offers.length ) {
		return null;
	}

	return (
		<div className="vo-delete-offers">
			{ offers.map( ( offer ) => {
				const usage = offer.usage || [];
				return (
					<Notice
						key={ offer.uuid }
						status={ usage.length ? 'warning' : 'info' }
						isDismissible={ false }
					>
						<p>
							<strong>VideoOptimizer:</strong>{ ' ' }
							{ sprintf(
								/* translators: %s: media file title */
								__(
									'"%s" was deleted from the media library. The optimized copy still exists in VideoOptimizer.',
									'videooptimizer'
								),
								offer.title || offer.uuid
							) }
						</p>
						{ usage.length ? (
							<>
								<p>
									{ sprintf(
										/* translators: %d: number of places */
										_n(
											'The video is still used in %d place — deleting it removes it there too:',
											'The video is still used in %d places — deleting it removes it there too:',
											usage.length,
											'videooptimizer'
										),
										usage.length
									) }
								</p>
								<ul className="vo-delete-offers__usage">
									{ usage.map( ( u ) => (
										<li key={ u.id }>
											{ u.edit_url ? (
												<a href={ u.edit_url }>
													{ u.title }
												</a>
											) : (
												u.title
											) }{ ' ' }
											<span className="description">
												({ u.type })
											</span>
										</li>
									) ) }
								</ul>
							</>
						) : null }
						<p className="vo-delete-offers__actions">
							{ canDelete ? (
								<Button
									variant="primary"
									isDestructive
									isBusy={ busy === offer.uuid }
									disabled={ !! busy }
									onClick={ () =>
										resolve( offer.uuid, true )
									}
								>
									{ __(
										'Delete in VideoOptimizer too',
										'videooptimizer'
									) }
								</Button>
							) : null }
							<Button
								variant="secondary"
								disabled={ !! busy }
								onClick={ () => resolve( offer.uuid, false ) }
							>
								{ __(
									'Keep in VideoOptimizer',
									'videooptimizer'
								) }
							</Button>
						</p>
					</Notice>
				);
			} ) }
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
		</div>
	);
}

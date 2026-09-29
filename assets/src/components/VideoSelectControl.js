/**
 * Compact "selected video" control: poster + title + change/remove, opens the picker.
 */
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { getVideoCached, videoTitle } from './api';
import VideoPicker from './VideoPicker';
import { Poster, StatusBadge } from './ui';

export function useVideo( uuid ) {
	const [ state, setState ] = useState( {
		video: null,
		loading: !! uuid,
		error: null,
	} );
	useEffect( () => {
		let live = true;
		if ( ! uuid ) {
			setState( { video: null, loading: false, error: null } );
			return undefined;
		}
		setState( ( s ) => ( { ...s, loading: true } ) );
		getVideoCached( uuid ).then(
			( video ) =>
				live && setState( { video, loading: false, error: null } ),
			( error ) =>
				live && setState( { video: null, loading: false, error } )
		);
		return () => {
			live = false;
		};
	}, [ uuid ] );
	return state;
}

export default function VideoSelectControl( {
	value,
	onChange,
	label,
	help,
	compact = false,
} ) {
	const [ open, setOpen ] = useState( false );
	const { video, loading, error } = useVideo( value );

	return (
		<div
			className={ 'vo-select' + ( compact ? ' vo-select--compact' : '' ) }
		>
			{ label ? <div className="vo-select__label">{ label }</div> : null }
			{ value ? (
				<div className="vo-select__current">
					{ loading ? <Spinner /> : null }
					{ video ? (
						<>
							<Poster video={ video } />
							<div className="vo-select__info">
								<strong title={ video.title }>
									{ videoTitle( video ) }
								</strong>
								<StatusBadge status={ video.status } />
							</div>
						</>
					) : null }
					{ error ? (
						<p className="vo-select__error">
							{ error.status === 404 ||
							error.upstreamStatus === 404
								? __(
										'This video no longer exists.',
										'videooptimizer'
								  )
								: error.message }
						</p>
					) : null }
				</div>
			) : null }
			<div className="vo-select__actions">
				<Button
					variant={ value ? 'secondary' : 'primary' }
					onClick={ () => setOpen( true ) }
					size={ compact ? 'small' : undefined }
				>
					{ value
						? __( 'Replace', 'videooptimizer' )
						: __( 'Choose video', 'videooptimizer' ) }
				</Button>
				{ value ? (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => onChange( '' ) }
						size={ compact ? 'small' : undefined }
					>
						{ __( 'Remove', 'videooptimizer' ) }
					</Button>
				) : null }
			</div>
			{ help ? <p className="vo-select__help">{ help }</p> : null }
			{ open ? (
				<VideoPicker
					selected={ value }
					onClose={ () => setOpen( false ) }
					onSelect={ ( v ) => {
						onChange( v.uuid, v );
						setOpen( false );
					} }
				/>
			) : null }
		</div>
	);
}

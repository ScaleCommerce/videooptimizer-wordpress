/**
 * Small shared UI pieces.
 */
import { __ } from '@wordpress/i18n';
import { Button, Modal } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { posterPreview, videoTitle, formatDuration } from './api';

export const STATUS_LABELS = {
	processing: __( 'Processing', 'videooptimizer' ),
	ready: __( 'Ready', 'videooptimizer' ),
	failed: __( 'Failed', 'videooptimizer' ),
	none: __( 'Not sent', 'videooptimizer' ),
	pending: __( 'Waiting for transfer', 'videooptimizer' ),
	uploading: __( 'Uploading', 'videooptimizer' ),
};

export function StatusBadge( { status } ) {
	return (
		<span className={ `vo-badge vo-badge--${ status }` }>
			{ STATUS_LABELS[ status ] || status }
		</span>
	);
}

export function ProgressBar( { value } ) {
	const pct = Math.round( ( value || 0 ) * 100 );
	return (
		<div
			className="vo-progress"
			role="progressbar"
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
			aria-valuenow={ pct }
		>
			<div className="vo-progress__bar" style={ { width: pct + '%' } } />
		</div>
	);
}

export function Poster( { video, className = '' } ) {
	const [ failed, setFailed ] = useState( false );
	const src = posterPreview( video );
	useEffect( () => setFailed( false ), [ src ] );
	return (
		<div className={ 'vo-poster ' + className }>
			{ src && ! failed ? (
				<img
					src={ src }
					alt=""
					loading="lazy"
					onError={ () => setFailed( true ) }
				/>
			) : (
				<span className="vo-poster__empty dashicons dashicons-format-video" />
			) }
			{ video && video.duration ? (
				<span className="vo-poster__duration">
					{ formatDuration( video.duration ) }
				</span>
			) : null }
		</div>
	);
}

export function VideoCard( { video, onClick, selected, actions } ) {
	const status = video.status || ( video.ready ? 'ready' : 'processing' );
	return (
		<div
			className={
				'vo-card' +
				( selected ? ' is-selected' : '' ) +
				( onClick ? ' is-clickable' : '' )
			}
		>
			<button
				type="button"
				className="vo-card__main"
				onClick={ onClick }
				disabled={ ! onClick }
				aria-pressed={ selected ? 'true' : undefined }
			>
				<Poster video={ video } />
				<span className="vo-card__title" title={ video.title }>
					{ videoTitle( video ) }
				</span>
			</button>
			<div className="vo-card__meta">
				<StatusBadge status={ status } />
				{ video.resolution ? (
					<span className="vo-card__res">{ video.resolution }</span>
				) : null }
				{ actions }
			</div>
			{ status === 'failed' && video.error ? (
				<p className="vo-card__error">{ video.error }</p>
			) : null }
		</div>
	);
}

export function ConfirmModal( {
	title,
	children,
	confirmLabel,
	onConfirm,
	onCancel,
	destructive = true,
	busy = false,
} ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } size="small">
			<div className="vo-confirm">{ children }</div>
			<div className="vo-modal-actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'videooptimizer' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive={ destructive }
					onClick={ onConfirm }
					isBusy={ busy }
					disabled={ busy }
				>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}

export function copyToClipboard( text ) {
	if ( window.navigator.clipboard ) {
		return window.navigator.clipboard.writeText( text );
	}
	const area = document.createElement( 'textarea' );
	area.value = text;
	document.body.appendChild( area );
	area.select();
	document.execCommand( 'copy' ); // eslint-disable-line @wordpress/no-global-active-element
	area.remove();
	return Promise.resolve();
}

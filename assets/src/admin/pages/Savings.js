/**
 * Savings strip on the Videos page: what VideoOptimizer saves compared with the original files.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, CardBody } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { api, formatBytes } from '../../components/api';
import { lastDaysRange, summarizeSavings } from '../../components/savings';

const percent = ( value ) => `${ Number( value ).toFixed( 1 ) } %`;

function Tile( { label, value, note } ) {
	return (
		<Card className="vo-savings__tile">
			<CardBody>
				<div className="vo-savings__label">{ label }</div>
				<div className="vo-savings__value">{ value }</div>
				{ note ? (
					<div className="vo-savings__note">{ note }</div>
				) : null }
			</CardBody>
		</Card>
	);
}

export default function Savings( { library = '' } ) {
	const [ summary, setSummary ] = useState( null );

	useEffect( () => {
		let live = true;
		setSummary( null );
		api.savings( { libraryId: library, ...lastDaysRange( 30 ) } ).then(
			( data ) => live && setSummary( summarizeSavings( data ) ),
			// The savings are a nice-to-have: stay quiet when they cannot be loaded.
			() => {}
		);
		return () => {
			live = false;
		};
	}, [ library ] );

	if ( ! summary ) {
		return null;
	}

	return (
		<div className="vo-savings">
			<Tile
				label={ __( 'Optimized videos', 'videooptimizer' ) }
				value={ summary.videos }
			/>
			<Tile
				label={ __( 'Storage saved', 'videooptimizer' ) }
				value={ percent( summary.reducedPercent ) }
				note={ sprintf(
					/* translators: 1: size of the original files, 2: size of the encoded versions */
					__( '%1$s original → %2$s encoded', 'videooptimizer' ),
					formatBytes( summary.originalBytes ),
					formatBytes( summary.encodedBytes )
				) }
			/>
			{ summary.allTime ? (
				<Tile
					label={ __(
						'Bandwidth saved (estimated)',
						'videooptimizer'
					) }
					value={ formatBytes( summary.allTime.savedBytes ) }
					note={ sprintf(
						/* translators: %s: percentage */
						__(
							'%s of the traffic since the start',
							'videooptimizer'
						),
						percent( summary.allTime.savedPercent )
					) }
				/>
			) : null }
			{ summary.range ? (
				<Tile
					label={ sprintf(
						/* translators: %d: number of days */
						_n(
							'Bandwidth saved, last %d day',
							'Bandwidth saved, last %d days',
							30,
							'videooptimizer'
						),
						30
					) }
					value={ formatBytes( summary.range.savedBytes ) }
					note={ percent( summary.range.savedPercent ) }
				/>
			) : null }
		</div>
	);
}

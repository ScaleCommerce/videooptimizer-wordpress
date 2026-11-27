/**
 * Prevents double submits: a second click before React has re-rendered the disabled button
 * would otherwise run the API action twice (e.g. two libraries).
 */
import { useRef, useState } from '@wordpress/element';

/**
 * @return {{run: Function, busy: Function}} Guard: run(action) starts an async action unless one
 *                                           is running (then it returns null).
 */
export function makeGuard() {
	let running = false;
	return {
		busy: () => running,
		run( action ) {
			if ( running ) {
				return null;
			}
			running = true;
			let promise;
			try {
				promise = Promise.resolve( action() );
			} catch ( error ) {
				running = false;
				throw error;
			}
			return promise.finally( () => {
				running = false;
			} );
		},
	};
}

/**
 * Hook: [busy, run]. `busy` drives isBusy/disabled, `run(action)` ignores repeated calls.
 *
 * @return {Array} [ busy, run ]
 */
export function useGuardedAction() {
	const guard = useRef( makeGuard() );
	const [ busy, setBusy ] = useState( false );
	const run = ( action ) => {
		const promise = guard.current.run( () => {
			setBusy( true );
			return action();
		} );
		return promise
			? promise.finally( () => setBusy( false ) )
			: Promise.resolve();
	};
	return [ busy, run ];
}

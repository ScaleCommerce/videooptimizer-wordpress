/**
 * Guard against double submits of API actions.
 */
import { makeGuard } from '../../assets/src/components/busy';

describe( 'makeGuard', () => {
	it( 'ignores a second call while the first is still running', async () => {
		const guard = makeGuard();
		let release;
		const action = jest.fn(
			() => new Promise( ( resolve ) => ( release = resolve ) )
		);

		const first = guard.run( action );
		const second = guard.run( action );

		expect( action ).toHaveBeenCalledTimes( 1 );
		expect( guard.busy() ).toBe( true );
		expect( second ).toBeNull();

		release( 'ok' );
		await expect( first ).resolves.toBe( 'ok' );
		expect( guard.busy() ).toBe( false );
		guard.run( action );
		expect( action ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'is free again after a failure', async () => {
		const guard = makeGuard();
		await expect(
			guard.run( () => Promise.reject( new Error( 'x' ) ) )
		).rejects.toThrow( 'x' );
		expect( guard.busy() ).toBe( false );
	} );
} );

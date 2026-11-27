/**
 * Talks to hosted VideoOptimizer players (iframes) through the embed's postMessage bridge:
 *   page -> iframe: { type: 'videooptimizer:command', command: 'play'|'pause'|'seek', time? }
 *   iframe -> page: { type: 'videooptimizer:event', event: 'ready'|'play'|'pause'|'ended', currentTime }
 */
const watchers = new Set();
let bound = false;

/**
 * @param {HTMLIFrameElement} iframe
 * @param {string}            command
 * @param {Object}            extra   Further message fields (e.g. `time`).
 */
export function sendCommand( iframe, command, extra = {} ) {
	try {
		iframe.contentWindow.postMessage(
			{ type: 'videooptimizer:command', command, ...extra },
			'*'
		);
	} catch ( e ) {}
}

/**
 * Calls back for events of the iframe inside a frame (the iframe may be injected later).
 *
 * @param {Element}                           frame   `.vo-frame`.
 * @param {function(string, Object): void}    onEvent Receives the event name and the message.
 */
export function watchHosted( frame, onEvent ) {
	watchers.add( { frame, onEvent } );
	if ( bound ) {
		return;
	}
	bound = true;
	window.addEventListener( 'message', ( event ) => {
		const data = event.data;
		if ( ! data || data.type !== 'videooptimizer:event' ) {
			return;
		}
		watchers.forEach( ( watcher ) => {
			const iframe = watcher.frame.querySelector( 'iframe' );
			if ( iframe && iframe.contentWindow === event.source ) {
				watcher.onEvent( String( data.event ), data );
			}
		} );
	} );
}

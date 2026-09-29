/**
 * Forwards localhost:8081/8082 inside a node container to "wp"/"woo" containers, so the site
 * URLs (http://localhost:808x) work exactly like on the host. Optional helper for Docker setups.
 */
const net = require( 'net' );

const routes = { 8081: 'wp', 8082: 'woo' };

for ( const [ port, host ] of Object.entries( routes ) ) {
	net.createServer( ( client ) => {
		const upstream = net.connect( 80, host );
		client.pipe( upstream ).pipe( client );
		const close = () => {
			client.destroy();
			upstream.destroy();
		};
		client.on( 'error', close );
		upstream.on( 'error', close );
	} ).listen( Number( port ), '127.0.0.1' );
}

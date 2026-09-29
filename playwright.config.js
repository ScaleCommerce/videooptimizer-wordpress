/**
 * End-to-end smoke tests against the local Docker instances (`just e2e`).
 */
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 60_000,
	retries: 1,
	reporter: [ [ 'list' ] ],
	use: {
		...devices[ 'Desktop Chrome' ],
		trace: 'retain-on-failure',
	},
} );

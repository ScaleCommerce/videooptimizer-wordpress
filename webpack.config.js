/**
 * Extends the @wordpress/scripts config: blocks are auto-detected from assets/src/blocks/*\/block.json,
 * the other bundles are explicit entries, and hls.js is copied as a separate file that the
 * frontend loads on demand only.
 */
const path = require( 'path' );
const CopyPlugin = require( 'copy-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const src = ( file ) => path.resolve( __dirname, 'assets/src', file );

module.exports = {
	...defaultConfig,
	entry: {
		...( typeof defaultConfig.entry === 'function'
			? defaultConfig.entry()
			: defaultConfig.entry ),
		'frontend/index': src( 'frontend/index.js' ),
		'admin/index': src( 'admin/index.js' ),
		'media/index': src( 'media/index.js' ),
		'woo/index': src( 'woo/index.js' ),
		'elementor/index': src( 'elementor/index.js' ),
	},
	plugins: [
		...defaultConfig.plugins,
		new CopyPlugin( {
			patterns: [
				{
					from: path.resolve(
						__dirname,
						'node_modules/hls.js/dist/hls.light.min.js'
					),
					to: 'vendor/hls.light.min.js',
				},
			],
		} ),
	],
};

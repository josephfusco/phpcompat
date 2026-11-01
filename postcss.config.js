/**
 * PostCSS config: the `@wordpress/scripts` defaults, plus WordPress's design
 * token fallbacks. Token values come from `@wordpress/theme` at build time, so
 * the stylesheet works on WordPress versions that don't load the tokens
 * without hand-maintained fallback values.
 */
const postcssPlugins = require( '@wordpress/postcss-plugins-preset' );
const dsTokenFallbacks =
	require( '@wordpress/theme/postcss-plugins/postcss-ds-token-fallbacks' ).default;

module.exports = ( { mode } ) => ( {
	plugins: [
		dsTokenFallbacks,
		...postcssPlugins,
		...( 'production' === mode
			? [
					require( 'cssnano' )( {
						preset: [
							'default',
							{ discardComments: { removeAll: true } },
						],
					} ),
				]
			: [] ),
	],
} );

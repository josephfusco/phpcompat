/**
 * ESLint: WordPress's flat config from `@wordpress/scripts`, plus the
 * globals this plugin gets from WordPress at runtime.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		settings: {
			// Provided by WordPress (the "jquery" script handle), not bundled.
			'import/core-modules': [ 'jquery' ],
		},
	},
];

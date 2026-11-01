/**
 * Playwright config: WordPress's shared config from `@wordpress/scripts`, with
 * this plugin's specs.
 */
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e/specs',
	webServer: {
		...baseConfig.webServer,
		// Separate test site from .wp-env.tests.json (port 8889), with the e2e
		// fixture plugin mapped in. The development site isn't touched.
		command: 'npm run wp-env:tests start',
	},
} );

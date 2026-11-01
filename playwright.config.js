/**
 * Playwright config: WordPress's shared config from `@wordpress/scripts`, with
 * this plugin's specs. Tests run against the wp-env tests site (port 8889).
 */
const { defineConfig } = require( '@playwright/test' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e/specs',
} );

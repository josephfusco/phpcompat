import { defineConfig } from 'vitest/config';

// Unit tests only; Playwright runs tests/e2e.
export default defineConfig( {
	test: {
		include: [ 'src/**/test/*.test.js' ],
	},
} );

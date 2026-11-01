/**
 * Fake Tide API responses in the browser, so tests don't depend on the live
 * service. The same fixture data is served to server-side requests by the
 * e2e fixture plugin.
 */
const fixtures = require( '../fixtures/tide-fixtures.json' );

/**
 * Build a Tide audit response.
 *
 * @param {string[]} failing PHP versions with problems.
 */
function audit( failing ) {
	const versions = {};
	fixtures.versions.forEach( ( version ) => {
		const fails = failing.includes( version );
		versions[ version ] = {
			totals: { errors: fails ? 1 : 0, warnings: 0, fixable: 0 },
			files: fails
				? {
						'includes/legacy.php': {
							errors: 1,
							warnings: 0,
							messages: [
								{
									message: `Example problem on PHP ${ version }`,
									source: 'PHPCompatibility.Example.Found',
									severity: 5,
									fixable: false,
									type: 'ERROR',
									line: 42,
									column: 1,
								},
							],
						},
					}
				: {},
		};
	} );

	return {
		status: 'complete',
		reports: {
			phpcs_phpcompatibilitywp: {
				report: {
					compatible: fixtures.versions.filter(
						( v ) => ! failing.includes( v )
					),
					incompatible: failing,
					totals: { errors: failing.length, warnings: 0, fixable: 0 },
					versions,
				},
			},
		},
	};
}

/**
 * Route Tide requests to the fake responses. Returns the list of requested slugs.
 *
 * @param {import('@playwright/test').Page} page
 */
async function mockTide( page ) {
	const requested = [];

	await page.route( 'https://wptide.org/api/v1/audit/**', ( route ) => {
		// Path: /api/v1/audit/wporg/{type}/{slug}/{version}
		const slug = new URL( route.request().url() ).pathname.split(
			'/'
		)[ 6 ];
		requested.push( slug );

		const fixture = fixtures.items[ slug ] || { status: 404 };
		const body =
			200 === fixture.status
				? audit( fixture.failing )
				: {
						message: 'The audit requested does not exist.',
						status: 404,
					};

		return route.fulfill( {
			status: fixture.status,
			contentType: 'application/json',
			headers: { 'Access-Control-Allow-Origin': '*' },
			body: JSON.stringify( body ),
		} );
	} );

	return requested;
}

module.exports = { mockTide };

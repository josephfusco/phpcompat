/**
 * Fake Tide API responses, so tests don't depend on the live service.
 */

const VERSIONS = [ '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0' ];

/**
 * Build a Tide audit response.
 *
 * @param {string[]} failing PHP versions with problems.
 */
function audit( failing = [] ) {
	const versions = {};
	VERSIONS.forEach( ( version ) => {
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
					compatible: VERSIONS.filter(
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

const RESPONSES = {
	'passing-plugin': { status: 200, body: audit() },
	// Requires 7.2, so 7.0 is "not counted" and 7.2/7.3 are real problems.
	'problem-plugin': { status: 200, body: audit( [ '7.0', '7.2', '7.3' ] ) },
	// Requires 8.3: every reported version is below the minimum.
	'new-php-plugin': {
		status: 200,
		body: audit( [ '5.6', '7.0', '7.1', '7.2', '7.3' ] ),
	},
	// Not on WordPress.org.
	'custom-plugin': {
		status: 404,
		body: { message: 'The audit requested does not exist.', status: 404 },
	},
	'inactive-plugin': { status: 200, body: audit() },
	'passing-theme': { status: 200, body: audit() },
};

/**
 * Route Tide requests to the fake responses. Returns the list of requested slugs.
 *
 * @param {import('@playwright/test').Page} page
 */
async function mockTide( page ) {
	const requested = [];

	await page.route( 'https://wptide.org/api/v1/audit/**', ( route ) => {
		// Path: /api/v1/audit/wporg/{type}/{slug}/{version}
		const itemSlug = new URL( route.request().url() ).pathname.split(
			'/'
		)[ 6 ];
		requested.push( itemSlug );

		const response = RESPONSES[ itemSlug ] || {
			status: 404,
			body: { message: 'Unknown fixture', status: 404 },
		};

		return route.fulfill( {
			status: response.status,
			contentType: 'application/json',
			headers: { 'Access-Control-Allow-Origin': '*' },
			body: JSON.stringify( response.body ),
		} );
	} );

	return requested;
}

module.exports = { mockTide };

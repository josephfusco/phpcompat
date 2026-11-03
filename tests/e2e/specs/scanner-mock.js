/**
 * Replace the batched scanner with fixture reports, so tests can hold or fail
 * a scan on cue. The same
 * fixtures feed WP-CLI and the Abilities API through the e2e fixture plugin.
 */
const fixtures = require( '../fixtures/scan-fixtures.json' );

/**
 * Install the fake scanner on the page. Returns the list of scanned slugs.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}                          options
 * @param {Object<string, string>}          options.fail Slug => error message, to make an item's scan fail.
 * @param {string[]}                        options.hold Slugs whose scan waits until window.phpcompatRelease() is called.
 * @param {Object<string, string>}          options.busy Slug => message, to refuse an item's scan because someone else is scanning the site.
 * @return {Promise<string[]>} Scanned slugs.
 */
async function mockScanner( page, { fail = {}, hold = [], busy = {} } = {} ) {
	const scanned = [];
	await page.exposeFunction( 'phpcompatScanned', ( slug ) =>
		scanned.push( slug )
	);
	await page.addInitScript(
		( { data, failures, held, refused } ) => {
			let release;
			const gate = new Promise( ( resolve ) => ( release = resolve ) );
			window.phpcompatRelease = () => release();

			window.phpcompatTestScanner = async (
				job,
				{ onStatus, ScanError }
			) => {
				window.phpcompatScanned( job.slug );
				onStatus( 'Checking files: 3 of 10…', { value: 3, max: 10 } );
				if ( held.includes( job.slug ) ) {
					await gate;
				}
				if ( refused[ job.slug ] ) {
					throw new ScanError( refused[ job.slug ], 'busy' );
				}
				if ( failures[ job.slug ] ) {
					throw new ScanError( failures[ job.slug ] );
				}

				const fixture = data.items[ job.slug ] || { failing: [] };
				const versions = {};
				data.versions.forEach( ( version ) => {
					const fails = fixture.failing.includes( version );
					versions[ version ] = {
						totals: { errors: fails ? 1 : 0, warnings: 0 },
						files: fails
							? {
									'includes/legacy.php': {
										errors: 1,
										warnings: 0,
										messages: [
											{
												line: 42,
												column: 1,
												type: 'ERROR',
												message: `Example problem on PHP ${ version }`,
												source: 'PHPCompatibility.Example.Found',
											},
										],
									},
								}
							: {},
					};
				} );

				return {
					source: fixture.source || 'wporg',
					report: {
						compatible: data.versions.filter(
							( v ) => ! fixture.failing.includes( v )
						),
						incompatible: fixture.failing,
						versions,
					},
				};
			};
		},
		{ data: fixtures, failures: fail, held: hold, refused: busy }
	);
	return scanned;
}

module.exports = { mockScanner };

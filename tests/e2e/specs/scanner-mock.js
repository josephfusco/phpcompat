/**
 * Replace the in-browser scanner (PHP WebAssembly from WordPress Playground)
 * with fixture reports, so tests don't depend on the network. The same
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
 * @return {Promise<string[]>} Scanned slugs.
 */
async function mockScanner( page, { fail = {}, hold = [] } = {} ) {
	const scanned = [];
	await page.exposeFunction( 'phpcompatScanned', ( slug ) =>
		scanned.push( slug )
	);
	await page.addInitScript(
		( { data, failures, held } ) => {
			let release;
			const gate = new Promise( ( resolve ) => ( release = resolve ) );
			window.phpcompatRelease = () => release();

			window.phpcompatTestScanner = async (
				job,
				{ onStatus, ScanError }
			) => {
				window.phpcompatScanned( job.slug );
				onStatus( 'Checking PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5…' );
				if ( held.includes( job.slug ) ) {
					await gate;
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
		{ data: fixtures, failures: fail, held: hold }
	);
	return scanned;
}

module.exports = { mockScanner };

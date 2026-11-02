/**
 * End-to-end tests for the PHP Compatibility scan page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { mockTide } = require( './tide-mock' );

const PAGE = [ 'tools.php', 'page=wpe-php-compat' ];

/**
 * @param {import('@playwright/test').Page} page
 */
function scanButton( page ) {
	return page.getByRole( 'button', { name: 'Scan site' } );
}

/**
 * Wait for the scan to finish (the download controls appear).
 *
 * @param {import('@playwright/test').Page} page
 */
async function scanFinished( page ) {
	await expect(
		page.getByRole( 'link', { name: 'Download Report' } )
	).toBeVisible();
}

/**
 * Result cards with a given result.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          result success, problems, skipped, or failed.
 */
function results( page, result ) {
	return page.locator( `#wpe_pcc_results [data-result="${ result }"]` );
}

async function setWpEngine( requestUtils, enabled ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: { phpcompat_e2e_is_wpe: enabled },
	} );
}

test.describe( 'PHP Compatibility scan', () => {
	let pageErrors;

	test.beforeEach( async ( { page, requestUtils } ) => {
		await setWpEngine( requestUtils, false );
		pageErrors = [];
		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
	} );

	test.afterEach( () => {
		expect( pageErrors ).toEqual( [] );
	} );

	test( 'scans active plugins and theme by default', async ( {
		admin,
		page,
	} ) => {
		const requested = await mockTide( page );
		await admin.visitAdminPage( ...PAGE );

		await expect(
			page.getByRole( 'radio', {
				name: 'Only scan active plugins and theme',
			} )
		).toBeChecked();
		await expect( page.locator( '#plugin_inactive-plugin' ) ).toHaveCount(
			0
		);

		await scanButton( page ).click();
		await scanFinished( page );

		expect( requested ).not.toContain( 'inactive-plugin' );
		await expect( results( page, 'success' ) ).toHaveCount( 2 );
		await expect( results( page, 'problems' ) ).toHaveCount( 1 );
		await expect( results( page, 'skipped' ) ).toHaveCount( 2 );

		// Below "Requires PHP": marked as not counted, not as a failure.
		const problem = page.locator( '#plugin_problem-plugin' );
		await expect( problem ).toContainText( '1 error' );
		await expect(
			problem.locator( '.wpe-pcc-php-version-unsupported', {
				hasText: '7.0',
			} )
		).toBeVisible();
		await expect( problem ).toContainText( 'minimum of PHP 7.2' );

		// Requires a newer PHP than any version with results.
		await expect( page.locator( '#plugin_new-php-plugin' ) ).toContainText(
			'requires PHP 8.3 or newer'
		);

		// Not on WordPress.org (#323).
		await expect( page.locator( '#plugin_custom-plugin' ) ).toContainText(
			'could not be scanned'
		);

		// Problems: try them in Playground instead of the old LocalWP link.
		const playground = page.getByRole( 'link', {
			name: /Try the plugins and themes with problems/,
		} );
		await expect( playground ).toHaveAttribute(
			'href',
			'https://playground.wordpress.net/?php=latest&plugin=problem-plugin'
		);
	} );

	test( 'scans all plugins and themes', async ( { admin, page } ) => {
		const requested = await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await page
			.getByRole( 'radio', { name: 'Scan all plugins and themes' } )
			.check();
		await expect( page.locator( '#plugin_inactive-plugin' ) ).toContainText(
			'(inactive)'
		);

		await scanButton( page ).click();
		await scanFinished( page );
		expect( requested ).toContain( 'inactive-plugin' );
		await expect( results( page, 'success' ) ).toHaveCount( 3 );
	} );

	test( 'chooses which plugins and themes to scan (#67)', async ( {
		admin,
		page,
	} ) => {
		const requested = await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await page
			.getByRole( 'radio', {
				name: 'Choose which plugins and themes to scan',
			} )
			.check();

		await expect( page.locator( '.wpe-pcc-select' ) ).toHaveCount( 6 );
		await page.getByRole( 'button', { name: 'Select none' } ).click();
		await expect( scanButton( page ) ).toBeDisabled();

		await page.getByRole( 'checkbox', { name: /Inactive Plugin/ } ).check();
		await scanButton( page ).click();
		await scanFinished( page );

		expect( requested ).toEqual( [ 'inactive-plugin' ] );
		await expect( page.locator( '.wpe-pcc-alert' ) ).toHaveCount( 1 );
		// Nothing had problems, so there's nothing to try in Playground.
		await expect( page.locator( '.wpe-pcc-playground' ) ).toBeHidden();
	} );

	test( 'warns when a newer PHP security release is available', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( ...PAGE );

		const notice = page.locator( '.wpe-pcc-release-notice' );
		await expect( notice ).toBeVisible();
		await expect( notice ).toContainText( '.999 is available' );
		await expect( notice ).toContainText( 'includes security fixes' );
	} );

	test( 'failing versions open and close their details', async ( {
		admin,
		page,
	} ) => {
		await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const problem = page.locator( '#plugin_problem-plugin' );
		const version = problem.getByRole( 'button', {
			name: 'Show problems on PHP 7.2',
		} );
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );

		await version.click();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'true' );
		const report = problem.locator(
			'.wpe-pcc-php-version-report[data-php-version="7.2"]'
		);
		await expect( report.locator( 'textarea' ) ).toHaveValue(
			/Example problem on PHP 7\.2/
		);

		await report.getByRole( 'link', { name: 'close' } ).click();
		await expect( report ).toBeHidden();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( version ).toBeFocused();
	} );

	test( 'explains when WP Tide cannot be reached (#231)', async ( {
		admin,
		page,
	} ) => {
		await mockTide( page );
		// Routes added later take priority: block one item's request.
		await page.route( '**/wporg/plugin/passing-plugin/**', ( route ) =>
			route.abort( 'blockedbyclient' )
		);
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const item = page.locator( '#plugin_passing-plugin' );
		await expect( item ).toContainText( 'Scan failed' );
		await expect( item ).toContainText( 'Could not connect to WP Tide' );
		await expect( item ).not.toContainText( 'Status: 0' );
	} );

	test( 'shows progress while Tide audits a new version (#42)', async ( {
		admin,
		page,
	} ) => {
		await mockTide( page );
		let calls = 0;
		await page.route( '**/wporg/plugin/passing-plugin/**', ( route ) => {
			calls++;
			if ( calls > 1 ) {
				return route.fallback();
			}
			return route.fulfill( {
				status: 200,
				contentType: 'application/json',
				headers: { 'Access-Control-Allow-Origin': '*' },
				body: JSON.stringify( { status: 'pending' } ),
			} );
		} );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const item = page.locator( '#plugin_passing-plugin' );
		await expect( item ).toContainText( 'auditing this version' );
		await expect( item ).toHaveAttribute( 'data-result', 'success', {
			timeout: 15000,
		} );
	} );

	test( 'cancels a running scan (#56)', async ( { admin, page } ) => {
		await mockTide( page );
		// Hold one request open so the scan is still running.
		let release;
		const held = new Promise( ( resolve ) => ( release = resolve ) );
		await page.route(
			'**/wporg/plugin/passing-plugin/**',
			async ( route ) => {
				await held;
				return route.fallback().catch( () => {} );
			}
		);
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		await expect( page.locator( '.wpe-pcc-progress' ) ).toContainText(
			'Now scanning'
		);

		await page.getByRole( 'link', { name: 'Cancel' } ).click();
		release();

		await expect( page.locator( '.wpe-pcc-progress' ) ).toBeHidden();
		await expect( scanButton( page ) ).toBeEnabled();
		await expect( page.locator( '#wpe_pcc_results' ) ).not.toContainText(
			'Scan failed'
		);

		// A new scan counts from zero.
		await page.unroute( '**/wporg/plugin/passing-plugin/**' );
		await scanButton( page ).click();
		await scanFinished( page );
		const progress = await page.evaluate( () => [
			window.phpcompat.completed,
			window.phpcompat.total,
		] );
		expect( progress ).toEqual( [ 5, 5 ] );
	} );

	test( 'downloads PDF (default, tagged), text, CSV, and JSON reports', async ( {
		admin,
		page,
	} ) => {
		await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		await scanFinished( page );

		await expect(
			page.getByRole( 'combobox', { name: 'Report format' } )
		).toHaveValue( 'pdf' );

		async function download( format ) {
			await page
				.getByRole( 'combobox', { name: 'Report format' } )
				.selectOption( format );
			const [ file ] = await Promise.all( [
				page.waitForEvent( 'download' ),
				page.getByRole( 'link', { name: 'Download Report' } ).click(),
			] );
			const chunks = [];
			for await ( const chunk of await file.createReadStream() ) {
				chunks.push( chunk );
			}
			return {
				name: file.suggestedFilename(),
				text: Buffer.concat( chunks ).toString( 'latin1' ),
			};
		}

		const txt = await download( 'txt' );
		expect( txt.name ).toMatch(
			/^php-compatibility-localhost-8889-\d{4}-\d{2}-\d{2}\.txt$/
		);
		expect( txt.text ).toContain( 'Site:        http://localhost:8889' );
		expect( txt.text ).toContain(
			'Summary:     2 pass, 1 with problems, 2 not checked, 0 failed to scan'
		);
		expect( txt.text ).toContain( 'Example problem on PHP 7.2' );

		const csv = await download( 'csv' );
		const rows = csv.text.split( '\r\n' );
		expect( rows[ 0 ] ).toBe(
			'type,name,slug,version,active,status,reason,php,file,line,severity,message'
		);
		expect( rows ).toContain(
			'plugin,Problem Plugin,problem-plugin,2.0.0,yes,problems,,7.2,includes/legacy.php,42,ERROR,Example problem on PHP 7.2'
		);

		const json = JSON.parse( ( await download( 'json' ) ).text );
		expect( json.summary ).toEqual( {
			passes: 2,
			problems: 1,
			not_checked: 2,
			failed: 0,
		} );
		const problem = json.items.find( ( i ) => 'problem-plugin' === i.slug );
		expect( problem.php[ '7.0' ] ).toBe( 'not_counted' );
		expect( problem.php[ '7.2' ] ).toBe( 'fail' );

		// PDF is the default format: a tagged PDF, downloaded in one click.
		const pdf = await download( 'pdf' );
		expect( pdf.name ).toMatch( /\.pdf$/ );
		expect( pdf.text.startsWith( '%PDF-' ) ).toBe( true );
		expect( pdf.text ).toContain( '/StructTreeRoot' );
		expect( pdf.text ).toContain( '/Marked true' );
		expect( pdf.text ).toMatch( /\/Lang \(/ );
		expect( pdf.text ).toContain( '/DisplayDocTitle true' );
		expect( pdf.text ).toContain( '(PHP Compatibility Checker ' );
		await expect(
			page.locator( 'iframe.wpe-pcc-print-frame' )
		).toHaveCount( 0 );
	} );

	test( 'WP Engine sidebar only shows on WP Engine', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await expect( page.locator( '.wpe-pcc-aside' ) ).toHaveCount( 0 );

		await setWpEngine( requestUtils, true );
		await admin.visitAdminPage( ...PAGE );

		const aside = page.locator( '.wpe-pcc-aside' );
		await expect( aside ).toBeVisible();
		await expect(
			aside.getByRole( 'link', { name: 'Open this environment' } )
		).toHaveAttribute( 'href', /my\.wpengine\.com\/installs\/e2esite/ );

		// "Need help" only appears once a scan finds problems.
		const help = aside.locator( '.wpe-pcc-information' );
		await expect( help ).toBeHidden();
		await scanButton( page ).click();
		await scanFinished( page );
		await expect( help ).toBeVisible();
	} );
} );

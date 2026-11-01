/**
 * End-to-end tests for the PHP Compatibility scan page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { mockTide } = require( './tide-mock' );

const PAGE = [ 'tools.php', 'page=wpe-php-compat' ];

/**
 * The scan button, labelled with the number of selected items.
 *
 * @param {import('@playwright/test').Page} page
 */
function scanButton( page ) {
	return page.getByRole( 'button', { name: /^Scan \d+ items?$/ } );
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

	test( 'shows a result for each kind of item', async ( { admin, page } ) => {
		await mockTide( page );
		await admin.visitAdminPage( ...PAGE );

		await scanButton( page ).click();

		const summary = page.locator( '#wpe-pcc-summary' );
		await expect( summary ).toContainText( '3 pass' );
		await expect( summary ).toContainText( '1 has problems' );
		await expect( summary ).toContainText( '2 not checked' );

		// Passing item.
		await expect( page.locator( '#plugin_passing-plugin' ) ).toContainText(
			'Passes'
		);

		// Below "Requires PHP": shown as not counted, not as a failure.
		const problem = page.locator( '#plugin_problem-plugin' );
		await expect( problem ).toContainText( 'Problems found' );
		await expect(
			problem.locator( '.wpe-pcc-chip-unsupported', { hasText: '7.0' } )
		).toBeVisible();
		await expect( problem ).toContainText( 'minimum of PHP 7.2' );

		// Requires a newer PHP than any version with results.
		await expect( page.locator( '#plugin_new-php-plugin' ) ).toContainText(
			'requires PHP 8.3 or newer'
		);

		// Not on WordPress.org.
		await expect( page.locator( '#plugin_custom-plugin' ) ).toContainText(
			'could not be scanned'
		);

		await expect( page.locator( '#theme_passing-theme' ) ).toContainText(
			'Passes'
		);

		// Everything is scanned by default, including inactive plugins.
		const inactive = page.locator( '#plugin_inactive-plugin' );
		await expect( inactive ).toContainText( 'Passes' );
		await expect( inactive ).toContainText( 'Inactive' );
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
			name: 'Problems on 7.2',
		} );
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );

		await version.click();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'true' );
		const report = problem.locator(
			'.wpe-pcc-php-version-report[data-php-version="7.2"]'
		);
		await expect( report ).toBeVisible();
		await expect( report.locator( 'textarea' ) ).toHaveValue(
			/Example problem on PHP 7\.2/
		);

		await report.getByRole( 'button', { name: 'Close' } ).click();
		await expect( report ).toBeHidden();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( version ).toBeFocused();
	} );

	test( 'scans only the selected items', async ( { admin, page } ) => {
		const requested = await mockTide( page );
		await admin.visitAdminPage( ...PAGE );

		// Every plugin and theme is listed and selected.
		await expect( page.locator( '.wpe-pcc-select' ) ).toHaveCount( 6 );
		await expect( page.locator( '.wpe-pcc-select:checked' ) ).toHaveCount(
			6
		);
		await expect( scanButton( page ) ).toHaveValue( 'Scan 6 items' );

		await page.getByRole( 'button', { name: 'Active only' } ).click();
		await expect( page.locator( '.wpe-pcc-select:checked' ) ).toHaveCount(
			5
		);

		await page.getByRole( 'button', { name: 'Select none' } ).click();
		await expect( scanButton( page ) ).toBeDisabled();
		await expect( scanButton( page ) ).toHaveValue( 'Scan 0 items' );

		await page.getByRole( 'checkbox', { name: /Inactive Plugin/ } ).check();
		await expect( scanButton( page ) ).toHaveValue( 'Scan 1 item' );
		await scanButton( page ).click();

		await expect( page.locator( '#wpe-pcc-summary' ) ).toHaveText(
			'1 passes'
		);
		expect( requested ).toEqual( [ 'inactive-plugin' ] );
		await expect( page.locator( '.wpe-pcc-alert' ) ).toHaveCount( 1 );

		// After a scan, the scan controls make way for "Start over", which
		// returns to the full, selected list.
		await expect( scanButton( page ) ).toBeHidden();
		await page.getByRole( 'button', { name: 'Start over' } ).click();
		await expect( scanButton( page ) ).toHaveValue( 'Scan 6 items' );
		await expect( page.locator( '.wpe-pcc-select:checked' ) ).toHaveCount(
			6
		);
	} );

	test( 'downloads a text report', async ( { admin, page } ) => {
		await mockTide( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		await expect( page.locator( '#wpe-pcc-summary' ) ).toContainText(
			'pass'
		);

		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			page.getByRole( 'button', { name: 'Download report' } ).click(),
		] );
		expect( download.suggestedFilename() ).toBe( 'report.txt' );

		const stream = await download.createReadStream();
		let text = '';
		for await ( const chunk of stream ) {
			text += chunk;
		}
		expect( text ).toContain( 'Problem Plugin 2.0.0' );
		expect( text ).toContain( 'Example problem on PHP 7.2' );
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
		await expect( page.locator( '#wpe-pcc-summary' ) ).toContainText(
			'has problems'
		);
		await expect( help ).toBeVisible();
	} );
} );

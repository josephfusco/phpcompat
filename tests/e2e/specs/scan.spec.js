/**
 * End-to-end tests for the PHP Compatibility scan page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { mockScanner } = require( './scanner-mock' );

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

async function setOption( requestUtils, name, enabled ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: { [ name ]: enabled },
	} );
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
		await setOption( requestUtils, 'phpcompat_e2e_advisory', false );
		await requestUtils.rest( {
			method: 'POST',
			path: '/phpcompat-e2e/v1/reset',
		} );
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
		const requested = await mockScanner( page );
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
		await expect( results( page, 'success' ) ).toHaveCount( 3 );
		await expect( results( page, 'problems' ) ).toHaveCount( 1 );
		await expect( results( page, 'skipped' ) ).toHaveCount( 1 );

		// Below "Requires PHP": marked as not counted, not as a failure.
		const problem = page.locator( '#plugin_problem-plugin' );
		await expect( problem ).toContainText( '1 error' );
		await expect(
			problem.locator( '.wpe-pcc-php-version-unsupported', {
				hasText: '7.4',
			} )
		).toBeVisible();
		await expect( problem ).toContainText( 'minimum of PHP 8.0' );

		// Requires a newer PHP than any version checked.
		await expect( page.locator( '#plugin_new-php-plugin' ) ).toContainText(
			'requires PHP 8.6 or newer'
		);

		// Not on WordPress.org: scanned from this site's files (#323).
		await expect( page.locator( '#plugin_custom-plugin' ) ).toHaveAttribute(
			'data-result',
			'success'
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
		const requested = await mockScanner( page );
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
		await expect( results( page, 'success' ) ).toHaveCount( 4 );
	} );

	test( 'chooses which plugins and themes to scan (#67)', async ( {
		admin,
		page,
	} ) => {
		const requested = await mockScanner( page );
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

	test( 'flags a critical PHP advisory that affects the server', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setOption( requestUtils, 'phpcompat_e2e_advisory', true );
		await admin.visitAdminPage( ...PAGE );

		const notice = page.locator( '.wpe-pcc-release-notice.notice-error' );
		await expect( notice ).toContainText( 'E2E Shell (CVE-2099-0001)' );
		await expect( notice ).toContainText( 'actively exploiting' );
		await expect(
			notice.getByRole( 'link', { name: /About E2E Shell/ } )
		).toHaveAttribute( 'href', 'https://example.com/e2e' );
	} );

	test( 'scans on the site in batches, with no outside service', async ( {
		admin,
		page,
	} ) => {
		// No mock: the real scanner code talks to /scan-batch, where the e2e
		// fixture plugin supplies reports through wpephpcompat_pre_scan_report.
		await admin.visitAdminPage( ...PAGE );

		// From here on, nothing may be loaded from another site.
		const outside = [];
		page.on( 'request', ( request ) => {
			const url = new URL( request.url() );
			if ( 'localhost' !== url.hostname ) {
				outside.push( url.hostname );
			}
		} );
		const batches = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'scan-batch' ) ) {
				batches.push( request.postDataJSON() );
			}
		} );

		await scanButton( page ).click();
		await scanFinished( page );

		expect( batches.map( ( b ) => b.slug ).sort() ).toEqual( [
			'custom-plugin',
			'new-php-plugin',
			'passing-plugin',
			'passing-theme',
			'problem-plugin',
		] );
		await expect( results( page, 'problems' ) ).toHaveCount( 1 );
		await expect(
			page.locator( '#plugin_problem-plugin' )
		).toHaveAttribute( 'data-result', 'problems' );
		expect( outside ).toEqual( [] );
	} );

	test( 'scans again without clearing results first', async ( {
		admin,
		page,
	} ) => {
		const scanned = await mockScanner( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		await scanFinished( page );

		const again = page.getByRole( 'button', { name: 'Scan again' } );
		await expect( again ).toBeEnabled();
		scanned.length = 0;
		await again.click();
		await scanFinished( page );

		expect( scanned.length ).toBeGreaterThan( 0 );
		await expect( results( page, 'problems' ) ).toHaveCount( 1 );
	} );

	test( 'lets one scan run at a time on the site', async ( {
		requestUtils,
	} ) => {
		const batch = ( token ) =>
			requestUtils.rest( {
				method: 'POST',
				path: '/php-compat/v1/scan-batch',
				data: {
					type: 'plugin',
					slug: 'passing-plugin',
					limit: 0,
					scan_token: token,
				},
			} );
		const first = 'a'.repeat( 32 );
		const second = 'b'.repeat( 32 );

		await batch( first );
		// A second scan (another admin or tab) is refused while the first runs.
		const refused = await batch( second ).catch( ( error ) => error );
		expect( refused.code ).toBe( 'phpcompat_scan_running' );

		// Once the first lets go, the second can start.
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/php-compat/v1/scan-lock?scan_token=${ first }`,
		} );
		const allowed = await batch( second );
		expect( allowed.cached ).toBe( false );
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/php-compat/v1/scan-lock?scan_token=${ second }`,
		} );
	} );

	test( 'refuses scan requests from people who are not logged in', async ( {
		playwright,
	} ) => {
		const anonymous = await playwright.request.newContext( {
			baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		} );
		// Complete requests: WordPress validates arguments before permissions.
		const requests = {
			'scan-batch': { type: 'plugin', slug: 'passing-plugin', limit: 0 },
			results: {
				type: 'plugin',
				slug: 'passing-plugin',
				version: '1.0.0',
				ruleset: 'x',
				source: 'local',
				report: {},
			},
		};
		for ( const [ path, data ] of Object.entries( requests ) ) {
			const response = await anonymous.post(
				`/wp-json/php-compat/v1/${ path }`,
				{ data }
			);
			expect( response.status() ).toBe( 401 );
		}
		await anonymous.dispose();
	} );

	test( 'reports stored results in Site Health', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'site-health.php' );
		await expect( page.locator( '.health-check-body' ) ).toContainText(
			'Check your plugins and themes for PHP compatibility'
		);

		await mockScanner( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		await scanFinished( page );
		await page.waitForLoadState( 'networkidle' );

		await admin.visitAdminPage( 'site-health.php' );
		const body = page.locator( '.health-check-body' );
		await expect( body ).toContainText(
			'Some active plugins or themes have problems on newer PHP versions'
		);
		await body
			.getByRole( 'button', {
				name: /Some active plugins or themes have problems/,
			} )
			.click();
		await expect( body ).toContainText(
			'Problem Plugin 2.0.0: problems on PHP'
		);

		await admin.visitAdminPage( 'site-health.php', 'tab=debug' );
		await expect(
			page.getByText( 'Stored results of the PHP Compatibility Checker' )
		).toBeAttached();
	} );

	test( 'failing versions open and close their details', async ( {
		admin,
		page,
	} ) => {
		await mockScanner( page );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const problem = page.locator( '#plugin_problem-plugin' );
		const version = problem.getByRole( 'button', {
			name: 'Show problems on PHP 8.0',
		} );
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );

		await version.click();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'true' );
		const report = problem.locator(
			'.wpe-pcc-php-version-report[data-php-version="8.0"]'
		);
		await expect( report.locator( 'textarea' ) ).toHaveValue(
			/Example problem on PHP 8\.0/
		);

		await report.getByRole( 'link', { name: 'close' } ).click();
		await expect( report ).toBeHidden();
		await expect( version ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( version ).toBeFocused();
	} );

	test( 'explains when a scan fails (#231)', async ( { admin, page } ) => {
		await mockScanner( page, {
			fail: {
				'passing-plugin':
					'This site stopped responding to the scan (HTTP 503).',
			},
		} );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const item = page.locator( '#plugin_passing-plugin' );
		await expect( item ).toContainText( 'Scan failed' );
		await expect( item ).toContainText( 'stopped responding' );
		await expect( item ).not.toContainText( 'Status: 0' );
	} );

	test( 'shows progress while scanning (#42)', async ( { admin, page } ) => {
		await mockScanner( page, { hold: [ 'passing-plugin' ] } );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();

		const item = page.locator( '#plugin_passing-plugin' );
		await expect( item ).toContainText( 'Checking files: 3 of 10' );
		const bar = item.getByRole( 'progressbar', {
			name: 'Files checked in Passing Plugin',
		} );
		await expect( bar ).toHaveAttribute( 'value', '3' );
		await expect( bar ).toHaveAttribute( 'max', '10' );
		await page.evaluate( () => window.phpcompatRelease() );
		await expect( item ).toHaveAttribute( 'data-result', 'success' );
	} );

	test( 'cancels a running scan (#56)', async ( { admin, page } ) => {
		await mockScanner( page, { hold: [ 'passing-plugin' ] } );
		await admin.visitAdminPage( ...PAGE );
		await scanButton( page ).click();
		// The item being scanned, not the number finished: never "0 of".
		await expect( page.locator( '.wpe-pcc-progress' ) ).toContainText(
			/Now scanning:\s*\d of 5/
		);
		await expect( page.locator( '.wpe-pcc-progress' ) ).not.toContainText(
			'0 of 5'
		);

		await page.getByRole( 'link', { name: 'Cancel' } ).click();
		// The cancelled scan finishing late must not touch the new one.
		await page.evaluate( () => window.phpcompatRelease() );

		await expect( page.locator( '.wpe-pcc-progress' ) ).toBeHidden();
		await expect( scanButton( page ) ).toBeEnabled();
		await expect( page.locator( '#wpe_pcc_results' ) ).not.toContainText(
			'Scan failed'
		);

		// A new scan counts from zero.
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
		await mockScanner( page );
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
			'Summary:     3 pass, 1 with problems, 1 not checked, 0 failed to scan'
		);
		expect( txt.text ).toContain( 'Example problem on PHP 8.0' );

		const csv = await download( 'csv' );
		const rows = csv.text.split( '\r\n' );
		expect( rows[ 0 ] ).toBe(
			'type,name,slug,version,active,status,reason,php,file,line,severity,message'
		);
		expect( rows ).toContain(
			'plugin,Problem Plugin,problem-plugin,2.0.0,yes,problems,,8.0,includes/legacy.php,42,ERROR,Example problem on PHP 8.0'
		);

		const json = JSON.parse( ( await download( 'json' ) ).text );
		expect( json.summary ).toEqual( {
			passes: 3,
			problems: 1,
			not_checked: 1,
			failed: 0,
		} );
		const problem = json.items.find( ( i ) => 'problem-plugin' === i.slug );
		expect( problem.php[ '7.4' ] ).toBe( 'not_counted' );
		expect( problem.php[ '8.0' ] ).toBe( 'fail' );

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
		await mockScanner( page );
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

/**
 * End-to-end tests for WP-CLI commands and Abilities API integration. Scan
 * reports come from the fixture plugin's wpephpcompat_pre_scan_report filter.
 */
const { spawnSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Run a WP-CLI command on the wp-env test site.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {{status: number, stdout: string}} Exit status and output.
 */
function wpCli( args ) {
	const result = spawnSync(
		'npx',
		[
			'wp-env',
			'--config=.wp-env.tests.json',
			'run',
			'cli',
			'wp',
			...args,
		],
		{ encoding: 'utf8' }
	);
	return { status: result.status, stdout: result.stdout };
}

/**
 * Parse the JSON document in WP-CLI output, ignoring wp-env's status lines.
 *
 * @param {string} stdout Command output.
 */
function parseJson( stdout ) {
	const line = stdout
		.split( '\n' )
		.find( ( l ) => l.startsWith( '[' ) || l.startsWith( '{' ) );
	return JSON.parse( line );
}

function resetResults() {
	wpCli( [ 'eval', 'WPEngine_PHPCompat\\Results::delete_all();' ] );
}

test.describe( 'WP-CLI', () => {
	test.beforeAll( resetResults );

	test( 'scan --cached reports items without a stored result as not scanned', () => {
		const { stdout } = wpCli( [
			'php-compat',
			'scan',
			'passing-plugin',
			'--cached',
			'--format=json',
			'--fields=slug,status',
		] );
		expect( parseJson( stdout ) ).toEqual( [
			{ slug: 'passing-plugin', status: 'not_scanned' },
		] );
	} );

	test( 'scan reports each item and exits 1 when there are problems', () => {
		const { status, stdout } = wpCli( [
			'php-compat',
			'scan',
			'--format=json',
			'--fields=slug,status,failing_versions',
		] );

		expect( status ).toBe( 1 );
		const rows = Object.fromEntries(
			parseJson( stdout ).map( ( row ) => [ row.slug, row ] )
		);
		expect( rows[ 'passing-plugin' ].status ).toBe( 'passes' );
		expect( rows[ 'problem-plugin' ].status ).toBe( 'problems' );
		expect( rows[ 'problem-plugin' ].failing_versions ).toBe( '8.0, 8.1' );
		expect( rows[ 'new-php-plugin' ].status ).toBe( 'not_checked' );
		// Not on WordPress.org: WP-CLI scans the installed files.
		expect( rows[ 'custom-plugin' ].status ).toBe( 'passes' );
		expect( rows[ 'passing-theme' ].status ).toBe( 'passes' );
	} );

	test( 'scan exits 0 when the selection passes', () => {
		const { status } = wpCli( [
			'php-compat',
			'scan',
			'passing-plugin',
			'passing-theme',
			'--format=count',
		] );
		expect( status ).toBe( 0 );
	} );

	test( 'report lists problems for one PHP version', () => {
		const { stdout } = wpCli( [
			'php-compat',
			'report',
			'problem-plugin',
			'--php=8.1',
			'--format=json',
		] );
		const rows = parseJson( stdout );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( {
			php: '8.1',
			file: 'includes/legacy.php',
			line: 42,
			message: 'Example problem on PHP 8.1',
		} );
	} );

	test( 'php-status reports the server PHP version and releases', () => {
		const { status, stdout } = wpCli( [
			'php-compat',
			'php-status',
			'--format=json',
		] );
		expect( status ).toBe( 0 );
		const report = parseJson( stdout );
		expect( report.php_version ).toMatch( /^\d+\.\d+$/ );
		// The fixture plugin supplies php.net data with a newer security release.
		expect( report.release.state ).toBe( 'update_available' );
		expect( report.release.latest_is_security ).toBe( true );
	} );

	test( 'scan still works when a host turns scanning off', () => {
		wpCli( [ 'option', 'update', 'phpcompat_e2e_scanning_off', '1' ] );
		try {
			const { status } = wpCli( [
				'php-compat',
				'scan',
				'passing-plugin',
				'--format=count',
			] );
			expect( status ).toBe( 0 );
		} finally {
			wpCli( [ 'option', 'delete', 'phpcompat_e2e_scanning_off' ] );
		}
	} );
} );

test.describe( 'Abilities API', () => {
	const scanPath =
		'/wp-abilities/v1/abilities/php-compatibility-checker/scan/run';

	test( 'abilities are registered and listed', async ( { requestUtils } ) => {
		const abilities = await requestUtils.rest( {
			path: '/wp-abilities/v1/abilities',
			params: { category: 'php-compatibility' },
		} );
		const names = abilities.map( ( ability ) => ability.name );
		expect( names ).toEqual(
			expect.arrayContaining( [
				'php-compatibility-checker/scan',
				'php-compatibility-checker/php-status',
			] )
		);
	} );

	test( 'scan ability returns stored results', async ( { requestUtils } ) => {
		resetResults();
		wpCli( [ 'php-compat', 'scan', 'problem-plugin', '--format=count' ] );

		const result = await requestUtils.rest( {
			path: scanPath,
			params: {
				'input[slugs][0]': 'problem-plugin',
				'input[include_details]': 'true',
			},
		} );

		expect( result.max_php_checked ).toBe( '8.5' );
		expect( result.summary.problems ).toBe( 1 );
		expect( result.items ).toHaveLength( 1 );

		const [ item ] = result.items;
		expect( item.status ).toBe( 'problems' );
		expect( item.failing_versions ).toEqual( [ '8.0', '8.1' ] );
		expect( item.scanned_at ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );
		expect( item.php ).toContainEqual( {
			version: '7.4',
			result: 'not_counted',
		} );
		expect( item.problems[ 0 ] ).toMatchObject( {
			php: '8.0',
			message: 'Example problem on PHP 8.0',
		} );
	} );

	test( 'php-status ability reports the server PHP version', async ( {
		requestUtils,
	} ) => {
		const status = await requestUtils.rest( {
			path: '/wp-abilities/v1/abilities/php-compatibility-checker/php-status/run',
		} );
		expect( status.php_version ).toMatch( /^\d+\.\d+$/ );
		expect( typeof status.message ).toBe( 'string' );

		// php.net data (faked by the fixture) reports a newer security release.
		expect( status.release.state ).toBe( 'update_available' );
		expect( status.release.latest ).toBe( `${ status.php_version }.999` );
		expect( status.release.latest_is_security ).toBe( true );
	} );
} );

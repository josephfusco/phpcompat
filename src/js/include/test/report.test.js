/**
 * Unit tests for the scan result logic and report builders.
 */
import { describe, it, expect, vi, beforeAll } from 'vitest';

// jQuery is provided by WordPress at runtime; these functions don't use it.
vi.mock( 'jquery', () => ( { default: () => ( {} ) } ) );

const { compareVersions, isBundledLibrary } = await import( '../render' );
const {
	groupProblems,
	groupByMessage,
	buildReportHtml,
	executiveSummary,
	recommendation,
} = await import( '../print-report' );
const { csvCell, csvReport, reportMeta } = await import( '../download' );

const problem = ( php, line, message, file = 'inc/legacy.php' ) => ( {
	php,
	file,
	line,
	type: 'ERROR',
	message,
	source: 'PHPCompatibility.Example',
} );

const items = [
	{
		type: 'plugin',
		name: 'Problem Plugin',
		slug: 'problem-plugin',
		version: '2.0.0',
		active: true,
		requires_php: '7.2',
		status: 'problems',
		reason: '',
		php: { '7.0': 'not_counted', 7.2: 'fail', 7.3: 'fail', 7.4: 'pass' },
		problems: [
			problem( '7.2', 10, 'mcrypt removed' ),
			problem( '7.3', 10, 'mcrypt removed' ),
			problem( '7.2', 20, 'mcrypt removed' ),
			problem( '7.2', 30, 'each() removed' ),
		],
	},
	{
		type: 'theme',
		name: 'Custom Theme',
		slug: 'custom-theme',
		version: '1.0',
		active: false,
		requires_php: '',
		status: 'not_checked',
		reason: 'Not on WordPress.org.',
		php: {},
		problems: [],
	},
	{
		type: 'plugin',
		name: 'Passing Plugin',
		slug: 'passing-plugin',
		version: '1.0.0',
		active: true,
		requires_php: '',
		status: 'passes',
		reason: '',
		php: { 7.4: 'pass', '8.0': 'pass' },
		problems: [],
	},
];

beforeAll( () => {
	globalThis.checkerList = {
		site: {
			url: 'https://example.com',
			name: 'Example',
			php: '8.3.36',
			wp: '7.2',
			plugin_version: '1.6.5',
		},
	};
} );

describe( 'compareVersions', () => {
	it( 'compares numerically, not as strings', () => {
		expect( compareVersions( '7.10', '7.9' ) ).toBeGreaterThan( 0 );
		expect( compareVersions( '8.0', '7.4' ) ).toBeGreaterThan( 0 );
		expect( compareVersions( '7.4', '7.4.0' ) ).toBe( 0 );
		expect( compareVersions( '5.6', '7.0' ) ).toBeLessThan( 0 );
	} );
} );

describe( 'isBundledLibrary', () => {
	it.each( [
		'vendor/phpseclib/Crypt.php',
		'core/lib/vendor/x.php',
		'third-party/lib.php',
		'vendor_prefixed/a.php',
	] )( 'flags %s', ( file ) => {
		expect( isBundledLibrary( file ) ).toBe( true );
	} );

	it.each( [ 'includes/vendors.php', 'src/Vendor.php', 'admin.php' ] )(
		'does not flag %s',
		( file ) => {
			expect( isBundledLibrary( file ) ).toBe( false );
		}
	);
} );

describe( 'groupProblems and groupByMessage', () => {
	it( 'merges the same problem across PHP versions', () => {
		const [ file ] = groupProblems( items[ 0 ].problems );
		const line10 = file.rows.find( ( row ) => 10 === row.line );
		expect( line10.versions ).toEqual( [ '7.2', '7.3' ] );
		expect( file.rows ).toHaveLength( 3 );
	} );

	it( 'collapses repeated messages into one row with every line', () => {
		const [ file ] = groupProblems( items[ 0 ].problems );
		const rows = groupByMessage( file.rows );
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ] ).toMatchObject( {
			message: 'mcrypt removed',
			lines: [ 10, 20 ],
			versions: [ '7.2', '7.3' ],
		} );
		expect( rows[ 1 ].lines ).toEqual( [ 30 ] );
	} );
} );

describe( 'CSV', () => {
	it( 'escapes quotes, commas, and newlines', () => {
		expect( csvCell( 'a,b' ) ).toBe( '"a,b"' );
		expect( csvCell( 'say "hi"' ) ).toBe( '"say ""hi"""' );
		expect( csvCell( 'two\nlines' ) ).toBe( '"two\nlines"' );
		expect( csvCell( undefined ) ).toBe( '' );
	} );

	it( 'keeps spreadsheets from running text as a formula', () => {
		expect( csvCell( '=HYPERLINK("x")' ) ).toBe( '"\'=HYPERLINK(""x"")"' );
		expect( csvCell( '+1' ) ).toBe( "'+1" );
		expect( csvCell( '@SUM(A1)' ) ).toBe( "'@SUM(A1)" );
	} );

	it( 'has one row per problem, and one row for items without problems', () => {
		const rows = csvReport( items ).split( '\r\n' );
		expect( rows ).toHaveLength( 1 + 4 + 1 + 1 );
		expect( rows[ 1 ] ).toContain( 'problem-plugin' );
		expect( rows[ 5 ] ).toContain( 'not_checked' );
	} );
} );

describe( 'reportMeta', () => {
	it( 'summarizes results and the newest PHP version checked', () => {
		const meta = reportMeta( items );
		expect( meta.summary ).toEqual( {
			passes: 1,
			problems: 1,
			not_checked: 1,
			failed: 0,
		} );
		expect( meta.max_php_checked ).toBe( '8.0' );
		expect( meta.site.url ).toBe( 'https://example.com' );
	} );
} );

describe( 'print report', () => {
	it( 'escapes plugin data in the HTML', () => {
		const html = buildReportHtml(
			[ { ...items[ 0 ], name: '<img src=x onerror=alert(1)>' } ],
			reportMeta( items )
		);
		expect( html ).not.toContain( '<img src=x' );
		expect( html ).toContain( '&lt;img src=x' );
	} );
} );

describe( 'PDF report (accessible HTML, saved via print)', () => {
	const html = () => buildReportHtml( items, reportMeta( items ) );

	it( 'sets the document language and title', () => {
		expect( html() ).toMatch( /<html lang="[a-z-]+"/i );
		expect( html() ).toContain(
			'<title>PHP Compatibility Report: Example</title>'
		);
	} );

	it( 'has one h1 and headings for every section', () => {
		expect( html().match( /<h1[ >]/g ) ).toHaveLength( 1 );
		[
			'At a glance',
			'Needs attention',
			'Not checked (1)',
			'Passes (1)',
			'About this report',
		].forEach( ( heading ) => expect( html() ).toContain( heading ) );
	} );

	it( 'gives every table a caption and scoped headers', () => {
		const doc = html();
		const tables = doc.match( /<table[ >]/g ).length;
		expect( doc.match( /<caption[ >]/g ) ).toHaveLength( tables );
		// Every header cell (not <thead>) declares what it labels.
		const headers = doc.match( /<th[\s>]/g ).length;
		expect( doc.match( /<th [^>]*scope="(col|row)"/g ) ).toHaveLength(
			headers
		);
	} );

	it( 'does not rely on color alone for status', () => {
		expect( html() ).toMatch( /class="status-problems">Problems</ );
		expect( html() ).toMatch( /class="type error">Error</ );
	} );

	it( 'opens with a verdict and next steps', () => {
		const { verdict, steps } = executiveSummary(
			items,
			reportMeta( items )
		);
		expect( verdict ).toBe(
			'1 of 2 checked plugins and themes have problems on PHP up to 8.0.'
		);
		expect( steps[ 0 ] ).toContain(
			'Ask the developers of Problem Plugin'
		);
		expect( html() ).toContain( verdict );
	} );

	it( 'recommends updating when a newer version exists', () => {
		expect(
			recommendation( { ...items[ 0 ], update_version: '2.1.0' } )
		).toBe( 'Update to 2.1.0, then scan again.' );
		expect(
			recommendation( {
				...items[ 0 ],
				problems: [ problem( '7.2', 1, 'x', 'vendor/lib/a.php' ) ],
			} )
		).toContain( 'bundled library' );
	} );
} );

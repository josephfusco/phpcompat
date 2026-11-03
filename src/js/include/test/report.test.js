/**
 * Unit tests for the scan result logic and report builders.
 */
import { describe, it, expect, vi, beforeAll } from 'vitest';

// jQuery is provided by WordPress at runtime; these functions don't use it.
vi.mock( 'jquery', () => ( { default: () => ( {} ) } ) );

const { compareVersions, isBundledLibrary, neededPhp, needsPhpNote } =
	await import( '../render' );
const { default: neededPhpCases } =
	await import( '../../../../tests/fixtures/needed-php-cases.json' );
const {
	groupProblems,
	groupByMessage,
	executiveSummary,
	recommendation,
	versionList,
	libraryOf,
	splitProblems,
	splitFound,
	glanceDetails,
	problemTables,
	problemTypeLabel,
} = await import( '../report-model' );
const { buildReportHtml } = await import( '../print-report' );
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
		[ 'At a glance', 'Needs attention', 'About this report' ].forEach(
			( heading ) => expect( html() ).toContain( heading )
		);
	} );

	it( 'gives every table a caption and scoped headers', () => {
		const doc = html();
		const tables = doc.match( /<table[ >]/g ).length;
		expect( doc.match( /<caption[ >]/g ) ).toHaveLength( tables );
		// Every header cell (not <thead>) declares what it labels.
		const headers = doc.match( /<th[\s>]/g ).length;
		expect(
			doc.match( /<th [^>]*scope="(col|row|rowgroup)"/g )
		).toHaveLength( headers );
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

describe( 'PDF download (PDFKit)', () => {
	it( 'is a tagged PDF with headings, tables, and lists', async () => {
		const { PDFDocument } = await import( 'pdfkit' );
		const { buildPdf, needsUnicodeFallback } =
			await import( '../pdf-report' );
		const meta = reportMeta( items );
		expect( needsUnicodeFallback( items, meta ) ).toBe( false );
		expect(
			needsUnicodeFallback(
				[ { ...items[ 2 ], name: 'お問い合わせフォーム' } ],
				meta
			)
		).toBe( true );

		const chunks = [];
		await new Promise( ( resolve ) => {
			buildPdf( PDFDocument, items, meta, {
				onData: ( chunk ) => chunks.push( chunk ),
				onEnd: resolve,
			} );
		} );
		const pdf = Buffer.concat( chunks ).toString( 'latin1' );

		expect( pdf ).toContain( '/StructTreeRoot' );
		expect( pdf ).toContain( '/Marked true' );
		expect( pdf ).toContain( '/DisplayDocTitle true' );
		expect( pdf ).toContain( '(PHP Compatibility Checker 1.6.5)' );
		[
			'Document',
			'H1',
			'H2',
			'H3',
			'Table',
			'TH',
			'TD',
			'L',
			'LI',
		].forEach( ( tag ) => expect( pdf ).toContain( `/S /${ tag }` ) );
	} );
} );

describe( 'report model', () => {
	const checked = [ '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' ];

	it( 'writes runs of versions as ranges', () => {
		expect( versionList( checked, checked ) ).toBe( '7.4–8.5' );
		expect( versionList( [ '8.3', '8.4', '8.5' ], checked ) ).toBe(
			'8.3–8.5'
		);
		expect( versionList( [ '7.4', '8.3', '8.4', '8.5' ], checked ) ).toBe(
			'7.4, 8.3–8.5'
		);
		expect( versionList( [ '7.4', '8.0' ], checked ) ).toBe( '7.4, 8.0' );
		expect( versionList( [ '7.4', '8.1' ], checked ) ).toBe( '7.4, 8.1' );
		expect( versionList( [], checked ) ).toBe( '' );
	} );

	it( 'finds the bundled library a file belongs to', () => {
		expect(
			libraryOf(
				'core/lib/vendor/paragonie/constant_time_encoding/src/Base32.php'
			)
		).toEqual( {
			name: 'paragonie/constant_time_encoding',
			path: 'core/lib/vendor/paragonie/constant_time_encoding',
			location: 'core/lib/vendor/',
		} );
		expect( libraryOf( 'node_modules/@scope/pkg/index.php' ) ).toEqual( {
			name: '@scope/pkg',
			path: 'node_modules/@scope/pkg',
			location: '',
		} );
		expect( libraryOf( 'third-party/Parsedown/Parsedown.php' ) ).toEqual( {
			name: 'Parsedown',
			path: 'third-party/Parsedown',
			location: '',
		} );
		expect( libraryOf( 'includes/legacy.php' ) ).toBeNull();
	} );

	it( 'summarizes bundled libraries and keeps own files in full', () => {
		const item = {
			php: { 7.4: 'fail', '8.0': 'fail' },
			problems: [
				problem( '7.4', 10, 'own problem', 'inc/own.php' ),
				problem( '7.4', 1, 'lib error', 'vendor/acme/lib/a.php' ),
				problem( '8.0', 1, 'lib error', 'vendor/acme/lib/a.php' ),
				{
					...problem(
						'8.0',
						2,
						'lib warning',
						'vendor/acme/lib/b.php'
					),
					type: 'WARNING',
				},
			],
		};
		const { files, libraries } = splitProblems( item );

		expect( files.map( ( file ) => file.file ) ).toEqual( [
			'inc/own.php',
		] );
		expect( libraries ).toEqual( [
			{
				name: 'acme/lib',
				path: 'vendor/acme/lib',
				location: '',
				files: 2,
				errors: 1,
				warnings: 1,
				versions: [ '7.4', '8.0' ],
			},
		] );
	} );

	it( 'builds the problem tables both reports use', () => {
		const item = {
			php: { 7.4: 'fail', '8.0': 'fail', 8.4: 'pass' },
			problems: [
				problem( '7.4', 10, 'everywhere', 'inc/own.php' ),
				problem( '8.0', 10, 'everywhere', 'inc/own.php' ),
				problem( '7.4', 1, 'lib error', 'vendor/acme/lib/a.php' ),
			],
		};
		const tables = problemTables( item );

		expect( tables.fails ).toBe( '7.4, 8.0' );
		expect( tables.groups.map( ( group ) => group.file ) ).toEqual( [
			'inc/own.php',
		] );
		expect( tables.libraries.map( ( lib ) => lib.name ) ).toEqual( [
			'acme/lib',
		] );
		// Every row fails on the item's versions, so no PHP column.
		expect( tables.showPhp ).toBe( false );

		// One row on fewer versions than the item: rows get their own PHP column.
		item.problems.push( problem( '8.0', 20, 'only 8.0', 'inc/own.php' ) );
		expect( problemTables( item ).showPhp ).toBe( true );

		expect( problemTypeLabel( 'WARNING' ) ).toBe( 'Warning' );
		expect( problemTypeLabel( 'ERROR' ) ).toBe( 'Error' );
	} );

	it( 'merges messages that differ only in what was found', () => {
		const msg = ( name ) =>
			`Implicitly marking a parameter as nullable is deprecated since PHP 8.4. Update the type to be explicitly nullable instead. Found implicitly nullable parameter: ${ name }.`;
		expect( splitFound( msg( '$link' ) ) ).toMatchObject( {
			label: 'Found implicitly nullable parameter',
			value: '$link',
		} );

		const [ file ] = groupProblems( [
			problem( '8.4', 95, msg( '$link' ) ),
			problem( '8.4', 95, msg( '$new_link' ) ),
			problem( '8.4', 129, msg( '$link' ) ),
			problem( '8.4', 200, 'Something else' ),
		] );
		const rows = groupByMessage( file.rows );

		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ].message ).toMatch(
			/Found implicitly nullable parameter: \$link, \$new_link\.$/
		);
		expect( rows[ 0 ].lines ).toEqual( [ 95, 129 ] );
	} );

	it( 'says whether a row fails or passes, with ranges', () => {
		const meta = { max_php_checked: '8.5' };
		const php = Object.fromEntries( checked.map( ( v ) => [ v, 'fail' ] ) );
		expect(
			glanceDetails( { status: 'problems', php, problems: [] }, meta )
		).toBe( 'Fails on PHP 7.4–8.5' );
		expect(
			glanceDetails(
				{
					status: 'passes',
					requires_php: '8.3',
					php: {
						8.2: 'not_counted',
						8.3: 'pass',
						8.4: 'pass',
						8.5: 'pass',
					},
				},
				meta
			)
		).toBe( 'Passes on PHP 8.3–8.5 (requires PHP 8.3)' );
	} );

	it( 'puts bundled libraries in a summary table in the printable report', () => {
		const html = buildReportHtml(
			[
				{
					...items[ 0 ],
					problems: [
						...items[ 0 ].problems,
						problem( '7.2', 5, 'lib', 'vendor/acme/lib/x.php' ),
					],
				},
			],
			{
				site: { url: 'https://example.com', name: 'Example' },
				summary: { problems: 1, not_checked: 0, passes: 0, failed: 0 },
				generated_at: '2026-11-02T00:00:00Z',
				source: 'PHPCompatibilityWP 3.0.0',
				max_php_checked: '7.4',
				scope: 'yes',
			}
		);
		expect( html ).toContain( 'Bundled libraries' );
		expect( html ).toContain( 'acme/lib' );
		expect( html ).not.toContain( '<code>vendor/acme/lib/x.php</code>' );
		expect( html ).not.toContain( 'WordPress Playground (playground' );
		expect( html ).toContain( 'Checked with' );
	} );
} );

describe( 'needed PHP (shared cases with the PHP side)', () => {
	const report = ( testCase ) => {
		const versions = {};
		Object.entries( testCase.errors ).forEach( ( [ version, errors ] ) => {
			const path = testCase.bundled
				? 'vendor/acme/lib/a.php'
				: 'includes/a.php';
			versions[ version ] = {
				totals: { errors, warnings: 0 },
				files: errors ? { [ path ]: { messages: [] } } : {},
			};
		} );
		return { versions, platform_php: testCase.platform_php || '' };
	};

	it.each( neededPhpCases.cases )( '$name', ( testCase ) => {
		expect(
			neededPhp(
				{ requires_php: testCase.requires_php },
				report( testCase )
			)
		).toEqual( testCase.expected );
	} );

	it( 'explains the finding in one sentence', () => {
		expect(
			needsPhpNote( {
				declared: '7.4',
				needed: '8.0',
				source: 'code',
				bundled: false,
			} )
		).toBe(
			'Its code needs PHP 8.0 or newer, but it says it requires PHP 7.4.'
		);
		expect(
			needsPhpNote( {
				declared: '',
				needed: '8.1',
				source: 'composer',
				bundled: false,
			} )
		).toMatch( /from Composer.*doesn't say/ );
	} );
} );

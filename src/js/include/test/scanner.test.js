/**
 * Unit tests for batched scanning: sizing, retries, skipping, merging.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

globalThis.window = globalThis.window || globalThis;
const apiFetch = vi.fn();
vi.mock( '@wordpress/api-fetch', () => ( { default: apiFetch } ) );

const {
	scanItem,
	nextBatchSize,
	timeLeft,
	pause,
	startScanSession,
	endScanSession,
	backoff,
	classify,
	parseJson,
	mergeReport,
	finishReport,
	cancelScans,
	MAX_BATCH,
} = await import( '../scanner' );

const VERSIONS = [ '7.4', '8.0' ];
const job = { type: 'plugin', slug: 'example', name: 'Example' };

/**
 * A Response-like object, as apiFetch returns (or rejects with) when parse is false.
 *
 * @param {number}        status  HTTP status.
 * @param {Object|string} body    JSON body, or raw text.
 * @param {Object}        headers Headers.
 */
function response( status, body, headers = {} ) {
	return {
		status,
		ok: status >= 200 && status < 300,
		headers: { get: ( name ) => headers[ name ] ?? null },
		text: async () =>
			'string' === typeof body ? body : JSON.stringify( body ),
	};
}

/**
 * Report for a batch where the given files fail on PHP 8.0.
 *
 * @param {string[]} failing Paths.
 */
function batchReport( failing = [] ) {
	const files = {};
	failing.forEach( ( path ) => {
		files[ path ] = {
			errors: 1,
			warnings: 0,
			messages: [ { line: 1, type: 'ERROR', message: 'x' } ],
		};
	} );
	return {
		versions: {
			7.4: { totals: { errors: 0, warnings: 0 }, files: {} },
			'8.0': { totals: { errors: failing.length, warnings: 0 }, files },
		},
	};
}

/**
 * A fake /scan-batch over a list of files.
 *
 * @param {string[]}                         files           Paths.
 * @param {Object}                           options
 * @param {(batch: string[]) => Object|null} options.fail    Return a Response to fail a batch.
 * @param {string[]}                         options.failing Paths with an error on PHP 8.0.
 */
function server( files, { fail = () => null, failing = [] } = {} ) {
	const calls = [];
	apiFetch.mockImplementation( async ( { data } ) => {
		calls.push( { ...data } );
		const batch = files.slice( data.cursor, data.cursor + data.limit );
		const failure = fail( batch, data );
		if ( failure ) {
			throw failure;
		}
		const next = data.cursor + batch.length;
		return response( 200, {
			cached: false,
			report: batchReport(
				batch.filter( ( f ) => failing.includes( f ) )
			),
			scanned: batch.length,
			next: next < files.length ? next : null,
			next_path: next < files.length ? files[ next ] : '',
			total: files.length,
			fingerprint: 'abc',
			skipped: [],
			truncated: false,
		} );
	} );
	return calls;
}

/**
 * Run a scan to the end, advancing fake timers through backoff waits.
 *
 * @param {Promise} promise Scan.
 */
async function settle( promise ) {
	let done = false;
	promise.finally( () => ( done = true ) ).catch( () => {} );
	while ( ! done ) {
		await vi.advanceTimersByTimeAsync( 1000 );
	}
	return promise;
}

const files = ( n ) =>
	Array.from(
		{ length: n },
		( _, i ) => `f${ String( i ).padStart( 3, '0' ) }.php`
	);

beforeEach( () => {
	vi.useFakeTimers();
	window.checkerList = { scanner: { phpVersions: VERSIONS, ruleset: 'r' } };
	apiFetch.mockReset();
	cancelScans();
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'scanItem', () => {
	it( 'walks every file once and merges the batches', async () => {
		const list = files( 25 );
		const calls = server( list, { failing: [ 'f003.php', 'f020.php' ] } );

		const result = await settle( scanItem( job ) );

		const seen = calls.flatMap( ( c ) =>
			list.slice( c.cursor, c.cursor + c.limit )
		);
		expect( new Set( seen ).size ).toBe( 25 );
		expect( result.cached ).toBe( false );
		expect( result.fingerprint ).toBe( 'abc' );
		expect( result.report.incompatible ).toEqual( [ '8.0' ] );
		expect( result.report.compatible ).toEqual( [ '7.4' ] );
		expect( result.report.versions[ '8.0' ].totals.errors ).toBe( 2 );
		expect( Object.keys( result.report.versions[ '8.0' ].files ) ).toEqual(
			[ 'f003.php', 'f020.php' ]
		);
		// Later batches send the fingerprint back, so changed files are caught.
		// The first request only plans: it scans nothing and has no fingerprint yet.
		expect( calls[ 0 ] ).toMatchObject( { cursor: 0, limit: 0 } );
		expect( calls[ 0 ].fingerprint ).toBeUndefined();
		expect(
			calls.slice( 1 ).every( ( c ) => 'abc' === c.fingerprint )
		).toBe( true );
	} );

	it( 'reports files checked so far, for the progress bar', async () => {
		server( files( 25 ) );
		const updates = [];

		await settle(
			scanItem( job, ( text, progress ) =>
				updates.push( { text, progress } )
			)
		);

		expect( updates[ 0 ].text ).toBe( 'Preparing…' );
		const withProgress = updates.filter( ( u ) => u.progress );
		expect( withProgress[ 0 ].progress ).toEqual( { value: 0, max: 25 } );
		expect( withProgress[ withProgress.length - 1 ].progress ).toEqual( {
			value: 25,
			max: 25,
		} );
		expect( withProgress[ 0 ].text ).toBe( 'Checking files: 0 of 25…' );
	} );

	it( 'returns a stored result when the site says the item is unchanged', async () => {
		apiFetch.mockResolvedValue(
			response( 200, {
				cached: true,
				result: {
					report: { compatible: VERSIONS },
					source: 'local',
					fingerprint: 'abc',
				},
			} )
		);

		const result = await settle( scanItem( job ) );

		expect( result.cached ).toBe( true );
		expect( result.report.compatible ).toEqual( VERSIONS );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits out a busy site, honoring Retry-After', async () => {
		let busy = 2;
		const calls = server( files( 3 ), {
			fail: () =>
				busy-- > 0
					? response(
							429,
							{ code: 'x', message: 'Slow down' },
							{ 'Retry-After': '5' }
						)
					: null,
		} );

		const result = await settle( scanItem( job ) );

		expect( result.report.compatible ).toEqual( VERSIONS );
		// Rate limiting isn't the batch's fault: same size, nothing skipped.
		expect( calls[ 0 ].limit ).toBe( calls[ 2 ].limit );
		expect( result.report.skipped ).toEqual( [] );
	} );

	it( 'splits a batch that times out, then skips the one file that breaks PHP', async () => {
		const list = files( 12 );
		const calls = server( list, {
			// Any batch containing f005.php dies like a PHP fatal (HTML, not JSON).
			fail: ( batch ) =>
				batch.includes( 'f005.php' )
					? response( 500, '<html>Fatal error</html>' )
					: null,
		} );

		const result = await settle( scanItem( job ) );

		expect( result.report.skipped ).toEqual( [ 'f005.php' ] );
		expect( result.report.skipped_count ).toBe( 1 );
		const scanned = calls
			.filter(
				( c ) =>
					! list
						.slice( c.cursor, c.cursor + c.limit )
						.includes( 'f005.php' )
			)
			.flatMap( ( c ) => list.slice( c.cursor, c.cursor + c.limit ) );
		expect( new Set( scanned ) ).toEqual(
			new Set( list.filter( ( f ) => 'f005.php' !== f ) )
		);
	} );

	it( 'starts the item again when its files change mid-scan', async () => {
		let changed = false;
		const calls = server( files( 30 ), {
			fail: ( batch, data ) => {
				if ( data.cursor > 0 && ! changed ) {
					changed = true;
					return response( 409, {
						code: 'phpcompat_files_changed',
						message: 'changed',
					} );
				}
				return null;
			},
		} );

		const result = await settle( scanItem( job ) );

		expect( result.report.compatible ).toEqual( VERSIONS );
		// Plan and first batch, twice.
		expect( calls.filter( ( c ) => 0 === c.limit ) ).toHaveLength( 2 );
	} );

	it( 'gives up with a clear message when the site keeps failing', async () => {
		server( files( 3 ), {
			fail: () => response( 503, { code: 'x', message: 'Down' } ),
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			/stopped responding/
		);
	} );

	it( 'gives up, never passes, when every request returns a web page', async () => {
		server( files( 3 ), { fail: () => response( 200, '<html>' ) } );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			/web page instead of a scan result/
		);
		expect( apiFetch.mock.calls.length ).toBeLessThanOrEqual( 7 );
	} );

	it( 'fails the item instead of passing when most files crash the scan', async () => {
		server( files( 40 ), {
			fail: ( batch, data ) =>
				data.limit > 0
					? response( 500, {
							code: 'phpcompat_scan_failed',
							message: 'Allowed memory size exhausted',
						} )
					: null,
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			/could not be checked.*memory size/
		);
	} );

	it( 'stops at once when the scanner is missing', async () => {
		server( files( 3 ), {
			fail: () =>
				response( 500, {
					code: 'phpcompat_no_scanner',
					message: 'Reinstall the plugin.',
				} ),
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			'Reinstall the plugin.'
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops at once when a firewall blocks the request', async () => {
		server( files( 3 ), {
			fail: () => response( 403, '<html>Blocked</html>' ),
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			/Reload the page/
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'explains a dropped connection instead of "Status: 0" (#231)', async () => {
		apiFetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			/stopped responding to the scan \(Failed to fetch\)/
		);
	} );

	it( 'sends the scan token with every request, and releases the lock at the end', async () => {
		startScanSession();
		const calls = server( files( 12 ) );

		await settle( scanItem( job ) );
		const token = calls[ 0 ].scan_token;
		expect( token ).toMatch( /^[a-f0-9]{32}$/ );
		expect( calls.every( ( c ) => token === c.scan_token ) ).toBe( true );

		apiFetch.mockClear();
		apiFetch.mockResolvedValue( {} );
		endScanSession();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: `/php-compat/v1/scan-lock?scan_token=${ token }`,
			method: 'DELETE',
		} );
		// Only once.
		endScanSession();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops at once when someone else is already scanning', async () => {
		server( files( 3 ), {
			fail: () =>
				response( 409, {
					code: 'phpcompat_scan_running',
					message: 'Alex is already scanning this site.',
				} ),
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			'Alex is already scanning this site.'
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fails at once on errors retrying cannot fix', async () => {
		server( files( 3 ), {
			fail: () =>
				response( 404, {
					code: 'phpcompat_unknown_item',
					message: 'Not installed.',
				} ),
		} );

		await expect( settle( scanItem( job ) ) ).rejects.toThrow(
			'Not installed.'
		);
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops when cancelled', async () => {
		server( files( 3 ), {
			fail: () => response( 503, { message: 'Down' } ),
		} );
		const scan = scanItem( job );
		await vi.advanceTimersByTimeAsync( 10 );
		cancelScans();

		await expect( settle( scan ) ).rejects.toMatchObject( {
			status: 'skipped',
		} );
	} );
} );

describe( 'batch sizing', () => {
	it( 'grows when fast, shrinks when slow, within bounds', () => {
		expect( nextBatchSize( 10, 500 ) ).toBe( 15 ); // At most 1.5 times larger.
		expect( nextBatchSize( 10, 2000 ) ).toBe( 10 ); // On target (2 s).
		expect( nextBatchSize( 10, 4000 ) ).toBe( 5 );
		expect( nextBatchSize( 10, 60000 ) ).toBe( 5 ); // At most halved.
		expect( nextBatchSize( 1, 60000 ) ).toBe( 1 );
		expect( nextBatchSize( MAX_BATCH, 1 ) ).toBe( MAX_BATCH );
	} );

	it( 'paces only after slow requests', () => {
		expect( pause( 1500 ) ).toBe( 0 ); // On target: no pause.
		expect( pause( 3000 ) ).toBe( 0 );
		expect( pause( 4000 ) ).toBe( 1000 ); // Slow: a quarter of it.
		expect( pause( 60000 ) ).toBe( 3000 ); // Capped.
	} );

	it( 'estimates time left in words', () => {
		expect( timeLeft( 20 ) ).toBe( 'less than a minute left' );
		expect( timeLeft( 150 ) ).toBe( 'about 3 min left' );
	} );

	it( 'backs off exponentially with jitter, capped', () => {
		expect( backoff( 1, 0, () => 0 ) ).toBe( 500 );
		expect( backoff( 1, 0, () => 1 ) ).toBe( 1000 );
		expect( backoff( 4, 0, () => 1 ) ).toBe( 8000 );
		expect( backoff( 20, 0, () => 1 ) ).toBe( 30000 );
		expect( backoff( 1, 7 ) ).toBe( 7000 );
	} );
} );

describe( 'responses', () => {
	it( 'classifies failures', () => {
		expect( classify( 429, {} ).kind ).toBe( 'busy' );
		expect( classify( 503, {} ).kind ).toBe( 'busy' );
		expect( classify( 504, null ).kind ).toBe( 'batch' );
		expect( classify( 200, null ).kind ).toBe( 'batch' );
		expect( classify( 500, { code: 'phpcompat_scan_failed' } ).kind ).toBe(
			'batch'
		);
		expect( classify( 403, { code: 'rest_forbidden' } ).kind ).toBe(
			'fatal'
		);
		expect(
			classify( 409, { code: 'phpcompat_files_changed' } ).code
		).toBe( 'phpcompat_files_changed' );
	} );

	it( 'reads JSON after a stray PHP notice', () => {
		expect( parseJson( 'Notice: x in y.php\n{"next":null}' ) ).toEqual( {
			next: null,
		} );
		expect( parseJson( '<html>' ) ).toBeNull();
		expect( parseJson( '' ) ).toBeNull();
	} );

	it( 'merges batch reports', () => {
		const report = { versions: {} };
		mergeReport( report, batchReport( [ 'a.php' ] ) );
		mergeReport( report, batchReport( [ 'b.php' ] ) );
		finishReport( report, [ '7.4', '8.0', '8.5' ] );

		expect( report.versions[ '8.0' ].totals.errors ).toBe( 2 );
		expect( report.incompatible ).toEqual( [ '8.0' ] );
		expect( report.compatible ).toEqual( [ '7.4', '8.5' ] );
	} );
} );

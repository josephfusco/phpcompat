/**
 * Scans plugins and themes on this site, a small batch of files per request.
 *
 * PHP_CodeSniffer with PHPCompatibilityWP ships in the plugin and runs on the
 * site's own PHP. Nothing is loaded from other sites. A scan is a resumable
 * batch job, built to stay inside shared-hosting limits:
 *
 * - One request at a time, so a scan never holds more than one PHP worker.
 * - Pacing: after a slow request (a busy host, often disk I/O on shared
 *   hosting), it waits a quarter of that time (at most 3 s) before the next.
 * - Adaptive batch size: each request aims to finish in about TARGET_MS. The
 *   size grows while requests are fast and halves after a slow or failed one,
 *   so slow hosts get small requests and fast hosts finish sooner. What's
 *   learned carries over to the next item.
 * - Retries with exponential backoff and jitter. A busy site (429, 503, or no
 *   connection) is waited out, honoring Retry-After. A batch that times out
 *   or crashes PHP is split until the file causing it is found; that file is
 *   skipped and listed, and the rest of the item is still checked.
 * - The server caps every batch (files and bytes) whatever is asked.
 * - Files that change mid-scan are detected (409) and the item starts again.
 * - Unchanged items aren't rescanned: the first request returns the stored result.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { wantedEngine, startBrowserEngine, scanInBrowser } from './wasm-engine';

/**
 * Error with a message that can be shown to people as is.
 */
export class ScanError extends Error {
	/**
	 * @param {string} message Message for people.
	 * @param {string} status  "failed", "skipped", or "busy" (someone else is
	 *                         scanning the site, or scanning is turned off,
	 *                         so the whole scan stops).
	 */
	constructor( message, status = 'failed' ) {
		super( message );
		this.status = status;
	}
}

/**
 * A failed batch request.
 */
export class BatchError extends Error {
	/**
	 * @param {string} message            Message.
	 * @param {Object} details
	 * @param {number} details.status     HTTP status (0 when there was no response).
	 * @param {string} details.code       WordPress error code, if any.
	 * @param {string} details.kind       "busy" (wait and retry), "batch" (retry smaller), or "fatal".
	 * @param {number} details.retryAfter Seconds the server asked to wait, or 0.
	 */
	constructor(
		message,
		{ status = 0, code = '', kind = 'fatal', retryAfter = 0 }
	) {
		super( message );
		this.status = status;
		this.code = code;
		this.kind = kind;
		this.retryAfter = retryAfter;
	}
}

/** Aim for requests this long: far below any host's limit, long enough to be efficient. */
export const TARGET_MS = 2000;
/** Most files per request; the server allows up to 100 (Rest_Controller::SCAN_MAX_FILES). */
export const MAX_BATCH = 50;
/** After this long without an answer, a request says it's still working. */
const SLOW_MS = 10000;
/** Files in the first request. */
const START_BATCH = 10;
/** Failed requests in a row (busy site, no connection) before giving up on an item. */
const MAX_FAILURES = 6;
/** Failures of a one-file batch before that file is skipped. */
const FILE_ATTEMPTS = 2;
/** Error codes that retrying can't fix. */
const FATAL_CODES = [
	'phpcompat_files_changed',
	'phpcompat_scan_running',
	'phpcompat_scanning_disabled',
	'phpcompat_no_scanner',
	'phpcompat_unknown_item',
	'phpcompat_no_files',
];
/** Times an item starts over because its files changed mid-scan. */
const MAX_RESTARTS = 2;

let batchSize = START_BATCH;
// Set once per page when the browser engine can't start in "auto" mode, so
// later items go straight to the server.
let browserFailed = false;
let browserReported = false;
let controller = new AbortController();
// Holds the site's scan lock for one scan (see Scan_Lock); '' between scans.
let scanToken = '';

/**
 * Start a scan: a new random token, sent with every request so the site can
 * keep a second scan (another admin, another tab) from running at once.
 */
export function startScanSession() {
	const bytes = new Uint8Array( 16 );
	globalThis.crypto.getRandomValues( bytes );
	scanToken = Array.from( bytes, ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

/**
 * End a scan (finished or cancelled) and let go of the site's scan lock. If
 * this never arrives (the tab closed), the lock expires on its own.
 */
export function endScanSession() {
	if ( ! scanToken ) {
		return;
	}
	const token = scanToken;
	scanToken = '';
	apiFetch( {
		path: `/php-compat/v1/scan-lock?scan_token=${ token }`,
		method: 'DELETE',
	} ).catch( () => {} );
}

/**
 * Settings from the page (see Scanner_Bundle::browser_config()).
 *
 * @return {Object} Config.
 */
function config() {
	return window.checkerList?.scanner || {};
}

/**
 * Stop every scan in progress. The request in flight finishes on the server,
 * but nothing more is sent.
 */
export function cancelScans() {
	controller.abort();
	controller = new AbortController();
}

/**
 * Scan one plugin or theme.
 *
 * @param {Object}                                                          job      Item: type, slug, name, version, source.
 * @param {(text: string, progress?: {value: number, max: number}) => void} onStatus Called with progress text and, once the file count is known, files checked so far.
 * @return {Promise<{report: Object, source: string, fingerprint: string, cached: boolean}>} Result.
 */
export async function scanItem( job, onStatus = () => {} ) {
	// End-to-end tests replace the scanner with fixtures.
	if ( 'function' === typeof window.phpcompatTestScanner ) {
		return window.phpcompatTestScanner( job, { onStatus, ScanError } );
	}

	const { signal } = controller;
	for ( let restarts = 0; ; restarts++ ) {
		try {
			return await scanOnce( job, onStatus, signal );
		} catch ( error ) {
			if (
				error instanceof BatchError &&
				'phpcompat_files_changed' === error.code &&
				restarts < MAX_RESTARTS
			) {
				continue;
			}
			throw toScanError( error, signal );
		}
	}
}

/**
 * One pass over an item's files: a plan request, then batches.
 *
 * @param {Object}                 job      Item.
 * @param {(text: string) => void} onStatus Progress callback.
 * @param {AbortSignal}            signal   Cancels the scan.
 * @return {Promise<Object>} Result (see scanItem()).
 */
async function scanOnce( job, onStatus, signal ) {
	const item = { type: job.type, slug: job.slug };
	if ( scanToken ) {
		item.scan_token = scanToken;
	}
	let failures = 0;

	// Wait out a failure that may pass (busy site, dropped connection), or
	// give up: on errors retrying can't fix, and after MAX_FAILURES in a row.
	const waitOrThrow = async ( error ) => {
		if ( ! ( error instanceof BatchError ) || 'fatal' === error.kind ) {
			throw error;
		}
		if ( ++failures > MAX_FAILURES ) {
			throw new ScanError(
				sprintf(
					/* translators: %s: last error message. */
					__(
						'This site stopped responding to the scan (%s). Try again later, or scan with WP-CLI: wp php-compat scan.',
						'php-compatibility-checker'
					),
					error.message
				)
			);
		}
		await sleep( backoff( failures, error.retryAfter ), signal );
	};

	// Plan: the stored result if nothing changed, otherwise the file count.
	onStatus( __( 'Preparing…', 'php-compatibility-checker' ) );
	let plan;
	while ( ! plan ) {
		try {
			plan = await postBatch( { ...item, cursor: 0, limit: 0 }, signal );
		} catch ( error ) {
			await waitOrThrow( error );
		}
	}
	failures = 0;

	if ( plan.cached && plan.result ) {
		return {
			report: plan.result.report,
			source: plan.result.source || 'local',
			fingerprint: plan.result.fingerprint || '',
			cached: true,
		};
	}

	const total = plan.total || 0;
	const fingerprint = plan.fingerprint || '';
	const engine = total ? await chooseEngine( onStatus ) : 'server';
	const versions = config().phpVersions || [];
	// Measured for the spike: where the time goes, per item.
	const timing = {
		engine,
		files: total,
		requestMs: 0,
		wasmMs: 0,
		batches: 0,
	};
	const itemStarted = Date.now();
	const report = { compatible: [], incompatible: [], versions: {} };
	// A host can answer with a whole report (wpephpcompat_pre_scan_report).
	mergeReport( report, plan.report || {} );

	if ( ! total && ! Object.keys( report.versions ).length ) {
		throw new ScanError(
			__(
				'No PHP files were found to check.',
				'php-compatibility-checker'
			),
			'skipped'
		);
	}

	// Files that crash the scan on their own are skipped, but only a few: past
	// that, something is wrong with the site, and a pass would mean nothing.
	const maxCrashes = Math.max( 5, Math.ceil( total * 0.1 ) );
	const crashed = [];
	let cursor = plan.next;
	let nextPath = plan.next_path || '';
	let checked = 0;
	let fileFailures = 0;

	const started = Date.now();
	let batches = 0;
	// "Checking files: 150 of 569 · about 2 min left", plus a progress bar.
	const statusText = () => {
		const counted = sprintf(
			/* translators: 1: files checked, 2: total files. */
			__( 'Checking files: %1$d of %2$d', 'php-compatibility-checker' ),
			checked,
			total
		);
		// An estimate needs a couple of batches to mean anything.
		const left =
			batches >= 2 && checked
				? timeLeft(
						( ( total - checked ) * ( Date.now() - started ) ) /
							checked /
							1000
					)
				: '';
		return left ? `${ counted } · ${ left }` : `${ counted }…`;
	};
	const status = ( note = '' ) =>
		onStatus( note ? `${ statusText() } · ${ note }` : statusText(), {
			value: checked,
			max: total,
		} );
	if ( total ) {
		status();
	}

	while ( null !== cursor && undefined !== cursor ) {
		const limit = batchSize;
		const batchStarted = Date.now();
		let response;
		// A long request (a slow host, or PHP in the browser on Playground)
		// says it's still going, so the row doesn't look stuck.
		const slow = setTimeout(
			() => status( __( 'still working…', 'php-compatibility-checker' ) ),
			SLOW_MS
		);

		let requestMs = 0;
		try {
			response = await postBatch(
				{ ...item, cursor, limit, fingerprint, engine },
				signal
			);
			requestMs = Date.now() - batchStarted;
			timing.requestMs += requestMs;
			if ( 'browser' === engine ) {
				const result = await scanBatchInBrowser(
					response.files || {},
					versions
				);
				timing.wasmMs += result.ms;
				response.report = result.report;
				response.scanned = result.checked;
			}
		} catch ( error ) {
			if ( ! ( error instanceof BatchError ) || 'batch' !== error.kind ) {
				await waitOrThrow( error );
				continue;
			}
			if ( limit > 1 ) {
				// Too much for one request: split it.
				batchSize = Math.max( 1, Math.floor( limit / 2 ) );
			} else if ( ++fileFailures >= FILE_ATTEMPTS ) {
				// This one file breaks the scan on its own: skip it, keep going.
				crashed.push(
					nextPath ||
						sprintf(
							/* translators: %d: file number. */
							__( 'PHP file #%d', 'php-compatibility-checker' ),
							cursor + 1
						)
				);
				if ( crashed.length > maxCrashes ) {
					throw new ScanError(
						sprintf(
							/* translators: 1: number of files, 2: last error message. */
							__(
								'%1$d files could not be checked because the scan stopped on each of them (%2$s). This is usually a memory or time limit on the server. Try again later, or scan with WP-CLI: wp php-compat scan.',
								'php-compatibility-checker'
							),
							crashed.length,
							error.message
						)
					);
				}
				fileFailures = 0;
				cursor = cursor + 1 < total ? cursor + 1 : null;
				nextPath = '';
				continue;
			}
			await sleep( backoff( 1 ), signal );
			continue;
		} finally {
			clearTimeout( slow );
		}

		failures = 0;
		fileFailures = 0;
		batches++;
		timing.batches++;
		const took = Date.now() - batchStarted;
		batchSize = nextBatchSize( limit, took );
		checked += response.scanned || 0;
		mergeReport( report, response.report || {} );
		cursor = response.next;
		nextPath = response.next_path || '';
		status();

		// A slow request means a busy host (often disk I/O on shared hosting):
		// leave it room for visitors before the next one.
		// With the browser engine the site only read files, so only that time counts.
		if ( null !== cursor && undefined !== cursor ) {
			await sleep(
				pause( 'browser' === engine ? requestMs : took ),
				signal
			);
		}
	}

	// eslint-disable-next-line no-console
	console.info( `PHP Compatibility Checker: ${ job.slug } on ${ engine }`, {
		...timing,
		totalMs: Date.now() - itemStarted,
	} );

	finishReport( report, versions );
	// Files that weren't checked, so a pass isn't overstated. Past the file
	// limit, how many weren't listed is unknown: count it as one.
	report.skipped = [ ...( plan.skipped || [] ), ...crashed ];
	report.skipped_count = report.skipped.length + ( plan.truncated ? 1 : 0 );
	// What its Composer dependencies require, to compare with "Requires PHP".
	report.platform_php = plan.platform_php || '';

	return { report, source: 'local', fingerprint, cached: false };
}

/**
 * Where this item's files are scanned: in the browser when the site allows it
 * and PHP loads here, otherwise on the server ("auto" falls back quietly; a
 * site set to "browser" only fails the scan).
 *
 * @param {(text: string) => void} onStatus Progress callback.
 * @return {Promise<string>} "browser" or "server".
 */
async function chooseEngine( onStatus ) {
	const wanted = wantedEngine( config() );
	if ( 'server' === wanted || ( 'auto' === wanted && browserFailed ) ) {
		return 'server';
	}
	onStatus(
		__(
			'Loading the scanner in your browser…',
			'php-compatibility-checker'
		)
	);
	try {
		const timings = await startBrowserEngine( config() );
		if ( ! browserReported ) {
			browserReported = true;
			// eslint-disable-next-line no-console
			console.info(
				'PHP Compatibility Checker: browser engine ready',
				timings
			);
		}
		return 'browser';
	} catch ( error ) {
		if ( 'browser' === wanted ) {
			throw new ScanError(
				sprintf(
					/* translators: %s: error message. */
					__(
						"PHP couldn't start in this browser (%s).",
						'php-compatibility-checker'
					),
					error.message
				)
			);
		}
		browserFailed = true;
		// eslint-disable-next-line no-console
		console.warn(
			'PHP Compatibility Checker: scanning on the server instead of in the browser:',
			error.message
		);
		return 'server';
	}
}

/**
 * Scan one batch in the browser. A failure is reported like a server batch
 * that crashed, so the batch is split until the file causing it is found.
 *
 * @param {Object<string, string|null>} files    Path => base64.
 * @param {string[]}                    versions PHP versions.
 * @return {Promise<Object>} Result (see scanInBrowser()).
 */
async function scanBatchInBrowser( files, versions ) {
	try {
		// Starts PHP again if it crashed on an earlier batch.
		await startBrowserEngine( config() );
		return await scanInBrowser( files, versions );
	} catch ( error ) {
		throw new BatchError( error.message, { kind: 'batch' } );
	}
}

/**
 * How long to wait after a request before the next: nothing while requests
 * are on target, then a quarter of a slow request's time (at most 3 s), so a
 * struggling host isn't kept busy back to back.
 *
 * @param {number} took Milliseconds the last request took.
 * @return {number} Milliseconds to wait.
 */
export function pause( took ) {
	return took > TARGET_MS * 1.5
		? Math.min( 3000, Math.round( took / 4 ) )
		: 0;
}

/**
 * Rough time left, for people: "less than a minute left", "about 3 min left".
 *
 * @param {number} seconds Estimated seconds left.
 * @return {string} Text.
 */
export function timeLeft( seconds ) {
	if ( seconds < 60 ) {
		return __( 'less than a minute left', 'php-compatibility-checker' );
	}
	const minutes = Math.round( seconds / 60 );
	return sprintf(
		/* translators: %d: minutes. */
		_n(
			'about %d min left',
			'about %d min left',
			minutes,
			'php-compatibility-checker'
		),
		minutes
	);
}

/**
 * The next batch size: scaled toward TARGET_MS, at most 1.5 times larger or
 * half the size per step, so one odd request doesn't swing it.
 *
 * @param {number} limit   Files requested.
 * @param {number} elapsed Milliseconds the request took.
 * @return {number} Files to request next.
 */
export function nextBatchSize( limit, elapsed ) {
	const ideal = Math.round( ( limit * TARGET_MS ) / Math.max( elapsed, 1 ) );
	const bounded = Math.min(
		Math.ceil( limit * 1.5 ),
		Math.max( Math.floor( limit / 2 ), ideal )
	);
	return Math.min( MAX_BATCH, Math.max( 1, bounded ) );
}

/**
 * Wait before retrying: the server's Retry-After if it gave one, otherwise
 * exponential backoff (1 s, 2 s, 4 s … up to 30 s) with jitter, so retries
 * don't line up with whatever made the site busy.
 *
 * @param {number}       attempt    Failures so far (1 for the first).
 * @param {number}       retryAfter Seconds the server asked for, or 0.
 * @param {() => number} random     Random number source (for tests).
 * @return {number} Milliseconds.
 */
export function backoff( attempt, retryAfter = 0, random = Math.random ) {
	if ( retryAfter > 0 ) {
		return Math.min( retryAfter, 120 ) * 1000;
	}
	const cap = Math.min( 30000, 1000 * 2 ** Math.max( 0, attempt - 1 ) );
	return Math.round( cap / 2 + random() * ( cap / 2 ) );
}

/**
 * POST /scan-batch, classifying failures for the retry logic.
 *
 * @param {Object}      data   Request body.
 * @param {AbortSignal} signal Cancels the request.
 * @return {Promise<Object>} Response body.
 */
async function postBatch( data, signal ) {
	let response;
	try {
		response = await apiFetch( {
			path: '/php-compat/v1/scan-batch',
			method: 'POST',
			data,
			signal,
			parse: false,
		} );
	} catch ( thrown ) {
		if ( 'AbortError' === thrown?.name ) {
			throw thrown;
		}
		// apiFetch rejects with the Response for HTTP errors when parse is false.
		if ( thrown && 'number' === typeof thrown.status && thrown.headers ) {
			response = thrown;
		} else {
			throw new BatchError(
				thrown?.message ||
					__( 'No connection', 'php-compatibility-checker' ),
				{ kind: 'busy' }
			);
		}
	}

	const text = await response.text().catch( () => '' );
	const body = parseJson( text );

	if ( response.ok && body && 'object' === typeof body ) {
		return body;
	}

	throw classify(
		response.status,
		body,
		parseRetryAfter( response.headers?.get?.( 'Retry-After' ) )
	);
}

/**
 * Turn a failed response into a BatchError.
 *
 * @param {number}      status     HTTP status.
 * @param {Object|null} body       Parsed body, or null if it wasn't JSON.
 * @param {number}      retryAfter Seconds, or 0.
 * @return {BatchError} Error.
 */
export function classify( status, body, retryAfter = 0 ) {
	const code = body?.code || '';
	let message = body?.message;
	if ( ! message && body ) {
		message = sprintf(
			/* translators: %d: HTTP status code. */
			__( 'HTTP %d', 'php-compatibility-checker' ),
			status
		);
	} else if ( ! message ) {
		message = sprintf(
			/* translators: %d: HTTP status code. */
			__(
				'the site sent back a web page instead of a scan result, HTTP %d',
				'php-compatibility-checker'
			),
			status
		);
	}

	let kind = 'fatal';
	if ( FATAL_CODES.includes( code ) || [ 401, 403 ].includes( status ) ) {
		// Retrying won't help: logged out, blocked, missing scanner, or files
		// changed (scanItem() starts the item again for that one).
		kind = 'fatal';
	} else if ( [ 408, 425, 429, 503 ].includes( status ) ) {
		kind = 'busy';
	} else if ( status >= 500 || ! body || 'phpcompat_scan_failed' === code ) {
		// A timeout, a PHP fatal (often memory), or a page that isn't JSON:
		// the batch was too much. Smaller batches find the file causing it.
		kind = 'batch';
	}

	return new BatchError( message, { status, code, kind, retryAfter } );
}

/**
 * JSON from a response, even when a PHP notice was printed before it.
 *
 * @param {string} text Response text.
 * @return {Object|null} Parsed JSON, or null.
 */
export function parseJson( text ) {
	if ( ! text ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		const start = text.indexOf( '{"' );
		if ( start > 0 ) {
			try {
				return JSON.parse( text.slice( start ) );
			} catch {}
		}
		return null;
	}
}

/**
 * @param {string|null} value Retry-After header: seconds or an HTTP date.
 * @return {number} Seconds, or 0.
 */
function parseRetryAfter( value ) {
	if ( ! value ) {
		return 0;
	}
	const seconds = Number( value );
	if ( Number.isFinite( seconds ) ) {
		return Math.max( 0, seconds );
	}
	const date = Date.parse( value );
	return Number.isNaN( date )
		? 0
		: Math.max( 0, Math.ceil( ( date - Date.now() ) / 1000 ) );
}

/**
 * A message for people from whatever went wrong.
 *
 * @param {Error}       error  Error.
 * @param {AbortSignal} signal The scan's signal.
 * @return {ScanError} Error to show.
 */
function toScanError( error, signal ) {
	if ( signal.aborted || 'AbortError' === error?.name ) {
		return new ScanError(
			__( 'The scan was cancelled.', 'php-compatibility-checker' ),
			'skipped'
		);
	}
	if ( error instanceof ScanError ) {
		return error;
	}
	if (
		error instanceof BatchError &&
		[ 'phpcompat_scan_running', 'phpcompat_scanning_disabled' ].includes(
			error.code
		)
	) {
		return new ScanError( error.message, 'busy' );
	}
	if (
		error instanceof BatchError &&
		'phpcompat_files_changed' === error.code
	) {
		return new ScanError(
			__(
				"This plugin's or theme's files kept changing during the scan. Scan again when updates have finished.",
				'php-compatibility-checker'
			)
		);
	}
	if (
		error instanceof BatchError &&
		[ 401, 403 ].includes( error.status )
	) {
		return new ScanError(
			sprintf(
				/* translators: %s: error message. */
				__(
					'%s Reload the page (you may have been logged out), then scan again.',
					'php-compatibility-checker'
				),
				error.message
			)
		);
	}
	return new ScanError(
		error?.message || __( 'The scan failed.', 'php-compatibility-checker' )
	);
}

/**
 * @param {number}      ms     Milliseconds.
 * @param {AbortSignal} signal Ends the wait early (rejects).
 * @return {Promise<void>} Resolves after the wait.
 */
function sleep( ms, signal ) {
	return new Promise( ( resolve, reject ) => {
		if ( signal.aborted ) {
			reject( new DOMException( 'Aborted', 'AbortError' ) );
			return;
		}
		const timer = setTimeout( done, ms );
		function done() {
			signal.removeEventListener( 'abort', abort );
			resolve();
		}
		function abort() {
			clearTimeout( timer );
			reject( new DOMException( 'Aborted', 'AbortError' ) );
		}
		signal.addEventListener( 'abort', abort, { once: true } );
	} );
}

/**
 * Add one batch's report. Batches cover different files, so files are
 * disjoint and totals add up.
 *
 * @param {Object} into Merged report (changed).
 * @param {Object} part Batch report.
 */
export function mergeReport( into, part ) {
	Object.entries( part.versions || {} ).forEach( ( [ version, data ] ) => {
		const merged = ( into.versions[ version ] ||= {
			totals: { errors: 0, warnings: 0 },
			files: {},
		} );
		merged.totals.errors += data?.totals?.errors || 0;
		merged.totals.warnings += data?.totals?.warnings || 0;
		Object.assign( merged.files, data?.files || {} );
	} );
}

/**
 * Fill in compatible and incompatible: a version is incompatible if any
 * batch found an error in it.
 *
 * @param {Object}   report   Merged report (changed).
 * @param {string[]} versions Versions checked.
 */
export function finishReport( report, versions ) {
	versions.forEach( ( version ) => {
		report.versions[ version ] ||= {
			totals: { errors: 0, warnings: 0 },
			files: {},
		};
	} );
	report.compatible = [];
	report.incompatible = [];
	Object.keys( report.versions ).forEach( ( version ) => {
		report[
			report.versions[ version ].totals.errors
				? 'incompatible'
				: 'compatible'
		].push( version );
	} );
}

/**
 * Store a result on the site, so later visits, WP-CLI, and the Abilities API can use it.
 *
 * @param {Object} job         Item.
 * @param {Object} report      Report.
 * @param {string} source      "wporg" or "local".
 * @param {string} fingerprint Fingerprint of the files scanned, so an unchanged item isn't rescanned.
 * @return {Promise<Object|null>} Stored result, or null if it couldn't be saved.
 */
export function saveResult( job, report, source, fingerprint = '' ) {
	return apiFetch( {
		path: '/php-compat/v1/results',
		method: 'POST',
		data: {
			type: job.type,
			slug: job.slug,
			version: job.version,
			ruleset: config().ruleset,
			source,
			fingerprint,
			report: trimReport( report ),
		},
	} ).catch( ( error ) => {
		// The scan page already shows the result; only later visits, WP-CLI,
		// and the Abilities API miss it.
		// eslint-disable-next-line no-console
		console.warn(
			`PHP Compatibility Checker: couldn't store the result for ${ job.name }:`,
			error?.message || error
		);
		return null;
	} );
}

/**
 * Most messages per PHP version sent to the site (matches Results::MAX_MESSAGES_PER_VERSION).
 */
const MAX_MESSAGES_PER_VERSION = 2000;

/**
 * A copy of a report with at most MAX_MESSAGES_PER_VERSION messages per
 * version, errors first. Totals are kept from the full report, so pass/fail
 * doesn't change; the site re-derives it from the messages it keeps.
 *
 * @param {Object} report Report.
 * @return {Object} Smaller report.
 */
export function trimReport( report ) {
	const versions = {};
	Object.entries( report.versions || {} ).forEach( ( [ version, data ] ) => {
		const files = {};
		let kept = 0;
		[ 'ERROR', 'WARNING' ].forEach( ( type ) => {
			Object.entries( data.files || {} ).forEach( ( [ path, file ] ) => {
				( file.messages || [] ).forEach( ( message ) => {
					if (
						type !== message.type ||
						kept >= MAX_MESSAGES_PER_VERSION
					) {
						return;
					}
					files[ path ] = files[ path ] || { messages: [] };
					files[ path ].messages.push( message );
					kept++;
				} );
			} );
		} );
		versions[ version ] = { totals: data.totals, files };
	} );
	return { ...report, versions };
}

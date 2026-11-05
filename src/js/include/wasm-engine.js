/**
 * Browser engine (experimental): scans in PHP compiled to WebAssembly, in a
 * Web Worker (see ../wasm-worker.js). The site only reads files; the CPU
 * work happens on this computer.
 *
 * Started once per page. The scanner's files are fetched from the site once
 * and kept in the browser's Cache Storage under the build's SHA-256, so they
 * download again only when the plugin is updated.
 */
import apiFetch from '@wordpress/api-fetch';

const CACHE_NAME = 'phpcompat-scanner';
/** Longest wait for PHP to load (includes downloading about 6.5 MB). */
const INIT_TIMEOUT_MS = 120000;
/** Longest wait for one batch; past this the worker is assumed stuck or dead. */
const SCAN_TIMEOUT_MS = 120000;

let worker = null;
let started = null;
let nextId = 1;
const pending = new Map();

/**
 * Where scans run on this page: "server", "browser", or "auto". A
 * ?phpcompat_engine= query argument overrides the site's setting, for testing.
 *
 * @param {Object} config Scanner settings (Scanner_Bundle::browser_config()).
 * @return {string} Engine.
 */
export function wantedEngine( config ) {
	const override = new URLSearchParams(
		globalThis.location?.search || ''
	).get( 'phpcompat_engine' );
	const engine = override || config.engine || 'server';
	return [ 'auto', 'browser', 'server' ].includes( engine )
		? engine
		: 'server';
}

/**
 * Start the browser engine (once per page; later calls share the first).
 *
 * @param {Object} config Scanner settings.
 * @return {Promise<Object>} Timings, once PHP and the scanner are ready.
 */
export function startBrowserEngine( config ) {
	if ( ! started ) {
		started = boot( config ).catch( ( error ) => {
			stopWorker();
			started = null;
			throw error;
		} );
	}
	return started;
}

/**
 * Scan one batch in the browser.
 *
 * @param {Object<string, string|null>} files    Path => base64, from /scan-batch.
 * @param {string[]}                    versions PHP versions.
 * @param {AbortSignal}                 signal   Stops waiting (the batch still finishes in the worker).
 * @return {Promise<{report: Object, checked: number, ms: number}>} Result.
 */
export function scanInBrowser( files, versions, signal ) {
	return call(
		{ type: 'scan', files, versions },
		{ timeout: SCAN_TIMEOUT_MS, signal }
	);
}

/**
 * @param {Object} config Scanner settings.
 * @return {Promise<Object>} Timings.
 */
async function boot( config ) {
	if (
		'undefined' === typeof WebAssembly ||
		'undefined' === typeof globalThis.Worker
	) {
		throw new Error( 'This browser cannot run WebAssembly in a worker.' );
	}
	const t0 = performance.now();
	const [ bundle, cachedBundle ] = await scannerBundle( config.sha256 );
	const t1 = performance.now();

	worker = new globalThis.Worker( config.workerUrl );
	worker.addEventListener( 'message', ( { data } ) => {
		const request = settle( data.id );
		if ( ! request ) {
			return;
		}
		if ( data.ok ) {
			request.resolve( data.result );
		} else {
			request.reject( new Error( data.error ) );
		}
	} );
	// The worker died (out of memory, or failed to load).
	worker.addEventListener( 'error', ( event ) => {
		event.preventDefault?.();
		crash( event.message || 'PHP in the browser stopped unexpectedly.' );
	} );

	const timings = await call(
		{
			type: 'init',
			wasmBaseUrl: config.wasmBaseUrl,
			bundle,
			sha256: config.sha256,
		},
		{ timeout: INIT_TIMEOUT_MS }
	);

	const result = {
		...timings,
		bundleMs: Math.round( t1 - t0 ),
		cachedBundle,
		totalMs: Math.round( performance.now() - t0 ),
	};
	// eslint-disable-next-line no-console
	console.info( 'PHP Compatibility Checker: browser engine ready', result );
	return result;
}

/**
 * The scanner's files: from Cache Storage when this build was fetched before,
 * otherwise from the site.
 *
 * @param {string} sha256 Build's SHA-256.
 * @return {Promise<[Object, boolean]>} Bundle, and whether it came from the cache.
 */
async function scannerBundle( sha256 ) {
	const key = `/phpcompat-scanner/${ sha256 }.json`;
	const cache = await globalThis.caches
		?.open( CACHE_NAME )
		.catch( () => null );

	const hit = cache ? await cache.match( key ).catch( () => null ) : null;
	if ( hit ) {
		try {
			return [ await hit.json(), true ];
		} catch {}
	}

	const bundle = await apiFetch( {
		path: `/php-compat/v1/scanner-bundle?sha256=${ sha256 }`,
	} );
	if ( cache ) {
		// Only the current build is kept.
		const keys = await cache.keys().catch( () => [] );
		await Promise.all( keys.map( ( request ) => cache.delete( request ) ) );
		await cache
			.put(
				key,
				new Response( JSON.stringify( bundle ), {
					headers: { 'Content-Type': 'application/json' },
				} )
			)
			.catch( () => {} );
	}
	return [ bundle, false ];
}

/**
 * Send a message to the worker and wait for its reply.
 *
 * A worker the browser killed (often for memory) may never answer or report
 * an error, so every call has a timeout. Past it, the worker is stopped, and
 * the next call starts a new one.
 *
 * @param {Object}      message         Message.
 * @param {Object}      options
 * @param {number}      options.timeout Milliseconds to wait.
 * @param {AbortSignal} options.signal  Optional. Stops waiting.
 * @return {Promise<Object>} Result.
 */
function call( message, { timeout, signal } ) {
	if ( ! worker ) {
		return Promise.reject(
			new Error( 'PHP in the browser is not running.' )
		);
	}
	if ( signal?.aborted ) {
		return Promise.reject( new DOMException( 'Aborted', 'AbortError' ) );
	}
	const id = nextId++;
	return new Promise( ( resolve, reject ) => {
		const timer = setTimeout(
			() => crash( 'PHP in the browser stopped responding.' ),
			timeout
		);
		const abort = () =>
			settle( id ) &&
			reject( new DOMException( 'Aborted', 'AbortError' ) );
		signal?.addEventListener( 'abort', abort, { once: true } );
		pending.set( id, {
			resolve,
			reject,
			cleanup: () => {
				clearTimeout( timer );
				signal?.removeEventListener( 'abort', abort );
			},
		} );
		worker.postMessage( { ...message, id } );
	} );
}

/**
 * Forget a pending call.
 *
 * @param {number} id Call ID.
 * @return {Object|undefined} The call, if it was still pending.
 */
function settle( id ) {
	const request = pending.get( id );
	if ( request ) {
		pending.delete( id );
		request.cleanup();
	}
	return request;
}

/**
 * The worker is gone or stuck: fail every pending call, stop it, and let
 * the next call start a new one.
 *
 * @param {string} message Error message.
 */
function crash( message ) {
	const error = new Error( message );
	[ ...pending.keys() ].forEach( ( id ) => settle( id ).reject( error ) );
	stopWorker();
	started = null;
}

function stopWorker() {
	worker?.terminate();
	worker = null;
}

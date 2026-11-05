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
 * @return {Promise<{report: Object, checked: number, ms: number}>} Result.
 */
export function scanInBrowser( files, versions ) {
	return call( { type: 'scan', files, versions } );
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
		const request = pending.get( data.id );
		if ( ! request ) {
			return;
		}
		pending.delete( data.id );
		if ( data.ok ) {
			request.resolve( data.result );
		} else {
			request.reject( new Error( data.error ) );
		}
	} );
	// The worker died (out of memory, or failed to load): fail what's waiting
	// and start a new one next time.
	worker.addEventListener( 'error', ( event ) => {
		event.preventDefault?.();
		const error = new Error(
			event.message || 'PHP in the browser stopped unexpectedly.'
		);
		pending.forEach( ( request ) => request.reject( error ) );
		pending.clear();
		stopWorker();
		started = null;
	} );

	const timings = await call( {
		type: 'init',
		wasmBaseUrl: config.wasmBaseUrl,
		bundle,
		sha256: config.sha256,
	} );

	return {
		...timings,
		bundleMs: Math.round( t1 - t0 ),
		cachedBundle,
		totalMs: Math.round( performance.now() - t0 ),
	};
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

	const bundle = await apiFetch( { path: '/php-compat/v1/scanner-bundle' } );
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
 * @param {Object} message Message.
 * @return {Promise<Object>} Result.
 */
function call( message ) {
	if ( ! worker ) {
		return Promise.reject(
			new Error( 'PHP in the browser is not running.' )
		);
	}
	const id = nextId++;
	return new Promise( ( resolve, reject ) => {
		pending.set( id, { resolve, reject } );
		worker.postMessage( { ...message, id } );
	} );
}

function stopWorker() {
	worker?.terminate();
	worker = null;
}

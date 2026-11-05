/**
 * Browser engine (experimental): PHP compiled to WebAssembly, in a Web Worker.
 *
 * Runs the plugin's own scanner (build/scanner/runner.php, the file the server
 * runs) on files the site sends, so the CPU work of a scan happens on the
 * admin's computer. The site only reads files.
 *
 * - PHP itself (the `@php-wasm/web-8-3` loader and .wasm, about 6.5 MB
 *   compressed) loads from config.wasmBaseUrl; the browser caches it.
 * - The scanner's files come from the site (GET /scanner-bundle) and are
 *   checked against the SHA-256 in the plugin's build manifest before use.
 * - No WordPress and no network access inside PHP: it only reads the files
 *   written into its in-memory file system and prints JSON.
 *
 * Messages: { id, type: 'init', wasmBaseUrl, bundle, sha256 } and
 * { id, type: 'scan', files, versions }. Replies: { id, ok, result | error }.
 */
import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { jspi } from 'wasm-feature-detect';

/** Where the scanner and the item's files go in PHP's file system. */
const SCANNER_DIR = '/scanner';
const ITEM_DIR = '/scan/item';

/** The scanner's files (path => bytes), kept to start PHP again after a crash. */
let scannerFiles = null;
/** The loaded Emscripten module for PHP, reused for restarts. */
let loader = null;
let asyncMode = 'asyncify';
/** The running PHP, or null after a crash. */
let php = null;

globalThis.onmessage = async ( { data } ) => {
	const { id, type } = data;
	try {
		let result;
		if ( 'init' === type ) {
			result = await init( data );
		} else if ( 'scan' === type ) {
			result = await scan( data );
		} else {
			throw new Error( `Unknown message: ${ type }` );
		}
		globalThis.postMessage( { id, ok: true, result } );
	} catch ( error ) {
		globalThis.postMessage( {
			id,
			ok: false,
			error: String( error?.message || error ),
		} );
	}
};

/**
 * Load PHP and the scanner.
 *
 * @param {Object} data
 * @param {string} data.wasmBaseUrl Base URL of the `@php-wasm/web-8-3` package.
 * @param {Object} data.bundle      GET /scanner-bundle response.
 * @param {string} data.sha256      Expected SHA-256 from the plugin's manifest.
 * @return {Promise<Object>} Timings.
 */
async function init( { wasmBaseUrl, bundle, sha256 } ) {
	const started = performance.now();

	// Decode and check the scanner before running any of it.
	const files = decodeFiles( bundle.files || {} );
	const actual = await manifestHash( files );
	if ( ! sha256 || actual !== sha256 ) {
		throw new Error(
			'The scanner files from the site do not match this copy of the plugin.'
		);
	}
	const checked = performance.now();

	// Stops Emscripten from installing a message-based polyfill that keeps
	// the PHP heap alive (as @php-wasm/web's loadWebRuntime() does).
	if ( ! ( 'setImmediate' in globalThis ) ) {
		globalThis.setImmediate = ( fn ) => setTimeout( fn, 0 );
	}

	asyncMode = ( await jspi() ) ? 'jspi' : 'asyncify';
	loader = await import(
		/* webpackIgnore: true */ `${ wasmBaseUrl }${ asyncMode }/php_8_3.js`
	);
	scannerFiles = files;
	await start();

	return {
		asyncMode,
		verifyMs: Math.round( checked - started ),
		bootMs: Math.round( performance.now() - checked ),
		bundleFiles: Object.keys( files ).length,
	};
}

/**
 * Start PHP and write the scanner into it.
 */
async function start() {
	const runtime = await loadPHPRuntime( loader, {
		phpWasmAsyncMode: asyncMode,
		processId: 1,
	} );
	php = new PHP( runtime );
	Object.entries( scannerFiles ).forEach( ( [ relative, bytes ] ) => {
		write( `${ SCANNER_DIR }/${ relative }`, bytes );
	} );
}

/**
 * Scan one batch of an item's files.
 *
 * @param {Object}                      data
 * @param {Object<string, string|null>} data.files    Relative path => base64 contents (null: unreadable).
 * @param {string[]}                    data.versions PHP versions.
 * @return {Promise<{report: Object, checked: number, ms: number}>} Report for the batch.
 */
async function scan( { files, versions } ) {
	if ( ! php ) {
		await start();
	}
	const started = performance.now();
	const paths = Object.keys( files );

	if ( php.fileExists( ITEM_DIR ) ) {
		php.rmdir( ITEM_DIR, { recursive: true } );
	}
	php.mkdir( ITEM_DIR );
	paths.forEach( ( relative ) => {
		if ( null !== files[ relative ] ) {
			write(
				`${ ITEM_DIR }/${ relative }`,
				base64ToBytes( files[ relative ] )
			);
		}
	} );
	php.writeFile(
		'/tmp/batch.json',
		JSON.stringify( { base: ITEM_DIR, files: paths, versions } )
	);

	let response;
	try {
		response = await php.run( {
			code: `<?php
define( 'ABSPATH', '/' );
ini_set( 'memory_limit', '-1' );
ini_set( 'display_errors', '0' );
require '${ SCANNER_DIR }/runner.php';
$in     = json_decode( file_get_contents( '/tmp/batch.json' ), true );
$done   = \\WPEngine_PHPCompat\\Bundle\\flag_files( $in['base'], $in['files'], $in['versions'] );
$report = \\WPEngine_PHPCompat\\Bundle\\report_flagged( $in['base'], $done['flagged'], $in['versions'] );
echo json_encode( array( 'checked' => $done['checked'], 'report' => $report ), JSON_INVALID_UTF8_SUBSTITUTE );
`,
		} );
	} catch ( error ) {
		// A crash (often memory) can leave PHP unusable: start fresh next time.
		php = null;
		throw error;
	}

	let parsed;
	try {
		parsed = JSON.parse( response.text );
	} catch {
		php = null;
		throw new Error(
			`PHP in the browser did not return a result: ${ (
				response.errors ||
				response.text ||
				''
			).slice( 0, 300 ) }`
		);
	}

	return {
		report: parsed.report,
		checked: parsed.checked,
		ms: Math.round( performance.now() - started ),
	};
}

/**
 * Write a file, creating its folders.
 *
 * @param {string}     path  Absolute path in PHP's file system.
 * @param {Uint8Array} bytes Contents.
 */
function write( path, bytes ) {
	const dir = path.slice( 0, path.lastIndexOf( '/' ) );
	if ( dir && ! php.fileExists( dir ) ) {
		php.mkdir( dir );
	}
	php.writeFile( path, bytes );
}

/**
 * @param {Object<string, string|null>} files Path => base64.
 * @return {Object<string, Uint8Array>} Path => bytes (unreadable files left out).
 */
function decodeFiles( files ) {
	const out = {};
	Object.entries( files ).forEach( ( [ path, data ] ) => {
		if ( null !== data ) {
			out[ path ] = base64ToBytes( data );
		}
	} );
	return out;
}

/**
 * @param {string} data Base64.
 * @return {Uint8Array} Bytes.
 */
function base64ToBytes( data ) {
	const binary = atob( data );
	const bytes = new Uint8Array( binary.length );
	for ( let i = 0; i < binary.length; i++ ) {
		bytes[ i ] = binary.charCodeAt( i );
	}
	return bytes;
}

/**
 * The build manifest's SHA-256 (tools/phpcs-bundle/build.php): over
 * "path\0sha256(file)\n" for every file, sorted by path.
 *
 * @param {Object<string, Uint8Array>} files Path => bytes.
 * @return {Promise<string>} Hex digest.
 */
async function manifestHash( files ) {
	const paths = Object.keys( files ).sort( ( a, b ) =>
		a < b ? -1 : Number( a > b )
	);
	const hashes = await Promise.all(
		paths.map( ( path ) => hex( files[ path ] ) )
	);
	const text = paths
		.map( ( path, i ) => `${ path }\0${ hashes[ i ] }\n` )
		.join( '' );
	return hex( new TextEncoder().encode( text ) );
}

/**
 * @param {Uint8Array} bytes Data.
 * @return {Promise<string>} SHA-256, hex.
 */
async function hex( bytes ) {
	const digest = await globalThis.crypto.subtle.digest( 'SHA-256', bytes );
	return Array.from( new Uint8Array( digest ), ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

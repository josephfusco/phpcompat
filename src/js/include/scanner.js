/**
 * Scans plugins and themes in the browser.
 *
 * PHP_CodeSniffer with PHPCompatibilityWP runs on PHP WebAssembly from
 * WordPress Playground (playground.wordpress.net), in a hidden iframe. The
 * site's server does no scanning: code from WordPress.org is downloaded
 * straight from downloads.wordpress.org, and only premium or custom code is
 * read from the server, in small batches.
 *
 * The plugin ships no WebAssembly: just the scanner rules (phpcs-bundle.zip,
 * about 1 MB) and the small Playground client.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Error with a message that can be shown to people as is.
 */
export class ScanError extends Error {
	/**
	 * @param {string} message Message for people.
	 * @param {string} status  "failed" or "skipped".
	 */
	constructor( message, status = 'failed' ) {
		super( message );
		this.status = status;
	}
}

let enginePromise = null;
// Bumped by stopEngine(), so a boot that is still running knows it was cancelled.
let engineGeneration = 0;

/**
 * Settings from the page (see Scanner_Bundle::browser_config()).
 *
 * @return {Object} Config.
 */
function config() {
	return window.checkerList?.scanner || {};
}

/**
 * Start (once) the PHP runtime with the scanner unpacked in it.
 *
 * @param {(text: string) => void} onStatus Called with loading progress (first call only).
 * @return {Promise<Object>} Playground client.
 */
export function getEngine( onStatus = () => {} ) {
	if ( ! enginePromise ) {
		const generation = engineGeneration;
		enginePromise = boot(
			onStatus,
			() => generation !== engineGeneration
		).catch( ( error ) => {
			if ( generation === engineGeneration ) {
				enginePromise = null;
			}
			throw error;
		} );
	}
	return enginePromise;
}

/**
 * Remove the runtime (e.g. when a scan is cancelled), including one still loading.
 */
export function stopEngine() {
	engineGeneration++;
	document.getElementById( 'wpe-pcc-engine' )?.remove();
	enginePromise = null;
}

/**
 * @param {(text: string) => void} onStatus  Progress callback.
 * @param {() => boolean}          cancelled Whether stopEngine() was called since this boot began.
 * @return {Promise<Object>} Playground client.
 */
async function boot( onStatus, cancelled ) {
	const { remoteUrl, bundleUrl, sha256 } = config();
	const stopIfCancelled = () => {
		if ( cancelled() ) {
			iframe?.remove();
			throw new ScanError(
				__( 'The scan was cancelled.', 'php-compatibility-checker' ),
				'skipped'
			);
		}
	};
	let iframe = null;

	onStatus(
		__(
			'Loading the scanner (about 8 MB the first time)…',
			'php-compatibility-checker'
		)
	);

	const [ { startPlaygroundWeb }, bundle ] = await Promise.all( [
		import(
			/* webpackChunkName: "playground-client" */ '@wp-playground/client'
		),
		fetchBundle( bundleUrl, sha256 ),
	] );
	stopIfCancelled();

	iframe = document.createElement( 'iframe' );
	iframe.id = 'wpe-pcc-engine';
	iframe.title = __( 'PHP scanner', 'php-compatibility-checker' );
	iframe.hidden = true;
	iframe.tabIndex = -1;
	iframe.setAttribute( 'aria-hidden', 'true' );
	document.body.appendChild( iframe );

	let client;
	try {
		client = await withTimeout(
			startPlaygroundWeb( {
				iframe,
				remoteUrl,
				blueprint: { preferredVersions: { php: '8.3', wp: 'latest' } },
				wordpressInstallMode: 'do-not-attempt-installing',
				disableProgressBar: true,
			} ),
			// Playground normally answers in a few seconds. When it doesn't, its
			// CDN is usually asking the browser to prove it isn't a bot, which a
			// hidden iframe can't do: say so instead of waiting minutes.
			45000
		);
		await client.isReady();
	} catch {
		iframe.remove();
		stopIfCancelled();
		throw new ScanError(
			__(
				'WordPress Playground (playground.wordpress.net), which runs the scanner, did not respond. It may be asking your browser to confirm you are not a bot: open playground.wordpress.net in a new tab, then scan again. Also check your internet connection, and that a firewall, browser extension, or security policy is not blocking it. WP-CLI can scan without it: wp php-compat scan.',
				'php-compatibility-checker'
			)
		);
	}

	try {
		stopIfCancelled();
		await client.writeFile( '/tmp/pcc-bundle.zip', bundle );
		await runPhp(
			client,
			`$zip = new ZipArchive();
			if ( true !== $zip->open( '/tmp/pcc-bundle.zip' ) || ! $zip->extractTo( '/pcc' ) ) { throw new RuntimeException( 'Could not unpack the scanner.' ); }
			$zip->close();
			unlink( '/tmp/pcc-bundle.zip' );
			echo json_encode( true );`
		);
		stopIfCancelled();
	} catch ( error ) {
		iframe.remove();
		throw error;
	}

	return client;
}

/**
 * Download the scanner rules and check them against the build's checksum.
 *
 * @param {string} url    Bundle URL.
 * @param {string} sha256 Expected SHA-256, hex.
 * @return {Promise<Uint8Array>} Zip bytes.
 */
async function fetchBundle( url, sha256 ) {
	const response = await fetch( url, { credentials: 'same-origin' } );
	if ( ! response.ok ) {
		throw new ScanError(
			__(
				'The scanner is missing from this copy of the plugin. Reinstall it.',
				'php-compatibility-checker'
			)
		);
	}
	const bytes = new Uint8Array( await response.arrayBuffer() );

	// crypto.subtle needs a secure context (HTTPS or localhost).
	if ( sha256 && window.crypto?.subtle ) {
		const digest = await window.crypto.subtle.digest( 'SHA-256', bytes );
		const hex = Array.from( new Uint8Array( digest ) )
			.map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
			.join( '' );
		if ( hex !== sha256 ) {
			throw new ScanError(
				__(
					'The scanner does not match its checksum. Reinstall the plugin.',
					'php-compatibility-checker'
				)
			);
		}
	}

	return bytes;
}

/**
 * Scan one plugin or theme.
 *
 * @param {Object}                 job      Item: type, slug, name, version, source ("wporg" or "local").
 * @param {(text: string) => void} onStatus Called with progress text.
 * @return {Promise<{report: Object, source: string}>} Report and where the code came from.
 */
export async function scanItem( job, onStatus = () => {} ) {
	// End-to-end tests replace the runtime, so they don't depend on the network.
	if ( 'function' === typeof window.phpcompatTestScanner ) {
		return window.phpcompatTestScanner( job, { onStatus, ScanError } );
	}

	const client = await getEngine( onStatus );
	const dir = `/pcc/scan/${ job.type }-${ job.slug }`;
	let source = '';
	let scanDir = dir;

	await runPhp(
		client,
		`pcc_rm( ${ phpString( dir ) } );
		mkdir( ${ phpString( dir ) }, 0777, true );
		echo json_encode( true );`
	);

	// The exact version from WordPress.org, when it's there. Anything else
	// (premium, custom, or a version WordPress.org doesn't have) is read
	// from this site.
	if ( 'wporg' === job.source ) {
		onStatus(
			__( 'Downloading from WordPress.org…', 'php-compatibility-checker' )
		);
		const zip = await fetchWporgZip( job );
		if ( zip ) {
			await client.writeFile( `${ dir }.zip`, zip );
			const root = await runPhp(
				client,
				`$zip = new ZipArchive();
				if ( true !== $zip->open( ${ phpString( dir + '.zip' ) } ) || ! $zip->extractTo( ${ phpString( dir ) } ) ) { throw new RuntimeException( 'The download from WordPress.org is damaged. Scan again.' ); }
				$zip->close();
				$php = 0;
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ${ phpString( dir ) }, FilesystemIterator::SKIP_DOTS ) ) as $f ) { if ( 'php' === strtolower( $f->getExtension() ) ) { $php++; } }
				if ( ! $php ) { throw new RuntimeException( 'The download from WordPress.org has no PHP files. Scan again.' ); }
				unlink( ${ phpString( dir + '.zip' ) } );
				$top = array_values( array_diff( scandir( ${ phpString(
					dir
				) } ), array( '.', '..' ) ) );
				echo json_encode( 1 === count( $top ) && is_dir( ${ phpString(
					dir
				) } . '/' . $top[0] ) ? $top[0] : '' );`
			);
			scanDir = root ? `${ dir }/${ root }` : dir;
			source = 'wporg';
		}
	}

	let skipped = [];
	let skippedCount = 0;
	if ( ! source ) {
		( { skipped, skippedCount } = await copyLocalFiles(
			client,
			job,
			dir,
			onStatus
		) );
		source = 'local';
	}

	onStatus(
		sprintf(
			/* translators: %s: list of PHP versions. */
			__( 'Checking PHP %s…', 'php-compatibility-checker' ),
			config().phpVersions.join( ', ' )
		)
	);

	try {
		const report = await runPhp(
			client,
			`require_once '/pcc/runner.php';
			echo json_encode( \\WPEngine_PHPCompat\\Bundle\\scan( ${ phpString(
				scanDir
			) }, json_decode( ${ phpString(
				JSON.stringify( config().phpVersions )
			) }, true ) ) );`
		);
		// Files that weren't checked, so a pass isn't overstated.
		report.skipped = skipped;
		report.skipped_count = skippedCount;
		return { report, source };
	} finally {
		runPhp(
			client,
			`pcc_rm( ${ phpString( dir ) } );
			echo json_encode( true );`
		).catch( () => {} );
	}
}

/**
 * The item's exact version from WordPress.org, or null if it isn't there.
 *
 * @param {Object} job Item.
 * @return {Promise<Uint8Array|null>} Zip bytes.
 */
async function fetchWporgZip( job ) {
	if (
		! /^[a-z0-9-]+$/.test( job.slug ) ||
		! /^[0-9A-Za-z.+-]+$/.test( job.version )
	) {
		return null;
	}
	let response;
	try {
		response = await fetch(
			`https://downloads.wordpress.org/${ job.type }/${ job.slug }.${ job.version }.zip`,
			{ credentials: 'omit' }
		);
	} catch {
		throw new ScanError(
			__(
				'Could not download from WordPress.org. Check your internet connection, and that a firewall or browser extension is not blocking downloads.wordpress.org, then scan again.',
				'php-compatibility-checker'
			)
		);
	}
	if ( 404 === response.status ) {
		return null;
	}
	if ( ! response.ok ) {
		throw new ScanError(
			sprintf(
				/* translators: %d: HTTP status code. */
				__(
					'WordPress.org returned HTTP %d. Scan again in a few minutes.',
					'php-compatibility-checker'
				),
				response.status
			)
		);
	}
	return new Uint8Array( await response.arrayBuffer() );
}

/**
 * Copy an item's PHP files from this site into the runtime, one batch at a time.
 *
 * @param {Object}                 client   Playground client.
 * @param {Object}                 job      Item.
 * @param {string}                 dir      Destination directory.
 * @param {(text: string) => void} onStatus Progress callback.
 */
async function copyLocalFiles( client, job, dir, onStatus ) {
	let cursor = 0;
	let copied = 0;
	let skipped = [];
	let truncated = false;

	do {
		const batch = await apiFetch( {
			path: `/php-compat/v1/files?type=${ encodeURIComponent(
				job.type
			) }&slug=${ encodeURIComponent( job.slug ) }&cursor=${ cursor }`,
		} ).catch( ( error ) => {
			throw new ScanError(
				error?.message ||
					__(
						"Could not read this plugin's or theme's files.",
						'php-compatibility-checker'
					)
			);
		} );

		copied += batch.files.length;
		if ( 0 === cursor ) {
			skipped = batch.skipped || [];
			truncated = !! batch.truncated;
		}
		onStatus(
			sprintf(
				/* translators: 1: files copied, 2: total files. */
				__(
					'Reading files from this site: %1$d of %2$d…',
					'php-compatibility-checker'
				),
				copied,
				batch.total
			)
		);

		// One write per batch; PHP unpacks it. Paths are re-checked inside.
		await client.writeFile(
			`${ dir }.batch.json`,
			new TextEncoder().encode( JSON.stringify( batch.files ) )
		);
		await runPhp(
			client,
			`$dir = ${ phpString( dir ) };
			$batch = json_decode( file_get_contents( $dir . '.batch.json' ), true );
			unlink( $dir . '.batch.json' );
			foreach ( $batch as $file ) {
				$path = str_replace( '\\\\', '/', (string) $file['path'] );
				if ( '' === $path || '/' === $path[0] || preg_match( '#(^|/)\\.\\.(/|$)#', $path ) ) { continue; }
				$target = $dir . '/' . $path;
				if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0777, true ); }
				file_put_contents( $target, base64_decode( $file['content'] ) );
			}
			echo json_encode( true );`
		);

		cursor = batch.next;
	} while ( null !== cursor && undefined !== cursor );

	if ( ! copied ) {
		throw new ScanError(
			__(
				'No PHP files were found to check.',
				'php-compatibility-checker'
			),
			'skipped'
		);
	}

	// Past the file limit, the count of what wasn't sent is unknown: count it as one.
	return { skipped, skippedCount: skipped.length + ( truncated ? 1 : 0 ) };
}

/**
 * Run PHP code and decode the JSON it echoes.
 *
 * @param {Object} client Playground client.
 * @param {string} code   PHP code without the opening tag; must echo JSON.
 * @return {Promise<Object|string|boolean>} Decoded output.
 */
async function runPhp( client, code ) {
	const response = await client.run( {
		code: `<?php
ini_set( 'memory_limit', '-1' );
ini_set( 'display_errors', '0' );
set_time_limit( 0 );
function pcc_rm( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}
try {
${ code }
} catch ( Throwable $e ) {
	echo json_encode( array( '__error' => $e->getMessage() ) );
}`,
	} );

	let data;
	try {
		data = JSON.parse( response.text );
	} catch {
		throw new ScanError(
			sprintf(
				/* translators: %s: error output. */
				__(
					'The scanner stopped unexpectedly: %s',
					'php-compatibility-checker'
				),
				( response.errors || response.text || '' ).slice( 0, 300 )
			)
		);
	}
	if ( data && data.__error ) {
		throw new ScanError(
			sprintf(
				/* translators: %s: error message. */
				__(
					'The scanner stopped unexpectedly: %s',
					'php-compatibility-checker'
				),
				data.__error
			)
		);
	}
	return data;
}

/**
 * A PHP single-quoted string literal.
 *
 * @param {string} value Value.
 * @return {string} PHP literal.
 */
export function phpString( value ) {
	return `'${ String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }'`;
}

function withTimeout( promise, ms ) {
	return Promise.race( [
		promise,
		new Promise( ( resolve, reject ) =>
			setTimeout( () => reject( new Error( 'timeout' ) ), ms )
		),
	] );
}

/**
 * Store a result on the site, so later visits, WP-CLI, and the Abilities API can use it.
 *
 * @param {Object} job    Item.
 * @param {Object} report Report.
 * @param {string} source "wporg" or "local".
 * @return {Promise<Object|null>} Stored result, or null if it couldn't be saved.
 */
export function saveResult( job, report, source ) {
	return apiFetch( {
		path: '/php-compat/v1/results',
		method: 'POST',
		data: {
			type: job.type,
			slug: job.slug,
			version: job.version,
			ruleset: config().ruleset,
			source,
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

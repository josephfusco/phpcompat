import {
	updateResultFailure,
	updateResult,
	finishResults,
	setItemStatus,
} from './render';
import $ from 'jquery';
import { hideDownload, showDownload } from './download';
import { __, sprintf } from '@wordpress/i18n';
import {
	scanItem,
	saveResult,
	stopEngine,
	getEngine,
	ScanError,
} from './scanner';

const progress = $( '.wpe-pcc-progress' );
const progressCount = $( '.wpe-pcc-progress-count' );

/**
 * Queue the plugins and themes for the selected scan option. In "choose"
 * mode everything is queued and limitQueue() narrows it to the selection.
 *
 * @param {Object} itemsToScan Plugins and themes from the page.
 * @param {string} mode        "yes" (active only), "no" (all), or "choose".
 */
export function initQueue( itemsToScan, mode ) {
	// Reset the queue. A new scan ID makes any scan still running ignore its results.
	window.phpcompat.queue = [];
	window.phpcompat.results = [];
	window.phpcompat.report = [];
	window.phpcompat.total = 0;
	window.phpcompat.completed = 0;
	window.phpcompat.scanId = ( window.phpcompat.scanId || 0 ) + 1;

	if ( window.phpcompat.running ) {
		// Stop the PHP runtime mid-scan; the next scan starts a fresh one.
		stopEngine();
		window.phpcompat.running = false;
	}

	hideDownload();
	resetProgress();
	$( '.wpe-pcc-playground' ).hide();
	$( '.wpe-pcc-information' ).hide();
	$( '#testResults' ).val( '' );

	const include = ( item ) => 'yes' !== mode || 'yes' === item.active;

	itemsToScan.plugins.filter( include ).forEach( ( plugin ) => {
		window.phpcompat.queue.push( { ...plugin, type: 'plugin' } );
	} );

	itemsToScan.themes.filter( include ).forEach( ( theme ) => {
		window.phpcompat.queue.push( { ...theme, type: 'theme' } );
	} );

	window.phpcompat.total = window.phpcompat.queue.length;
}

/**
 * Limit the queue to the selected items.
 *
 * @param {string[]} ids Item IDs in "type_slug" form.
 */
export function limitQueue( ids ) {
	window.phpcompat.queue = window.phpcompat.queue.filter( ( job ) =>
		ids.includes( `${ job.type }_${ job.slug }` )
	);
	window.phpcompat.total = window.phpcompat.queue.length;
}

/**
 * Scan one item, or reuse its stored result.
 *
 * Results for code from WordPress.org are reused when the same version was
 * already scanned with the same rules: that code can't change. Code read from
 * this site can change without a new version number, so it's always scanned.
 *
 * @param {Object}     job Queued item.
 * @param {() => void} cb  Called when done (not called if the scan was reset).
 */
export async function executeJob( job, cb ) {
	const scanId = window.phpcompat.scanId;
	const current = () => scanId === window.phpcompat.scanId;

	if ( job.stored && 'wporg' === job.stored.source ) {
		window.phpcompat.results.push( job );
		updateResult( job.stored.report, job );
		updateProgress();
		cb();
		return;
	}

	setItemStatus( job, __( 'Scanning…', 'php-compatibility-checker' ), true );
	window.phpcompat.running = true;

	try {
		const { report, source } = await scanItem( job, ( text ) => {
			if ( current() ) {
				setItemStatus( job, text, true );
			}
		} );
		if ( ! current() ) {
			return;
		}
		window.phpcompat.results.push( job );
		updateResult( report, job );
		updateProgress();
		saveResult( job, report, source );
	} catch ( error ) {
		if ( ! current() ) {
			return;
		}
		updateProgress();
		updateResultFailure(
			{
				status: error instanceof ScanError ? error.status : 'failed',
				message:
					error instanceof ScanError
						? error.message
						: sprintf(
								/* translators: %s: error message. */
								__(
									'Scan failed: %s',
									'php-compatibility-checker'
								),
								error?.message || String( error )
							),
			},
			job
		);
	} finally {
		if ( current() ) {
			window.phpcompat.running = false;
		}
	}

	if ( current() ) {
		cb();
	}
}

/**
 * Link to WordPress Playground on the latest PHP with every plugin and theme
 * that had problems pre-installed, so people can see whether they actually run.
 */
export function showPlayground() {
	const problems = window.phpcompat.results.filter(
		( job ) =>
			'problems' ===
			$( `#${ job.type }_${ job.slug }` ).attr( 'data-result' )
	);
	if ( ! problems.length ) {
		return;
	}

	const params = new URLSearchParams( { php: 'latest' } );
	problems.forEach( ( job ) => params.append( job.type, job.slug ) );

	$( '#playgroundLink' ).attr(
		'href',
		`https://playground.wordpress.net/?${ params.toString() }`
	);
	$( '.wpe-pcc-playground' ).show();
}

export function runNextJob() {
	if ( window.phpcompat.queue.length === 0 ) {
		resetProgress();
		showDownload();
		finishResults();
		$( 'input[type=radio][name=active_plugins]' ).prop( 'disabled', false );

		// Only offer help when something actually failed.
		if ( $( '[data-result="problems"]' ).length ) {
			$( '.wpe-pcc-information' ).show();
			showPlayground();
		}
		return;
	}

	// One at a time: each scan uses the same PHP runtime.
	executeJob( window.phpcompat.queue.shift(), runNextJob );
}

export function updateProgress() {
	window.phpcompat.completed++;
	showProgressCount();
}

function showProgressCount() {
	progressCount.text(
		sprintf(
			/* translators: 1: number scanned, 2: total number to scan. */
			__( '%1$d of %2$d', 'php-compatibility-checker' ),
			window.phpcompat.completed,
			window.phpcompat.total
		)
	);
}

/**
 * Show the spinner and "Now scanning" at the start of a scan.
 */
export function startProgress() {
	$( '.wpe-pcc-spinner' ).show();
	progress.show();
	showProgressCount();
	warmUpEngine();
}

/**
 * Start loading the PHP runtime as soon as the scan starts, while stored
 * results are shown, if any item needs a real scan. Loading progress goes on
 * the progress line, not on whichever item happens to wait for it.
 */
function warmUpEngine() {
	const needsScan = window.phpcompat.queue.some(
		( job ) => ! ( job.stored && 'wporg' === job.stored.source )
	);
	if ( ! needsScan || 'function' === typeof window.phpcompatTestScanner ) {
		return;
	}
	const scanId = window.phpcompat.scanId;
	const note = ( text ) => {
		if ( scanId === window.phpcompat.scanId ) {
			progressNote().text( text );
		}
	};
	window.phpcompat.running = true;
	getEngine( note ).then(
		() => note( '' ),
		() => note( '' )
	);
}

function progressNote() {
	let note = progress.find( '.wpe-pcc-progress-note' );
	if ( ! note.length ) {
		note = $( '<span class="wpe-pcc-progress-note"></span>' );
		progressCount.after( note );
	}
	return note;
}

function resetProgress() {
	$( '.wpe-pcc-spinner' ).hide();
	progressCount.text( '' );
	progress.find( '.wpe-pcc-progress-note' ).remove();
	progress.hide();
}

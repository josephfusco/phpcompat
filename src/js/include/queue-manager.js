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
	cancelScans,
	ScanError,
	startScanSession,
	endScanSession,
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
		// Stop sending batches; the next scan starts fresh.
		cancelScans();
		window.phpcompat.running = false;
	}
	// Let go of the site's scan lock (does nothing if no scan was running).
	endScanSession();

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
 * Scan one item. The site returns the stored result instead when the item's
 * files haven't changed since it was scanned with the same rules.
 *
 * @param {Object}     job Queued item.
 * @param {() => void} cb  Called when done (not called if the scan was reset).
 */
export async function executeJob( job, cb ) {
	const scanId = window.phpcompat.scanId;
	const current = () => scanId === window.phpcompat.scanId;

	setItemStatus( job, __( 'Scanning…', 'php-compatibility-checker' ), true );
	window.phpcompat.running = true;

	try {
		const { report, source, fingerprint, cached } = await scanItem(
			job,
			( text, files ) => {
				if ( current() ) {
					setItemStatus( job, text, true, files );
				}
			}
		);
		if ( ! current() ) {
			return;
		}
		window.phpcompat.results.push( job );
		updateResult( report, job );
		updateProgress();
		if ( ! cached ) {
			saveResult( job, report, source, fingerprint );
		}
	} catch ( error ) {
		if ( ! current() ) {
			return;
		}
		// Someone else is scanning the site: every item would be refused the
		// same way, so stop the whole scan and say so once (see scan.js).
		if ( error instanceof ScanError && 'busy' === error.status ) {
			window.phpcompat.running = false;
			$( document ).trigger( 'phpcompat:busy', [ error.message ] );
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
		endScanSession();
		resetProgress();
		showDownload();
		finishResults();
		$( 'input[type=radio][name=active_plugins]' ).prop( 'disabled', false );
		// Ready to scan again: unchanged items come back from storage at once.
		window.phpcompat.finished = true;
		// One primary action at a time: with results on screen, that's the download.
		$( '#runButton' )
			.prop( 'disabled', false )
			.removeClass( 'button-primary' )
			.val( __( 'Scan again', 'php-compatibility-checker' ) );

		// Only offer help when something actually failed.
		if ( $( '[data-result="problems"]' ).length ) {
			$( '.wpe-pcc-information' ).show();
			showPlayground();
		}
		return;
	}

	// One item at a time, one request at a time: a scan uses at most one PHP worker.
	executeJob( window.phpcompat.queue.shift(), runNextJob );
}

export function updateProgress() {
	window.phpcompat.completed++;
	showProgressCount();
}

/**
 * "1 of 3": the item being scanned now, not the number finished, so the
 * first item doesn't read "0 of 3" while a large plugin takes a minute.
 */
function showProgressCount() {
	const { completed, total } = window.phpcompat;
	progressCount.text(
		sprintf(
			/* translators: 1: number of the item being scanned, 2: total number to scan. */
			__( '%1$d of %2$d', 'php-compatibility-checker' ),
			Math.min( completed + 1, total ),
			total
		)
	);
}

/**
 * Show the spinner and "Now scanning" at the start of a scan.
 */
export function startProgress() {
	startScanSession();
	$( '.wpe-pcc-spinner' ).show();
	progress.show();
	showProgressCount();
	window.phpcompat.queue.forEach( ( job ) =>
		setItemStatus( job, __( 'Waiting…', 'php-compatibility-checker' ) )
	);
}

function resetProgress() {
	$( '.wpe-pcc-spinner' ).hide();
	progressCount.text( '' );
	progress.hide();
}

import {
	updateResultFailure,
	updateResult,
	finishResults,
	setItemStatus,
} from './render';
import $ from 'jquery';
import { hideDownload, showDownload } from './download';
import { __, sprintf } from '@wordpress/i18n';

// Tide audits new versions on demand. Poll every 5 seconds for up to 2 minutes,
// then report the item as still pending instead of spinning forever.
const RETRY_DELAY = 5000;
const MAX_ATTEMPTS = 24;

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
	// Reset the queue.
	window.phpcompat.queue = [];
	window.phpcompat.results = [];
	window.phpcompat.report = [];
	window.phpcompat.total = 0;
	window.phpcompat.completed = 0;

	clearTimeout( window.phpcompat.ticker );
	if ( window.phpcompat.xhr ) {
		window.phpcompat.xhr.abort();
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

export function executeJob( job, cb ) {
	const endpoint = `https://wptide.org/api/v1/audit/wporg/${ job.type }/${ encodeURIComponent( job.slug ) }/${ encodeURIComponent( job.version ) }?reports=phpcs_phpcompatibilitywp`;

	// Only allow 1 concurrent request at a time
	if (
		false === window.phpcompat.xhr ||
		[ 0, 4 ].includes( window.phpcompat.xhr.readyState )
	) {
		window.phpcompat.xhr = $.ajax( endpoint, {
			dataType: 'json',
			timeout: 30000,
			beforeSend: () => {
				if ( ! job.attempts ) {
					setItemStatus(
						job,
						__( 'Scanning…', 'php-compatibility-checker' ),
						true
					);
				}
			},
		} )
			.done( ( response ) => {
				if ( 'complete' === response.status ) {
					window.phpcompat.results.push( { ...job, ...response } );
					updateResult( response, job );
					updateProgress();
				} else if ( 'pending' === response.status ) {
					job.attempts = ( job.attempts || 1 ) + 1;

					if ( job.attempts > MAX_ATTEMPTS ) {
						updateProgress();
						updateResultFailure(
							{
								status: 'skipped',
								message: sprintf(
									/* translators: 1: plugin or theme name, 2: version number. */
									__(
										'Tide is still auditing %1$s %2$s. Scan it again in a few minutes.',
										'php-compatibility-checker'
									),
									job.name,
									job.version
								),
							},
							job
						);
					} else {
						showWaiting( job );
						job.retryAt = new Date(
							new Date().getTime() + RETRY_DELAY
						);
						window.phpcompat.queue.push( job );
					}
				} else {
					updateProgress();
					// Unexpected behaviour. Stop scanning and display current status.
					updateResultFailure( response, job );
				}
			} )
			.fail( ( jqXHR, textStatus ) => {
				// Aborted by "Start over": the scan was reset, so leave the
				// new scan's progress and results alone.
				if ( 'abort' === textStatus ) {
					return;
				}

				updateProgress();

				if ( 'timeout' === textStatus ) {
					updateResultFailure(
						{
							status: 'failed',
							message: __(
								'WP Tide took too long to respond. Please scan again.',
								'php-compatibility-checker'
							),
						},
						job
					);
				} else if ( 0 === jqXHR.status ) {
					// No HTTP response: offline, or the request was blocked
					// (firewall, proxy, or browser extension).
					updateResultFailure(
						{
							status: 'failed',
							message: __(
								'Could not connect to WP Tide (wptide.org). Check your internet connection, and that a firewall or browser extension is not blocking it, then scan again.',
								'php-compatibility-checker'
							),
						},
						job
					);
				} else if ( 404 === jqXHR.status ) {
					// Tide only audits plugins and themes hosted on WordPress.org.
					updateResultFailure(
						{
							status: 'skipped',
							message: sprintf(
								/* translators: 1: plugin or theme name, 2: version number. */
								__(
									'%1$s %2$s could not be scanned. Only plugins and themes available on WordPress.org can be checked, so premium, custom, or modified versions are skipped.',
									'php-compatibility-checker'
								),
								job.name,
								job.version
							),
						},
						job
					);
				} else {
					updateResultFailure(
						jqXHR.responseJSON ?? {
							status: jqXHR.status,
							message: jqXHR.responseText,
						},
						job
					);
				}
			} )
			.always( ( data, textStatus ) => {
				if ( 'abort' !== textStatus ) {
					cb();
				}
			} );
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

/**
 * Tell the user an item is waiting on Tide rather than stuck.
 *
 * @param {Object} job Queued item.
 */
function showWaiting( job ) {
	setItemStatus(
		job,
		__(
			'Tide is auditing this version for the first time. This can take a couple of minutes.',
			'php-compatibility-checker'
		),
		true
	);
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

	const now = new Date();

	// Pick up next job from queue.
	const job = window.phpcompat.queue.shift();

	// The job is new or the time has come.
	if ( 'undefined' === typeof job.retryAt || job.retryAt <= now ) {
		// Run the job now.
		executeJob( job, () => {
			runNextJob();
		} );
	} else {
		// Or put it back to the end of queue.
		window.phpcompat.queue.push( job );
		window.phpcompat.ticker = setTimeout( runNextJob, 1000 );
	}
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
}

function resetProgress() {
	$( '.wpe-pcc-spinner' ).hide();
	progressCount.text( '' );
	progress.hide();
}

import $ from 'jquery';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Compare dotted version strings numerically ("7.10" > "7.9").
 *
 * @param {string} a Version.
 * @param {string} b Version.
 * @return {number} Negative, zero, or positive.
 */
export function compareVersions( a, b ) {
	const pa = String( a ).split( '.' ).map( Number );
	const pb = String( b ).split( '.' ).map( Number );
	for ( let i = 0; i < Math.max( pa.length, pb.length ); i++ ) {
		const diff = ( pa[ i ] || 0 ) - ( pb[ i ] || 0 );
		if ( diff ) {
			return diff;
		}
	}
	return 0;
}

/**
 * Whether a file is part of a bundled third-party library. Libraries often
 * check for a PHP feature before using it, which static analysis can't see,
 * so their problems are frequently false positives.
 *
 * @param {string} file Path relative to the plugin or theme.
 * @return {boolean} Whether the file is bundled library code.
 */
export function isBundledLibrary( file ) {
	return /(^|\/)(vendor|vendor_prefixed|vendor-prefixed|third-party|node_modules)\//i.test(
		file
	);
}

/**
 * Render a result card with the core wp.template() helper.
 *
 * @param {Object} view Template data.
 * @return {string} HTML.
 */
function renderCard( view ) {
	return wp.template( 'wpe-pcc-result' )( view );
}

/**
 * List the plugins and themes the selected option will scan.
 *
 * @param {Object} itemsToScan Plugins and themes from the page.
 * @param {string} mode        "yes" (active only), "no" (all), or "choose".
 */
export function initResults( itemsToScan, mode ) {
	const container = $( '#wpe_pcc_results' );
	container.empty();

	const groups = [
		{
			items: itemsToScan.plugins,
			type: 'plugin',
			heading: __( 'Plugins', 'php-compatibility-checker' ),
		},
		{
			items: itemsToScan.themes,
			type: 'theme',
			heading: __( 'Themes', 'php-compatibility-checker' ),
		},
	];

	groups.forEach( ( { items, type, heading } ) => {
		const shown = items.filter(
			( item ) => 'yes' !== mode || 'yes' === item.active
		);
		if ( ! shown.length ) {
			return;
		}

		container.append( $( '<h3>' ).text( heading ) );
		shown.forEach( ( item ) => {
			container.append(
				renderCard( {
					...item,
					type,
					status: 'pending',
					inactive: 'yes' !== item.active,
					selectable: 'choose' === mode,
				} )
			);
		} );
	} );

	$( '.wpe-pcc-results' ).show();
}

/**
 * IDs ("type_slug") of the ticked items in "choose" mode.
 *
 * @return {string[]} Selected item IDs.
 */
export function getSelectedIds() {
	return $( '.wpe-pcc-select:checked' )
		.map( ( i, el ) => el.value )
		.get();
}

/**
 * Show progress on an item's card, next to its name.
 *
 * @param {Object}  job      Queued item.
 * @param {string}  text     Status text.
 * @param {boolean} spinning Show the spinner.
 */
export function setItemStatus( job, text, spinning = false ) {
	const card = $( `#${ job.type }_${ job.slug }` );
	card.find( '.wpe-pcc-status' ).text( text );
	if ( spinning && ! card.find( '.spinner' ).length ) {
		$( '<span class="spinner"></span>' ).show().appendTo( card );
	}
}

/**
 * "46 errors, 2 warnings" style count.
 *
 * @param {number} errors   Error count.
 * @param {number} warnings Warning count.
 * @return {string} Count.
 */
function countLabel( errors, warnings ) {
	const parts = [];
	if ( errors ) {
		parts.push(
			sprintf(
				/* translators: %d: number of errors. */
				_n(
					'%d error',
					'%d errors',
					errors,
					'php-compatibility-checker'
				),
				errors
			)
		);
	}
	if ( warnings ) {
		parts.push(
			sprintf(
				/* translators: %d: number of warnings. */
				_n(
					'%d warning',
					'%d warnings',
					warnings,
					'php-compatibility-checker'
				),
				warnings
			)
		);
	}
	return parts.join( ', ' );
}

export function updateResult( response, job ) {
	const report = response.reports?.phpcs_phpcompatibilitywp?.report;

	// If we have a success response but don't have report data, show an error.
	if ( ! report ) {
		updateResultFailure(
			{
				status: 'failed',
				message: __(
					'No scan results found.',
					'php-compatibility-checker'
				),
			},
			job
		);
		return;
	}

	const phpVersions = Object.keys( report.versions ).sort( compareVersions );

	// Versions below the item's own "Requires PHP" can never run it, so problems
	// there aren't real incompatibilities. Show them as "not counted" instead.
	const requiresPhp = job.requires_php || '';
	const isUnsupported = ( phpVersion ) =>
		'' !== requiresPhp && compareVersions( phpVersion, requiresPhp ) < 0;

	// If every reported version is below the minimum, nothing it can run on was
	// checked (e.g. it requires PHP 8.3 but results stop at 8.0). Don't show that
	// as a pass.
	if ( phpVersions.length && phpVersions.every( isUnsupported ) ) {
		updateResultFailure(
			{
				status: 'skipped',
				message: sprintf(
					/* translators: 1: plugin or theme name, 2: minimum PHP version, 3: newest PHP version with results. */
					__(
						'%1$s was not checked. It requires PHP %2$s or newer, and results only go up to PHP %3$s.',
						'php-compatibility-checker'
					),
					job.name,
					requiresPhp,
					phpVersions[ phpVersions.length - 1 ]
				),
			},
			job
		);
		return;
	}

	const hasProblems = phpVersions.some( ( phpVersion ) => {
		if ( isUnsupported( phpVersion ) ) {
			return false;
		}
		const totals = report.versions[ phpVersion ]?.totals || {};
		return (
			report.incompatible.includes( phpVersion ) ||
			totals.errors > 0 ||
			totals.warnings > 0
		);
	} );

	const view = {
		...job,
		inactive: 'yes' !== job.active,
		status: hasProblems ? 'error' : 'success',
		badge: hasProblems ? 'problems' : 'success',
		has_errors: false,
		has_unsupported: phpVersions.some( isUnsupported ),
		php: [],
		reports: [],
	};

	const entryPhp = {};
	const entryProblems = [];
	let rawReport = `${ job.name } ${ job.version }\n\n`;

	phpVersions.forEach( ( phpVersion ) => {
		const id = phpVersion.replace( /\W/g, '-' );

		if ( isUnsupported( phpVersion ) ) {
			entryPhp[ phpVersion ] = 'not_counted';
			view.php.push( { phpversion: phpVersion, id, unsupported: true } );
			rawReport += `PHP ${ phpVersion }: not counted (requires PHP ${ requiresPhp })\n`;
			return;
		}

		const incompatible = report.incompatible.includes( phpVersion );
		entryPhp[ phpVersion ] = incompatible ? 'fail' : 'pass';
		view.php.push( { phpversion: phpVersion, id, passed: ! incompatible } );

		if ( ! incompatible ) {
			rawReport += `Compatible with PHP ${ phpVersion }\n`;
			return;
		}

		view.has_errors = true;
		const files = report.versions[ phpVersion ].files;
		const fileReports = [];

		Object.keys( files ).forEach( ( file ) => {
			if ( ! files[ file ].messages.length ) {
				return;
			}
			fileReports.push( processFileReport( file, files[ file ] ) );
			files[ file ].messages.forEach( ( message ) => {
				entryProblems.push( {
					php: phpVersion,
					file,
					line: message.line,
					type: message.type,
					message: message.message,
					source: message.source,
				} );
			} );
		} );

		const versionReport =
			`PHP ${ phpVersion } incompatibilities:\n\n` +
			fileReports.join( '\n\n' ) +
			'\n\n';
		rawReport += versionReport;
		view.reports.push( {
			phpversion: phpVersion,
			id,
			text: versionReport,
		} );
	} );

	if ( view.has_errors ) {
		const totals = report.versions[ view.reports[ 0 ].phpversion ].totals;
		view.status_text = countLabel(
			totals?.errors || 0,
			totals?.warnings || 0
		);
		view.bundled_only = entryProblems.every( ( problem ) =>
			isBundledLibrary( problem.file )
		);
	}

	$( `#${ job.type }_${ job.slug }` ).replaceWith( renderCard( view ) );

	recordResult( job, {
		status: hasProblems ? 'problems' : 'passes',
		reason: '',
		php: entryPhp,
		problems: entryProblems,
	} );
	appendRawReport( rawReport );
}

export function processFileReport( fileName, fileReport ) {
	const widths = [ 0, 0, 0 ];
	fileReport.messages.forEach( ( message ) => {
		widths[ 0 ] = Math.max( widths[ 0 ], `${ message.line }`.length );
		widths[ 1 ] = Math.max( widths[ 1 ], `${ message.type }`.length );
		widths[ 2 ] = Math.max( widths[ 2 ], `${ message.message }`.length );
	} );

	const uniqueLines = new Set(
		fileReport.messages.map( ( message ) => message.line )
	);

	const plainMessages = fileReport.messages.map( ( message ) => {
		const col1 = `${ message.line }`.padStart( widths[ 0 ], ' ' );
		const col2 = `${ message.type }`.padEnd( widths[ 1 ], ' ' );
		const col3 = `${ message.message }`.padEnd( widths[ 2 ], ' ' );

		return ` ${ col1 } | ${ col2 } | ${ col3 } `;
	} );

	const header = `FILE: ${ fileName }`;
	const found = `FOUND ${ fileReport.errors } ERRORS AND ${ fileReport.warnings } WARNINGS AFFECTING ${ uniqueLines.size } LINES`;
	const hr = '-'.repeat(
		Math.max( header.length, found.length, plainMessages[ 0 ].length )
	);

	return (
		`${ header }\n${ hr }\n${ found }\n${ hr }\n` +
		plainMessages.join( '\n' ) +
		`\n${ hr }`
	);
}

export function updateResultFailure( response, job ) {
	const skipped = 'skipped' === response.status;
	const message =
		response.message ||
		( response.errors || [] )
			.map( ( error ) => error.message )
			.join( ' ' ) ||
		sprintf(
			/* translators: %s: response status. */
			__( 'Response status: %s', 'php-compatibility-checker' ),
			response.status
		);

	$( `#${ job.type }_${ job.slug }` ).replaceWith(
		renderCard( {
			...job,
			inactive: 'yes' !== job.active,
			status: skipped ? 'skipped' : 'error',
			badge: skipped ? 'skipped' : 'failed',
			status_text: skipped
				? __( 'Not checked', 'php-compatibility-checker' )
				: __( 'Scan failed', 'php-compatibility-checker' ),
			message,
		} )
	);

	recordResult( job, {
		status: skipped ? 'not_checked' : 'failed',
		reason: response.message || '',
	} );

	let rawReport = `${ job.name } ${ job.version }\n\n`;
	if ( response.status ) {
		rawReport += `Scan status: ${ response.status }\n`;
	}
	appendRawReport( rawReport + message + '\n\n' );
}

/**
 * Add an item's text to the raw report.
 *
 * @param {string} text Item report.
 */
function appendRawReport( text ) {
	const field = $( '#testResults' );
	const current = field.val();
	field.val( current + ( current.length ? '\n\n\n' : '' ) + text );
}

/**
 * Keep a structured copy of each result for the downloadable reports.
 *
 * @param {Object} job    Scanned item.
 * @param {Object} result Status, reason, and (when scanned) php and problems.
 */
function recordResult( job, result ) {
	window.phpcompat.report.push( {
		type: job.type,
		name: job.name,
		slug: job.slug,
		version: job.version,
		active: 'yes' === job.active,
		requires_php: job.requires_php || '',
		update_version: job.update_version || '',
		php: {},
		problems: [],
		...result,
	} );
}

/**
 * Announce the outcome when a scan finishes.
 */
export function finishResults() {
	const count = ( status ) =>
		window.phpcompat.report.filter( ( item ) => status === item.status )
			.length;
	speak(
		sprintf(
			/* translators: 1: passing count, 2: count with problems, 3: not checked count, 4: failed count. */
			__(
				'Scan complete: %1$d pass, %2$d with problems, %3$d not checked, %4$d failed.',
				'php-compatibility-checker'
			),
			count( 'passes' ),
			count( 'problems' ),
			count( 'not_checked' ),
			count( 'failed' )
		)
	);
}

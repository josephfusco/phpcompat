/* global checkerList */
import $ from 'jquery';
import { compareVersions } from './render';

export function showDownload() {
	$( '#wpe-pcc-download-report' ).show();
}

export function hideDownload() {
	$( '#wpe-pcc-download-report' ).hide();
}

/**
 * Download the scan results.
 *
 * @param {string} format "txt", "csv", or "json".
 */
export function downloadReport( format = 'txt' ) {
	const items = window.phpcompat.report;
	if ( ! items.length ) {
		return;
	}

	const meta = reportMeta( items );
	const builders = {
		txt: () => [ textReport( meta ), 'text/plain' ],
		csv: () => [ csvReport( items ), 'text/csv' ],
		json: () => [
			JSON.stringify( { ...meta, items }, null, 2 ),
			'application/json',
		],
	};
	const [ content, type ] = ( builders[ format ] || builders.txt )();
	const date = meta.generated_at.slice( 0, 10 );
	const host = meta.site.url
		.replace( /^https?:\/\//, '' )
		.replace( /[^a-z0-9.-]+/gi, '-' );

	save(
		content,
		`${ type };charset=utf-8`,
		`php-compatibility-${ host }-${ date }.${ builders[ format ] ? format : 'txt' }`
	);
}

/**
 * Report header data shared by every format.
 *
 * @param {Object[]} items Recorded results.
 * @return {Object} Metadata.
 */
export function reportMeta( items ) {
	const summary = { passes: 0, problems: 0, not_checked: 0, failed: 0 };
	let maxPhp = '';

	items.forEach( ( item ) => {
		summary[ item.status ]++;
		Object.keys( item.php ).forEach( ( version ) => {
			if ( ! maxPhp || compareVersions( version, maxPhp ) > 0 ) {
				maxPhp = version;
			}
		} );
	} );

	return {
		generator: 'PHP Compatibility Checker',
		generated_at: new Date().toISOString(),
		site: checkerList.site || { url: window.location.origin },
		source:
			checkerList.scanner?.tools ||
			`PHPCompatibilityWP (${ checkerList.scanner?.ruleset || '' })`,
		scope: globalThis.phpcompat?.scope || 'yes',
		max_php_checked: maxPhp,
		summary,
	};
}

/**
 * Plain-text report: a header, then the per-item text shown in raw mode.
 *
 * @param {Object} meta Report metadata.
 * @return {string} Report.
 */
function textReport( meta ) {
	const { site, summary } = meta;
	const lines = [
		site.plugin_version
			? `PHP Compatibility Checker ${ site.plugin_version } report`
			: 'PHP Compatibility Checker report',
		'',
		`Site:        ${ site.url }`,
		`Generated:   ${ meta.generated_at }`,
	];
	if ( site.php ) {
		lines.push( `Server PHP:  ${ site.php }` );
	}
	if ( site.wp ) {
		lines.push( `WordPress:   ${ site.wp }` );
	}
	lines.push(
		`Results:     ${ meta.source }, PHP versions up to ${ meta.max_php_checked || 'n/a' }`,
		`Summary:     ${ summary.passes } pass, ${ summary.problems } with problems, ${ summary.not_checked } not checked, ${ summary.failed } failed to scan`,
		'',
		'='.repeat( 72 ),
		'',
		$( '#testResults' ).val()
	);
	return lines.join( '\n' );
}

/**
 * CSV report: one row per problem, or one row for an item without problems.
 *
 * @param {Object[]} items Recorded results.
 * @return {string} Report.
 */
export function csvReport( items ) {
	const header = [
		'type',
		'name',
		'slug',
		'version',
		'active',
		'status',
		'reason',
		'php',
		'file',
		'line',
		'severity',
		'message',
	];
	const rows = [ header ];

	items.forEach( ( item ) => {
		const base = [
			item.type,
			item.name,
			item.slug,
			item.version,
			item.active ? 'yes' : 'no',
			item.status,
			item.reason,
		];
		if ( ! item.problems.length ) {
			rows.push( [ ...base, '', '', '', '', '' ] );
			return;
		}
		item.problems.forEach( ( problem ) => {
			rows.push( [
				...base,
				problem.php,
				problem.file,
				problem.line,
				problem.type,
				problem.message,
			] );
		} );
	} );

	return rows.map( ( row ) => row.map( csvCell ).join( ',' ) ).join( '\r\n' );
}

/**
 * Quote a CSV cell, and keep spreadsheets from treating text as a formula.
 *
 * @param {string|number|undefined} value Cell value.
 * @return {string} Escaped cell.
 */
export function csvCell( value ) {
	let text = String( value ?? '' );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = `'${ text }`;
	}
	return /[",\r\n]/.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
}

/**
 * Save content as a file.
 *
 * @param {string} content  File content.
 * @param {string} type     MIME type.
 * @param {string} filename File name.
 */
function save( content, type, filename ) {
	const url = URL.createObjectURL( new Blob( [ content ], { type } ) );
	const el = document.createElement( 'a' );
	el.href = url;
	el.download = filename;
	el.style.display = 'none';
	document.body.appendChild( el );
	el.click();
	document.body.removeChild( el );
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

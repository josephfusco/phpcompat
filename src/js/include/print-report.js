/**
 * The PDF report: an accessible HTML document, saved as PDF with the
 * browser's print dialog. Browsers that tag PDFs (Chrome, Edge) carry the
 * headings, tables, language, and title into the PDF for screen readers.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { isBundledLibrary, compareVersions } from './render';

/**
 * Escape text for HTML.
 *
 * @param {string|number} value Text.
 * @return {string} Escaped text.
 */
function esc( value ) {
	return String( value ?? '' ).replace(
		/[&<>"']/g,
		( c ) =>
			( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			} )[ c ]
	);
}

/**
 * Page language, for the document and for dates and lists.
 *
 * @return {string|undefined} BCP 47 language tag.
 */
function pageLang() {
	return globalThis.document?.documentElement?.lang || undefined;
}

/**
 * Merge identical problems reported for several PHP versions into one row.
 *
 * @param {Object[]} problems Problems from the recorded result.
 * @return {Object[]} Files, each with rows listing the PHP versions affected.
 */
export function groupProblems( problems ) {
	const files = new Map();

	problems.forEach( ( problem ) => {
		if ( ! files.has( problem.file ) ) {
			files.set( problem.file, new Map() );
		}
		const rows = files.get( problem.file );
		const key = `${ problem.line }\u0000${ problem.type }\u0000${ problem.message }`;
		if ( ! rows.has( key ) ) {
			rows.set( key, { ...problem, versions: [] } );
		}
		const row = rows.get( key );
		if ( ! row.versions.includes( problem.php ) ) {
			row.versions.push( problem.php );
		}
	} );

	return [ ...files ].map( ( [ file, rows ] ) => ( {
		file,
		rows: [ ...rows.values() ].sort( ( a, b ) => a.line - b.line ),
	} ) );
}

/**
 * Collapse rows with the same message into one row listing every line, so a
 * deprecated function used 20 times reads as one problem, not 20.
 *
 * @param {Object[]} rows Rows from groupProblems() for one file.
 * @return {Object[]} Rows with `lines` and `versions`, in order of first line.
 */
export function groupByMessage( rows ) {
	const messages = new Map();
	rows.forEach( ( row ) => {
		const key = `${ row.type }\u0000${ row.message }`;
		if ( ! messages.has( key ) ) {
			messages.set( key, {
				type: row.type,
				message: row.message,
				lines: [],
				versions: [],
			} );
		}
		const entry = messages.get( key );
		if ( ! entry.lines.includes( row.line ) ) {
			entry.lines.push( row.line );
		}
		row.versions.forEach( ( version ) => {
			if ( ! entry.versions.includes( version ) ) {
				entry.versions.push( version );
			}
		} );
	} );

	return [ ...messages.values() ]
		.map( ( entry ) => ( {
			...entry,
			lines: entry.lines.sort( ( a, b ) => a - b ),
			versions: entry.versions.sort( compareVersions ),
		} ) )
		.sort( ( a, b ) => a.lines[ 0 ] - b.lines[ 0 ] );
}

/**
 * Whether every problem in an item is in a bundled library.
 *
 * @param {Object} item Recorded result.
 * @return {boolean} Bundled only.
 */
export function bundledOnly( item ) {
	return (
		item.problems.length > 0 &&
		item.problems.every( ( problem ) => isBundledLibrary( problem.file ) )
	);
}

/**
 * One recommended action for an item with problems.
 *
 * @param {Object} item Recorded result.
 * @return {string} Recommendation.
 */
export function recommendation( item ) {
	if ( item.update_version ) {
		return sprintf(
			/* translators: %s: version number. */
			__( 'Update to %s, then scan again.', 'php-compatibility-checker' ),
			item.update_version
		);
	}
	if ( bundledOnly( item ) ) {
		return __(
			'Test it on a newer PHP version before treating these as blockers; every problem is in a bundled library.',
			'php-compatibility-checker'
		);
	}
	return __(
		'Ask the developer about PHP support, or look for an alternative.',
		'php-compatibility-checker'
	);
}

/**
 * Verdict and next steps for the start of the report.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata.
 * @return {{verdict: string, steps: string[]}} Summary.
 */
export function executiveSummary( items, meta ) {
	const problems = items.filter( ( item ) => 'problems' === item.status );
	const checked =
		items.length - meta.summary.not_checked - ( meta.summary.failed || 0 );
	const maxPhp = meta.max_php_checked || '–';
	const names = ( list ) => {
		const values = list.map( ( item ) => item.name );
		try {
			return new Intl.ListFormat( pageLang(), {
				type: 'conjunction',
			} ).format( values );
		} catch {
			return values.join( ', ' );
		}
	};

	const verdict = problems.length
		? sprintf(
				/* translators: 1: number with problems, 2: number checked, 3: newest PHP version checked. */
				_n(
					'%1$d of %2$d checked plugin or theme has problems on PHP up to %3$s.',
					'%1$d of %2$d checked plugins and themes have problems on PHP up to %3$s.',
					checked,
					'php-compatibility-checker'
				),
				problems.length,
				checked,
				maxPhp
			)
		: sprintf(
				/* translators: 1: number checked, 2: newest PHP version checked. */
				_n(
					'No problems found in the %1$d plugin or theme checked, on PHP up to %2$s.',
					'No problems found in the %1$d plugins and themes checked, on PHP up to %2$s.',
					checked,
					'php-compatibility-checker'
				),
				checked,
				maxPhp
			);

	const toUpdate = problems.filter( ( item ) => item.update_version );
	const toTest = problems.filter(
		( item ) => ! item.update_version && bundledOnly( item )
	);
	const toContact = problems.filter(
		( item ) => ! item.update_version && ! bundledOnly( item )
	);

	const steps = [
		toUpdate.length &&
			sprintf(
				/* translators: %s: list of plugin and theme names. */
				__(
					'Update %s, then scan again: a newer version may already be compatible.',
					'php-compatibility-checker'
				),
				names( toUpdate )
			),
		toTest.length &&
			sprintf(
				/* translators: %s: list of plugin and theme names. */
				__(
					'Test %s on a newer PHP version (WordPress Playground or a staging site). Every problem is in a bundled library, so these may be false positives.',
					'php-compatibility-checker'
				),
				names( toTest )
			),
		toContact.length &&
			sprintf(
				/* translators: %s: list of plugin and theme names. */
				__(
					'Ask the developers of %s about PHP support, or look for an alternative.',
					'php-compatibility-checker'
				),
				names( toContact )
			),
		meta.summary.failed &&
			__(
				'Scan again to check the items that failed to scan.',
				'php-compatibility-checker'
			),
	].filter( Boolean );

	return { verdict, steps };
}

/**
 * Text for a double-quoted CSS string (e.g. in `@page` content), where HTML
 * entities aren't decoded.
 *
 * @param {string} value Text.
 * @return {string} Escaped text.
 */
function cssString( value ) {
	return String( value ).replace(
		/["\\\n<]/g,
		( char ) => `\\${ char.charCodeAt( 0 ).toString( 16 ) } `
	);
}

/**
 * Build the report document.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata (site, summary, dates).
 * @return {string} HTML document.
 */
export function buildReportHtml( items, meta ) {
	const { site, summary } = meta;
	const order = [ 'problems', 'failed', 'not_checked', 'passes' ];
	const sorted = [ ...items ].sort(
		( a, b ) =>
			order.indexOf( a.status ) - order.indexOf( b.status ) ||
			a.name.localeCompare( b.name )
	);
	const byStatus = ( status ) =>
		sorted.filter( ( item ) => status === item.status );
	const typeLabel = ( item ) =>
		'theme' === item.type
			? __( 'Theme', 'php-compatibility-checker' )
			: __( 'Plugin', 'php-compatibility-checker' );
	const versionsWith = ( item, state ) =>
		Object.keys( item.php )
			.filter( ( version ) => state === item.php[ version ] )
			.sort( compareVersions );
	const statusLabel = {
		problems: __( 'Problems', 'php-compatibility-checker' ),
		failed: __( 'Scan failed', 'php-compatibility-checker' ),
		not_checked: __( 'Not checked', 'php-compatibility-checker' ),
		passes: __( 'Passes', 'php-compatibility-checker' ),
	};
	const inactive = ( item ) =>
		item.active
			? ''
			: ` <span class="muted">(${ esc(
					__( 'inactive', 'php-compatibility-checker' )
				) })</span>`;

	let generated = meta.generated_at;
	try {
		generated = new Date( meta.generated_at ).toLocaleString( pageLang(), {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
			timeZoneName: 'short',
		} );
	} catch {}

	const scope =
		{
			yes: __( 'Active plugins and theme', 'php-compatibility-checker' ),
			no: __( 'All plugins and themes', 'php-compatibility-checker' ),
			choose: __(
				'Selected plugins and themes',
				'php-compatibility-checker'
			),
		}[ meta.scope ] ||
		__( 'Plugins and themes', 'php-compatibility-checker' );

	const reasonFor = ( item ) => {
		if ( 'failed' === item.status ) {
			return item.reason;
		}
		if (
			item.requires_php &&
			meta.max_php_checked &&
			compareVersions( item.requires_php, meta.max_php_checked ) > 0
		) {
			return sprintf(
				/* translators: 1: minimum PHP version, 2: newest PHP version with results. */
				__(
					'Requires PHP %1$s or newer; results only go up to PHP %2$s.',
					'php-compatibility-checker'
				),
				item.requires_php,
				meta.max_php_checked
			);
		}
		// The scan's own explanation (e.g. no PHP files), when it gave one.
		return (
			item.reason ||
			__(
				'Not on WordPress.org (premium, custom, or modified).',
				'php-compatibility-checker'
			)
		);
	};

	const phpResult = ( item ) => {
		if ( 'problems' === item.status ) {
			const fails = sprintf(
				/* translators: %s: PHP versions. */
				__( 'On PHP %s', 'php-compatibility-checker' ),
				versionsWith( item, 'fail' ).join( ', ' )
			);
			return item.update_version
				? sprintf(
						/* translators: 1: "On PHP 7.4, 8.0", 2: version number. */
						__(
							'%1$s; update to %2$s available',
							'php-compatibility-checker'
						),
						fails,
						item.update_version
					)
				: fails;
		}
		if ( 'passes' === item.status ) {
			return sprintf(
				/* translators: %s: PHP versions. */
				__( 'On PHP %s', 'php-compatibility-checker' ),
				versionsWith( item, 'pass' ).join( ', ' )
			);
		}
		return reasonFor( item );
	};

	const playground = ( list ) => {
		const params = new URLSearchParams( { php: 'latest' } );
		list.forEach( ( item ) => params.append( item.type, item.slug ) );
		return `https://playground.wordpress.net/?${ params.toString() }`;
	};

	const { verdict, steps } = executiveSummary( items, meta );
	const problemItems = byStatus( 'problems' );

	const facts = [
		[
			__( 'Site', 'php-compatibility-checker' ),
			`<a href="${ esc( site.url ) }">${ esc( site.url ) }</a>`,
		],
		[ __( 'Generated', 'php-compatibility-checker' ), esc( generated ) ],
		[ __( 'Server PHP', 'php-compatibility-checker' ), esc( site.php ) ],
		[ __( 'WordPress', 'php-compatibility-checker' ), esc( site.wp ) ],
		[
			__( 'Results', 'php-compatibility-checker' ),
			esc(
				sprintf(
					/* translators: 1: data source, 2: newest PHP version checked. */
					__( '%1$s, up to PHP %2$s', 'php-compatibility-checker' ),
					meta.source,
					meta.max_php_checked || '–'
				)
			),
		],
		[
			__( 'Scope', 'php-compatibility-checker' ),
			esc(
				sprintf(
					/* translators: 1: what was scanned, 2: number of items. */
					_n(
						'%1$s (%2$d item)',
						'%1$s (%2$d items)',
						items.length,
						'php-compatibility-checker'
					),
					scope,
					items.length
				)
			),
		],
	].filter( ( [ , value ] ) => value );

	const tiles = [
		[ 'problems', __( 'With problems', 'php-compatibility-checker' ) ],
		[ 'failed', __( 'Failed to scan', 'php-compatibility-checker' ) ],
		[ 'not_checked', __( 'Not checked', 'php-compatibility-checker' ) ],
		[ 'passes', __( 'Pass', 'php-compatibility-checker' ) ],
	].filter( ( [ key ] ) => 'failed' !== key || summary.failed );

	const problemSections = problemItems
		.map( ( item, index ) => {
			const passes = versionsWith( item, 'pass' );
			const facts_ = [
				sprintf(
					/* translators: %s: PHP versions with problems. */
					__( 'Problems on PHP %s.', 'php-compatibility-checker' ),
					versionsWith( item, 'fail' ).join( ', ' )
				),
				passes.length &&
					sprintf(
						/* translators: %s: PHP versions that pass. */
						__( 'Passes on PHP %s.', 'php-compatibility-checker' ),
						passes.join( ', ' )
					),
				item.requires_php &&
					sprintf(
						/* translators: %s: minimum PHP version. */
						__( 'Requires PHP %s.', 'php-compatibility-checker' ),
						item.requires_php
					),
			].filter( Boolean );

			const fails = versionsWith( item, 'fail' ).join( ', ' );
			const files = groupProblems( item.problems )
				.map( ( file, fileIndex ) => {
					const captionId = `item-${ index }-file-${ fileIndex }`;
					const rows = groupByMessage( file.rows );
					// Only show versions per row when they differ from the item's.
					const showPhp = rows.some(
						( row ) => row.versions.join( ', ' ) !== fails
					);
					return `
				<table aria-describedby="${ captionId }">
					<caption id="${ captionId }"><code>${ esc( file.file ) }</code>${
						isBundledLibrary( file.file )
							? ` <span class="tag">${ esc(
									__(
										'Bundled library',
										'php-compatibility-checker'
									)
								) }</span>`
							: ''
					}</caption>
					<thead><tr>
						<th scope="col" class="type">${ esc( __( 'Type', 'php-compatibility-checker' ) ) }</th>
						<th scope="col">${ esc( __( 'Problem', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="lines">${ esc( __( 'Lines', 'php-compatibility-checker' ) ) }</th>
						${ showPhp ? `<th scope="col" class="php">${ esc( __( 'PHP', 'php-compatibility-checker' ) ) }</th>` : '' }
					</tr></thead>
					<tbody>${ rows
						.map(
							( row ) => `<tr>
						<td class="type ${ 'WARNING' === row.type ? 'warning' : 'error' }">${ esc(
							'WARNING' === row.type
								? __( 'Warning', 'php-compatibility-checker' )
								: __( 'Error', 'php-compatibility-checker' )
						) }</td>
						<td>${ esc( row.message ) }</td>
						<td class="lines">${ esc( row.lines.join( ', ' ) ) }</td>
						${ showPhp ? `<td class="php">${ esc( row.versions.join( ', ' ) ) }</td>` : '' }
					</tr>`
						)
						.join( '' ) }</tbody>
				</table>`;
				} )
				.join( '' );

			return `
			<section class="item" aria-labelledby="item-${ index }">
				<h3 id="item-${ index }">${ esc( item.name ) } <span class="muted">${ esc( item.version ) } · ${ esc( typeLabel( item ) ) }${
					item.active
						? ''
						: ` · ${ esc( __( 'Inactive', 'php-compatibility-checker' ) ) }`
				}</span></h3>
				<p>${ esc( facts_.join( ' ' ) ) }</p>
				<p><strong>${ esc( __( 'Recommended:', 'php-compatibility-checker' ) ) }</strong> ${ esc( recommendation( item ) ) }</p>
				<p><a href="${ esc( playground( [ item ] ) ) }">${ esc(
					sprintf(
						/* translators: %s: plugin or theme name. */
						__(
							'Try %s in WordPress Playground on the latest PHP',
							'php-compatibility-checker'
						),
						item.name
					)
				) }</a></p>
				${ files }
			</section>`;
		} )
		.join( '' );

	const title = sprintf(
		/* translators: %s: site name or URL. */
		__( 'PHP Compatibility Report: %s', 'php-compatibility-checker' ),
		site.name || site.url
	);

	return `<!DOCTYPE html>
<html lang="${ esc( pageLang() || 'en' ) }">
<head>
<meta charset="utf-8">
<title>${ esc( title ) }</title>
<meta name="author" content="${ esc( site.name || site.url ) }">
<meta name="generator" content="${ esc(
		`PHP Compatibility Checker ${ site.plugin_version || '' }`.trim()
	) }">
<meta name="description" content="${ esc( verdict ) }">
<style>
	@page {
		margin: 18mm 16mm 20mm;
		@bottom-left { content: "${ cssString(
			sprintf(
				/* translators: %s: plugin version number. */
				__(
					'Generated by PHP Compatibility Checker %s by WP Engine',
					'php-compatibility-checker'
				),
				site.plugin_version || ''
			).trim()
		) }"; font: 8pt sans-serif; color: #50575e; }
		@bottom-right { content: "${ cssString(
			__( 'Page', 'php-compatibility-checker' )
		) } " counter(page) " / " counter(pages); font: 8pt sans-serif; color: #50575e; }
	}
	* { box-sizing: border-box; }
	body { margin: 0; color: #1e1e1e; font: 10.5pt/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	a { color: #2145e6; }
	.screen-reader-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
	header.masthead { margin: 0 0 16pt; padding: 18pt 20pt; background: #1e1e1e; color: #fff; border-bottom: 3pt solid #3858e9; }
	h1 { margin: 0; font-size: 22pt; line-height: 1.2; }
	.site-name { margin: 4pt 0 0; color: #dcdcde; }
	h2 { margin: 20pt 0 8pt; padding-bottom: 4pt; border-bottom: 1px solid #c3c4c7; font-size: 14pt; break-after: avoid; }
	h3 { margin: 0 0 4pt; font-size: 12pt; break-after: avoid; }
	p { margin: 0 0 6pt; }
	code { font: 8.5pt/1.4 Menlo, Consolas, monospace; word-break: break-all; }
	.muted { color: #50575e; font-weight: 400; }
	dl.facts { display: grid; grid-template-columns: 1fr 1fr; gap: 8pt 24pt; margin: 0 0 14pt; }
	dl.facts div { break-inside: avoid; }
	dt { color: #50575e; font-size: 7.5pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
	dd { margin: 2pt 0 0; }
	.summary { margin: 0 0 14pt; padding: 12pt 16pt; background: #f6f7f7; border-left: 3pt solid #3858e9; break-inside: avoid; }
	.summary .verdict { font-size: 11pt; font-weight: 700; }
	.summary ol { margin: 6pt 0 0; padding-left: 18pt; }
	ul.tiles { display: flex; gap: 10pt; margin: 0 0 6pt; padding: 0; list-style: none; }
	ul.tiles li { flex: 1; padding: 8pt 12pt; border: 1px solid #c3c4c7; border-top: 3pt solid #c3c4c7; border-radius: 3pt; color: #50575e; font-size: 9pt; }
	ul.tiles strong { display: block; color: #1e1e1e; font-size: 20pt; line-height: 1.2; }
	ul.tiles .problems, ul.tiles .failed { border-top-color: #cc1818; }
	ul.tiles .problems strong, ul.tiles .failed strong { color: #cc1818; }
	ul.tiles .not_checked { border-top-color: #b26200; }
	ul.tiles .passes { border-top-color: #008a20; }
	table { width: 100%; margin: 0 0 12pt; border-collapse: collapse; font-size: 8.5pt; }
	caption { padding: 0 0 4pt; text-align: left; caption-side: top; }
	th, td { padding: 4pt 6pt; border-bottom: 1px solid #dcdcde; text-align: left; vertical-align: top; }
	thead th { background: #f0f0f1; border-bottom-color: #c3c4c7; }
	tbody th { font-weight: 600; }
	tbody tr:nth-child(even) { background: #f6f7f7; }
	tr { break-inside: avoid; }
	thead { display: table-header-group; }
	td.type { font-weight: 700; width: 5em; }
	td.error { color: #cc1818; }
	td.warning { color: #b26200; }
	.lines { width: 10em; }
	.php { width: 6em; white-space: nowrap; }
	[class^="status-"] { white-space: nowrap; }
	.status-problems, .status-failed { color: #cc1818; font-weight: 600; }
	.status-not_checked { color: #b26200; font-weight: 600; }
	.status-passes { color: #007017; font-weight: 600; }
	.tag { margin-left: 4pt; padding: 0 5pt; border: 1px solid #c3c4c7; border-radius: 8pt; color: #50575e; font: 7.5pt sans-serif; white-space: nowrap; }
	section.item { margin: 0 0 16pt; }
	section.about { break-inside: avoid; }
	.credit { margin-top: 14pt; font-size: 9pt; }
</style>
</head>
<body>
<header class="masthead">
	<h1>${ esc( __( 'PHP Compatibility Report', 'php-compatibility-checker' ) ) }</h1>
	<p class="site-name">${ esc( site.name || site.url ) }</p>
</header>
<main>
	<section aria-labelledby="summary-heading">
		<h2 id="summary-heading" class="screen-reader-text">${ esc( __( 'Summary', 'php-compatibility-checker' ) ) }</h2>
		<dl class="facts">${ facts
			.map(
				( [ label, value ] ) =>
					`<div><dt>${ esc( label ) }</dt><dd>${ value }</dd></div>`
			)
			.join( '' ) }</dl>
		<div class="summary">
			<p class="verdict">${ esc( verdict ) }</p>
			${
				steps.length
					? `<ol>${ steps
							.map( ( step ) => `<li>${ esc( step ) }</li>` )
							.join( '' ) }</ol>`
					: ''
			}
		</div>
		<ul class="tiles" aria-label="${ esc( __( 'Results by status', 'php-compatibility-checker' ) ) }">${ tiles
			.map(
				( [ key, label ] ) =>
					`<li class="${ key }"><strong>${ esc( summary[ key ] || 0 ) }</strong> ${ esc( label ) }</li>`
			)
			.join( '' ) }</ul>
	</section>

	<section aria-labelledby="glance-heading">
		<h2 id="glance-heading">${ esc( __( 'At a glance', 'php-compatibility-checker' ) ) }</h2>
		<table>
			<caption class="screen-reader-text">${ esc( __( 'Every plugin and theme scanned, with its result', 'php-compatibility-checker' ) ) }</caption>
			<thead><tr>
				<th scope="col">${ esc( __( 'Name', 'php-compatibility-checker' ) ) }</th>
				<th scope="col">${ esc( __( 'Type', 'php-compatibility-checker' ) ) }</th>
				<th scope="col">${ esc( __( 'Version', 'php-compatibility-checker' ) ) }</th>
				<th scope="col">${ esc( __( 'Status', 'php-compatibility-checker' ) ) }</th>
				<th scope="col">${ esc( __( 'Details', 'php-compatibility-checker' ) ) }</th>
			</tr></thead>
			<tbody>${ sorted
				.map(
					( item ) => `<tr>
				<th scope="row">${ esc( item.name ) }${ inactive( item ) }</th>
				<td>${ esc( typeLabel( item ) ) }</td>
				<td>${ esc( item.version ) }</td>
				<td class="status-${ esc( item.status ) }">${ esc( statusLabel[ item.status ] ) }</td>
				<td>${ esc( phpResult( item ) ) }</td>
			</tr>`
				)
				.join( '' ) }</tbody>
		</table>
	</section>

	<section aria-labelledby="attention-heading">
		<h2 id="attention-heading">${ esc( __( 'Needs attention', 'php-compatibility-checker' ) ) }</h2>
		${
			problemSections ||
			`<p>${ esc( __( 'No problems found.', 'php-compatibility-checker' ) ) }</p>`
		}
	</section>

	<section class="about" aria-labelledby="about-heading">
		<h2 id="about-heading">${ esc( __( 'About this report', 'php-compatibility-checker' ) ) }</h2>
		<p>${ esc(
			__(
				'Results are static analysis by PHPCompatibilityWP, run in the browser on WordPress Playground. Plugins and themes from WordPress.org are checked as published there; premium, custom, and modified code is checked as installed on this site. Code is not executed, so runtime issues cannot be detected.',
				'php-compatibility-checker'
			)
		) }</p>
		<p>${ esc(
			__(
				'PHP versions below a plugin\'s or theme\'s own "Requires PHP" are not counted, because it cannot run on them. A pass only covers the PHP versions listed; newer versions were not checked.',
				'php-compatibility-checker'
			)
		) }</p>
		<p>${ esc(
			__(
				'If something fails, check for an update first: a newer version may already be compatible. Problems in bundled libraries are often guarded by runtime checks, so test before treating them as blockers.',
				'php-compatibility-checker'
			)
		) }</p>
		<p><a href="https://github.com/PHPCompatibility/PHPCompatibilityWP">${ esc( __( 'About PHPCompatibilityWP', 'php-compatibility-checker' ) ) }</a>${
			problemItems.length
				? ` · <a href="${ esc( playground( problemItems ) ) }">${ esc(
						__(
							'Try every plugin and theme with problems in WordPress Playground',
							'php-compatibility-checker'
						)
					) }</a>`
				: ''
		}</p>
		<p class="muted credit">${ esc(
			__(
				'PHP Compatibility Checker is made by',
				'php-compatibility-checker'
			)
		) } <a href="${ esc(
			site.maker_url || 'https://wpengine.com/'
		) }">WP Engine</a>.</p>
	</section>
</main>
</body>
</html>`;
}

/**
 * Open the report in a hidden frame and show the print dialog, where it can
 * be saved as PDF. Isolated from admin styles.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata.
 */
export function printReport( items, meta ) {
	const frame = document.createElement( 'iframe' );
	frame.className = 'wpe-pcc-print-frame';
	frame.title = __( 'PHP Compatibility Report', 'php-compatibility-checker' );
	frame.setAttribute( 'aria-hidden', 'true' );
	frame.style.cssText =
		'position:fixed;width:0;height:0;border:0;inset-inline-end:0;bottom:0;';
	document.body.appendChild( frame );

	const doc = frame.contentDocument;
	doc.open();
	doc.write( buildReportHtml( items, meta ) );
	doc.close();

	const cleanup = () => setTimeout( () => frame.remove(), 500 );
	frame.contentWindow.addEventListener( 'afterprint', cleanup );
	frame.contentWindow.focus();
	frame.contentWindow.print();
	// Some browsers don't fire afterprint for frames.
	setTimeout( cleanup, 60000 );
}

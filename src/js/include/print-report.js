/**
 * The PDF report: an accessible HTML document, saved as PDF with the
 * browser's print dialog. Browsers that tag PDFs (Chrome, Edge) carry the
 * headings, tables, language, and title into the PDF for screen readers.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	aboutParagraphs,
	executiveSummary,
	glanceDetails,
	itemFacts,
	pageLang,
	playgroundUrl,
	recommendation,
	reportFacts,
	sortItems,
	splitProblems,
	groupByMessage,
	statusLabels,
	statusTiles,
	typeLabel,
	versionList,
	versionsWith,
} from './report-model';

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
	const sorted = sortItems( items );
	const problemItems = sorted.filter(
		( item ) => 'problems' === item.status
	);
	const labels = statusLabels();
	const { verdict, steps } = executiveSummary( items, meta );
	const facts = reportFacts( items, meta ).map( ( [ label, value, url ] ) => [
		label,
		url ? `<a href="${ esc( url ) }">${ esc( value ) }</a>` : esc( value ),
	] );
	const tiles = statusTiles( summary );
	const inactive = ( item ) =>
		item.active
			? ''
			: ` <span class="muted">(${ esc(
					__( 'inactive', 'php-compatibility-checker' )
				) })</span>`;
	const typeCell = ( row ) =>
		esc(
			'WARNING' === row.type
				? __( 'Warning', 'php-compatibility-checker' )
				: __( 'Error', 'php-compatibility-checker' )
		);

	const problemSections = problemItems
		.map( ( item, index ) => {
			const fails = versionsWith( item, 'fail' ).join( ', ' );
			const { files, libraries } = splitProblems( item );
			// The item's own code: one table, a row naming each file above its problems.
			const groups = files.map( ( file ) => ( {
				file: file.file,
				rows: groupByMessage( file.rows ),
			} ) );
			const showPhp = groups.some( ( group ) =>
				group.rows.some(
					( row ) => row.versions.join( ', ' ) !== fails
				)
			);
			const fileTables = groups.length
				? `
				<table>
					<caption class="screen-reader-text">${ esc(
						sprintf(
							/* translators: %s: plugin or theme name. */
							__( 'Problems in %s', 'php-compatibility-checker' ),
							item.name
						)
					) }</caption>
					<thead><tr>
						<th scope="col" class="type">${ esc( __( 'Type', 'php-compatibility-checker' ) ) }</th>
						<th scope="col">${ esc( __( 'Problem', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="lines">${ esc( __( 'Lines', 'php-compatibility-checker' ) ) }</th>
						${ showPhp ? `<th scope="col" class="php">${ esc( __( 'PHP', 'php-compatibility-checker' ) ) }</th>` : '' }
					</tr></thead>
					${ groups
						.map(
							( group ) => `<tbody>
					<tr class="file"><th scope="rowgroup" colspan="${ showPhp ? 4 : 3 }"><code>${ esc( group.file ) }</code></th></tr>
					${ group.rows
						.map(
							( row ) => `<tr>
						<td class="type ${ 'WARNING' === row.type ? 'warning' : 'error' }">${ typeCell( row ) }</td>
						<td>${ esc( row.message ) }</td>
						<td class="lines">${ esc( row.lines.join( ', ' ) ) }</td>
						${ showPhp ? `<td class="php">${ esc( versionList( row.versions, Object.keys( item.php ) ) ) }</td>` : '' }
					</tr>`
						)
						.join( '' ) }
					</tbody>`
						)
						.join( '' ) }
				</table>`
				: '';

			const libraryTable = libraries.length
				? `
				<table>
					<caption>${ esc( __( 'Bundled libraries', 'php-compatibility-checker' ) ) } <span class="muted">${ esc(
						__(
							'Often guarded by runtime checks; see the CSV or JSON download for every line.',
							'php-compatibility-checker'
						)
					) }</span></caption>
					<thead><tr>
						<th scope="col">${ esc( __( 'Library', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="num">${ esc( __( 'Files', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="num">${ esc( __( 'Errors', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="num">${ esc( __( 'Warnings', 'php-compatibility-checker' ) ) }</th>
						<th scope="col" class="php">${ esc( __( 'PHP', 'php-compatibility-checker' ) ) }</th>
					</tr></thead>
					<tbody>${ libraries
						.map(
							( library ) => `<tr>
						<th scope="row">${ esc( library.name ) }${ library.location ? `<br><code class="muted">${ esc( library.location ) }</code>` : '' }</th>
						<td class="num">${ esc( library.files ) }</td>
						<td class="num${ library.errors ? ' error' : '' }">${ esc( library.errors ) }</td>
						<td class="num${ library.warnings ? ' warning' : '' }">${ esc( library.warnings ) }</td>
						<td class="php">${ esc( versionList( library.versions, Object.keys( item.php ) ) ) }</td>
					</tr>`
						)
						.join( '' ) }</tbody>
				</table>`
				: '';

			return `
			<section class="item" aria-labelledby="item-${ index }">
				<h3 id="item-${ index }">${ esc( item.name ) }</h3>
				<p>${ esc( itemFacts( item ) ) }</p>
				<p><strong>${ esc( __( 'Recommended:', 'php-compatibility-checker' ) ) }</strong> ${ esc( recommendation( item ) ) }</p>
				<p><a href="${ esc( playgroundUrl( [ item ] ) ) }">${ esc(
					sprintf(
						/* translators: %s: plugin or theme name. */
						__(
							'Try %s in WordPress Playground on the latest PHP',
							'php-compatibility-checker'
						),
						item.name
					)
				) }</a></p>
				${ fileTables }
				${ libraryTable }
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
	tr.file th { padding-top: 8pt; background: #f6f7f7; font-weight: 400; }
	tr { break-inside: avoid; }
	thead { display: table-header-group; }
	td.type { font-weight: 700; width: 5em; }
	td.error { color: #cc1818; }
	td.warning { color: #b26200; }
	.lines { width: 10em; }
	.num { width: 5em; text-align: right; }
	td.num.error, td.num.warning { font-weight: 700; }
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
				<td class="status-${ esc( item.status ) }">${ esc( labels[ item.status ] ) }</td>
				<td>${ esc( glanceDetails( item, meta ) ) }</td>
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
		${ aboutParagraphs()
			.map( ( text ) => `<p>${ esc( text ) }</p>` )
			.join( '' ) }
		<p><a href="https://github.com/PHPCompatibility/PHPCompatibilityWP">${ esc( __( 'About PHPCompatibilityWP', 'php-compatibility-checker' ) ) }</a>${
			problemItems.length
				? ` · <a href="${ esc( playgroundUrl( problemItems ) ) }">${ esc(
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

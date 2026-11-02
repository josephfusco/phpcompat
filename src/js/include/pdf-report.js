/**
 * PDF report, downloaded in one click and tagged for accessibility.
 *
 * Built with PDFKit, loaded only when someone downloads a PDF. Every piece of
 * content is in the structure tree (Document > Sect > H1-H3, P, L, Table) and
 * decoration (background bands, rules, page headers and footers) is marked as
 * artifacts, so screen readers read the report in order with real headings
 * and table headers.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	groupProblems,
	groupByMessage,
	executiveSummary,
	recommendation,
} from './print-report';
import { isBundledLibrary, compareVersions } from './render';

const COLOR = {
	charcoal: '#1e1e1e',
	gray: '#50575e',
	grayLight: '#dcdcde',
	border: '#dcdcde',
	head: '#f0f0f1',
	surface: '#f6f7f7',
	blueberry: '#3858e9',
	link: '#2145e6',
	white: '#ffffff',
	error: '#cc1818',
	warning: '#b26200',
	success: '#007017',
};

const MARGIN = 48;

/**
 * Page language.
 *
 * @return {string|undefined} BCP 47 language tag.
 */
function pageLang() {
	return globalThis.document?.documentElement?.lang || undefined;
}

/**
 * Normalize text for the built-in fonts (narrow spaces from date formatting).
 *
 * @param {string|number|undefined} value Text.
 * @return {string} Text.
 */
function clean( value ) {
	// Narrow and no-break spaces, which date formatting inserts.
	return String( value ?? '' ).replace( /[\u00a0\u2009\u202f]/g, ' ' );
}

// Characters outside Latin-1 that Windows-1252 (and so the built-in PDF
// fonts) can still draw.
const CP1252_EXTRAS = new Set(
	[
		0x20ac, 0x201a, 0x0192, 0x201e, 0x2026, 0x2020, 0x2021, 0x02c6, 0x2030,
		0x0160, 0x2039, 0x0152, 0x017d, 0x2018, 0x2019, 0x201c, 0x201d, 0x2022,
		0x2013, 0x2014, 0x02dc, 0x2122, 0x0161, 0x203a, 0x0153, 0x017e, 0x0178,
	].map( ( code ) => String.fromCharCode( code ) )
);

/**
 * Whether text fits Windows-1252, which the built-in PDF fonts cover.
 *
 * @param {string} value Text.
 * @return {boolean} Fits.
 */
function isCp1252( value ) {
	return [ ...value ].every(
		( char ) => char.charCodeAt( 0 ) <= 0xff || CP1252_EXTRAS.has( char )
	);
}

/**
 * Whether any text in the report needs characters the built-in fonts lack.
 * Those reports use the accessible print version instead.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata.
 * @return {boolean} Needs the fallback.
 */
export function needsUnicodeFallback( items, meta ) {
	const strings = [
		meta.site?.name,
		meta.site?.url,
		...items.flatMap( ( item ) => [
			item.name,
			item.version,
			item.reason,
			...item.problems.flatMap( ( problem ) => [
				problem.file,
				problem.message,
			] ),
		] ),
	];
	return ! strings.every( ( text ) => isCp1252( clean( text ) ) );
}

/**
 * Report file name, e.g. php-compatibility-example.com-2026-11-02.pdf.
 *
 * @param {Object} meta Report metadata.
 * @return {string} File name.
 */
function fileName( meta ) {
	const host = String( meta.site.url || 'site' )
		.replace( /^https?:\/\//, '' )
		.replace( /[^a-z0-9.-]+/gi, '-' );
	return `php-compatibility-${ host }-${ meta.generated_at.slice( 0, 10 ) }.pdf`;
}

/**
 * Build and download the PDF.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata.
 */
export async function savePdfReport( items, meta ) {
	const [ { PDFDocument, registerStdFonts }, ...fonts ] = await Promise.all( [
		import( /* webpackChunkName: "pdf" */ 'pdfkit' ),
		import(
			/* webpackChunkName: "pdf" */ 'pdfkit/standard-fonts/Helvetica'
		),
		import(
			/* webpackChunkName: "pdf" */ 'pdfkit/standard-fonts/HelveticaBold'
		),
		import( /* webpackChunkName: "pdf" */ 'pdfkit/standard-fonts/Courier' ),
	] );
	registerStdFonts( ...fonts.map( ( font ) => font.default ) );

	const chunks = [];
	const done = new Promise( ( resolve, reject ) => {
		const doc = buildPdf( PDFDocument, items, meta, {
			onData: ( chunk ) => chunks.push( chunk ),
			onEnd: resolve,
		} );
		doc.on( 'error', reject );
	} );
	await done;

	const url = URL.createObjectURL(
		new Blob( chunks, { type: 'application/pdf' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = fileName( meta );
	link.style.display = 'none';
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

/**
 * Build the tagged PDF document.
 *
 * @param {typeof Object} PDFDocument PDFKit's document class.
 * @param {Object[]}      items       Recorded results.
 * @param {Object}        meta        Report metadata.
 * @param {Object}        [stream]    Optional onData/onEnd callbacks.
 * @return {Object} The ended PDFKit document.
 */
export function buildPdf( PDFDocument, items, meta, stream = {} ) {
	const { site, summary } = meta;
	const lang = pageLang() || 'en-US';
	const letter = /^(en-US|en-CA|es-MX)$/i.test(
		globalThis.navigator?.language || lang
	);
	const title = sprintf(
		/* translators: %s: site name or URL. */
		__( 'PHP Compatibility Report – %s', 'php-compatibility-checker' ),
		site.name || site.url
	);

	const doc = new PDFDocument( {
		size: letter ? 'LETTER' : 'A4',
		margins: { top: 72, bottom: 64, left: MARGIN, right: MARGIN },
		bufferPages: true,
		tagged: true,
		lang,
		displayTitle: true,
		pdfVersion: '1.7',
		info: {
			Title: clean( title ),
			Author: clean( site.name || site.url ),
			Subject: __(
				'PHP version compatibility of the plugins and themes on a WordPress site',
				'php-compatibility-checker'
			),
			Keywords: [
				'PHP',
				'WordPress',
				'compatibility',
				'plugins',
				'themes',
				meta.max_php_checked && `PHP ${ meta.max_php_checked }`,
				site.php && `PHP ${ site.php }`,
			]
				.filter( Boolean )
				.join( ', ' ),
			Creator: `PHP Compatibility Checker ${
				site.plugin_version || ''
			}`.trim(),
			CreationDate: new Date( meta.generated_at ),
		},
	} );
	if ( stream.onData ) {
		doc.on( 'data', stream.onData );
	}
	if ( stream.onEnd ) {
		doc.on( 'end', stream.onEnd );
	}

	const W = doc.page.width;
	const H = doc.page.height;
	const contentW = W - MARGIN * 2;
	const bottomLimit = () => H - doc.page.margins.bottom;

	// Structure helpers.
	const root = doc.struct( 'Document' );
	doc.addStructure( root );
	const section = ( parent = root ) => {
		const sect = doc.struct( 'Sect' );
		parent.add( sect );
		return sect;
	};
	const artifact = ( draw ) => {
		doc.markContent( 'Artifact' );
		draw();
		doc.endMarkedContent();
	};
	const font = ( size, weight = 'normal', color = COLOR.charcoal ) => {
		doc.font( 'bold' === weight ? 'Helvetica-Bold' : 'Helvetica' )
			.fontSize( size )
			.fillColor( color );
	};
	const ensure = ( height ) => {
		if ( doc.y + height > bottomLimit() ) {
			doc.addPage();
		}
	};
	const text = ( parent, type, value, options = {} ) => {
		doc.text( clean( value ), options.x ?? MARGIN, options.y, {
			width: options.width ?? contentW,
			lineGap: options.lineGap ?? 2,
			structParent: parent,
			structType: type,
			...options.text,
		} );
	};
	const heading = ( parent, value, level ) => {
		const size = { 1: 22, 2: 14, 3: 12 }[ level ];
		ensure( level === 2 ? 70 : 50 );
		if ( level === 2 ) {
			doc.moveDown( 0.6 );
		}
		font( size, 'bold' );
		text( parent, `H${ level }`, value );
		if ( level === 2 ) {
			const ruleY = doc.y + 3;
			artifact( () =>
				doc
					.moveTo( MARGIN, ruleY )
					.lineTo( W - MARGIN, ruleY )
					.lineWidth( 0.75 )
					.strokeColor( COLOR.border )
					.stroke()
			);
			doc.y = ruleY + 10;
		} else {
			doc.moveDown( 0.25 );
		}
	};
	const paragraph = ( parent, value, size = 10, color = COLOR.charcoal ) => {
		font( size, 'normal', color );
		text( parent, 'P', value );
		doc.moveDown( 0.4 );
	};
	const table = ( parent, head, rows, columnStyles, cellStyle ) => {
		ensure( 50 );
		doc.x = MARGIN;
		font( 9 );
		doc.table( {
			structParent: parent,
			position: { x: MARGIN, y: doc.y },
			maxWidth: contentW,
			columnStyles,
			defaultStyle: {
				padding: [ 5, 6 ],
				border: { top: 0, right: 0, bottom: 0.75, left: 0 },
				borderColor: COLOR.border,
				font: { size: 9 },
			},
			data: [
				head.map( ( value ) => ( {
					text: clean( value ),
					type: 'TH',
					scope: 'Column',
					backgroundColor: COLOR.head,
					font: { src: 'Helvetica-Bold', size: 9 },
				} ) ),
				...rows.map( ( row, rowIndex ) =>
					row.map( ( value, colIndex ) => ( {
						text: clean( value ),
						backgroundColor:
							rowIndex % 2 ? COLOR.surface : undefined,
						...( cellStyle ? cellStyle( rowIndex, colIndex ) : {} ),
					} ) )
				),
			],
		} );
		doc.x = MARGIN;
		doc.moveDown( 1 );
	};

	const typeLabel = ( item ) =>
		'theme' === item.type
			? __( 'Theme', 'php-compatibility-checker' )
			: __( 'Plugin', 'php-compatibility-checker' );
	const versionsWith = ( item, state ) =>
		Object.keys( item.php )
			.filter( ( version ) => state === item.php[ version ] )
			.sort( compareVersions );
	const order = [ 'problems', 'failed', 'not_checked', 'passes' ];
	const sorted = [ ...items ].sort(
		( a, b ) =>
			order.indexOf( a.status ) - order.indexOf( b.status ) ||
			a.name.localeCompare( b.name )
	);
	const byStatus = ( status ) =>
		sorted.filter( ( item ) => status === item.status );
	const problemItems = byStatus( 'problems' );
	const inactive = ( item ) =>
		item.active
			? ''
			: ` (${ __( 'inactive', 'php-compatibility-checker' ) })`;
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
		return __(
			'Not on WordPress.org (premium, custom, or modified).',
			'php-compatibility-checker'
		);
	};
	const statusLabel = {
		problems: __( 'Problems', 'php-compatibility-checker' ),
		failed: __( 'Scan failed', 'php-compatibility-checker' ),
		not_checked: __( 'Not checked', 'php-compatibility-checker' ),
		passes: __( 'Passes', 'php-compatibility-checker' ),
	};
	const statusColor = {
		problems: COLOR.error,
		failed: COLOR.error,
		not_checked: COLOR.warning,
		passes: COLOR.success,
	};
	const playground = ( list ) => {
		const params = new URLSearchParams( { php: 'latest' } );
		list.forEach( ( item ) => params.append( item.type, item.slug ) );
		return `https://playground.wordpress.net/?${ params.toString() }`;
	};

	let generated = meta.generated_at;
	try {
		generated = new Date( meta.generated_at ).toLocaleString( lang, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
			timeZoneName: 'short',
		} );
	} catch {}
	generated = clean( generated );

	// Header band (decoration) with the title and site name (content).
	const top = section();
	artifact( () => {
		doc.rect( 0, 0, W, 92 ).fill( COLOR.charcoal );
		doc.rect( 0, 92, W, 3 ).fill( COLOR.blueberry );
	} );
	font( 22, 'bold', COLOR.white );
	text(
		top,
		'H1',
		__( 'PHP Compatibility Report', 'php-compatibility-checker' ),
		{
			y: 30,
		}
	);
	font( 10.5, 'normal', COLOR.grayLight );
	text( top, 'P', site.name || site.url, { y: 62 } );
	doc.outline.addItem( __( 'Summary', 'php-compatibility-checker' ) );
	doc.y = 120;

	// Facts in two columns.
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
	const facts = [
		[ __( 'Site', 'php-compatibility-checker' ), site.url, site.url ],
		[ __( 'Generated', 'php-compatibility-checker' ), generated ],
		[ __( 'Server PHP', 'php-compatibility-checker' ), site.php ],
		[ __( 'WordPress', 'php-compatibility-checker' ), site.wp ],
		[
			__( 'Results', 'php-compatibility-checker' ),
			sprintf(
				/* translators: 1: data source, 2: newest PHP version checked. */
				__( '%1$s, up to PHP %2$s', 'php-compatibility-checker' ),
				meta.source,
				meta.max_php_checked || '–'
			),
		],
		[
			__( 'Scope', 'php-compatibility-checker' ),
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
			),
		],
	].filter( ( [ , value ] ) => value );
	const colW = ( contentW - 24 ) / 2;
	for ( let i = 0; i < facts.length; i += 2 ) {
		const rowY = doc.y;
		let rowEnd = rowY;
		facts.slice( i, i + 2 ).forEach( ( [ label, value, url ], col ) => {
			const x = MARGIN + col * ( colW + 24 );
			font( 7.5, 'bold', COLOR.gray );
			text( top, 'P', label.toUpperCase(), {
				x,
				y: rowY,
				width: colW,
				text: { characterSpacing: 0.6 },
			} );
			font( 10.5, 'normal', url ? COLOR.link : COLOR.charcoal );
			text( top, 'P', value, {
				x,
				y: rowY + 12,
				width: colW,
				text: url ? { link: url } : {},
			} );
			rowEnd = Math.max( rowEnd, doc.y );
		} );
		doc.y = rowEnd + 8;
	}
	doc.x = MARGIN;
	doc.y += 4;

	// Executive summary: verdict and next steps.
	{
		const { verdict, steps } = executiveSummary( items, meta );
		const summarySect = section();
		const textW = contentW - 30;
		font( 10.5, 'bold' );
		const verdictH = doc.heightOfString( clean( verdict ), {
			width: textW,
		} );
		font( 9.5 );
		const stepsH = steps.reduce(
			( sum, step ) =>
				sum +
				doc.heightOfString( clean( step ), {
					width: textW - 16,
					lineGap: 2,
				} ) +
				4,
			0
		);
		const boxH = 26 + verdictH + ( steps.length ? 8 + stepsH : 0 );
		ensure( boxH );
		const boxY = doc.y;
		artifact( () => {
			doc.rect( MARGIN, boxY, contentW, boxH ).fill( COLOR.surface );
			doc.rect( MARGIN, boxY, 3, boxH ).fill( COLOR.blueberry );
		} );
		font( 10.5, 'bold' );
		text( summarySect, 'P', verdict, {
			x: MARGIN + 16,
			y: boxY + 13,
			width: textW,
		} );
		if ( steps.length ) {
			doc.moveDown( 0.4 );
			font( 9.5 );
			const list = doc.struct( 'L' );
			summarySect.add( list );
			doc.list( steps.map( clean ), MARGIN + 30, doc.y, {
				width: textW - 16,
				listType: 'numbered',
				lineGap: 2,
				paragraphGap: 4,
				structParent: list,
			} );
			list.end();
		}
		doc.x = MARGIN;
		doc.y = boxY + boxH + 16;
	}

	// Totals: three numbers, with the label beside each in the same text.
	{
		const totals = section();
		const tiles = [
			[ 'problems', __( 'With problems', 'php-compatibility-checker' ) ],
			[ 'failed', __( 'Failed to scan', 'php-compatibility-checker' ) ],
			[ 'not_checked', __( 'Not checked', 'php-compatibility-checker' ) ],
			[ 'passes', __( 'Pass', 'php-compatibility-checker' ) ],
		].filter( ( [ key ] ) => 'failed' !== key || summary.failed );
		const gap = 12;
		const tileW = ( contentW - gap * ( tiles.length - 1 ) ) / tiles.length;
		const tileH = 60;
		ensure( tileH );
		const tileY = doc.y;
		tiles.forEach( ( [ key, label ], i ) => {
			const x = MARGIN + i * ( tileW + gap );
			const accent = {
				problems: COLOR.error,
				failed: COLOR.error,
				not_checked: COLOR.warning,
				passes: COLOR.success,
			}[ key ];
			artifact( () => {
				doc.roundedRect( x, tileY, tileW, tileH, 3 )
					.lineWidth( 0.75 )
					.strokeColor( COLOR.border )
					.stroke();
				doc.rect( x, tileY, tileW, 3 ).fill( accent );
			} );
			const count = summary[ key ] || 0;
			font(
				22,
				'bold',
				count && ( 'problems' === key || 'failed' === key )
					? COLOR.error
					: COLOR.charcoal
			);
			// One P per tile: "2 With problems".
			const para = doc.struct( 'P' );
			totals.add( para );
			const content = doc.markStructureContent( 'P' );
			doc.text( String( count ), x + 14, tileY + 12, {
				width: tileW - 28,
				lineBreak: false,
			} );
			font( 9, 'normal', COLOR.gray );
			doc.text( ` ${ clean( label ) }`, x + 13, tileY + 38, {
				width: tileW - 28,
				lineBreak: false,
			} );
			doc.endMarkedContent();
			para.add( content );
			para.end();
		} );
		doc.x = MARGIN;
		doc.y = tileY + tileH + 10;
	}

	// At a glance.
	{
		const glance = section();
		heading( glance, __( 'At a glance', 'php-compatibility-checker' ), 2 );
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
		table(
			glance,
			[
				__( 'Name', 'php-compatibility-checker' ),
				__( 'Type', 'php-compatibility-checker' ),
				__( 'Version', 'php-compatibility-checker' ),
				__( 'Status', 'php-compatibility-checker' ),
				__( 'Details', 'php-compatibility-checker' ),
			],
			sorted.map( ( item ) => [
				item.name + inactive( item ),
				typeLabel( item ),
				item.version,
				statusLabel[ item.status ],
				phpResult( item ),
			] ),
			[
				{ width: contentW * 0.3 },
				{ width: contentW * 0.09 },
				{ width: contentW * 0.1 },
				{ width: contentW * 0.13 },
				{ width: contentW * 0.38 },
			],
			( rowIndex, colIndex ) => {
				if ( 0 === colIndex ) {
					return {
						type: 'TH',
						scope: 'Row',
						font: { src: 'Helvetica-Bold', size: 9 },
					};
				}
				if ( 3 === colIndex ) {
					return {
						textColor: statusColor[ sorted[ rowIndex ].status ],
						font: { src: 'Helvetica-Bold', size: 9 },
					};
				}
				return {};
			}
		);
	}

	// Needs attention.
	{
		const attention = section();
		doc.addPage();
		const outlineItem = doc.outline.addItem(
			__( 'Needs attention', 'php-compatibility-checker' )
		);
		heading(
			attention,
			__( 'Needs attention', 'php-compatibility-checker' ),
			2
		);
		if ( ! problemItems.length ) {
			paragraph(
				attention,
				__( 'No problems found.', 'php-compatibility-checker' )
			);
		}
		problemItems.forEach( ( item ) => {
			const itemSect = section( attention );
			ensure( 110 );
			outlineItem.addItem( clean( item.name ) );
			heading( itemSect, item.name, 3 );
			const passes = versionsWith( item, 'pass' );
			paragraph(
				itemSect,
				[
					sprintf(
						/* translators: 1: version number, 2: Plugin or Theme. */
						__(
							'Version %1$s · %2$s.',
							'php-compatibility-checker'
						),
						item.version,
						typeLabel( item )
					),
					! item.active &&
						__( 'Inactive.', 'php-compatibility-checker' ),
					sprintf(
						/* translators: %s: PHP versions with problems. */
						__(
							'Problems on PHP %s.',
							'php-compatibility-checker'
						),
						versionsWith( item, 'fail' ).join( ', ' )
					),
					passes.length &&
						sprintf(
							/* translators: %s: PHP versions that pass. */
							__(
								'Passes on PHP %s.',
								'php-compatibility-checker'
							),
							passes.join( ', ' )
						),
					item.requires_php &&
						sprintf(
							/* translators: %s: minimum PHP version. */
							__(
								'Requires PHP %s.',
								'php-compatibility-checker'
							),
							item.requires_php
						),
				]
					.filter( Boolean )
					.join( ' ' )
			);
			{
				const para = doc.struct( 'P' );
				itemSect.add( para );
				const content = doc.markStructureContent( 'P' );
				font( 9.5, 'bold' );
				doc.text(
					`${ clean(
						__( 'Recommended:', 'php-compatibility-checker' )
					) } `,
					MARGIN,
					doc.y,
					{ width: contentW, lineGap: 2, continued: true }
				);
				font( 9.5 );
				doc.text( clean( recommendation( item ) ), {
					width: contentW,
					lineGap: 2,
				} );
				doc.endMarkedContent();
				para.add( content );
				para.end();
				doc.moveDown( 0.4 );
			}
			font( 9, 'normal', COLOR.link );
			text(
				itemSect,
				'P',
				sprintf(
					/* translators: %s: plugin or theme name. */
					__(
						'Try %s in WordPress Playground on the latest PHP',
						'php-compatibility-checker'
					),
					item.name
				),
				{ text: { link: playground( [ item ] ), underline: true } }
			);
			doc.moveDown( 0.6 );

			groupProblems( item.problems ).forEach( ( file ) => {
				ensure( 60 );
				font( 8, 'normal', COLOR.charcoal );
				doc.font( 'Courier' );
				text(
					itemSect,
					'P',
					isBundledLibrary( file.file )
						? `${ file.file }  (${ __(
								'bundled library',
								'php-compatibility-checker'
							) })`
						: file.file
				);
				doc.moveDown( 0.3 );
				const grouped = groupByMessage( file.rows );
				// Only show versions per row when they differ from the item's.
				const fails = versionsWith( item, 'fail' ).join( ', ' );
				const showPhp = grouped.some(
					( row ) => row.versions.join( ', ' ) !== fails
				);
				const phpW = showPhp ? 56 : 0;
				table(
					itemSect,
					[
						__( 'Type', 'php-compatibility-checker' ),
						__( 'Problem', 'php-compatibility-checker' ),
						__( 'Lines', 'php-compatibility-checker' ),
						showPhp && __( 'PHP', 'php-compatibility-checker' ),
					].filter( Boolean ),
					grouped.map( ( row ) =>
						[
							'WARNING' === row.type
								? __( 'Warning', 'php-compatibility-checker' )
								: __( 'Error', 'php-compatibility-checker' ),
							row.message,
							row.lines.join( ', ' ),
							showPhp && row.versions.join( ', ' ),
						].filter( ( cell ) => false !== cell )
					),
					[
						{ width: 52 },
						{ width: contentW - 52 - 100 - phpW },
						{ width: 100 },
						showPhp && { width: phpW },
					].filter( Boolean ),
					( rowIndex, colIndex ) =>
						0 === colIndex
							? {
									textColor:
										'WARNING' === grouped[ rowIndex ].type
											? COLOR.warning
											: COLOR.error,
									font: { src: 'Helvetica-Bold', size: 9 },
								}
							: {}
				);
			} );
		} );
	}

	// About this report.
	{
		const sect = section();
		const about = [
			__(
				'Results are static analysis by PHPCompatibilityWP, run by WP Tide on the versions published on WordPress.org. Your code is not executed, so runtime issues cannot be detected, and plugins and themes that are not on WordPress.org cannot be checked.',
				'php-compatibility-checker'
			),
			__(
				'PHP versions below a plugin\'s or theme\'s own "Requires PHP" are not counted, because it cannot run on them. A pass only covers the PHP versions listed; newer versions were not checked.',
				'php-compatibility-checker'
			),
			__(
				'If something fails, check for an update first: a newer version may already be compatible. Problems in bundled libraries are often guarded by runtime checks, so test before treating them as blockers.',
				'php-compatibility-checker'
			),
		];
		// Methodology on its own page, so findings aren't crowded.
		doc.addPage();
		doc.outline.addItem(
			__( 'About this report', 'php-compatibility-checker' )
		);
		heading(
			sect,
			__( 'About this report', 'php-compatibility-checker' ),
			2
		);
		about.forEach( ( value ) => paragraph( sect, value ) );
		// Both links in one paragraph, so the section ends cleanly.
		const para = doc.struct( 'P' );
		sect.add( para );
		const content = doc.markStructureContent( 'P' );
		font( 9.5, 'normal', COLOR.link );
		doc.text(
			clean(
				__( 'About WP Tide (wptide.org)', 'php-compatibility-checker' )
			),
			MARGIN,
			doc.y,
			{
				width: contentW,
				link: 'https://wptide.org/',
				underline: true,
				continued: problemItems.length > 0,
			}
		);
		if ( problemItems.length ) {
			font( 9.5, 'normal', COLOR.gray );
			doc.text( '  ·  ', {
				link: null,
				underline: false,
				continued: true,
			} );
			font( 9.5, 'normal', COLOR.link );
			doc.text(
				clean(
					__(
						'Try every plugin and theme with problems in WordPress Playground',
						'php-compatibility-checker'
					)
				),
				{ link: playground( problemItems ), underline: true }
			);
		}
		doc.endMarkedContent();
		para.add( content );
		para.end();

		// Attribution: who makes the tool.
		doc.moveDown( 1.2 );
		const credit = doc.struct( 'P' );
		sect.add( credit );
		const creditContent = doc.markStructureContent( 'P' );
		font( 9, 'normal', COLOR.gray );
		doc.text(
			clean(
				__(
					'PHP Compatibility Checker is made by',
					'php-compatibility-checker'
				)
			) + ' ',
			MARGIN,
			doc.y,
			{ width: contentW, continued: true }
		);
		font( 9, 'normal', COLOR.link );
		doc.text( 'WP Engine', {
			link: site.maker_url || 'https://wpengine.com/',
			underline: true,
			continued: true,
		} );
		font( 9, 'normal', COLOR.gray );
		doc.text( '.', { link: null, underline: false } );
		doc.endMarkedContent();
		credit.add( creditContent );
		credit.end();
	}

	root.end();

	// Running header (pages 2+) and footer on every page, as artifacts.
	const generatedBy = site.plugin_version
		? sprintf(
				/* translators: %s: plugin version number. */
				__(
					'Generated by PHP Compatibility Checker %s by WP Engine',
					'php-compatibility-checker'
				),
				site.plugin_version
			)
		: __(
				'Generated by PHP Compatibility Checker by WP Engine',
				'php-compatibility-checker'
			);
	const range = doc.bufferedPageRange();
	for ( let i = range.start; i < range.start + range.count; i++ ) {
		doc.switchToPage( i );
		const savedBottom = doc.page.margins.bottom;
		doc.page.margins.bottom = 0;
		artifact( () => {
			font( 8, 'normal', COLOR.gray );
			if ( i > range.start ) {
				doc.text(
					clean(
						`${ __( 'PHP Compatibility Report', 'php-compatibility-checker' ) } · ${
							site.name || site.url
						}`
					),
					MARGIN,
					32,
					{ width: contentW / 2, lineBreak: false }
				);
				doc.text( generated, MARGIN + contentW / 2, 32, {
					width: contentW / 2,
					align: 'right',
					lineBreak: false,
				} );
				doc.moveTo( MARGIN, 48 )
					.lineTo( W - MARGIN, 48 )
					.lineWidth( 0.5 )
					.strokeColor( COLOR.border )
					.stroke();
			}
			doc.moveTo( MARGIN, H - 44 )
				.lineTo( W - MARGIN, H - 44 )
				.lineWidth( 0.5 )
				.strokeColor( COLOR.border )
				.stroke();
			font( 8, 'normal', COLOR.gray );
			doc.text( clean( generatedBy ), MARGIN, H - 36, {
				width: contentW / 2,
				lineBreak: false,
			} );
			doc.text(
				sprintf(
					/* translators: 1: page number, 2: total pages. */
					__( 'Page %1$d of %2$d', 'php-compatibility-checker' ),
					i - range.start + 1,
					range.count
				),
				MARGIN + contentW / 2,
				H - 36,
				{ width: contentW / 2, align: 'right', lineBreak: false }
			);
		} );
		doc.page.margins.bottom = savedBottom;
	}

	doc.end();
	return doc;
}

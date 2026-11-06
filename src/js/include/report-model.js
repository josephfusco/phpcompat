/**
 * What goes into a report, shared by the PDF and the printable page: labels,
 * ordering, wording, grouping. The renderers only decide how it looks.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	isBundledLibrary,
	compareVersions,
	needsPhpNote,
	LIBRARY_FOLDERS,
} from './render';

/** Report order: what needs action first. */
export const STATUS_ORDER = [ 'problems', 'failed', 'not_checked', 'passes' ];

/**
 * The page's language, for dates and lists.
 *
 * @return {string} BCP 47 tag, or '' if unknown.
 */
export function pageLang() {
	return (
		globalThis.document?.documentElement?.lang ||
		globalThis.navigator?.language ||
		''
	);
}

/**
 * @return {Object<string, string>} Status => label.
 */
export function statusLabels() {
	return {
		problems: __( 'Problems', 'php-compatibility-checker' ),
		failed: __( 'Scan failed', 'php-compatibility-checker' ),
		not_checked: __( 'Not checked', 'php-compatibility-checker' ),
		passes: __( 'Passes', 'php-compatibility-checker' ),
	};
}

/**
 * Status tiles for the summary, without "failed" when nothing failed.
 *
 * @param {Object} summary Counts by status.
 * @return {Array<[string, string]>} [status, label] pairs.
 */
export function statusTiles( summary ) {
	return [
		[ 'problems', __( 'With problems', 'php-compatibility-checker' ) ],
		[ 'failed', __( 'Failed to scan', 'php-compatibility-checker' ) ],
		[ 'not_checked', __( 'Not checked', 'php-compatibility-checker' ) ],
		[ 'passes', __( 'Pass', 'php-compatibility-checker' ) ],
	].filter( ( [ key ] ) => 'failed' !== key || summary.failed );
}

/**
 * @param {Object} item Recorded result.
 * @return {string} "Plugin" or "Theme".
 */
export function typeLabel( item ) {
	return 'theme' === item.type
		? __( 'Theme', 'php-compatibility-checker' )
		: __( 'Plugin', 'php-compatibility-checker' );
}

/**
 * Items in report order: by status, then name.
 *
 * @param {Object[]} items Recorded results.
 * @return {Object[]} Sorted copy.
 */
export function sortItems( items ) {
	return [ ...items ].sort(
		( a, b ) =>
			STATUS_ORDER.indexOf( a.status ) -
				STATUS_ORDER.indexOf( b.status ) ||
			a.name.localeCompare( b.name )
	);
}

/**
 * An item's PHP versions in a given state, oldest first.
 *
 * @param {Object} item  Recorded result.
 * @param {string} state "pass", "fail", or "not_counted".
 * @return {string[]} Versions.
 */
export function versionsWith( item, state ) {
	return Object.keys( item.php )
		.filter( ( version ) => state === item.php[ version ] )
		.sort( compareVersions );
}

/**
 * Versions as compact text: runs of three or more versions in a row (in the
 * list of versions checked) become a range, e.g. "7.4–8.5" or "7.4, 8.3–8.5".
 *
 * @param {string[]} versions Versions to describe.
 * @param {string[]} checked  Every version checked, in any order.
 * @return {string} Text.
 */
export function versionList( versions, checked = versions ) {
	const all = [ ...new Set( checked ) ].sort( compareVersions );
	const wanted = new Set( versions );
	const runs = [];
	let run = [];
	all.forEach( ( version ) => {
		if ( wanted.has( version ) ) {
			run.push( version );
		} else if ( run.length ) {
			runs.push( run );
			run = [];
		}
	} );
	if ( run.length ) {
		runs.push( run );
	}
	return runs
		.map( ( list ) =>
			list.length >= 3
				? `${ list[ 0 ] }–${ list[ list.length - 1 ] }`
				: list.join( ', ' )
		)
		.join( ', ' );
}

/**
 * An item's versions in a state, as compact text.
 *
 * @param {Object} item  Recorded result.
 * @param {string} state "pass" or "fail".
 * @return {string} Text, or '' if none.
 */
function versionsText( item, state ) {
	return versionList( versionsWith( item, state ), Object.keys( item.php ) );
}

/**
 * @param {string} scope "yes", "no", or "choose".
 * @return {string} What the scan covered.
 */
export function scopeLabel( scope ) {
	return (
		{
			yes: __( 'Active plugins and theme', 'php-compatibility-checker' ),
			no: __( 'All plugins and themes', 'php-compatibility-checker' ),
			choose: __(
				'Selected plugins and themes',
				'php-compatibility-checker'
			),
		}[ scope ] || __( 'Plugins and themes', 'php-compatibility-checker' )
	);
}

/**
 * The report's date in the page's language, e.g. "November 2, 2026 at 11:02 AM EST".
 *
 * @param {string} iso ISO date.
 * @return {string} Date.
 */
export function formatDate( iso ) {
	try {
		return new Date( iso ).toLocaleString( pageLang() || undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
			timeZoneName: 'short',
		} );
	} catch {
		return iso;
	}
}

/**
 * Why an item that wasn't checked (or failed) has no result.
 *
 * @param {Object} item Recorded result.
 * @param {Object} meta Report metadata.
 * @return {string} Reason.
 */
export function reasonFor( item, meta ) {
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
	return (
		item.reason ||
		__( 'Not checked in this scan.', 'php-compatibility-checker' )
	);
}

/**
 * One line for the "At a glance" table.
 *
 * @param {Object} item Recorded result.
 * @param {Object} meta Report metadata.
 * @return {string} Details.
 */
export function glanceDetails( item, meta ) {
	if ( 'problems' === item.status ) {
		const fails = sprintf(
			/* translators: %s: PHP versions, e.g. "7.4–8.5". */
			__( 'Fails on PHP %s', 'php-compatibility-checker' ),
			versionsText( item, 'fail' )
		);
		return item.update_version
			? sprintf(
					/* translators: 1: "Fails on PHP 7.4–8.5", 2: version number. */
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
		const passes = sprintf(
			/* translators: %s: PHP versions, e.g. "7.4–8.5". */
			__( 'Passes on PHP %s', 'php-compatibility-checker' ),
			versionsText( item, 'pass' )
		);
		return versionsWith( item, 'not_counted' ).length && item.requires_php
			? sprintf(
					/* translators: 1: "Passes on PHP 8.3–8.5", 2: minimum PHP version. */
					__(
						'%1$s (requires PHP %2$s)',
						'php-compatibility-checker'
					),
					passes,
					item.requires_php
				)
			: passes;
	}
	return reasonFor( item, meta );
}

/**
 * The facts sentence under an item's heading in "Needs attention".
 *
 * @param {Object} item Recorded result.
 * @return {string} Text.
 */
export function itemFacts( item ) {
	const passes = versionsText( item, 'pass' );
	return [
		sprintf(
			/* translators: 1: version number, 2: Plugin or Theme. */
			__( 'Version %1$s · %2$s.', 'php-compatibility-checker' ),
			item.version,
			typeLabel( item )
		),
		! item.active && __( 'Inactive.', 'php-compatibility-checker' ),
		sprintf(
			/* translators: %s: PHP versions, e.g. "7.4–8.5". */
			__( 'Fails on PHP %s.', 'php-compatibility-checker' ),
			versionsText( item, 'fail' )
		),
		passes &&
			sprintf(
				/* translators: %s: PHP versions, e.g. "8.0–8.5". */
				__( 'Passes on PHP %s.', 'php-compatibility-checker' ),
				passes
			),
		item.requires_php &&
			sprintf(
				/* translators: %s: minimum PHP version. */
				__( 'Requires PHP %s.', 'php-compatibility-checker' ),
				item.requires_php
			),
		item.needs_php && needsPhpNote( item.needs_php ),
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * Facts for the top of the report.
 *
 * @param {Object[]} items Recorded results.
 * @param {Object}   meta  Report metadata.
 * @return {Array<[string, string, string?]>} [label, value, link] triples.
 */
export function reportFacts( items, meta ) {
	const { site } = meta;
	return [
		[ __( 'Site', 'php-compatibility-checker' ), site.url, site.url ],
		[
			__( 'Generated', 'php-compatibility-checker' ),
			formatDate( meta.generated_at ),
		],
		[ __( 'Server PHP', 'php-compatibility-checker' ), site.php ],
		[ __( 'WordPress', 'php-compatibility-checker' ), site.wp ],
		[
			__( 'Checked with', 'php-compatibility-checker' ),
			sprintf(
				/* translators: 1: tool and version, 2: newest PHP version checked. */
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
				scopeLabel( meta.scope ),
				items.length
			),
		],
	].filter( ( [ , value ] ) => value );
}

/**
 * Methodology paragraphs for "About this report".
 *
 * @return {string[]} Paragraphs.
 */
export function aboutParagraphs() {
	return [
		__(
			"Results are static analysis by PHPCompatibilityWP, run on this site's own server with the scanner that ships in the plugin. Every plugin and theme is checked as installed here, including premium, custom, and modified code. Code is not executed, so runtime issues cannot be detected.",
			'php-compatibility-checker'
		),
		__(
			'PHP versions below a plugin\'s or theme\'s own "Requires PHP" are not counted, because it cannot run on them. A pass only covers the PHP versions listed; newer versions were not checked.',
			'php-compatibility-checker'
		),
		__(
			'If something fails, check for an update first: a newer version may already be compatible. Problems in bundled libraries are often guarded by runtime checks, so test before treating them as blockers. This report summarizes them per library; the CSV and JSON downloads list every file and line.',
			'php-compatibility-checker'
		),
	];
}

/**
 * Link to WordPress Playground on the latest PHP with these items installed.
 *
 * @param {Object[]} list Items.
 * @return {string} URL.
 */
export function playgroundUrl( list ) {
	const params = new URLSearchParams( { php: 'latest' } );
	list.forEach( ( item ) => params.append( item.type, item.slug ) );
	return `https://playground.wordpress.net/?${ params.toString() }`;
}

/**
 * Group an item's problems by file, merging the same problem across PHP versions.
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
 * Split "… Found implicitly nullable parameter: $link." into the shared part
 * and the value, so the same problem with different values reads as one.
 *
 * @param {string} message Message.
 * @return {{base: string, label: string, value: string}|null} Parts, or null.
 */
export function splitFound( message ) {
	const match = /^(.*?[.;!?])\s+(Found(?:\s[^:]{1,60})?):\s*(.+?)\.?$/s.exec(
		message
	);
	return match
		? { base: match[ 1 ], label: match[ 2 ], value: match[ 3 ] }
		: null;
}

/**
 * Collapse rows with the same message into one row listing every line, so a
 * deprecated function used 20 times reads as one problem, not 20. Messages
 * that only differ in what was found ("Found: $a", "Found: $b") are merged
 * too, listing each value once.
 *
 * @param {Object[]} rows Rows from groupProblems() for one file.
 * @return {Object[]} Rows with `message`, `lines` and `versions`, in order of first line.
 */
export function groupByMessage( rows ) {
	const messages = new Map();
	rows.forEach( ( row ) => {
		const found = splitFound( row.message );
		const key = found
			? `${ row.type }\u0000${ found.base }\u0000${ found.label }`
			: `${ row.type }\u0000${ row.message }`;
		if ( ! messages.has( key ) ) {
			messages.set( key, {
				type: row.type,
				message: row.message,
				found,
				values: [],
				lines: [],
				versions: [],
			} );
		}
		const entry = messages.get( key );
		if ( found && ! entry.values.includes( found.value ) ) {
			entry.values.push( found.value );
		}
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
		.map( ( { found, values, ...entry } ) => ( {
			...entry,
			message:
				found && values.length > 1
					? `${ found.base } ${ found.label }: ${ values.join(
							', '
						) }.`
					: entry.message,
			lines: entry.lines.sort( ( a, b ) => a - b ),
			versions: entry.versions.sort( compareVersions ),
		} ) )
		.sort( ( a, b ) => a.lines[ 0 ] - b.lines[ 0 ] );
}

/**
 * The bundled library a file belongs to: the folder up to the package, e.g.
 * "lib/vendor/paragonie/constant_time_encoding".
 *
 * @param {string} file Path relative to the item.
 * @return {{name: string, path: string, location: string}|null} Package name, its folder, and the
 *         folder holding it when nested (e.g. "core/lib/vendor/"), or null.
 */
export function libraryOf( file ) {
	const match = new RegExp(
		`^(.*?(?:^|/))(${ LIBRARY_FOLDERS })/((?:@[^/]+/)?[^/]+)(/[^/]+)?/`,
		'i'
	).exec( file );
	if ( ! match ) {
		return null;
	}
	const [ , prefix, folder, first, second ] = match;
	// Composer packages are vendor/name; other folders hold one name.
	const twoLevels =
		/^vendor/i.test( folder ) && ! first.startsWith( '@' ) && second;
	const name = twoLevels ? `${ first }${ second }` : first;
	return {
		name,
		path: `${ prefix }${ folder }/${ name }`,
		// Where it is, when that says more than "vendor/".
		location: prefix ? `${ prefix }${ folder }/` : '',
	};
}

/**
 * Split an item's problems into its own files (in full) and a per-library
 * summary of bundled code.
 *
 * @param {Object} item Recorded result.
 * @return {{files: Object[], libraries: Object[]}} Own files (see groupProblems())
 *         and libraries ({name, path, files, errors, warnings, versions}).
 */
export function splitProblems( item ) {
	const files = [];
	const libraries = new Map();

	groupProblems( item.problems ).forEach( ( file ) => {
		// A loose file in a library folder (e.g. vendor/autoload.php) is its own entry.
		const library = isBundledLibrary( file.file )
			? libraryOf( file.file ) || {
					name: file.file,
					path: file.file,
					location: '',
				}
			: null;
		if ( ! library ) {
			files.push( file );
			return;
		}
		if ( ! libraries.has( library.path ) ) {
			libraries.set( library.path, {
				...library,
				files: 0,
				errors: 0,
				warnings: 0,
				versions: [],
			} );
		}
		const entry = libraries.get( library.path );
		entry.files++;
		file.rows.forEach( ( row ) => {
			entry[ 'WARNING' === row.type ? 'warnings' : 'errors' ]++;
			row.versions.forEach( ( version ) => {
				if ( ! entry.versions.includes( version ) ) {
					entry.versions.push( version );
				}
			} );
		} );
	} );

	return {
		files,
		libraries: [ ...libraries.values() ]
			.map( ( entry ) => ( {
				...entry,
				versions: entry.versions.sort( compareVersions ),
			} ) )
			.sort(
				( a, b ) =>
					b.errors - a.errors ||
					b.warnings - a.warnings ||
					a.name.localeCompare( b.name )
			),
	};
}

/**
 * The problem tables for one item, shared by the PDF and print reports: the
 * item's own files with their problems grouped by message, the bundled
 * libraries, and whether rows need their own PHP column (only when a row's
 * versions differ from the item's).
 *
 * @param {Object} item Recorded result.
 * @return {{fails: string, groups: Object[], libraries: Object[], showPhp: boolean}} Tables.
 */
export function problemTables( item ) {
	const fails = versionsWith( item, 'fail' ).join( ', ' );
	const { files, libraries } = splitProblems( item );
	const groups = files.map( ( file ) => ( {
		file: file.file,
		rows: groupByMessage( file.rows ),
	} ) );
	const showPhp = groups.some( ( group ) =>
		group.rows.some( ( row ) => row.versions.join( ', ' ) !== fails )
	);
	return { fails, groups, libraries, showPhp };
}

/**
 * "Warning" or "Error" for a problem row.
 *
 * @param {string} type Row type from PHP_CodeSniffer (WARNING or ERROR).
 * @return {string} Label.
 */
export function problemTypeLabel( type ) {
	return 'WARNING' === type
		? __( 'Warning', 'php-compatibility-checker' )
		: __( 'Error', 'php-compatibility-checker' );
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
 * Names as a list in the page's language: "A, B, and C".
 *
 * @param {Object[]} list Items.
 * @return {string} Names.
 */
function names( list ) {
	const values = list.map( ( item ) => item.name );
	try {
		return new Intl.ListFormat( pageLang() || undefined, {
			type: 'conjunction',
		} ).format( values );
	} catch {
		return values.join( ', ' );
	}
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
 * Which PHP versions a set of results is ready for. Mirrors
 * Scanner::readiness(); both are tested against
 * tests/fixtures/readiness-cases.json.
 *
 * A version is ready when no item fails on it and none requires a newer PHP.
 *
 * @param {Object[]} items   Results (type, slug, name, version, status, php: version => pass|fail|not_counted).
 * @param {string}   current The server's PHP version, any precision.
 * @return {{complete: boolean, ready_for: string[], next_ready: string, next_blocked: string, blockers: Object<string, Object[]>, unknown: Object[]}} Readiness.
 */
export function readiness( items, current ) {
	const branch = `${ current }`.split( '.' ).slice( 0, 2 ).join( '.' );
	const unknown = [];
	const block = {};
	const all = new Set();

	items.forEach( ( result ) => {
		const item = {
			type: result.type,
			slug: result.slug,
			name: result.name || result.slug,
			version: result.version || '',
		};
		if ( ! [ 'passes', 'problems' ].includes( result.status ) ) {
			unknown.push( {
				type: item.type,
				slug: item.slug,
				name: item.name,
				status: result.status,
			} );
			return;
		}
		Object.entries( result.php || {} ).forEach(
			( [ version, outcome ] ) => {
				all.add( version );
				// "not_counted": below the item's own minimum, so it won't run there.
				if ( 'pass' !== outcome ) {
					( block[ version ] ||= [] ).push( item );
				}
			}
		);
	} );

	const readyFor = [];
	const blockers = {};
	let nextReady = '';
	let nextBlocked = '';
	[ ...all ].sort( compareVersions ).forEach( ( version ) => {
		const blocked = !! block[ version ];
		if ( blocked ) {
			blockers[ version ] = block[ version ];
		} else {
			readyFor.push( version );
		}
		if ( nextBlocked || compareVersions( version, branch ) <= 0 ) {
			return;
		}
		if ( blocked ) {
			nextBlocked = version;
		} else {
			nextReady = version;
		}
	} );

	return {
		complete: ! unknown.length && items.length > 0,
		ready_for: readyFor,
		next_ready: nextReady,
		next_blocked: nextBlocked,
		blockers,
		unknown,
	};
}

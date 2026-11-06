/**
 * After a scan, tell the WP Engine sidebar which newer PHP the active
 * plugins and theme are ready for, and what blocks the next one, next to
 * the link for previewing PHP in the User Portal.
 */
import $ from 'jquery';
import { __, _n, sprintf } from '@wordpress/i18n';
import { readiness, pageLang } from './report-model';

/** Names listed before "and N more". */
const MAX_NAMES = 3;

/**
 * Item names as a sentence list: "A, B, and 2 more".
 *
 * @param {Object[]} items Items with name.
 * @return {string} List.
 */
export function nameList( items ) {
	const names = items.slice( 0, MAX_NAMES ).map( ( item ) => item.name );
	const rest = items.length - names.length;
	if ( rest > 0 ) {
		names.push(
			sprintf(
				/* translators: %d: number of other plugins and themes. */
				_n( '%d more', '%d more', rest, 'php-compatibility-checker' ),
				rest
			)
		);
	}
	try {
		return new Intl.ListFormat( pageLang() || undefined, {
			type: 'conjunction',
		} ).format( names );
	} catch {
		return names.join( ', ' );
	}
}

/**
 * The sidebar's sentence for a readiness result.
 *
 * @param {Object}  result  From readiness().
 * @param {boolean} missing Whether some active items weren't part of this scan.
 * @return {{lead: string, text: string}|null} Bold lead and the rest, or null for nothing to say.
 */
export function readinessMessage( result, missing ) {
	if ( missing ) {
		return {
			lead: __( 'Ready for a newer PHP?', 'php-compatibility-checker' ),
			text: __(
				'Scan the active plugins and theme to find out.',
				'php-compatibility-checker'
			),
		};
	}
	if ( ! result.complete ) {
		return {
			lead: __( 'Ready for a newer PHP?', 'php-compatibility-checker' ),
			text: sprintf(
				/* translators: %s: list of plugin and theme names. */
				__(
					'Not known yet: no results for %s.',
					'php-compatibility-checker'
				),
				nameList( result.unknown )
			),
		};
	}

	const { next_ready: ready, next_blocked: blocked } = result;
	if ( ready && blocked ) {
		return {
			lead: sprintf(
				/* translators: %s: PHP version, e.g. 8.4 */
				__( 'Ready for PHP %s:', 'php-compatibility-checker' ),
				ready
			),
			text: sprintf(
				/* translators: 1: PHP version, 2: list of plugin and theme names. */
				__(
					'the active plugins and theme pass on it. PHP %1$s has problems in %2$s.',
					'php-compatibility-checker'
				),
				blocked,
				nameList( result.blockers[ blocked ] )
			),
		};
	}
	if ( ready ) {
		return {
			lead: sprintf(
				/* translators: %s: PHP version, e.g. 8.5 */
				__( 'Ready for PHP %s:', 'php-compatibility-checker' ),
				ready
			),
			text: __(
				'the active plugins and theme pass on every newer version checked.',
				'php-compatibility-checker'
			),
		};
	}
	if ( blocked ) {
		return {
			lead: sprintf(
				/* translators: %s: PHP version, e.g. 8.4 */
				__( 'Before PHP %s:', 'php-compatibility-checker' ),
				blocked
			),
			text: sprintf(
				/* translators: %s: list of plugin and theme names. */
				__(
					'update or replace %s, which have problems on it.',
					'php-compatibility-checker'
				),
				nameList( result.blockers[ blocked ] )
			),
		};
	}
	// Already on the newest version checked.
	return null;
}

/**
 * Fill in the sidebar note from this scan's results.
 *
 * @param {Object}   itemsToScan Plugins and themes from the page (with active).
 * @param {Object[]} results     window.phpcompat.report.
 * @param {string}   php         The server's PHP version.
 */
export function showReadiness( itemsToScan, results, php ) {
	const note = $( '.wpe-pcc-readiness' );
	if ( ! note.length ) {
		return;
	}

	const active = [
		...itemsToScan.plugins.map( ( item ) => ( {
			...item,
			type: 'plugin',
		} ) ),
		...itemsToScan.themes.map( ( item ) => ( { ...item, type: 'theme' } ) ),
	].filter( ( item ) => 'yes' === item.active );
	const found = active
		.map( ( item ) =>
			results.find(
				( result ) =>
					result.type === item.type && result.slug === item.slug
			)
		)
		.filter( Boolean );

	const message = readinessMessage(
		readiness( found, php ),
		found.length < active.length
	);
	if ( ! message ) {
		note.prop( 'hidden', true ).empty();
		return;
	}
	note.empty()
		.append( $( '<strong>' ).text( message.lead ) )
		.append( document.createTextNode( ` ${ message.text }` ) )
		.prop( 'hidden', false );
}

/**
 * Hide the note when a new scan starts.
 */
export function hideReadiness() {
	$( '.wpe-pcc-readiness' ).prop( 'hidden', true ).empty();
}

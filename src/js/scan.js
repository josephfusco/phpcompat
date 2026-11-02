/* global checkerList */
import '../scss/scan.scss';
import jQuery from 'jquery';
import {
	initQueue,
	runNextJob,
	limitQueue,
	startProgress,
} from './include/queue-manager';
import { initResults, getSelectedIds } from './include/render';
import { downloadReport, reportMeta } from './include/download';
import { printReport } from './include/print-report';
import { savePdfReport, needsUnicodeFallback } from './include/pdf-report';

( function ( $ ) {
	if ( 'undefined' === typeof checkerList ) {
		return;
	}

	window.phpcompat = {
		queue: [],
		total: 0,
		completed: 0,
		scanId: 0,
		running: false,
		results: [],
		report: [],
	};

	const activeOnlySwitch = $( 'input[type=radio][name=active_plugins]' );
	const runButton = $( '#runButton' );

	init();

	activeOnlySwitch.on( 'change', function () {
		disableDeveloperMode();
		init();
	} );

	$( '#cleanupButton' ).on( 'click', function ( event ) {
		event.preventDefault();
		disableDeveloperMode();
		init();
	} );

	// Stop a running scan and go back to the list (#56).
	$( '#cancelButton' ).on( 'click', function ( event ) {
		event.preventDefault();
		init();
		runButton.trigger( 'focus' );
	} );

	runButton.on( 'click', function ( event ) {
		event.preventDefault();

		// "Choose" mode scans only the ticked items (#67).
		if ( 'choose' === mode() ) {
			const ids = getSelectedIds();
			if ( ! ids.length ) {
				return;
			}
			limitQueue( ids );
			$( '.wpe-pcc-select' )
				.not( ':checked' )
				.closest( '.wpe-pcc-alert' )
				.remove();
			$( '.wpe-pcc-select' ).prop( 'disabled', true );
			$( '.wpe-pcc-select-actions' ).hide();
		}

		// Recorded for the reports: what this scan covered.
		window.phpcompat.scope = mode();

		activeOnlySwitch.prop( 'disabled', true );
		$( this ).prop( 'disabled', true );
		startProgress();
		runNextJob();
	} );

	$( document ).on( 'change', '.wpe-pcc-select', updateRunButton );

	$( '.wpe-pcc-select-actions' ).on( 'click', 'button', function ( event ) {
		event.preventDefault();
		$( '.wpe-pcc-select' ).prop(
			'checked',
			'all' === $( this ).data( 'select' )
		);
		updateRunButton();
	} );

	$( document ).on(
		'click',
		'.wpe-pcc-php-version-errors',
		function ( event ) {
			event.preventDefault();
			const link = $( this );
			// getElementById: slugs can hold characters a selector would choke on.
			const report = $(
				document.getElementById( link.attr( 'aria-controls' ) )
			);
			const opening = ! report.is( ':visible' );
			$( '.wpe-pcc-php-version-report' ).not( report ).hide();
			$( '.wpe-pcc-php-version-errors' )
				.not( link )
				.attr( 'aria-expanded', 'false' );
			report.toggle( opening );
			link.attr( 'aria-expanded', opening ? 'true' : 'false' );
		}
	);

	$( document ).on(
		'click',
		'.wpe-pcc-php-version-report-close',
		function ( event ) {
			event.preventDefault();
			const report = $( this )
				.closest( '.wpe-pcc-php-version-report' )
				.hide();
			$( `[aria-controls="${ report.attr( 'id' ) }"]` )
				.attr( 'aria-expanded', 'false' )
				.trigger( 'focus' );
		}
	);

	$( '#developermode' ).on( 'change', function () {
		const raw = $( this ).is( ':checked' );
		$( '#developerMode' ).toggle( raw );
		$( '#wpe_pcc_results' ).toggle( ! raw );
	} );

	$( '#downloadReport' ).on( 'click', async function ( event ) {
		event.preventDefault();
		const format = $( '#wpe-pcc-export-format' ).val();
		if ( 'pdf' !== format ) {
			downloadReport( format );
			return;
		}

		// PDF: a tagged, accessible PDF downloaded in one click (PDFKit,
		// loaded on demand). Its built-in fonts only cover Latin text, so other
		// scripts use the accessible print version instead.
		const items = window.phpcompat.report;
		if ( ! items.length ) {
			return;
		}
		const meta = reportMeta( items );
		if ( needsUnicodeFallback( items, meta ) ) {
			printReport( items, meta );
			return;
		}
		try {
			await savePdfReport( items, meta );
		} catch {
			printReport( items, meta );
		}
	} );

	function mode() {
		return activeOnlySwitch.filter( ':checked' ).val();
	}

	function init() {
		initQueue( checkerList, mode() );
		initResults( checkerList, mode() );
		activeOnlySwitch.prop( 'disabled', false );
		$( '.wpe-pcc-select-actions' ).toggle( 'choose' === mode() );
		runButton.prop( 'disabled', false );
		updateRunButton();
	}

	function updateRunButton() {
		if ( 'choose' === mode() ) {
			runButton.prop( 'disabled', ! getSelectedIds().length );
		}
	}

	function disableDeveloperMode() {
		$( '#developermode' ).prop( 'checked', false );
		$( '#developerMode' ).hide();
		$( '#wpe_pcc_results' ).show();
	}
} )( jQuery );

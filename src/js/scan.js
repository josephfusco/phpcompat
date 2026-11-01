/* global checkerList */
import '../scss/scan.scss';
import jQuery from 'jquery';
import { __, _n, sprintf } from '@wordpress/i18n';
import { initQueue, runNextJob, limitQueue } from './include/queue-manager';
import { initResults, getSelectedIds } from './include/render';
import { downloadReport } from './include/download';

( function ( $ ) {
	if ( 'undefined' === typeof checkerList ) {
		return;
	}

	window.phpcompat = {
		queue: [],
		total: 0,
		completed: 0,
		xhr: false,
		ticker: false,
		results: [],
	};

	const runButton = $( '#runButton' );

	init();

	$( '#cleanupButton' ).on( 'click', function ( event ) {
		event.preventDefault();
		disableDeveloperMode();
		init();
	} );

	runButton.on( 'click', function ( event ) {
		event.preventDefault();

		const ids = getSelectedIds();
		if ( ! ids.length ) {
			return;
		}
		limitQueue( ids );

		// Drop unselected items from the list and lock the selection.
		$( '.wpe-pcc-select' )
			.not( ':checked' )
			.closest( '.wpe-pcc-alert' )
			.remove();
		$( '.wpe-pcc-select' ).prop( 'disabled', true );
		$( '.wpe-pcc-select-actions' ).hide();
		$( '#wpe_pcc_results h3' ).each( function () {
			if ( ! $( this ).nextUntil( 'h3', '.wpe-pcc-alert' ).length ) {
				$( this ).remove();
			}
		} );

		runNextJob();
		$( '.wpe-pcc-spinner' ).show();
		runButton
			.prop( 'disabled', true )
			.val( __( 'Scanning…', 'php-compatibility-checker' ) );
	} );

	$( document ).on( 'change', '.wpe-pcc-select', updateRunButton );

	$( document ).on(
		'click',
		'.wpe-pcc-select-actions button',
		function ( event ) {
			event.preventDefault();
			const select = $( this ).data( 'select' );
			$( '.wpe-pcc-select' ).each( function () {
				let checked = 'all' === select;
				if ( 'active' === select ) {
					checked = 'yes' === $( this ).data( 'active' );
				}
				$( this ).prop( 'checked', checked );
			} );
			updateRunButton();
		}
	);

	$( document ).on(
		'click',
		'.wpe-pcc-php-version-errors',
		function ( event ) {
			event.preventDefault();
			const button = $( this );
			const phpVersion = button.data( 'php-version' );
			const report = button
				.closest( '.wpe-pcc-alert' )
				.find(
					`.wpe-pcc-php-version-report[data-php-version="${ phpVersion }"]`
				);
			const opening = ! report.is( ':visible' );

			// Only one report open at a time.
			$( '.wpe-pcc-php-version-report' ).not( report ).hide();
			$( '.wpe-pcc-php-version-errors' )
				.not( button )
				.attr( 'aria-expanded', 'false' );

			report.toggle( opening );
			button.attr( 'aria-expanded', opening ? 'true' : 'false' );
		}
	);

	$( document ).on(
		'click',
		'.wpe-pcc-php-version-report-close',
		function ( event ) {
			event.preventDefault();
			const report = $( this ).closest( '.wpe-pcc-php-version-report' );
			const phpVersion = report.data( 'php-version' );
			report.hide();
			report
				.closest( '.wpe-pcc-alert' )
				.find(
					`.wpe-pcc-php-version-errors[data-php-version="${ phpVersion }"]`
				)
				.attr( 'aria-expanded', 'false' )
				.trigger( 'focus' );
		}
	);

	$( '#developermode' ).on( 'change', function () {
		const raw = $( this ).is( ':checked' );
		$( '#developerMode' ).toggle( raw );
		$( '#wpe_pcc_results' ).toggle( ! raw );
	} );

	$( '#downloadReport' ).on( 'click', function ( event ) {
		event.preventDefault();
		downloadReport();
	} );

	function init() {
		initQueue( checkerList );
		initResults( checkerList );
		$( '.wpe-pcc-toolbar' ).show();
		updateRunButton();
	}

	// The scan button shows how many items are selected, and is disabled
	// when there are none.
	function updateRunButton() {
		const count = getSelectedIds().length;
		runButton.prop( 'disabled', ! count );
		runButton.val(
			sprintf(
				/* translators: %d: number of selected plugins and themes. */
				_n(
					'Scan %d item',
					'Scan %d items',
					count,
					'php-compatibility-checker'
				),
				count
			)
		);
	}

	function disableDeveloperMode() {
		$( '#developermode' ).prop( 'checked', false );
		$( '#developerMode' ).hide();
		$( '#wpe_pcc_results' ).show();
	}
} )( jQuery );

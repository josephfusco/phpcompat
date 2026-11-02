<?php
/**
 * A phpcs report that collects messages into an array instead of printing them.
 *
 * phpcs's own JSON report builds its output with json_encode() per message.
 * When a message quotes source code that isn't valid UTF-8, that call returns
 * false and the report is no longer valid JSON. Collecting the arrays directly
 * avoids the round trip, and messages are made valid UTF-8 here.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat\Bundle;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Reports\Report;

/**
 * Collects each file's messages for run_phpcs().
 */
class Collect_Report implements Report {

	/**
	 * Messages of the current run: path => list of messages.
	 *
	 * @var array<string, array[]>
	 */
	public static $files = array();

	/**
	 * Store one file's messages.
	 *
	 * @param array $report      File report from phpcs.
	 * @param File  $phpcs_file  The file.
	 * @param bool  $show_sources Unused.
	 * @param int   $width        Unused.
	 * @return bool Always false: nothing is printed.
	 */
	public function generateFileReport( array $report, File $phpcs_file, bool $show_sources = false, int $width = 80 ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Interface method.
		$messages = array();
		foreach ( $report['messages'] as $line => $columns ) {
			foreach ( $columns as $column => $errors ) {
				foreach ( $errors as $error ) {
					$messages[] = array(
						'line'    => (int) $line,
						'column'  => (int) $column,
						'type'    => (string) $error['type'],
						'message' => self::utf8( (string) $error['message'] ),
						'source'  => (string) $error['source'],
					);
				}
			}
		}
		if ( $messages ) {
			self::$files[ (string) $report['filename'] ] = array( 'messages' => $messages );
		}
		return false;
	}

	/**
	 * Nothing to print at the end of the run.
	 *
	 * @param string $cached_data    Unused.
	 * @param int    $total_files    Unused.
	 * @param int    $total_errors   Unused.
	 * @param int    $total_warnings Unused.
	 * @param int    $total_fixable  Unused.
	 * @param bool   $show_sources   Unused.
	 * @param int    $width          Unused.
	 * @param bool   $interactive    Unused.
	 * @param bool   $to_screen      Unused.
	 */
	public function generate( string $cached_data, int $total_files, int $total_errors, int $total_warnings, int $total_fixable, bool $show_sources = false, int $width = 80, bool $interactive = false, bool $to_screen = true ) {}

	/**
	 * Text as valid UTF-8: source quoted from Latin-1 (or broken) files is converted.
	 *
	 * @param string $text Text.
	 * @return string UTF-8.
	 */
	public static function utf8( string $text ): string {
		if ( preg_match( '//u', $text ) ) {
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			// Most non-UTF-8 PHP files are Windows-1252 / Latin-1.
			return (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
	}
}

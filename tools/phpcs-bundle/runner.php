<?php
/**
 * Runs PHPCompatibilityWP over a directory for several PHP versions.
 *
 * Shipped in build/scanner/ next to vendor/ and collect-report.php. The same file runs for the scan
 * page (a batch of files per request) and under WP-CLI (a whole directory), so
 * both produce identical results.
 *
 * Each PHP version is checked on its own (testVersion "8.0", not a range), so a
 * version's result means "this code breaks on PHP 8.0" — the same meaning the
 * plugin's results have always had.
 *
 * To keep that affordable, the first pass checks every file once against the
 * whole range (e.g. "7.4-8.5"), which flags anything that breaks on any version
 * in it. Only the files it flags get the per-version passes; every other file
 * is compatible with every version. Most plugins have few flagged files, so
 * this is several times faster than a full pass per version.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat\Bundle;

// Only runs when the plugin (or WP-CLI) includes it.
defined( 'ABSPATH' ) || exit;

/**
 * Scan a directory.
 *
 * @param string   $dir      Directory to scan.
 * @param string[] $versions PHP versions in major.minor form, e.g. array( '7.4', '8.0' ).
 * @return array {
 *     @type string[] $compatible   Versions with no errors.
 *     @type string[] $incompatible Versions with at least one error.
 *     @type array    $versions     Per version: totals (errors, warnings) and files
 *                                  (path relative to $dir => errors, warnings, messages).
 *                                  Only files with messages are listed.
 * }
 */
function scan( string $dir, array $versions ): array {
	// phpcs reports real paths; match them.
	$real = realpath( $dir );
	$dir  = rtrim( slashes( false !== $real ? $real : $dir ), '/' );

	// Paths in the report are relative to $base (a one-file plugin's own folder).
	return scan_targets( is_file( $dir ) ? dirname( $dir ) : $dir, array( $dir ), $versions );
}

/**
 * Scan some files of a directory, e.g. one batch at a time on a busy server.
 *
 * Reports from several batches of the same directory can be merged: each
 * version's files are disjoint, totals add up, and a version is incompatible
 * if any batch found an error in it.
 *
 * @param string   $base     Directory the paths are relative to.
 * @param string[] $files    Relative paths of PHP files inside $base.
 * @param string[] $versions PHP versions.
 * @return array Report (see scan()).
 */
function scan_files( string $base, array $files, array $versions ): array {
	$real    = realpath( $base );
	$base    = rtrim( slashes( false !== $real ? $real : $base ), '/' );
	$targets = array();

	foreach ( $files as $file ) {
		$path = realpath( $base . '/' . ltrim( (string) $file, '/' ) );
		// Only files inside $base (compared with forward slashes, for Windows).
		if ( false !== $path && is_file( $path ) && 0 === strpos( slashes( $path ), $base . '/' ) ) {
			$targets[] = $path;
		}
	}

	return scan_targets( $base, $targets, $versions );
}

/**
 * Two-pass scan of files and directories.
 *
 * @param string   $base     Directory paths in the report are relative to.
 * @param string[] $targets  Absolute paths of files or directories.
 * @param string[] $versions PHP versions.
 * @return array Report (see scan()).
 */
function scan_targets( string $base, array $targets, array $versions ): array {
	require_once __DIR__ . '/vendor/squizlabs/php_codesniffer/autoload.php';

	// Another plugin (e.g. one bundling PHP_CodeSniffer 3) may have loaded its
	// own copy first; its results wouldn't match, so say so instead.
	if ( version_compare( \PHP_CodeSniffer\Config::VERSION, '4.0.0', '<' ) ) {
		// Not HTML: the message goes back as JSON (REST) or plain text (WP-CLI).
		throw new \RuntimeException( sprintf( 'Another plugin loaded PHP_CodeSniffer %s, which this scanner cannot use. Scan with WP-CLI (wp php-compat scan), or deactivate that plugin while scanning.', \PHP_CodeSniffer\Config::VERSION ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned as JSON or CLI text, never printed as HTML.
	}

	$result = array(
		'compatible'   => array(),
		'incompatible' => array(),
		'versions'     => array(),
	);

	$versions = array_values(
		array_filter(
			$versions,
			static function ( $version ) {
				return (bool) preg_match( '/^\d+\.\d+$/', $version );
			}
		)
	);
	usort( $versions, 'version_compare' );

	if ( ! $versions ) {
		return $result;
	}

	// Pass 1: the whole range, every file.
	$flagged = array();
	$range   = reset( $versions ) . '-' . end( $versions );
	$report  = $targets ? run_phpcs( $targets, count( $versions ) > 1 ? $range : $versions[0] ) : array();
	foreach ( isset( $report['files'] ) ? $report['files'] : array() as $path => $file ) {
		foreach ( $file['messages'] as $message ) {
			if ( 0 !== strpos( (string) $message['source'], 'Internal.' ) ) {
				$flagged[] = $path;
				break;
			}
		}
	}

	foreach ( $versions as $version ) {
		// Pass 2: each version, flagged files only.
		$report = $flagged ? run_phpcs( $flagged, $version ) : array();
		$files  = array();
		$totals = array(
			'errors'   => 0,
			'warnings' => 0,
		);

		foreach ( isset( $report['files'] ) ? $report['files'] : array() as $path => $file ) {
			$messages = array();
			$counts   = array(
				'errors'   => 0,
				'warnings' => 0,
			);

			foreach ( $file['messages'] as $message ) {
				// "No PHP code found" and similar are about the scan, not compatibility.
				if ( 0 === strpos( (string) $message['source'], 'Internal.' ) ) {
					continue;
				}
				$type       = 'ERROR' === $message['type'] ? 'ERROR' : 'WARNING';
				$messages[] = array(
					'line'    => (int) $message['line'],
					'column'  => (int) $message['column'],
					'type'    => $type,
					'message' => (string) $message['message'],
					'source'  => (string) $message['source'],
				);
				++$counts[ 'ERROR' === $type ? 'errors' : 'warnings' ];
			}

			if ( ! $messages ) {
				continue;
			}

			$relative           = ltrim( substr( slashes( $path ), strlen( $base ) ), '/' );
			$files[ $relative ] = array_merge( $counts, array( 'messages' => $messages ) );
			$totals['errors']  += $counts['errors'];
			$totals['warnings'] += $counts['warnings'];
		}

		ksort( $files );
		$result['versions'][ $version ] = array(
			'totals' => $totals,
			'files'  => $files,
		);
		$result[ $totals['errors'] ? 'incompatible' : 'compatible' ][] = $version;
	}

	return $result;
}

/**
 * One phpcs run.
 *
 * @param string[] $paths        Files or directories to scan.
 * @param string   $test_version PHP version or range, e.g. "8.0" or "7.4-8.5".
 * @return array { @type array $files Real path => { @type array[] $messages line, column, type, message, source. } }
 */
function run_phpcs( array $paths, string $test_version ): array {
	reset_state();
	require_once __DIR__ . '/collect-report.php';
	Collect_Report::$files = array();

	// phpcs reads its arguments from $_SERVER.
	// Saved as-is and put back after the run; never output or used otherwise.
	$saved_argv = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored unchanged after the run.
	$saved_argc = isset( $_SERVER['argc'] ) ? $_SERVER['argc'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored unchanged after the run.

	$_SERVER['argv'] = array(
		'phpcs',
		'-q',
		'--standard=PHPCompatibilityWP',
		'--extensions=php',
		'--report=' . Collect_Report::class,
		'--no-cache',
		'--runtime-set',
		'testVersion',
		$test_version,
		'--',
	);
	foreach ( $paths as $path ) {
		$_SERVER['argv'][] = $path;
	}
	$_SERVER['argc'] = count( $_SERVER['argv'] );

	// Nothing should be printed; anything that is (a notice, phpcs's own
	// output on a failure) must not end up in the response.
	ob_start();
	try {
		( new \PHP_CodeSniffer\Runner() )->runPHPCS();
	} finally {
		$output          = (string) ob_get_clean();
		$_SERVER['argv'] = $saved_argv;
		$_SERVER['argc'] = $saved_argc;
	}

	$files = Collect_Report::$files;
	if ( ! $files && '' !== trim( $output ) && false !== stripos( $output, 'error' ) ) {
		throw new \RuntimeException( 'phpcs failed: ' . substr( Collect_Report::utf8( $output ), 0, 500 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned as JSON or CLI text, never printed as HTML.
	}

	return array( 'files' => $files );
}

/**
 * A path with forward slashes (Windows' realpath() returns backslashes).
 *
 * @param string $path Path.
 * @return string
 */
function slashes( string $path ): string {
	return str_replace( '\\', '/', $path );
}

/**
 * Clear state that PHPCompatibility and PHPCSUtils keep for a whole process,
 * so the next run uses its own testVersion.
 */
function reset_state(): void {
	$class = 'PHPCompatibility\\Helpers\\ScannedCode';
	if ( class_exists( $class, false ) ) {
		foreach ( array( 'testVersion', 'testVersions' ) as $name ) {
			$property = new \ReflectionProperty( $class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, null );
		}
	}

	foreach ( array( 'PHPCSUtils\\Internal\\Cache', 'PHPCSUtils\\Internal\\NoFileCache' ) as $cache ) {
		if ( class_exists( $cache, false ) ) {
			$cache::clear();
		}
	}
}

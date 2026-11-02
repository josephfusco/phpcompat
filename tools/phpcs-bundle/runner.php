<?php
/**
 * Runs PHPCompatibilityWP over a directory for several PHP versions.
 *
 * Shipped inside phpcs-bundle.zip next to vendor/. The same file runs in the
 * browser (PHP WebAssembly, via WordPress Playground) and under WP-CLI, so both
 * produce identical results.
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
	require_once __DIR__ . '/vendor/squizlabs/php_codesniffer/autoload.php';

	// phpcs reports real paths; match them.
	$real   = realpath( $dir );
	$dir    = rtrim( false !== $real ? $real : $dir, '/' );
	// Paths in the report are relative to this (a one-file plugin's own folder).
	$base   = is_file( $dir ) ? dirname( $dir ) : $dir;
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
	$report  = run_phpcs( array( $dir ), count( $versions ) > 1 ? $range : $versions[0] );
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

			$relative           = ltrim( substr( $path, strlen( $base ) ), '/' );
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
 * One phpcs run, returning its JSON report.
 *
 * @param string[] $paths       Files or directories to scan.
 * @param string   $test_version PHP version or range, e.g. "8.0" or "7.4-8.5".
 * @return array Decoded phpcs JSON report.
 */
function run_phpcs( array $paths, string $test_version ): array {
	reset_state();

	// phpcs reads its arguments from $_SERVER.
	$saved_argv = isset( $_SERVER['argv'] ) ? $_SERVER['argv'] : null;
	$saved_argc = isset( $_SERVER['argc'] ) ? $_SERVER['argc'] : null;

	$_SERVER['argv'] = array(
		'phpcs',
		'-q',
		'--standard=PHPCompatibilityWP',
		'--extensions=php',
		'--report=json',
		'--report-width=120',
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

	ob_start();
	try {
		( new \PHP_CodeSniffer\Runner() )->runPHPCS();
		$output = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		throw $e;
	} finally {
		$_SERVER['argv'] = $saved_argv;
		$_SERVER['argc'] = $saved_argc;
	}

	$report = json_decode( $output, true );
	if ( ! is_array( $report ) ) {
		throw new \RuntimeException( 'phpcs did not return a JSON report: ' . substr( $output, 0, 500 ) );
	}

	return $report;
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

<?php
/**
 * Runs PHPCompatibilityWP over plugin and theme files for several PHP versions.
 *
 * Shipped in build/scanner/ next to vendor/. The same file runs for the scan
 * page (a batch of files per request) and under WP-CLI (a whole directory), so
 * both produce identical results.
 *
 * Each PHP version is checked on its own (testVersion "8.0", not a range), so a
 * version's result means "this code breaks on PHP 8.0", the meaning the
 * plugin's results have always had.
 *
 * To keep that affordable, the first pass checks every file once against the
 * whole range (e.g. "7.4-8.5"), which flags anything that breaks on any version
 * in it. Only the files it flags get the per-version passes; every other file
 * is compatible with every version.
 *
 * PHP_CodeSniffer's ruleset is loaded once per PHP process and reused for
 * every file and version (loading it costs about a quarter of a second), and
 * files are processed one by one, so a caller can stop after any file when
 * its time is up.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat\Bundle;

// Only runs when the plugin (or WP-CLI) includes it.
defined( 'ABSPATH' ) || exit;

/**
 * Scan a directory, or a one-file plugin.
 *
 * @param string   $dir      Directory or file to scan.
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
	$dir = base_dir( $dir );

	// Paths in the report are relative to a one-file plugin's own folder.
	$base  = is_file( $dir ) ? dirname( $dir ) : $dir;
	$paths = is_file( $dir ) ? array( $dir ) : php_files( $dir );

	return report_flagged( $base, flag_paths( $paths, $versions )['flagged'], $versions );
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
	return report_flagged( $base, flag_files( $base, $files, $versions )['flagged'], $versions );
}

/**
 * Pass 1 for some files of a directory: which of them have a problem on any
 * version in the range. With a deadline, it stops after the file that passes
 * it (always checking at least one), so a request can stay within a time budget.
 *
 * @param string     $base     Directory the paths are relative to.
 * @param string[]   $files    Relative paths of PHP files inside $base.
 * @param string[]   $versions PHP versions.
 * @param float|null $deadline Optional. microtime( true ) to stop at.
 * @return array { @type string[] $flagged Absolute paths of flagged files. @type int $checked Files checked, from the start of $files. }
 */
function flag_files( string $base, array $files, array $versions, ?float $deadline = null ): array {
	$base  = base_dir( $base );
	$paths = array();
	foreach ( $files as $file ) {
		$path = realpath( $base . '/' . ltrim( (string) $file, '/' ) );
		// Only files inside $base (compared with forward slashes, for Windows).
		$paths[] = false !== $path && is_file( $path ) && 0 === strpos( slashes( $path ), $base . '/' ) ? $path : '';
	}

	return flag_paths( $paths, $versions, $deadline );
}

/**
 * Pass 1 over absolute paths ('' entries are counted as checked and skipped).
 *
 * @param string[]   $paths    Absolute paths of PHP files.
 * @param string[]   $versions PHP versions.
 * @param float|null $deadline Optional. microtime( true ) to stop at.
 * @return array { @type string[] $flagged Flagged paths. @type int $checked Paths checked. }
 */
function flag_paths( array $paths, array $versions, ?float $deadline = null ): array {
	$versions = normalize_versions( $versions );
	$flagged  = array();
	$checked  = 0;

	if ( ! $versions ) {
		return array(
			'flagged' => array(),
			'checked' => count( $paths ),
		);
	}

	$engine = Engine::get();
	$engine->use_version( count( $versions ) > 1 ? reset( $versions ) . '-' . end( $versions ) : $versions[0] );

	foreach ( $paths as $path ) {
		if ( $checked && null !== $deadline && microtime( true ) >= $deadline ) {
			break;
		}
		++$checked;
		if ( '' !== $path && $engine->messages( $path ) ) {
			$flagged[] = $path;
		}
	}

	return array(
		'flagged' => $flagged,
		'checked' => $checked,
	);
}

/**
 * Pass 2: each version on its own, for the flagged files only.
 *
 * @param string   $base     Directory paths in the report are relative to.
 * @param string[] $flagged  Absolute paths from flag_files().
 * @param string[] $versions PHP versions.
 * @return array Report (see scan()).
 */
function report_flagged( string $base, array $flagged, array $versions ): array {
	$base     = base_dir( $base );
	$versions = normalize_versions( $versions );
	$result   = array(
		'compatible'   => array(),
		'incompatible' => array(),
		'versions'     => array(),
	);

	foreach ( $versions as $version ) {
		$files  = array();
		$totals = array(
			'errors'   => 0,
			'warnings' => 0,
		);

		if ( $flagged ) {
			$engine = Engine::get();
			$engine->use_version( $version );

			foreach ( $flagged as $path ) {
				$messages = $engine->messages( $path );
				if ( ! $messages ) {
					continue;
				}
				$counts = array(
					'errors'   => 0,
					'warnings' => 0,
				);
				foreach ( $messages as $message ) {
					++$counts[ 'ERROR' === $message['type'] ? 'errors' : 'warnings' ];
				}

				$relative           = ltrim( substr( slashes( $path ), strlen( $base ) ), '/' );
				$files[ $relative ] = array_merge( $counts, array( 'messages' => $messages ) );
				$totals['errors']  += $counts['errors'];
				$totals['warnings'] += $counts['warnings'];
			}
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
 * PHP_CodeSniffer with PHPCompatibilityWP, loaded once and reused.
 */
final class Engine {

	/**
	 * The engine for this PHP process.
	 *
	 * @var Engine|null
	 */
	private static $instance = null;

	/**
	 * PHP_CodeSniffer configuration.
	 *
	 * @var \PHP_CodeSniffer\Config
	 */
	private $config;

	/**
	 * The loaded PHPCompatibilityWP ruleset.
	 *
	 * @var \PHP_CodeSniffer\Ruleset
	 */
	private $ruleset;

	/**
	 * The engine, loading PHP_CodeSniffer and the ruleset the first time.
	 *
	 * @return Engine
	 */
	public static function get(): Engine {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load PHP_CodeSniffer and the PHPCompatibilityWP ruleset.
	 *
	 * @throws \RuntimeException When another plugin loaded PHP_CodeSniffer 3 or older.
	 */
	private function __construct() {
		require_once __DIR__ . '/vendor/squizlabs/php_codesniffer/autoload.php';

		// Another plugin (e.g. one bundling PHP_CodeSniffer 3) may have loaded its
		// own copy first; its results wouldn't match, so say so instead.
		if ( version_compare( \PHP_CodeSniffer\Config::VERSION, '4.0.0', '<' ) ) {
			// Not HTML: the message goes back as JSON (REST) or plain text (WP-CLI).
			throw new \RuntimeException( sprintf( 'Another plugin loaded PHP_CodeSniffer %s, which this scanner cannot use. Scan with WP-CLI (wp php-compat scan), or deactivate that plugin while scanning.', \PHP_CodeSniffer\Config::VERSION ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Returned as JSON or CLI text, never printed as HTML.
		}

		// What \PHP_CodeSniffer\Runner::init() sets up, without its reporting.
		if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
			define( 'PHP_CODESNIFFER_CBF', false );
		}
		if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
			define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
		}

		// A file argument keeps Config from reading STDIN for code to check.
		$this->config = new \PHP_CodeSniffer\Config( array( '-q', '--standard=PHPCompatibilityWP', '--extensions=php', '--no-cache', __FILE__ ) );

		new \PHP_CodeSniffer\Util\Tokens();
		foreach ( \PHP_CodeSniffer\Util\Standards::getInstalledStandardDetails() as $details ) {
			\PHP_CodeSniffer\Autoload::addSearchPath( $details['path'], $details['namespace'] );
		}

		$this->ruleset = new \PHP_CodeSniffer\Ruleset( $this->config );
	}

	/**
	 * Check against a PHP version or range from now on, e.g. "8.0" or "7.4-8.5".
	 *
	 * @param string $test_version Version or range.
	 */
	public function use_version( string $test_version ): void {
		$this->config->setConfigData( 'testVersion', $test_version, true );
		reset_state();
	}

	/**
	 * Problems in one file for the current version: line, column, type, message, source.
	 *
	 * Problems about the scan itself (PHP_CodeSniffer's "Internal." codes, such
	 * as "no PHP code found") are left out.
	 *
	 * @param string $path Absolute path.
	 * @return array[]
	 */
	public function messages( string $path ): array {
		$file = new \PHP_CodeSniffer\Files\LocalFile( $path, $this->ruleset, $this->config );

		// As PHP_CodeSniffer's runner does, without leaving it off for the rest of the request.
		$jit = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- As PHP_CodeSniffer's runner does; restored right after.
		try {
			$file->process();
		} catch ( \Throwable $e ) {
			// PHP_CodeSniffer's runner records these as Internal errors, which aren't
			// compatibility problems; the file has nothing to report.
			$file->cleanUp();
			return array();
		} finally {
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restoring the original value.
		}

		$messages = array();
		foreach ( array(
			'ERROR'   => $file->getErrors(),
			'WARNING' => $file->getWarnings(),
		) as $type => $lines ) {
			foreach ( $lines as $line => $columns ) {
				foreach ( $columns as $column => $found ) {
					foreach ( $found as $message ) {
						if ( 0 === strpos( (string) $message['source'], 'Internal.' ) ) {
							continue;
						}
						$messages[] = array(
							'line'    => (int) $line,
							'column'  => (int) $column,
							'type'    => $type,
							'message' => utf8( (string) $message['message'] ),
							'source'  => (string) $message['source'],
						);
					}
				}
			}
		}
		$file->cleanUp();

		// Source and message break ties, so the order doesn't depend on which PHP
		// runs the scan (the site's, or PHP in the browser).
		usort(
			$messages,
			static function ( $a, $b ) {
				return array( $a['line'], $a['column'], $a['source'], $a['message'] ) <=> array( $b['line'], $b['column'], $b['source'], $b['message'] );
			}
		);

		return $messages;
	}
}

/**
 * Every PHP file in a directory, sorted. Doesn't follow symlinked directories.
 *
 * @param string $dir Directory.
 * @return string[] Absolute paths.
 */
function php_files( string $dir ): array {
	$paths    = array();
	// Same folders left out as Scanner::SKIPPED_FOLDERS in the plugin.
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			static function ( $entry ) {
				return ! ( $entry->isDir() && in_array( $entry->getFilename(), array( 'node_modules', '.git', '.svn', '.hg' ), true ) );
			}
		)
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$paths[] = $file->getPathname();
		}
	}
	sort( $paths );
	return $paths;
}

/**
 * Valid versions (major.minor), oldest first.
 *
 * @param string[] $versions PHP versions.
 * @return string[]
 */
function normalize_versions( array $versions ): array {
	$versions = array_values(
		array_filter(
			$versions,
			static function ( $version ) {
				return (bool) preg_match( '/^\d+\.\d+$/', $version );
			}
		)
	);
	usort( $versions, 'version_compare' );
	return $versions;
}

/**
 * A path's real path with forward slashes and no trailing slash.
 *
 * @param string $path Directory or file.
 * @return string
 */
function base_dir( string $path ): string {
	$real = realpath( $path );
	return rtrim( slashes( false !== $real ? $real : $path ), '/' );
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
 * Text as valid UTF-8: messages quoting source from Latin-1 (or broken) files
 * are converted, so the report can always be encoded as JSON.
 *
 * @param string $text Text.
 * @return string UTF-8.
 */
function utf8( string $text ): string {
	if ( preg_match( '//u', $text ) ) {
		return $text;
	}
	if ( function_exists( 'mb_convert_encoding' ) ) {
		// Most non-UTF-8 PHP files are Windows-1252 / Latin-1.
		return (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
	}
	return (string) preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
}

/**
 * Clear state that PHPCompatibility and PHPCSUtils keep for a whole process,
 * so the next pass uses its own testVersion.
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

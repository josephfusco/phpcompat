<?php
/**
 * The bundled PHP_CodeSniffer + PHPCompatibilityWP scanner.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

/**
 * Describes build/scanner/ and runs it in this PHP process.
 *
 * The same files run in two places, so results are identical:
 * - from the scan page, a few files per request (Rest_Controller::scan_batch()),
 *   so each request stays short on shared hosts;
 * - under WP-CLI, which has no request time limit.
 *
 * They're plain PHP files in the plugin, not an archive, because WordPress.org
 * doesn't accept compressed files in plugins.
 */
class Scanner_Bundle {

	/**
	 * PHP versions every item is checked against.
	 *
	 * WordPress 7.0 needs PHP 7.4, so older versions can't run current WordPress.
	 * 8.2 and newer are the branches php.net still supports.
	 */
	const PHP_VERSIONS = array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );

	/**
	 * Directory holding runner.php and vendor/.
	 *
	 * @return string
	 */
	public static function dir(): string {
		return WPEPHPCOMPAT_PLUGIN_DIR . 'build/scanner';
	}

	/**
	 * Build manifest: ruleset, package versions, file list, and a sha256 over every file.
	 *
	 * @return array
	 */
	public static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$file     = WPEPHPCOMPAT_PLUGIN_DIR . 'build/scanner.json';
			$data     = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$manifest = is_array( $data ) ? $data : array();
		}

		return $manifest;
	}

	/**
	 * Rule set identifier, used to key stored results.
	 *
	 * @return string
	 */
	public static function ruleset(): string {
		$manifest = self::manifest();
		return isset( $manifest['ruleset'] ) ? (string) $manifest['ruleset'] : 'unknown';
	}

	/**
	 * The tools and versions, for people: "PHPCompatibilityWP 3.0.0 (PHPCompatibility 10.0.0, PHP_CodeSniffer 4.0.4)".
	 *
	 * @return string
	 */
	public static function tools(): string {
		$packages = (array) ( self::manifest()['packages'] ?? array() );
		$version  = static function ( $name ) use ( $packages ) {
			return isset( $packages[ $name ] ) ? (string) $packages[ $name ] : '';
		};

		if ( '' === $version( 'phpcompatibility/phpcompatibility-wp' ) ) {
			return 'PHPCompatibilityWP (' . self::ruleset() . ')';
		}

		return sprintf(
			'PHPCompatibilityWP %1$s (PHPCompatibility %2$s, PHP_CodeSniffer %3$s)',
			$version( 'phpcompatibility/phpcompatibility-wp' ),
			$version( 'phpcompatibility/php-compatibility' ),
			$version( 'squizlabs/php_codesniffer' )
		);
	}

	/**
	 * Whether this copy of the scanner is complete and unchanged: every file
	 * in the build manifest is there and the SHA-256 over them matches. The
	 * check reads about 5 MB, so its result is kept for a day per build.
	 *
	 * @return bool
	 */
	public static function verify(): bool {
		$manifest = self::manifest();
		$expected = isset( $manifest['sha256'] ) ? (string) $manifest['sha256'] : '';
		$files    = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();

		if ( '' === $expected || ! $files ) {
			return false;
		}

		$key = 'wpephpcompat_scanner_ok';
		if ( get_transient( $key ) === $expected ) {
			return true;
		}

		$hash = hash_init( 'sha256' );
		foreach ( $files as $relative ) {
			$file = self::dir() . '/' . $relative;
			if ( ! is_readable( $file ) ) {
				return false;
			}
			hash_update( $hash, $relative . "\0" . hash_file( 'sha256', $file ) . "\n" );
		}

		if ( ! hash_equals( $expected, hash_final( $hash ) ) ) {
			return false;
		}

		set_transient( $key, $expected, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Settings the scan page needs.
	 *
	 * @return array
	 */
	public static function browser_config(): array {
		return array(
			'ruleset'     => self::ruleset(),
			'tools'       => self::tools(),
			'phpVersions' => self::PHP_VERSIONS,
		);
	}

	/**
	 * Scan a directory (or a one-file plugin) in this PHP process.
	 *
	 * @param string   $dir      Directory or file to scan.
	 * @param string[] $versions PHP versions; defaults to PHP_VERSIONS.
	 * @return array Report (see Results::sanitize_report()).
	 * @throws \RuntimeException When the scanner is missing.
	 */
	public static function scan_directory( string $dir, array $versions = self::PHP_VERSIONS ): array {
		self::load();

		return \WPEngine_PHPCompat\Bundle\scan( $dir, $versions );
	}

	/**
	 * Scan some files of a directory in this PHP process.
	 *
	 * @param string   $base     Directory the paths are relative to.
	 * @param string[] $files    Relative paths.
	 * @param string[] $versions PHP versions; defaults to PHP_VERSIONS.
	 * @return array Report for those files.
	 * @throws \RuntimeException When the scanner is missing.
	 */
	public static function scan_files( string $base, array $files, array $versions = self::PHP_VERSIONS ): array {
		self::load();

		return \WPEngine_PHPCompat\Bundle\scan_files( $base, $files, $versions );
	}

	/**
	 * Load runner.php.
	 *
	 * @throws \RuntimeException When the scanner is missing.
	 */
	private static function load(): void {
		$runner = self::dir() . '/runner.php';

		if ( ! is_readable( $runner ) ) {
			throw new \RuntimeException( esc_html__( 'The scanner is missing from this copy of the plugin. Reinstall it.', 'php-compatibility-checker' ) );
		}

		require_once $runner;
	}
}

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
	 * Whether scans may run from the scan page. A host can turn them off, for
	 * one site or a whole platform, with
	 * define( 'WPEPHPCOMPAT_DISABLE_SCANNING', true ) or the
	 * wpephpcompat_scanning_enabled filter. Stored results, reports, Site
	 * Health, and WP-CLI keep working.
	 *
	 * @return bool
	 */
	public static function scanning_enabled(): bool {
		$enabled = ! ( defined( 'WPEPHPCOMPAT_DISABLE_SCANNING' ) && WPEPHPCOMPAT_DISABLE_SCANNING );

		/**
		 * Filters whether scans may run from the scan page.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled False when WPEPHPCOMPAT_DISABLE_SCANNING is set.
		 */
		return (bool) apply_filters( 'wpephpcompat_scanning_enabled', $enabled );
	}

	/**
	 * What people see when scanning is turned off.
	 *
	 * @return string
	 */
	public static function disabled_message(): string {
		return __( 'Scanning from this page is turned off on this site, usually by the host. You can still scan with WP-CLI: wp php-compat scan.', 'php-compatibility-checker' );
	}

	/**
	 * The php-wasm build the browser engine loads: @php-wasm/web-8-3, the same
	 * version as the @php-wasm/universal bundled in build/wasm-worker.js.
	 */
	const WASM_PACKAGE = '@php-wasm/web-8-3@3.1.62';

	/**
	 * Where scans run (experimental):
	 * - "server": on this site's PHP, a batch per request.
	 * - "browser": in the admin's browser, in PHP compiled to WebAssembly; the
	 *   site only reads files.
	 * - "auto": the browser when it can load PHP, otherwise the server.
	 *
	 * Always "server" on multisite for anyone but super admins: the browser
	 * engine sends plugin source to the browser, and subsite admins can't
	 * otherwise read network-installed plugin code.
	 *
	 * @return string
	 */
	public static function engine(): string {
		if ( ! self::can_read_code() ) {
			return 'server';
		}

		/**
		 * Filters where scans from the scan page run.
		 *
		 * @since 2.0.0
		 *
		 * @param string $engine "auto", "browser", or "server".
		 */
		$engine = (string) apply_filters( 'wpephpcompat_scan_engine', 'auto' );

		return in_array( $engine, array( 'auto', 'browser', 'server' ), true ) ? $engine : 'auto';
	}

	/**
	 * Whether the current user may receive plugin and theme source (browser engine).
	 *
	 * @return bool
	 */
	public static function can_read_code(): bool {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * URL of build/wasm-worker.js, versioned by its build hash so an update
	 * isn't served a cached copy.
	 *
	 * @return string
	 */
	public static function worker_url(): string {
		$asset   = WPEPHPCOMPAT_PLUGIN_DIR . 'build/wasm-worker.asset.php';
		$version = is_readable( $asset ) ? ( ( require $asset )['version'] ?? WPEPHPCOMPAT_VERSION ) : WPEPHPCOMPAT_VERSION;

		return add_query_arg( 'ver', rawurlencode( (string) $version ), plugins_url( 'build/wasm-worker.js', WPEPHPCOMPAT_PLUGIN_FILE ) );
	}

	/**
	 * Base URL of the php-wasm loader and .wasm file for the browser engine
	 * (about 6.5 MB compressed, cached by the browser).
	 *
	 * @return string
	 */
	public static function wasm_base_url(): string {
		/**
		 * Filters where the browser loads PHP for WebAssembly from, e.g. a copy on
		 * the host's own CDN. The URL must serve the contents of the
		 * WASM_PACKAGE npm package with CORS allowed.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url Base URL, ending in a slash.
		 */
		return trailingslashit( (string) apply_filters( 'wpephpcompat_wasm_base_url', 'https://cdn.jsdelivr.net/npm/' . self::WASM_PACKAGE . '/' ) );
	}

	/**
	 * Settings the scan page needs.
	 *
	 * @return array
	 */
	public static function browser_config(): array {
		return array(
			'ruleset'         => self::ruleset(),
			'tools'           => self::tools(),
			'phpVersions'     => self::PHP_VERSIONS,
			'enabled'         => self::scanning_enabled(),
			'disabledMessage' => self::disabled_message(),
			'engine'          => self::engine(),
			'wasmBaseUrl'     => self::wasm_base_url(),
			'workerUrl'       => self::worker_url(),
			'sha256'          => (string) ( self::manifest()['sha256'] ?? '' ),
		);
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
	 * Pass 1 for some files: which have a problem on any PHP version checked.
	 * With a deadline, it stops after the file that passes it (at least one file).
	 *
	 * @param string     $base     Directory the paths are relative to.
	 * @param string[]   $files    Relative paths.
	 * @param float|null $deadline Optional. microtime( true ) to stop at.
	 * @return array { @type string[] $flagged Absolute paths of flagged files. @type int $checked Files checked, from the start. }
	 * @throws \RuntimeException When the scanner is missing.
	 */
	public static function flag_files( string $base, array $files, ?float $deadline = null ): array {
		self::load();

		return \WPEngine_PHPCompat\Bundle\flag_files( $base, $files, self::PHP_VERSIONS, $deadline );
	}

	/**
	 * Pass 2: the report for each PHP version, for flagged files only.
	 *
	 * @param string   $base    Directory paths in the report are relative to.
	 * @param string[] $flagged Absolute paths from flag_files().
	 * @return array Report (see Results::sanitize_report()).
	 * @throws \RuntimeException When the scanner is missing.
	 */
	public static function report_flagged( string $base, array $flagged ): array {
		self::load();

		return \WPEngine_PHPCompat\Bundle\report_flagged( $base, $flagged, self::PHP_VERSIONS );
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

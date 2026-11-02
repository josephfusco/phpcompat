<?php
/**
 * The bundled PHP_CodeSniffer + PHPCompatibilityWP scanner.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

/**
 * Describes build/phpcs-bundle.zip and runs it natively for WP-CLI.
 *
 * In wp-admin the zip runs in the browser on PHP WebAssembly (WordPress
 * Playground), so scanning costs the server nothing. WP-CLI has no browser
 * and no time limit, so it unpacks the same zip to a temporary directory and
 * runs the same runner.php in-process.
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
	 * Build manifest (ruleset, package versions, sha256).
	 *
	 * @return array
	 */
	public static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$file     = WPEPHPCOMPAT_PLUGIN_DIR . 'build/phpcs-bundle.json';
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
	 * Settings the browser scanner needs.
	 *
	 * @return array
	 */
	public static function browser_config(): array {
		$manifest = self::manifest();

		return array(
			'bundleUrl'   => plugins_url( 'build/phpcs-bundle.zip', WPEPHPCOMPAT_PLUGIN_FILE ),
			'sha256'      => isset( $manifest['sha256'] ) ? (string) $manifest['sha256'] : '',
			'ruleset'     => self::ruleset(),
			'phpVersions' => self::PHP_VERSIONS,
			'remoteUrl'   => self::playground_remote_url(),
		);
	}

	/**
	 * Where the PHP WebAssembly runtime comes from.
	 *
	 * @return string
	 */
	public static function playground_remote_url(): string {
		/**
		 * Filters the WordPress Playground remote URL used to run scans in the browser.
		 *
		 * Point this at a self-hosted Playground to keep scans off playground.wordpress.net.
		 *
		 * @since 1.7.0
		 *
		 * @param string $url Playground remote.html URL.
		 */
		return (string) apply_filters( 'wpephpcompat_playground_remote_url', 'https://playground.wordpress.net/remote.html' );
	}

	/**
	 * Scan a directory with the bundled scanner, in this PHP process.
	 *
	 * @param string   $dir      Directory to scan.
	 * @param string[] $versions PHP versions; defaults to PHP_VERSIONS.
	 * @return array Report (see Results::sanitize_report()).
	 * @throws \RuntimeException When the bundle can't be unpacked or run.
	 */
	public static function scan_directory( string $dir, array $versions = self::PHP_VERSIONS ): array {
		require_once self::unpack() . '/runner.php';

		return \WPEngine_PHPCompat\Bundle\scan( $dir, $versions );
	}

	/**
	 * Unpack the bundle to a new private temporary directory, once per process.
	 *
	 * A fresh, randomly named directory (mode 0700) that only this process
	 * created, removed when the process ends: a predictable shared path could
	 * be planted by another user on the same server before we run its code.
	 *
	 * @return string Directory containing runner.php and vendor/.
	 * @throws \RuntimeException When it can't be unpacked.
	 */
	private static function unpack(): string {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}

		$zip_file = WPEPHPCOMPAT_PLUGIN_DIR . 'build/phpcs-bundle.zip';
		$manifest = self::manifest();

		if ( ! is_readable( $zip_file ) || empty( $manifest['sha256'] ) ) {
			throw new \RuntimeException( esc_html__( 'The scanner is missing from this copy of the plugin. Reinstall it.', 'php-compatibility-checker' ) );
		}

		if ( ! hash_equals( (string) $manifest['sha256'], (string) hash_file( 'sha256', $zip_file ) ) ) {
			throw new \RuntimeException( esc_html__( 'The scanner bundle doesn\'t match its checksum. Reinstall the plugin.', 'php-compatibility-checker' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( esc_html__( 'Scanning from WP-CLI needs PHP\'s zip extension.', 'php-compatibility-checker' ) );
		}

		$target = rtrim( get_temp_dir(), '/' ) . '/phpcompat-scanner-' . bin2hex( random_bytes( 12 ) );

		// mkdir() fails if anything already exists at the path, so the directory is ours.
		if ( ! @mkdir( $target, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new \RuntimeException( esc_html__( 'Couldn\'t create a temporary directory for the scanner.', 'php-compatibility-checker' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_file ) || ! $zip->extractTo( $target ) ) {
			self::remove_directory( $target );
			throw new \RuntimeException( esc_html__( 'Couldn\'t unpack the scanner to the temporary directory.', 'php-compatibility-checker' ) );
		}
		$zip->close();

		register_shutdown_function( array( __CLASS__, 'remove_directory' ), $target );
		$dir = $target;

		return $dir;
	}

	/**
	 * Delete a directory this class created.
	 *
	 * @param string $dir Directory.
	 */
	public static function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) || false === strpos( basename( $dir ), 'phpcompat-scanner-' ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

<?php
/**
 * Stored scan results.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

/**
 * Keeps the latest scan result for each plugin and theme.
 *
 * Results come from the scan page (via the REST API) or from WP-CLI. A
 * result belongs to one exact version and rule set, so a plugin update or a
 * newer PHPCompatibility simply misses the cache and gets scanned again. It
 * also keeps a fingerprint of the files scanned, so an unchanged item can be
 * reused without scanning (see Scanner::file_list()).
 *
 * Each plugin or theme has one non-autoloaded option, overwritten by its
 * next scan. Nothing is shared between items, so the browser can save several
 * results at once without them overwriting each other, page loads never pull
 * results into memory, and large reports don't bloat a single row.
 */
class Results {

	/**
	 * Option name prefix for one result.
	 */
	const PREFIX = 'wpephpcompat_result_';

	/**
	 * Most problems kept per PHP version, so one huge report can't fill the database.
	 */
	const MAX_MESSAGES_PER_VERSION = 2000;

	/**
	 * Largest accepted report, in bytes of JSON.
	 */
	const MAX_REPORT_BYTES = 4194304; // 4 MB.

	/**
	 * Rule set the bundled scanner uses, e.g. "phpcs-4.0.4+phpcompatibility-10.0.0-alpha2+wp-3.0.0-alpha2".
	 *
	 * @var string
	 */
	private $ruleset;

	/**
	 * Constructor.
	 *
	 * @param string $ruleset Rule set; defaults to the bundled scanner's.
	 */
	public function __construct( string $ruleset = '' ) {
		$this->ruleset = '' !== $ruleset ? $ruleset : Scanner_Bundle::ruleset();
	}

	/**
	 * The stored result for an item, if it matches the item's version and the current rule set.
	 *
	 * @param array $item Item with type, slug, and version.
	 * @return array|null {
	 *     @type string $version    Version scanned.
	 *     @type string $source     "local" (scanned from this site's files) or, for results stored by
	 *                              older versions, "wporg" (scanned from WordPress.org).
	 *     @type string $ruleset    Rule set used.
	 *     @type int    $scanned_at Unix time.
	 *     @type string $fingerprint Fingerprint of the files scanned, or empty.
	 *     @type array  $report     Report (see sanitize_report()).
	 * }
	 */
	public function get( array $item ): ?array {
		$stored = get_option( self::option_name( $item ) );

		if ( ! is_array( $stored ) || ! isset( $stored['report'], $stored['version'], $stored['ruleset'] ) ) {
			return null;
		}

		// Another version, or older rules: scan again.
		if ( (string) $item['version'] !== $stored['version'] || $this->ruleset !== $stored['ruleset'] ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Store a result, replacing the item's previous one.
	 *
	 * @param array  $item   Item with type, slug, and version.
	 * @param array  $report Report from the scanner.
	 * @param string $source      "wporg" or "local".
	 * @param string $fingerprint Fingerprint of the files scanned (Scanner::file_list()), so an
	 *                            unchanged item can reuse this result; empty to always rescan.
	 * @return array The stored result.
	 */
	public function save( array $item, array $report, string $source, string $fingerprint = '' ): array {
		$stored = array(
			'version'     => (string) $item['version'],
			'source'      => 'wporg' === $source ? 'wporg' : 'local',
			'ruleset'     => $this->ruleset,
			'scanned_at'  => time(),
			'fingerprint' => $fingerprint,
			'report'      => self::sanitize_report( $report ),
		);

		update_option( self::option_name( $item ), $stored, false );

		return $stored;
	}

	/**
	 * Delete every stored result.
	 */
	public static function delete_all(): void {
		global $wpdb;

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup; options have no API to list by prefix.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' )
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Keep only well-formed report data.
	 *
	 * Reports come from the browser, so treat them as untrusted: known keys only,
	 * versions as major.minor, plain-text messages, and a cap on how many.
	 *
	 * @param array $report Report: compatible, incompatible, and versions
	 *                      (version => totals, files (path => errors, warnings, messages)).
	 * @return array Sanitized report.
	 */
	public static function sanitize_report( array $report ): array {
		$clean = array(
			'compatible'   => array(),
			'incompatible' => array(),
			'versions'     => array(),
		);

		$versions = isset( $report['versions'] ) && is_array( $report['versions'] ) ? $report['versions'] : array();

		foreach ( $versions as $version => $data ) {
			$version = (string) $version;
			if ( ! preg_match( '/^\d{1,2}\.\d{1,2}$/', $version ) || ! is_array( $data ) ) {
				continue;
			}

			// Clean every message first, so totals (and pass/fail) count all of them.
			$by_file = array();
			$totals  = array(
				'errors'   => 0,
				'warnings' => 0,
			);
			foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $path => $file ) {
				$path = self::sanitize_path( (string) $path );
				if ( '' === $path || ! is_array( $file ) || empty( $file['messages'] ) || ! is_array( $file['messages'] ) ) {
					continue;
				}
				foreach ( $file['messages'] as $message ) {
					if ( ! is_array( $message ) ) {
						continue;
					}
					$type               = isset( $message['type'] ) && 'ERROR' === $message['type'] ? 'ERROR' : 'WARNING';
					$by_file[ $path ][] = array(
						'line'    => isset( $message['line'] ) ? absint( $message['line'] ) : 0,
						'column'  => isset( $message['column'] ) ? absint( $message['column'] ) : 0,
						'type'    => $type,
						'message' => isset( $message['message'] ) ? self::sanitize_message( (string) $message['message'] ) : '',
						'source'  => isset( $message['source'] ) ? preg_replace( '/[^A-Za-z0-9_.]/', '', (string) $message['source'] ) : '',
					);
					++$totals[ 'ERROR' === $type ? 'errors' : 'warnings' ];
				}
			}

			// Keep at most MAX_MESSAGES_PER_VERSION messages: errors before warnings.
			$files = array();
			$kept  = 0;
			foreach ( array( 'ERROR', 'WARNING' ) as $keep_type ) {
				foreach ( $by_file as $path => $messages ) {
					foreach ( $messages as $message ) {
						if ( $keep_type !== $message['type'] || $kept >= self::MAX_MESSAGES_PER_VERSION ) {
							continue;
						}
						if ( ! isset( $files[ $path ] ) ) {
							$files[ $path ] = array(
								'errors'   => 0,
								'warnings' => 0,
								'messages' => array(),
							);
						}
						$files[ $path ]['messages'][] = $message;
						++$files[ $path ][ 'ERROR' === $message['type'] ? 'errors' : 'warnings' ];
						++$kept;
					}
				}
			}
			foreach ( $files as $path => $file ) {
				usort(
					$files[ $path ]['messages'],
					// Same order as the scanner (runner.php), so a report reads the
					// same whether it was scanned on the server or in the browser.
					static function ( $a, $b ) {
						return array( $a['line'], $a['column'], $a['source'], $a['message'] ) <=> array( $b['line'], $b['column'], $b['source'], $b['message'] );
					}
				);
			}
			ksort( $files );

			$clean['versions'][ $version ] = array(
				'totals' => $totals,
				'files'  => $files,
			);
			// Derived from the messages, not trusted from the input.
			$clean[ $totals['errors'] ? 'incompatible' : 'compatible' ][] = $version;
		}

		// Files that weren't scanned (too big, past the file limit, or stopped the scan), so a pass isn't overstated.
		$skipped                = isset( $report['skipped'] ) && is_array( $report['skipped'] ) ? $report['skipped'] : array();
		$clean['skipped']       = array_slice( array_values( array_filter( array_map( array( __CLASS__, 'sanitize_path' ), array_map( 'strval', $skipped ) ) ) ), 0, 100 );
		$clean['skipped_count'] = isset( $report['skipped_count'] ) ? absint( $report['skipped_count'] ) : count( $clean['skipped'] );

		// The PHP version the item's Composer dependencies require (see Scanner::composer_php()).
		$platform              = isset( $report['platform_php'] ) ? (string) $report['platform_php'] : '';
		$clean['platform_php'] = preg_match( '/^\d{1,2}\.\d{1,2}$/', $platform ) ? $platform : '';

		uksort( $clean['versions'], 'version_compare' );
		usort( $clean['compatible'], 'version_compare' );
		usort( $clean['incompatible'], 'version_compare' );

		return $clean;
	}

	/**
	 * A plain-text message: valid UTF-8, no control characters, at most 1000 characters.
	 *
	 * Kept as text (with "<" and quotes intact, e.g. "<?php"); it's escaped
	 * wherever it's shown.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function sanitize_message( string $message ): string {
		if ( ! preg_match( '//u', $message ) ) {
			return '';
		}
		$message = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 1000 ) : substr( $message, 0, 1000 );
	}

	/**
	 * A relative file path inside a plugin or theme, or empty if it isn't one.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function sanitize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		if ( '' === $path || strlen( $path ) > 1024 || preg_match( '#(^/|(^|/)\.\.(/|$)|[\x00-\x1f])#', $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Option holding an item's result.
	 *
	 * @param array $item Item with type and slug.
	 * @return string
	 */
	private static function option_name( array $item ): string {
		return self::PREFIX . md5( $item['type'] . '|' . $item['slug'] );
	}
}

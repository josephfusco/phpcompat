<?php
/**
 * Server-side scanner, used by WP-CLI and the Abilities API.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * Turns scan reports into statuses for plugins and themes.
 *
 * Reports come from stored results (scans run from the scan page or by WP-CLI) or
 * from scanning the installed files right now (WP-CLI). Applies the same rules
 * as the scan page: versions below an item's "Requires PHP" aren't counted,
 * and items with nothing checkable are "not_checked".
 */
class Scanner {

	/**
	 * Use stored results only.
	 */
	const MODE_STORED = 'stored';

	/**
	 * Scan the installed files now (WP-CLI), and store the result.
	 */
	const MODE_LIVE = 'live';

	/**
	 * Provides the list of plugins and themes.
	 *
	 * @var PHP_Compatibility_Checker
	 */
	private $checker;

	/**
	 * Constructor.
	 *
	 * @param PHP_Compatibility_Checker|null $checker Source of the plugin and theme lists.
	 */
	public function __construct( $checker = null ) {
		$this->checker = $checker ? $checker : new PHP_Compatibility_Checker();
	}

	/**
	 * Plugins and themes that can be scanned.
	 *
	 * @param array $args {
	 *     Optional. Filters.
	 *
	 *     @type string[] $slugs       Only these slugs.
	 *     @type string   $type        "plugin" or "theme".
	 *     @type bool     $active_only Only active items.
	 * }
	 * @return array[] Items with type, slug, name, version, active (bool), and requires_php.
	 */
	public function get_items( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'slugs'       => array(),
				'type'        => '',
				'active_only' => false,
			)
		);

		$items = array();
		foreach ( $this->checker->get_plugins_to_scan() as $plugin ) {
			$items[] = $this->normalize_item( $plugin, 'plugin' );
		}
		foreach ( $this->checker->get_themes_to_scan() as $theme ) {
			$items[] = $this->normalize_item( $theme, 'theme' );
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $args ) {
					if ( $args['type'] && $item['type'] !== $args['type'] ) {
						return false;
					}
					if ( $args['active_only'] && ! $item['active'] ) {
						return false;
					}
					if ( $args['slugs'] && ! in_array( $item['slug'], (array) $args['slugs'], true ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * Scan items.
	 *
	 * @param array[]  $items           Items from get_items().
	 * @param bool     $include_details Include file, line, and message for each problem.
	 * @param string   $mode            MODE_STORED or MODE_LIVE.
	 * @param callable $progress        Optional. Called with each item before it is scanned (live mode).
	 * @return array {
	 *     @type array[] $items           Result per item (see scan_item()).
	 *     @type array   $summary         Count per status.
	 *     @type string  $max_php_checked Newest PHP version with results, or empty if none.
	 *     @type string  $source          Where results come from.
	 * }
	 */
	public function scan( $items, $include_details = false, $mode = self::MODE_STORED, $progress = null ) {
		$results = array();
		$summary = array_fill_keys( self::STATUSES, 0 );
		$max     = '';

		foreach ( $items as $item ) {
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $item );
			}
			$result = $this->scan_item( $item, $include_details, $mode );
			++$summary[ $result['status'] ];

			if ( $result['max_php_checked'] && ( ! $max || version_compare( $result['max_php_checked'], $max, '>' ) ) ) {
				$max = $result['max_php_checked'];
			}

			$results[] = $result;
		}

		return array(
			'items'           => $results,
			'summary'         => $summary,
			'max_php_checked' => $max,
			'source'          => self::source(),
		);
	}

	/**
	 * Every status an item can have.
	 */
	const STATUSES = array( 'passes', 'problems', 'not_checked', 'not_scanned', 'failed' );

	/**
	 * Where results come from, included in scan output.
	 *
	 * @return string
	 */
	public static function source() {
		return sprintf( 'PHPCompatibilityWP (%s)', Scanner_Bundle::ruleset() );
	}

	/**
	 * Scan one item.
	 *
	 * @param array  $item            Item from get_items().
	 * @param bool   $include_details Include file, line, and message for each problem.
	 * @param string $mode            MODE_STORED or MODE_LIVE.
	 * @return array Item fields plus status, reason, php (version => pass|fail|not_counted),
	 *               failing_versions, max_php_checked, scanned_at, and problems (when requested).
	 */
	public function scan_item( $item, $include_details = false, $mode = self::MODE_STORED ) {
		$results = new Results();

		if ( self::MODE_LIVE === $mode ) {
			$root = self::item_root( $item );
			if ( '' === $root && ! has_filter( 'wpephpcompat_pre_scan_report' ) ) {
				return $this->result( $item, 'failed', __( 'Couldn\'t find this plugin\'s or theme\'s files.', 'php-compatibility-checker' ) );
			}
			try {
				/**
				 * Filters the report for an item before WP-CLI scans its files.
				 *
				 * Return a report (see Results::sanitize_report()) to use it instead of
				 * scanning, e.g. results your host already has. Null scans as usual.
				 *
				 * @since 2.0.0
				 *
				 * @param array|null $report Report, or null to scan.
				 * @param array      $item   Item from get_items().
				 */
				$report      = apply_filters( 'wpephpcompat_pre_scan_report', null, $item );
				$fingerprint = '';
				if ( ! is_array( $report ) ) {
					// Taken before scanning, so a file edited during the scan isn't covered by it.
					$list                   = self::file_list( $root, self::fingerprint_salt( $item ) );
					$fingerprint            = $list['fingerprint'];
					$report                 = Scanner_Bundle::scan_directory( $root );
					$report['platform_php'] = $list['platform_php'];
				}
				$stored = $results->save( $item, $report, 'local', $fingerprint );
			} catch ( \Throwable $e ) {
				return $this->result( $item, 'failed', $e->getMessage() );
			}
		} else {
			$stored = $results->get( $item );
			if ( ! $stored ) {
				return $this->result( $item, 'not_scanned', __( 'Not scanned yet with this version. Run a scan in Tools > PHP Compatibility, or with wp php-compat scan.', 'php-compatibility-checker' ) );
			}
		}

		$result               = $this->evaluate( $item, $stored['report'], $include_details );
		$result['scanned_at'] = gmdate( 'c', (int) $stored['scanned_at'] );

		return $result;
	}

	/**
	 * Absolute path of an item's directory (or single file, for one-file plugins).
	 *
	 * @param array $item Item with type and file.
	 * @return string Real path, or empty if not found.
	 */
	public static function item_root( $item ) {
		$file = isset( $item['file'] ) ? (string) $item['file'] : '';

		// Without a file there's nothing to resolve; never fall back to the whole directory.
		if ( '' === $file || false !== strpos( $file, '..' ) || 0 === strpos( $file, '/' ) ) {
			return '';
		}

		if ( 'theme' === $item['type'] ) {
			$theme = wp_get_theme( $file );
			$path  = $theme->exists() ? $theme->get_stylesheet_directory() : '';
		} else {
			$main = WP_PLUGIN_DIR . '/' . $file;
			// One-file plugins (e.g. hello.php) are scanned on their own, not with their neighbours.
			$path = false === strpos( $file, '/' ) ? $main : dirname( $main );
		}

		$real = '' !== $path ? realpath( $path ) : false;
		if ( false === $real ) {
			return '';
		}

		// Must be inside (never equal to) the plugins or themes directory. A
		// symlinked plugin (e.g. a Composer path repository) is inside it
		// where it's installed, even if its files live elsewhere.
		$parent_dir = 'theme' === $item['type'] ? get_theme_root( $file ) : WP_PLUGIN_DIR;
		$parent     = realpath( $parent_dir );
		$inside     = false !== $parent && ( self::is_inside( $real, $parent ) || self::is_inside( $path, $parent_dir ) || self::is_inside( $path, $parent ) );

		return $inside ? self::normalize_path( $real ) : '';
	}

	/**
	 * A path with forward slashes, as WordPress uses on every OS (Windows'
	 * realpath() returns backslashes).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		return function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', (string) $path );
	}

	/**
	 * Whether a path is inside (never equal to) a directory, on any OS.
	 *
	 * @param string $path   Path.
	 * @param string $dir  Directory.
	 * @return bool
	 */
	public static function is_inside( $path, $dir ) {
		$path = self::normalize_path( $path );
		$dir  = rtrim( self::normalize_path( $dir ), '/' );

		return '' !== $dir
			&& 0 === strpos( $path, $dir . '/' )
			&& strlen( $path ) > strlen( $dir ) + 1
			&& ! preg_match( '#(^|/)\.\.(/|$)#', $path );
	}

	/**
	 * Files bigger than this aren't scanned (generated or data files, not code to check).
	 */
	const MAX_FILE_BYTES = 1048576; // 1 MB.

	/**
	 * Most PHP files listed for one item.
	 */
	const MAX_FILES = 20000;

	/**
	 * A file list with no files.
	 *
	 * @return array See file_list().
	 */
	public static function empty_file_list() {
		return array(
			'files'        => array(),
			'skipped'      => array(),
			'truncated'    => false,
			'fingerprint'  => '',
			'platform_php' => '',
		);
	}

	/**
	 * What else a file fingerprint depends on: the item's version and the rule set.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	public static function fingerprint_salt( $item ) {
		return (string) $item['version'] . '|' . Scanner_Bundle::ruleset();
	}

	/**
	 * An item's PHP files, sorted, with a fingerprint of their current state.
	 *
	 * The fingerprint covers every PHP file's path, size, and modification time
	 * (it only needs a directory walk, no reads), so it changes when any of them
	 * is added, removed, or edited.
	 *
	 * @param string $root Real path of the item's directory or single file.
	 * @param string $salt Mixed into the fingerprint, e.g. version and rule set.
	 * @return array {
	 *     @type string[] $files       Relative paths.
	 *     @type string[] $skipped     Relative paths of files too big to scan.
	 *     @type bool     $truncated   Whether there were more than MAX_FILES.
	 *     @type string   $fingerprint 32 hex characters.
	 * }
	 */
	public static function file_list( $root, $salt = '' ) {
		$list    = self::empty_file_list();
		$entries = array();

		if ( is_file( $root ) ) {
			$entries[ basename( $root ) ] = array( (int) filesize( $root ), (int) filemtime( $root ) );
		} elseif ( is_dir( $root ) ) {
			// Doesn't follow symlinked directories, so the walk stays inside the item.
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isLink() || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				if ( count( $entries ) >= self::MAX_FILES ) {
					$list['truncated'] = true;
					break;
				}
				$relative             = substr( $file->getPathname(), strlen( $root ) + 1 );
				$entries[ $relative ] = array( (int) $file->getSize(), (int) $file->getMTime() );
			}
		}

		ksort( $entries, SORT_STRING );

		$hash = hash_init( 'md5' );
		hash_update( $hash, $salt . "\n" );
		foreach ( $entries as $relative => $stat ) {
			hash_update( $hash, $relative . "\0" . $stat[0] . "\0" . $stat[1] . "\n" );
			if ( $stat[0] > self::MAX_FILE_BYTES ) {
				$list['skipped'][] = (string) $relative;
			} else {
				$list['files'][] = (string) $relative;
			}
		}
		$list['fingerprint']  = hash_final( $hash );
		$list['platform_php'] = is_dir( $root ) ? self::composer_php( $root, array_keys( $entries ) ) : '';

		return $list;
	}

	/**
	 * The PHP version an item's Composer dependencies require, from Composer's
	 * own vendor/composer/platform_check.php (written by `composer install`
	 * from every package's "php" requirement). Kept current by the libraries'
	 * authors, so it never goes stale.
	 *
	 * @param string   $root  Item directory.
	 * @param string[] $files Relative paths of its PHP files.
	 * @return string Major.minor, e.g. "8.1", or '' if there's no such file.
	 */
	public static function composer_php( $root, array $files ) {
		$highest = 0;

		foreach ( $files as $relative ) {
			if ( ! preg_match( '#(^|/)composer/platform_check\.php$#', (string) $relative ) ) {
				continue;
			}
			$code = (string) file_get_contents( $root . '/' . $relative, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, first 8 KB.
			if ( preg_match_all( '/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', $code, $matches ) ) {
				$highest = max( $highest, ...array_map( 'intval', $matches[1] ) );
			}
		}

		return $highest ? intdiv( $highest, 10000 ) . '.' . intdiv( $highest % 10000, 100 ) : '';
	}

	/**
	 * The PHP version an item really needs, when that's newer than its
	 * "Requires PHP" header says (or the header is missing).
	 *
	 * Two sources: the scan (the oldest version from which every checked
	 * version passes) and Composer's platform check.
	 *
	 * @param array $item   Item with requires_php.
	 * @param array $report Report (see Results::sanitize_report()).
	 * @return array|null {
	 *     @type string $declared "Requires PHP", or '' if none.
	 *     @type string $needed   Major.minor.
	 *     @type string $source   "code" (the scan) or "composer".
	 *     @type bool   $bundled  Whether everything that needs it is in bundled libraries.
	 * } Null when the header is right (or nothing is known).
	 */
	public static function needed_php( $item, $report ) {
		$versions = array_map( 'strval', array_keys( isset( $report['versions'] ) ? (array) $report['versions'] : array() ) );
		usort( $versions, 'version_compare' );

		// Oldest version from which everything newer passes too.
		$from    = '';
		$bundled = true;
		foreach ( array_reverse( $versions ) as $version ) {
			if ( ! empty( $report['versions'][ $version ]['totals']['errors'] ) ) {
				foreach ( array_keys( (array) ( $report['versions'][ $version ]['files'] ?? array() ) ) as $path ) {
					$bundled = $bundled && self::is_bundled( (string) $path );
				}
				break;
			}
			$from = $version;
		}
		// Only a finding when some older checked version fails.
		$oldest = (string) reset( $versions );
		$code   = '' !== $from && $oldest !== $from ? $from : '';

		$composer = isset( $report['platform_php'] ) ? (string) $report['platform_php'] : '';
		$declared = isset( $item['requires_php'] ) ? (string) $item['requires_php'] : '';
		$floor    = '' !== $declared ? $declared : $oldest;

		$candidates = array_filter(
			array(
				'code'     => $code,
				'composer' => $composer,
			),
			static function ( $version ) use ( $floor ) {
				return '' !== $version && '' !== $floor && version_compare( $version, $floor, '>' );
			}
		);
		if ( ! $candidates ) {
			return null;
		}

		uasort( $candidates, 'version_compare' );
		$needed = (string) end( $candidates );
		$source = (string) key( $candidates );

		return array(
			'declared' => $declared,
			'needed'   => $needed,
			'source'   => $source,
			'bundled'  => 'code' === $source && $bundled,
		);
	}

	/**
	 * Whether a path is in a bundled library folder (vendor, third-party, ...).
	 * Matches LIBRARY_FOLDERS in src/js/include/render.js.
	 *
	 * @param string $path Path relative to the item.
	 * @return bool
	 */
	public static function is_bundled( $path ) {
		return (bool) preg_match( '#(^|/)(vendor|vendor_prefixed|vendor-prefixed|third-party|node_modules)/#i', $path );
	}

	/**
	 * Turn a scan report into a result for the item.
	 *
	 * @param array $item            Item from get_items().
	 * @param array $report          Report (see Results::sanitize_report()).
	 * @param bool  $include_details Include file, line, and message for each problem.
	 * @return array Result (see scan_item()).
	 */
	public function evaluate( $item, $report, $include_details = false ) {
		$versions = array_map( 'strval', array_keys( isset( $report['versions'] ) ? (array) $report['versions'] : array() ) );
		usort( $versions, 'version_compare' );

		if ( ! $versions ) {
			return $this->result( $item, 'failed', __( 'The scan returned no results for this item.', 'php-compatibility-checker' ) );
		}

		$requires     = $item['requires_php'];
		$incompatible = isset( $report['incompatible'] ) ? array_map( 'strval', (array) $report['incompatible'] ) : array();
		$max          = end( $versions );
		$php          = array();
		$failing      = array();
		$problems     = array();

		foreach ( $versions as $version ) {
			if ( '' !== $requires && version_compare( $version, $requires, '<' ) ) {
				$php[ $version ] = 'not_counted';
				continue;
			}

			// Errors fail a version; warnings (deprecations) still run.
			$totals = isset( $report['versions'][ $version ]['totals'] ) ? $report['versions'][ $version ]['totals'] : array();
			$fails  = in_array( $version, $incompatible, true ) || ! empty( $totals['errors'] );

			$php[ $version ] = $fails ? 'fail' : 'pass';

			if ( $fails ) {
				$failing[] = $version;
				if ( $include_details ) {
					$problems[ $version ] = $this->problems_for_version( $report['versions'][ $version ] );
				}
			}
		}

		if ( ! in_array( 'pass', $php, true ) && ! in_array( 'fail', $php, true ) ) {
			$result = $this->result(
				$item,
				'not_checked',
				sprintf(
					/* translators: 1: minimum PHP version, 2: newest PHP version with results. */
					__( 'Requires PHP %1$s or newer, and results only go up to PHP %2$s.', 'php-compatibility-checker' ),
					$requires,
					$max
				)
			);
		} else {
			$result = $this->result( $item, $failing ? 'problems' : 'passes' );
		}

		$result['php']              = $php;
		$result['failing_versions'] = $failing;
		$result['max_php_checked']  = $max;
		$result['needs_php']        = self::needed_php( $item, $report );

		if ( $include_details ) {
			$result['problems'] = $problems;
		}

		return $result;
	}

	/**
	 * The server's PHP version and WordPress's assessment of it.
	 *
	 * @return array {
	 *     @type string    $php_version         Major.minor, e.g. "8.3".
	 *     @type string    $php_full_version    Full version, e.g. "8.3.36".
	 *     @type bool|null $is_secure           Whether it still gets security updates (null if unknown).
	 *     @type bool|null $is_recommended      Whether it meets WordPress's recommended version (null if unknown).
	 *     @type string    $recommended_version WordPress's recommended version, or empty if unknown.
	 *     @type string    $message             Plain-language summary.
	 *     @type array     $release             Patch release status from php.net (see Php_Releases::evaluate()).
	 * }
	 */
	public function php_status() {
		// Loads wp-admin/includes/misc.php when needed (WP-CLI, REST).
		$message  = $this->checker->get_php_status_message();
		$checker  = function_exists( 'wp_check_php_version' ) ? wp_check_php_version() : false;
		$response = is_array( $checker ) ? $checker : array();

		return array(
			'php_version'         => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'php_full_version'    => PHP_VERSION,
			'is_secure'           => isset( $response['is_secure'] ) ? (bool) $response['is_secure'] : null,
			'is_recommended'      => isset( $response['is_supported'] ) ? (bool) $response['is_supported'] : null,
			'recommended_version' => isset( $response['recommended_version'] ) ? (string) $response['recommended_version'] : '',
			'message'             => $message,
			'release'             => ( new Php_Releases() )->get_status(),
		);
	}

	/**
	 * Normalize an item from the plugin or theme list.
	 *
	 * @param array  $item Item from get_plugins_to_scan() or get_themes_to_scan().
	 * @param string $type "plugin" or "theme".
	 * @return array
	 */
	private function normalize_item( $item, $type ) {
		return array(
			'type'         => $type,
			'slug'         => (string) $item['slug'],
			'name'         => (string) $item['name'],
			'version'      => (string) $item['version'],
			'active'       => 'yes' === $item['active'],
			'requires_php' => isset( $item['requires_php'] ) ? (string) $item['requires_php'] : '',
			'source'       => isset( $item['source'] ) ? (string) $item['source'] : 'local',
			'file'         => isset( $item['file'] ) ? (string) $item['file'] : '',
		);
	}

	/**
	 * Base result for an item.
	 *
	 * @param array  $item   Item from get_items().
	 * @param string $status One of STATUSES.
	 * @param string $reason Explanation for not_checked, not_scanned, or failed.
	 * @return array
	 */
	private function result( $item, $status, $reason = '' ) {
		return array_merge(
			$item,
			array(
				'status'           => $status,
				'reason'           => $reason,
				'php'              => array(),
				'failing_versions' => array(),
				'max_php_checked'  => '',
				'needs_php'        => null,
				'scanned_at'       => '',
			)
		);
	}

	/**
	 * Problems reported for one PHP version.
	 *
	 * @param array $version_report Report for a single PHP version.
	 * @return array[] Each with file, line, type, message, and source.
	 */
	private function problems_for_version( $version_report ) {
		$problems = array();
		$files    = isset( $version_report['files'] ) ? (array) $version_report['files'] : array();

		foreach ( $files as $file => $file_report ) {
			foreach ( isset( $file_report['messages'] ) ? (array) $file_report['messages'] : array() as $message ) {
				$problems[] = array(
					'file'    => (string) $file,
					'line'    => isset( $message['line'] ) ? (int) $message['line'] : 0,
					'type'    => isset( $message['type'] ) ? (string) $message['type'] : '',
					'message' => isset( $message['message'] ) ? (string) $message['message'] : '',
					'source'  => isset( $message['source'] ) ? (string) $message['source'] : '',
				);
			}
		}

		return $problems;
	}
}

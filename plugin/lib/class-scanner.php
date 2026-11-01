<?php
/**
 * Server-side scanner, used by WP-CLI and the Abilities API.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * Looks up Tide results for plugins and themes and turns them into statuses.
 *
 * Applies the same rules as the scan page: versions below an item's
 * "Requires PHP" aren't counted, items with nothing checkable are
 * "not_checked", and items Tide doesn't know (not on WordPress.org) are skipped.
 */
class Scanner {

	/**
	 * Tide audit endpoint.
	 */
	const TIDE_URL = 'https://wptide.org/api/v1/audit/wporg/%s/%s/%s?reports=phpcs_phpcompatibilitywp';

	/**
	 * Where results come from, included in scan output.
	 */
	const SOURCE = 'Tide (wptide.org), using PHPCompatibilityWP';

	/**
	 * Cache lifetime for completed audits. Tide results for a given release don't change.
	 */
	const CACHE_COMPLETE = WEEK_IN_SECONDS;

	/**
	 * Cache lifetime for "not on WordPress.org" responses.
	 */
	const CACHE_NOT_FOUND = DAY_IN_SECONDS;

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
	 * @param array[] $items           Items from get_items().
	 * @param bool    $include_details Include file, line, and message for each problem.
	 * @return array {
	 *     @type array[] $items           Result per item (see scan_item()).
	 *     @type array   $summary         Count per status.
	 *     @type string  $max_php_checked Newest PHP version with results, or empty if none.
	 *     @type string  $source          Where results come from.
	 * }
	 */
	public function scan( $items, $include_details = false ) {
		$results = array();
		$summary = array_fill_keys( array( 'passes', 'problems', 'not_checked', 'pending', 'failed' ), 0 );
		$max     = '';

		foreach ( $items as $item ) {
			$result = $this->scan_item( $item, $include_details );
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
			'source'          => self::SOURCE,
		);
	}

	/**
	 * Scan one item.
	 *
	 * @param array $item            Item from get_items().
	 * @param bool  $include_details Include file, line, and message for each problem.
	 * @return array Item fields plus status, reason, php (version => pass|fail|not_counted),
	 *               failing_versions, max_php_checked, and problems (when requested).
	 */
	public function scan_item( $item, $include_details = false ) {
		$audit = $this->fetch_audit( $item );

		if ( 'complete' !== $audit['state'] ) {
			return $this->result( $item, $audit['state'], $audit['reason'] );
		}

		return $this->evaluate( $item, $audit['report'], $include_details );
	}

	/**
	 * Turn a Tide report into a result for the item.
	 *
	 * @param array $item            Item from get_items().
	 * @param array $report          Tide "report" object.
	 * @param bool  $include_details Include file, line, and message for each problem.
	 * @return array Result (see scan_item()).
	 */
	public function evaluate( $item, $report, $include_details = false ) {
		$versions = array_map( 'strval', array_keys( isset( $report['versions'] ) ? (array) $report['versions'] : array() ) );
		usort( $versions, 'version_compare' );

		if ( ! $versions ) {
			return $this->result( $item, 'failed', __( 'Tide returned no results for this item.', 'php-compatibility-checker' ) );
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

			$totals = isset( $report['versions'][ $version ]['totals'] ) ? $report['versions'][ $version ]['totals'] : array();
			$fails  = in_array( $version, $incompatible, true )
				|| ! empty( $totals['errors'] )
				|| ! empty( $totals['warnings'] );

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

		if ( $include_details ) {
			$result['problems'] = $problems;
		}

		return $result;
	}

	/**
	 * Fetch (or read cached) Tide audit for an item.
	 *
	 * @param array $item Item from get_items().
	 * @return array {
	 *     @type string $state  complete, pending, not_checked, or failed.
	 *     @type string $reason Explanation when not complete.
	 *     @type array  $report Tide report when complete.
	 * }
	 */
	public function fetch_audit( $item ) {
		$cache_key = 'wpephpcompat_tide_' . md5( $item['type'] . '/' . $item['slug'] . '/' . $item['version'] );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( self::TIDE_URL, rawurlencode( $item['type'] ), rawurlencode( $item['slug'] ), rawurlencode( $item['version'] ) ),
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return $this->audit_state( 'failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			$audit = $this->audit_state( 'not_checked', __( 'Not available on WordPress.org, so it can\'t be checked.', 'php-compatibility-checker' ) );
			set_transient( $cache_key, $audit, self::CACHE_NOT_FOUND );
			return $audit;
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			/* translators: %d: HTTP status code. */
			return $this->audit_state( 'failed', sprintf( __( 'Tide returned HTTP %d.', 'php-compatibility-checker' ), $code ) );
		}

		$status = isset( $body['status'] ) ? $body['status'] : '';

		if ( 'pending' === $status ) {
			return $this->audit_state( 'pending', __( 'Tide is still auditing this version. Try again in a few minutes.', 'php-compatibility-checker' ) );
		}

		$report = isset( $body['reports']['phpcs_phpcompatibilitywp']['report'] ) ? $body['reports']['phpcs_phpcompatibilitywp']['report'] : null;

		if ( 'complete' !== $status || ! is_array( $report ) ) {
			return $this->audit_state( 'failed', __( 'No scan results found.', 'php-compatibility-checker' ) );
		}

		$audit = array(
			'state'  => 'complete',
			'reason' => '',
			'report' => $report,
		);
		set_transient( $cache_key, $audit, self::CACHE_COMPLETE );

		return $audit;
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
		);
	}

	/**
	 * Base result for an item.
	 *
	 * @param array  $item   Item from get_items().
	 * @param string $status passes, problems, not_checked, pending, or failed.
	 * @param string $reason Explanation for not_checked, pending, or failed.
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
			)
		);
	}

	/**
	 * Audit state without a report.
	 *
	 * @param string $state  pending, not_checked, or failed.
	 * @param string $reason Explanation.
	 * @return array
	 */
	private function audit_state( $state, $reason ) {
		return array(
			'state'  => $state,
			'reason' => $reason,
			'report' => null,
		);
	}

	/**
	 * Problems reported for one PHP version.
	 *
	 * @param array $version_report Tide report for a single PHP version.
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

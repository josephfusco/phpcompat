<?php
/**
 * WP-CLI commands.
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Check plugins and themes for PHP compatibility.
 *
 * Scans the installed files with PHPCompatibilityWP, the same scanner the
 * Tools > PHP Compatibility page runs in batches, for PHP 7.4 to 8.5.
 * Works for every plugin and theme, including premium and custom code.
 *
 * ## EXAMPLES
 *
 *     # Scan every plugin and theme.
 *     $ wp php-compat scan
 *
 *     # Scan active items only, as JSON.
 *     $ wp php-compat scan --active --format=json
 *
 *     # Show the problems for one plugin.
 *     $ wp php-compat report broken-link-checker
 *
 *     # Which PHP versions the active plugins and theme are ready for.
 *     $ wp php-compat readiness --format=json
 */
class CLI_Command {

	/**
	 * Scan plugins and themes.
	 *
	 * Exits with status 1 if any item has problems, so it can be used in scripts and CI.
	 *
	 * ## OPTIONS
	 *
	 * [<slug>...]
	 * : Only scan these plugin or theme slugs.
	 *
	 * [--type=<type>]
	 * : Only scan plugins or themes.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--active]
	 * : Only scan active plugins and themes.
	 *
	 * [--cached]
	 * : Use stored results (from the scan page or an earlier run) instead of scanning. Items without one are "not_scanned".
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Available: type, slug, name, version, active, requires_php, needs_php, source, status, failing_versions, reason, max_php_checked, scanned_at.
	 * ---
	 * default: name,type,version,status,failing_versions,reason
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp php-compat scan --active
	 *     +-------------------+--------+---------+----------+------------------+--------+
	 *     | name              | type   | version | status   | failing_versions | reason |
	 *     +-------------------+--------+---------+----------+------------------+--------+
	 *     | Classic Editor    | plugin | 1.7.0   | passes   |                  |        |
	 *     | WP-Optimize       | plugin | 4.7.0   | problems | 7.4              |        |
	 *     +-------------------+--------+---------+----------+------------------+--------+
	 *     Success: 1 passes, 1 has problems. Results cover PHP up to 8.5.
	 *
	 * @param array $args       Slugs.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) {
		$scanner = new Scanner();
		$items   = $scanner->get_items(
			array(
				'slugs'       => $args,
				'type'        => Utils\get_flag_value( $assoc_args, 'type', '' ),
				'active_only' => (bool) Utils\get_flag_value( $assoc_args, 'active', false ),
			)
		);

		if ( ! $items ) {
			WP_CLI::error( 'No matching plugins or themes.' );
		}

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$cached = (bool) Utils\get_flag_value( $assoc_args, 'cached', false );
		$scan   = $scanner->scan(
			$items,
			false,
			$cached ? Scanner::MODE_STORED : Scanner::MODE_LIVE,
			static function ( $item ) use ( $cached, $format ) {
				// Progress on stderr keeps json/csv output clean.
				if ( ! $cached && 'count' !== $format ) {
					fwrite( STDERR, sprintf( "Scanning %s %s...\n", $item['name'], $item['version'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				}
			}
		);
		$rows   = array_map( array( $this, 'row' ), $scan['items'] );
		$fields = Utils\get_flag_value( $assoc_args, 'fields', 'name,type,version,status,failing_versions,reason' );
		Utils\format_items( $format, $rows, $fields );

		$summary = $scan['summary'];

		// Machine-readable formats stay clean; the table gets a summary line.
		if ( 'table' === $format ) {
			$this->print_summary( $summary, $scan['max_php_checked'] );
		}

		if ( $summary['problems'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Show the problems found for a plugin or theme.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin or theme slug.
	 *
	 * [--type=<type>]
	 * : Whether the slug is a plugin or theme, if both exist.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--php=<version>]
	 * : Only show problems for this PHP version, e.g. 7.4.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp php-compat report wp-optimize --php=7.4
	 *
	 * @param array $args       Slug.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		$scanner = new Scanner();
		$items   = $scanner->get_items(
			array(
				'slugs' => array( $args[0] ),
				'type'  => Utils\get_flag_value( $assoc_args, 'type', '' ),
			)
		);

		if ( ! $items ) {
			WP_CLI::error( sprintf( 'No plugin or theme with the slug "%s".', $args[0] ) );
		}

		// The stored result if there is one for this version; otherwise scan now.
		$result = $scanner->scan_item( $items[0], true );
		if ( 'not_scanned' === $result['status'] ) {
			WP_CLI::log( sprintf( 'Scanning %s %s...', $items[0]['name'], $items[0]['version'] ) );
			$result = $scanner->scan_item( $items[0], true, Scanner::MODE_LIVE );
		}
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$php    = Utils\get_flag_value( $assoc_args, 'php', '' );

		if ( 'problems' !== $result['status'] ) {
			$message = sprintf( '%s %s: %s.', $result['name'], $result['version'], str_replace( '_', ' ', $result['status'] ) );
			if ( $result['reason'] ) {
				$message .= ' ' . $result['reason'];
			}
			WP_CLI::success( $message );
			return;
		}

		$rows = array();
		foreach ( $result['problems'] as $version => $problems ) {
			if ( $php && (string) $version !== $php ) {
				continue;
			}
			foreach ( $problems as $problem ) {
				$rows[] = array_merge( array( 'php' => (string) $version ), $problem );
			}
		}

		if ( ! $rows ) {
			WP_CLI::success( sprintf( 'No problems reported for PHP %s.', $php ) );
			return;
		}

		Utils\format_items( $format, $rows, array( 'php', 'file', 'line', 'type', 'message' ) );
	}

	/**
	 * Show which PHP versions the active plugins and theme are ready for.
	 *
	 * Reads stored results only and never scans, so it's quick to run across
	 * many sites. The JSON is a versioned document (schema_version); see
	 * docs/readiness.md in the plugin's GitHub repository. Items nobody
	 * has scanned are listed as unknown, and "complete" is false.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Is this site ready for a newer PHP?
	 *     $ wp php-compat readiness
	 *
	 *     # The full document, for collecting across sites.
	 *     $ wp php-compat readiness --format=json
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function readiness( $args, $assoc_args ) {
		$report = ( new Scanner() )->readiness_report();
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'table' !== $format ) {
			WP_CLI::print_value( $report, array( 'format' => $format ) );
			return;
		}

		$names = static function ( $items ) {
			return implode( ', ', wp_list_pluck( $items, 'name' ) );
		};

		$rows = array(
			array( 'php', $report['environment']['php'] ),
			array( 'complete', $report['complete'] ? 'yes' : 'no' ),
			array( 'ready_for', implode( ', ', $report['ready_for'] ) ),
			array( 'next_ready', $report['next_ready'] ),
			array( 'next_blocked', $report['next_blocked'] ),
		);
		// Older versions don't matter for an upgrade; the JSON has them all.
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( $report['blockers'] as $version => $items ) {
			if ( version_compare( (string) $version, $branch, '>=' ) ) {
				$rows[] = array( 'blocks ' . $version, $names( $items ) );
			}
		}
		if ( $report['unknown'] ) {
			$rows[] = array( 'unknown', $names( $report['unknown'] ) );
		}
		$rows[] = array( 'source', $report['source'] );

		Utils\format_items(
			'table',
			array_map(
				static function ( $row ) {
					return array(
						'field' => $row[0],
						'value' => $row[1],
					);
				},
				$rows
			),
			array( 'field', 'value' )
		);

		if ( ! $report['complete'] ) {
			WP_CLI::warning( 'Some active plugins or themes have no results yet. Run a scan in Tools > PHP Compatibility, or wp php-compat scan --active.' );
		}
	}

	/**
	 * Show the server's PHP version and WordPress's assessment of it.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @subcommand php-status
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Options.
	 */
	public function php_status( $args, $assoc_args ) {
		$status = ( new Scanner() )->php_status();
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'table' === $format ) {
			$release = $status['release'];
			unset( $status['release'] );
			$status['release_state']        = $release['state'];
			$status['latest_release']       = $release['latest'];
			$status['latest_is_security']   = $release['latest_is_security'];
			$status['security_support_end'] = $release['security_support_end'];
			$status['release_message']      = $release['message'];
			foreach ( $release['advisories'] as $advisory ) {
				$label = Php_Releases::label( $advisory );
				if ( ! empty( $advisory['conditions'] ) ) {
					$status[ 'advisory: ' . $advisory['id'] ] = $label . ' — ' . $advisory['mitigation'];
				} else {
					$status[ 'advisory: ' . $advisory['id'] ] = $advisory['fixed_in']
						/* translators: 1: vulnerability name and CVE IDs, 2: fixed PHP version. */
						? sprintf( __( '%1$s — fixed in %2$s', 'php-compatibility-checker' ), $label, $advisory['fixed_in'] )
						/* translators: %s: vulnerability name and CVE IDs. */
						: sprintf( __( '%s — no official fix for this version', 'php-compatibility-checker' ), $label );
				}
			}

			$rows = array();
			foreach ( $status as $key => $value ) {
				$rows[] = array(
					'field' => $key,
					'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( null === $value ? 'unknown' : $value ),
				);
			}
			Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
			return;
		}

		WP_CLI::print_value( $status, array( 'format' => $format ) );
	}

	/**
	 * Flatten a result for display.
	 *
	 * @param array $result Scan result.
	 * @return array
	 */
	private function row( $result ) {
		$row                     = $result;
		$row['active']           = $result['active'] ? 'yes' : 'no';
		$row['failing_versions'] = implode( ', ', $result['failing_versions'] );
		// The PHP it really needs, when newer than "Requires PHP" (from the scan or Composer).
		$row['needs_php'] = ! empty( $result['needs_php'] ) ? $result['needs_php']['needed'] . ' (' . $result['needs_php']['source'] . ')' : '';
		unset( $row['php'], $row['problems'] );

		return $row;
	}

	/**
	 * Print a one-line summary.
	 *
	 * @param array  $summary Count per status.
	 * @param string $max_php Newest PHP version with results.
	 */
	private function print_summary( $summary, $max_php ) {
		$labels = array(
			'passes'      => '%d passes',
			'problems'    => '%d %s problems',
			'not_checked' => '%d not checked',
			'not_scanned' => '%d not scanned yet',
			'failed'      => '%d failed to scan',
		);

		$parts = array();
		foreach ( $labels as $status => $label ) {
			if ( empty( $summary[ $status ] ) ) {
				continue;
			}
			$parts[] = 'problems' === $status
				? sprintf( $label, $summary[ $status ], 1 === $summary[ $status ] ? 'has' : 'have' )
				: sprintf( $label, $summary[ $status ] );
		}

		$message = implode( ', ', $parts ) . '.';
		if ( $max_php ) {
			$message .= sprintf( ' Results cover PHP up to %s.', $max_php );
		}

		if ( $summary['problems'] > 0 ) {
			WP_CLI::warning( $message );
		} else {
			WP_CLI::success( $message );
		}
	}
}

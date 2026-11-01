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
 * Results come from Tide (wptide.org), which audits plugins and themes on
 * WordPress.org with PHPCompatibilityWP. Items that aren't on WordPress.org
 * can't be checked.
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
	 * [--wait=<seconds>]
	 * : How long to keep retrying items Tide is still auditing.
	 * ---
	 * default: 60
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Fields to show. Available: type, slug, name, version, active, requires_php, status, failing_versions, reason, max_php_checked.
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
	 *     | WP-Optimize       | plugin | 4.7.0   | problems | 7.2, 7.3         |        |
	 *     +-------------------+--------+---------+----------+------------------+--------+
	 *     Success: 1 passes, 1 has problems. Results cover PHP up to 8.0.
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

		$scan = $this->scan_with_retries( $scanner, $items, (int) Utils\get_flag_value( $assoc_args, 'wait', 60 ) );
		$rows = array_map( array( $this, 'row' ), $scan['items'] );

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
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
	 *     $ wp php-compat report wp-optimize --php=7.3
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

		$result = $scanner->scan_item( $items[0], true );
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
	 * Scan, retrying items Tide is still auditing until they finish or time runs out.
	 *
	 * @param Scanner $scanner Scanner.
	 * @param array[] $items   Items.
	 * @param int     $wait    Seconds to keep retrying.
	 * @return array Scan results.
	 */
	private function scan_with_retries( $scanner, $items, $wait ) {
		$deadline = time() + max( 0, $wait );
		$scan     = $scanner->scan( $items );

		while ( $scan['summary']['pending'] > 0 && time() < $deadline ) {
			WP_CLI::log( sprintf( 'Waiting for Tide to finish auditing %d item(s)...', $scan['summary']['pending'] ) );
			sleep( 5 );
			$scan = $scanner->scan( $items );
		}

		return $scan;
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
			'pending'     => '%d still being audited',
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

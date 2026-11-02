<?php
/**
 * REST API for the scan page.
 *
 * @package WPEngine_PHPCompat
 */

declare( strict_types=1 );

namespace WPEngine_PHPCompat;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Routes under php-compat/v1:
 *
 * - POST /scan-batch Scan the next few PHP files of one plugin or theme.
 * - POST /results    Store the merged result of a finished scan.
 *
 * Scanning is a resumable batch job, driven by the browser so no request runs
 * long enough to hit a shared host's time, memory, or worker limits:
 *
 * - The browser sends one request at a time, so a scan uses at most one PHP
 *   worker. It sizes each batch to finish in a few seconds, shrinks it after
 *   a slow or failed request, retries with backoff, and skips a file that
 *   fails on its own instead of failing the whole item.
 * - Each request is also capped here (files, bytes), whatever the browser asks.
 * - The cursor is an index into the item's sorted file list. The list has a
 *   fingerprint (paths, sizes, modification times); if the files change mid-scan,
 *   the request fails with 409 and the browser starts that item again.
 * - The fingerprint is stored with the result, so an unchanged item isn't
 *   scanned again: the first request returns the stored result.
 *
 * Both need the plugin's capability (manage_options). Items are looked up from
 * the installed plugin and theme lists; no path from the request is used to
 * open a file. Responses carry compatibility messages, never file contents.
 */
class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const ROUTE_NAMESPACE = 'php-compat/v1';

	/**
	 * Most files per scan request, whatever the browser asks for.
	 */
	const SCAN_MAX_FILES = 100;

	/**
	 * Most bytes of PHP per scan request (at least one file is always scanned).
	 */
	const SCAN_MAX_BYTES = 1048576; // 1 MB.

	/**
	 * Default files per scan request, when the browser doesn't say.
	 */
	const SCAN_DEFAULT_FILES = 10;

	/**
	 * How long an item's file list is kept between the requests of one scan.
	 */
	const LIST_TTL = HOUR_IN_SECONDS;

	/**
	 * Source of the installed plugin and theme lists.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Scanner|null $scanner Scanner.
	 */
	public function __construct( ?Scanner $scanner = null ) {
		$this->scanner = $scanner ? $scanner : new Scanner();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$item_args = array(
			'type' => array(
				'type'     => 'string',
				'enum'     => array( 'plugin', 'theme' ),
				'required' => true,
			),
			'slug' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z0-9_-]+$',
				'required' => true,
			),
		);

		$fingerprint = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{0,32}$',
			'default' => '',
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/scan-batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_batch' ),
				'permission_callback' => array( $this, 'can_scan' ),
				'args'                => array_merge(
					$item_args,
					array(
						'cursor'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						// 0 only plans: the stored result if nothing changed, else the file count.
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => self::SCAN_MAX_FILES,
							'default' => self::SCAN_DEFAULT_FILES,
						),
						'fingerprint' => $fingerprint,
					)
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/results',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_result' ),
				'permission_callback' => array( $this, 'can_scan' ),
				'args'                => array_merge(
					$item_args,
					array(
						'version'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'ruleset'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'source'      => array(
							'type'     => 'string',
							'enum'     => array( 'wporg', 'local' ),
							'required' => true,
						),
						'fingerprint' => $fingerprint,
						'report'      => array(
							'type'     => 'object',
							'required' => true,
						),
					)
				),
			)
		);
	}

	/**
	 * Permission check for every route.
	 *
	 * @return bool
	 */
	public function can_scan(): bool {
		return current_user_can( WPEPHPCOMPAT_CAPABILITY );
	}

	/**
	 * POST /scan-batch: scan the next batch of an item's PHP files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error {
	 * With limit 0, nothing is scanned: the response only plans the scan.
	 *
	 *     @type bool     $cached      True when the item is unchanged since its stored
	 *                                 result; then only $result is set.
	 *     @type array    $result      The stored result (when cached).
	 *     @type array    $report      Report for this batch (see Results::sanitize_report()).
	 *     @type int      $scanned     Files in this batch.
	 *     @type int|null $next        Cursor for the next batch, or null when done.
	 *     @type string   $next_path   Path of the file at $next, so the browser can name it if it has to skip it.
	 *     @type int      $total       Number of PHP files.
	 *     @type string   $fingerprint Fingerprint of the file list; send it back with later batches.
	 *     @type string[] $skipped     Files left out because they are too big.
	 *     @type bool     $truncated   Whether there were more PHP files than Scanner::MAX_FILES.
	 *     @type string   $platform_php PHP version the item's Composer dependencies require, or ''.
	 * }
	 */
	public function scan_batch( WP_REST_Request $request ) {
		$item = $this->find_item( (string) $request['type'], (string) $request['slug'] );
		if ( ! $item ) {
			return new WP_Error( 'phpcompat_unknown_item', __( 'That plugin or theme isn\'t installed.', 'php-compatibility-checker' ), array( 'status' => 404 ) );
		}

		$cursor = (int) $request['cursor'];

		// Same hook as WP-CLI: a host can supply reports it already has.
		if ( 0 === $cursor ) {
			/** This filter is documented in plugin/lib/class-scanner.php */
			$report = apply_filters( 'wpephpcompat_pre_scan_report', null, $item );
			if ( is_array( $report ) ) {
				return new WP_REST_Response( $this->batch_response( Results::sanitize_report( $report ), 0, null, '', Scanner::empty_file_list() ) );
			}
		}

		$root = Scanner::item_root( $item );
		if ( '' === $root ) {
			return new WP_Error( 'phpcompat_no_files', __( 'Couldn\'t find this plugin\'s or theme\'s files.', 'php-compatibility-checker' ), array( 'status' => 404 ) );
		}

		// A new scan always looks at the files as they are now.
		$list        = $this->file_list( $item, $root, 0 === $cursor );
		$fingerprint = (string) $request['fingerprint'];

		if ( '' !== $fingerprint && ! hash_equals( $list['fingerprint'], $fingerprint ) ) {
			return new WP_Error( 'phpcompat_files_changed', __( 'This plugin\'s or theme\'s files changed during the scan. Scanning it again.', 'php-compatibility-checker' ), array( 'status' => 409 ) );
		}

		if ( 0 === $cursor ) {
			$stored = ( new Results() )->get( $item );
			if ( $stored && ! empty( $stored['fingerprint'] ) && hash_equals( (string) $stored['fingerprint'], $list['fingerprint'] ) ) {
				return new WP_REST_Response(
					array(
						'cached' => true,
						'result' => $stored,
					)
				);
			}
		}

		if ( 0 === $cursor && ! Scanner_Bundle::verify() ) {
			return new WP_Error( 'phpcompat_no_scanner', __( 'The scanner in this copy of the plugin is missing or incomplete. Reinstall the plugin.', 'php-compatibility-checker' ), array( 'status' => 500 ) );
		}

		if ( 0 === (int) $request['limit'] ) {
			return new WP_REST_Response( $this->batch_response( array( 'versions' => array() ), 0, $cursor < count( $list['files'] ) ? $cursor : null, $list['files'][ $cursor ] ?? '', $list ) );
		}

		$base  = is_file( $root ) ? dirname( $root ) : $root;
		$batch = self::select_batch( $base, $list['files'], $cursor, (int) $request['limit'] );

		$this->prepare_for_scan();

		try {
			$report = $batch['files'] ? Scanner_Bundle::scan_files( $base, $batch['files'] ) : array( 'versions' => array() );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'phpcompat_scan_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			$this->batch_response(
				Results::sanitize_report( $report ),
				count( $batch['files'] ),
				$batch['next'],
				null === $batch['next'] ? '' : $list['files'][ $batch['next'] ],
				$list
			)
		);
	}

	/**
	 * The files of the next batch: at most $limit files and SCAN_MAX_BYTES, but at least one.
	 *
	 * @param string   $base   Directory the paths are relative to.
	 * @param string[] $paths  Relative paths.
	 * @param int      $cursor Index to start at.
	 * @param int      $limit  Most files.
	 * @return array { @type string[] $files Relative paths. @type int|null $next Next cursor, or null when done. }
	 */
	public static function select_batch( string $base, array $paths, int $cursor, int $limit ): array {
		$limit = max( 1, min( self::SCAN_MAX_FILES, $limit ) );
		$count = count( $paths );
		$files = array();
		$bytes = 0;

		for ( $i = $cursor; $i < $count; $i++ ) {
			if ( count( $files ) >= $limit || ( $files && $bytes >= self::SCAN_MAX_BYTES ) ) {
				break;
			}
			$size = @filesize( $base . '/' . $paths[ $i ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Size estimate only; a missing file is skipped by the scanner.
			if ( $files && false !== $size && $bytes + $size > self::SCAN_MAX_BYTES ) {
				break;
			}
			$files[] = $paths[ $i ];
			$bytes  += false === $size ? 0 : $size;
		}

		return array(
			'files' => $files,
			'next'  => $i < $count ? $i : null,
		);
	}

	/**
	 * Give the scan as much room as the host allows. The batch size keeps it
	 * well inside the limits; this is headroom for a slow moment.
	 */
	private function prepare_for_scan(): void {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Headroom for a slow batch; most take a few seconds.
		}
	}

	/**
	 * Response for one batch.
	 *
	 * @param array    $report    Sanitized report.
	 * @param int      $scanned   Files in the batch.
	 * @param int|null $next      Next cursor.
	 * @param string   $next_path Path at $next.
	 * @param array    $files     File list (see Scanner::file_list()).
	 * @return array
	 */
	private function batch_response( array $report, int $scanned, ?int $next, string $next_path, array $files ): array {
		return array(
			'cached'       => false,
			'report'       => $report,
			'scanned'      => $scanned,
			'next'         => $next,
			'next_path'    => $next_path,
			'total'        => count( $files['files'] ),
			'fingerprint'  => $files['fingerprint'],
			'skipped'      => $files['skipped'],
			'truncated'    => $files['truncated'],
			'platform_php' => $files['platform_php'] ?? '',
		);
	}

	/**
	 * POST /results: store a scan result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error The stored result.
	 */
	public function save_result( WP_REST_Request $request ) {
		$item = $this->find_item( (string) $request['type'], (string) $request['slug'] );
		if ( ! $item ) {
			return new WP_Error( 'phpcompat_unknown_item', __( 'That plugin or theme isn\'t installed.', 'php-compatibility-checker' ), array( 'status' => 404 ) );
		}

		// A result for another version or rule set would be stored under the wrong key.
		if ( $item['version'] !== (string) $request['version'] || Scanner_Bundle::ruleset() !== (string) $request['ruleset'] ) {
			return new WP_Error( 'phpcompat_stale_result', __( 'This result is for a different version. Scan again.', 'php-compatibility-checker' ), array( 'status' => 409 ) );
		}

		$source = 'wporg' === $request['source'] && 'wporg' === $item['source'] ? 'wporg' : 'local';

		// Measure what would be stored (capped and cleaned), not what was sent.
		$report = Results::sanitize_report( (array) $request['report'] );
		if ( strlen( (string) wp_json_encode( $report ) ) > Results::MAX_REPORT_BYTES ) {
			return new WP_Error( 'phpcompat_too_large', __( 'The scan result is too large to store.', 'php-compatibility-checker' ), array( 'status' => 413 ) );
		}

		// Only keep a fingerprint this server computed for the scan, so a result
		// is reused only while the files really are unchanged.
		$fingerprint = (string) $request['fingerprint'];
		$root        = '' !== $fingerprint ? Scanner::item_root( $item ) : '';
		$list        = '' !== $root ? $this->file_list( $item, $root, false ) : null;
		if ( ! $list || ! hash_equals( $list['fingerprint'], $fingerprint ) ) {
			$fingerprint = '';
		}

		$stored = ( new Results() )->save( $item, $report, $source, $fingerprint );

		return new WP_REST_Response( $stored );
	}

	/**
	 * An installed plugin or theme by type and slug.
	 *
	 * @param string $type "plugin" or "theme".
	 * @param string $slug Slug.
	 * @return array|null Item from Scanner::get_items().
	 */
	private function find_item( string $type, string $slug ): ?array {
		foreach ( $this->scanner->get_items( array( 'type' => $type ) ) as $item ) {
			if ( $item['slug'] === $slug ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Delete the kept file lists and the scanner check, e.g. on uninstall.
	 */
	public static function delete_transients(): void {
		global $wpdb;

		delete_transient( 'wpephpcompat_scanner_ok' );

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup; transients have no API to list by prefix.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_wpephpcompat_files_' ) . '%' )
		);

		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	/**
	 * An item's file list, kept between the batches of one scan.
	 *
	 * @param array  $item    Item.
	 * @param string $root    Real path from item_root().
	 * @param bool   $refresh Walk the files again instead of using the kept list.
	 * @return array See Scanner::file_list().
	 */
	private function file_list( array $item, string $root, bool $refresh ): array {
		$key = 'wpephpcompat_files_' . md5( $item['type'] . '|' . $item['slug'] . '|' . $item['version'] . '|' . $root . '|' . Scanner_Bundle::ruleset() );

		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['files'], $cached['fingerprint'] ) ) {
				return $cached;
			}
		}

		$list = Scanner::file_list( $root, Scanner::fingerprint_salt( $item ) );
		set_transient( $key, $list, self::LIST_TTL );

		return $list;
	}
}

<?php
/**
 * REST API for the browser scanner.
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
 * - GET  /files   PHP files of one installed plugin or theme, in small batches,
 *                 for code that isn't on WordPress.org (premium, custom, or a
 *                 version WordPress.org doesn't have).
 * - POST /results Store the browser's scan result for one plugin or theme.
 *
 * Both need the plugin's capability (manage_options). Reading files also
 * needs the right to see that code anyway: network admin on multisite, and
 * permission to edit or install plugins (or themes) on a single site. Items
 * are looked up from the installed plugin and theme lists; no path from the
 * request is ever used to open a file.
 */
class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const ROUTE_NAMESPACE = 'php-compat/v1';

	/**
	 * Most bytes of file content per /files response. Small batches keep each
	 * request short and spread disk reads out on hosts with limited I/O.
	 */
	const BATCH_BYTES = 524288; // 512 KB.

	/**
	 * Most files per /files response.
	 */
	const BATCH_FILES = 200;

	/**
	 * Files bigger than this are skipped (generated or data files, not code to check).
	 */
	const MAX_FILE_BYTES = 1048576; // 1 MB.

	/**
	 * Most PHP files listed for one item.
	 */
	const MAX_FILES = 20000;

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

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_files' ),
				'permission_callback' => array( $this, 'can_read_files' ),
				'args'                => array_merge(
					$item_args,
					array(
						'cursor' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
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
						'version' => array(
							'type'     => 'string',
							'required' => true,
						),
						'ruleset' => array(
							'type'     => 'string',
							'required' => true,
						),
						'source'  => array(
							'type'     => 'string',
							'enum'     => array( 'wporg', 'local' ),
							'required' => true,
						),
						'report'  => array(
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
	 * Permission check for reading source files.
	 *
	 * Someone who may scan isn't necessarily allowed to read every plugin's
	 * code: sub-site admins on multisite can't, nor can admins on sites that
	 * turn off file editing and installs (DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_read_files( WP_REST_Request $request ) {
		if ( ! $this->can_scan() ) {
			return false;
		}

		$theme   = 'theme' === $request['type'];
		$allowed = is_multisite()
			? current_user_can( $theme ? 'manage_network_themes' : 'manage_network_plugins' )
			: current_user_can( $theme ? 'edit_themes' : 'edit_plugins' ) || current_user_can( $theme ? 'install_themes' : 'install_plugins' );

		if ( ! $allowed ) {
			return new WP_Error(
				'phpcompat_cannot_read_files',
				__( 'You aren\'t allowed to read this plugin\'s or theme\'s files, so it can\'t be scanned here. A network administrator can scan it, or run wp php-compat scan.', 'php-compatibility-checker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /files: a batch of an item's PHP files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error {
	 *     @type array[]  $files   Each with path (relative to the item) and content (base64).
	 *     @type int|null $next    Cursor for the next batch, or null when done.
	 *     @type int      $total   Number of PHP files.
	 *     @type string[] $skipped   Files left out because they are too big (first batch only).
	 *     @type bool     $truncated Whether there were more PHP files than MAX_FILES (first batch only).
	 * }
	 */
	public function get_files( WP_REST_Request $request ) {
		$item = $this->find_item( (string) $request['type'], (string) $request['slug'] );
		if ( ! $item ) {
			return new WP_Error( 'phpcompat_unknown_item', __( 'That plugin or theme isn\'t installed.', 'php-compatibility-checker' ), array( 'status' => 404 ) );
		}

		$root = Scanner::item_root( $item );
		if ( '' === $root ) {
			return new WP_Error( 'phpcompat_no_files', __( 'Couldn\'t find this plugin\'s or theme\'s files.', 'php-compatibility-checker' ), array( 'status' => 404 ) );
		}

		$list   = $this->list_files( $item, $root );
		$cursor = (int) $request['cursor'];
		$files  = array();
		$bytes  = 0;
		$count  = count( $list['files'] );

		for ( $i = $cursor; $i < $count; $i++ ) {
			if ( count( $files ) >= self::BATCH_FILES || ( $files && $bytes >= self::BATCH_BYTES ) ) {
				break;
			}

			$relative = $list['files'][ $i ];
			$content  = $this->read_file( $root, $relative );
			if ( null === $content ) {
				continue;
			}

			$files[] = array(
				'path'    => $relative,
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe transport of source files.
			);
			$bytes  += strlen( $content );
		}

		return new WP_REST_Response(
			array(
				'files'     => $files,
				'next'      => $i < $count ? $i : null,
				'total'     => $count,
				'skipped'   => 0 === $cursor ? $list['skipped'] : array(),
				'truncated' => 0 === $cursor && ! empty( $list['truncated'] ),
			)
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

		// Only code from WordPress.org is reused without rescanning, so only believe
		// "wporg" for items WordPress.org actually hosts.
		$source = 'wporg' === $request['source'] && 'wporg' === $item['source'] ? 'wporg' : 'local';

		// Measure what would be stored (capped and cleaned), not what was sent.
		$report = Results::sanitize_report( (array) $request['report'] );
		if ( strlen( (string) wp_json_encode( $report ) ) > Results::MAX_REPORT_BYTES ) {
			return new WP_Error( 'phpcompat_too_large', __( 'The scan result is too large to store.', 'php-compatibility-checker' ), array( 'status' => 413 ) );
		}

		$stored = ( new Results() )->save( $item, $report, $source );

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
	 * Sorted PHP files of an item, cached briefly so batches don't re-walk the tree.
	 *
	 * @param array  $item Item.
	 * @param string $root Real path from item_root().
	 * @return array { @type string[] $files Relative paths. @type string[] $skipped Too big. }
	 */
	private function list_files( array $item, string $root ): array {
		$key    = 'wpephpcompat_files_' . md5( $item['type'] . '|' . $item['slug'] . '|' . $item['version'] . '|' . $root );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$list = array(
			'files'     => array(),
			'skipped'   => array(),
			'truncated' => false,
		);

		if ( is_file( $root ) ) {
			$list['files'][] = basename( $root );
		} else {
			// Doesn't follow symlinked directories, so the walk stays inside the item.
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS )
			);
			foreach ( $iterator as $file ) {
				if ( count( $list['files'] ) >= self::MAX_FILES ) {
					$list['truncated'] = true;
					break;
				}
				if ( $file->isLink() || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
				if ( $file->getSize() > self::MAX_FILE_BYTES ) {
					$list['skipped'][] = $relative;
					continue;
				}
				$list['files'][] = $relative;
			}
			sort( $list['files'] );
		}

		set_transient( $key, $list, 10 * MINUTE_IN_SECONDS );

		return $list;
	}

	/**
	 * Read one file, only if it really is inside the item.
	 *
	 * @param string $root     Real path from item_root().
	 * @param string $relative Relative path from list_files().
	 * @return string|null Content, or null if it can't be read safely.
	 */
	private function read_file( string $root, string $relative ): ?string {
		$path = is_file( $root ) ? $root : $root . '/' . $relative;
		$real = realpath( $path );

		if ( false === $real || ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) || ! is_readable( $real ) ) {
			return null;
		}

		$content = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		return false === $content ? null : $content;
	}
}

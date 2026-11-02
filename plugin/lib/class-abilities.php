<?php
/**
 * Abilities API integration (WordPress 6.9+).
 *
 * @package WPEngine_PHPCompat
 */

namespace WPEngine_PHPCompat;

/**
 * Registers abilities so AI agents and other clients can check PHP
 * compatibility through the Abilities API (REST at wp-abilities/v1, MCP).
 *
 * The hooks only fire on WordPress versions that have the Abilities API,
 * so this does nothing on older sites.
 */
class Abilities {

	const CATEGORY = 'php-compatibility';

	/**
	 * Hook into the Abilities API.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 */
	public static function register_category() {
		// The hook only fires on WordPress 6.9+, where this function exists.
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'PHP compatibility', 'php-compatibility-checker' ),
				'description' => __( 'Check whether plugins, themes, and the server are ready for newer PHP versions.', 'php-compatibility-checker' ),
			)
		);
	}

	/**
	 * Register the abilities.
	 */
	public static function register_abilities() {
		// The hook only fires on WordPress 6.9+, where this function exists.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$meta = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'public'       => true,
			'show_in_rest' => true,
		);

		wp_register_ability(
			'php-compatibility-checker/scan',
			array(
				'label'               => __( 'Get PHP compatibility results for plugins and themes', 'php-compatibility-checker' ),
				'description'         => __( 'Returns the stored PHPCompatibilityWP results for installed plugins and themes, for PHP 7.4 to 8.5. Results come from the last scan of each item\'s current version, run on the Tools > PHP Compatibility page or with wp php-compat scan; scanned_at says when. Each item gets a status: passes, problems (fails on at least one PHP version), not_checked (requires a newer PHP than any version checked), not_scanned (no result for this version yet; ask an administrator to run a scan), or failed. PHP versions below an item\'s own "Requires PHP" are not counted. max_php_checked is the newest PHP version checked; nothing newer was checked, so don\'t treat a pass as readiness for later PHP versions.', 'php-compatibility-checker' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::scan_input_schema(),
				'output_schema'       => self::scan_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_scan' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			'php-compatibility-checker/php-status',
			array(
				'label'               => __( 'Get the server\'s PHP status', 'php-compatibility-checker' ),
				'description'         => __( 'Returns the PHP version this site runs on, whether WordPress considers it secure and at or above its recommended version, and (from php.net) whether it is the latest patch release of a branch that still gets security fixes. Use release.state to tell whether the server needs a PHP update.', 'php-compatibility-checker' ),
				'category'            => self::CATEGORY,
				'output_schema'       => self::php_status_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_php_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta,
			)
		);
	}

	/**
	 * Only administrators can see installed plugins and server details.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( WPEPHPCOMPAT_CAPABILITY );
	}

	/**
	 * Run a scan.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public static function execute_scan( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$scanner = new Scanner();
		$items   = $scanner->get_items(
			array(
				'slugs'       => isset( $input['slugs'] ) ? array_map( 'sanitize_title', (array) $input['slugs'] ) : array(),
				'type'        => isset( $input['type'] ) ? $input['type'] : '',
				'active_only' => ! empty( $input['active_only'] ),
			)
		);

		$scan = $scanner->scan( $items, ! empty( $input['include_details'] ) );

		// JSON objects need string keys; return PHP results as lists for a stable schema.
		$scan['items'] = array_map( array( __CLASS__, 'shape_item' ), $scan['items'] );

		return $scan;
	}

	/**
	 * Get the PHP status.
	 *
	 * @return array
	 */
	public static function execute_php_status() {
		return ( new Scanner() )->php_status();
	}

	/**
	 * Convert version-keyed maps to lists, which serialize predictably as JSON.
	 *
	 * @param array $item Scan result.
	 * @return array
	 */
	public static function shape_item( $item ) {
		$php = array();
		foreach ( $item['php'] as $version => $result ) {
			$php[] = array(
				'version' => (string) $version,
				'result'  => $result,
			);
		}
		$item['php'] = $php;

		if ( isset( $item['problems'] ) ) {
			$problems = array();
			foreach ( $item['problems'] as $version => $list ) {
				foreach ( $list as $problem ) {
					$problems[] = array_merge( array( 'php' => (string) $version ), $problem );
				}
			}
			$item['problems'] = $problems;
		}

		return $item;
	}

	/**
	 * Input schema for the scan ability.
	 *
	 * @return array
	 */
	private static function scan_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slugs'           => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Only scan these plugin or theme slugs. Omit to scan everything.', 'php-compatibility-checker' ),
				),
				'type'            => array(
					'type'        => 'string',
					'enum'        => array( 'plugin', 'theme' ),
					'description' => __( 'Only scan plugins or themes.', 'php-compatibility-checker' ),
				),
				'active_only'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only scan active plugins and themes.', 'php-compatibility-checker' ),
				),
				'include_details' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include the file, line, and message for each problem.', 'php-compatibility-checker' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema for the scan ability.
	 *
	 * @return array
	 */
	private static function scan_output_schema() {
		$status = Scanner::STATUSES;
		$count  = array( 'type' => 'integer' );

		return array(
			'type'       => 'object',
			'properties' => array(
				'items'           => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'type'             => array(
								'type' => 'string',
								'enum' => array( 'plugin', 'theme' ),
							),
							'slug'             => array( 'type' => 'string' ),
							'name'             => array( 'type' => 'string' ),
							'version'          => array( 'type' => 'string' ),
							'active'           => array( 'type' => 'boolean' ),
							'requires_php'     => array(
								'type'        => 'string',
								'description' => __( 'The item\'s own "Requires PHP", or empty.', 'php-compatibility-checker' ),
							),
							'status'           => array(
								'type' => 'string',
								'enum' => $status,
							),
							'reason'           => array( 'type' => 'string' ),
							'failing_versions' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'max_php_checked'  => array( 'type' => 'string' ),
							'source'           => array(
								'type'        => 'string',
								'description' => __( 'wporg if the plugin or theme is on WordPress.org, otherwise local.', 'php-compatibility-checker' ),
							),
							'scanned_at'       => array(
								'type'        => 'string',
								'description' => __( 'When the result was produced (ISO 8601), or empty if not scanned.', 'php-compatibility-checker' ),
							),
							'php'              => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'version' => array( 'type' => 'string' ),
										'result'  => array(
											'type' => 'string',
											'enum' => array( 'pass', 'fail', 'not_counted' ),
										),
									),
								),
							),
							'problems'         => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'php'     => array( 'type' => 'string' ),
										'file'    => array( 'type' => 'string' ),
										'line'    => array( 'type' => 'integer' ),
										'type'    => array( 'type' => 'string' ),
										'message' => array( 'type' => 'string' ),
										'source'  => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
				'summary'         => array(
					'type'       => 'object',
					'properties' => array_fill_keys( $status, $count ),
				),
				'max_php_checked' => array(
					'type'        => 'string',
					'description' => __( 'Newest PHP version with results. Nothing newer was checked.', 'php-compatibility-checker' ),
				),
				'source'          => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Output schema for the PHP status ability.
	 *
	 * @return array
	 */
	private static function php_status_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'php_version'         => array( 'type' => 'string' ),
				'php_full_version'    => array( 'type' => 'string' ),
				'is_secure'           => array( 'type' => array( 'boolean', 'null' ) ),
				'is_recommended'      => array( 'type' => array( 'boolean', 'null' ) ),
				'recommended_version' => array( 'type' => 'string' ),
				'message'             => array( 'type' => 'string' ),
				'release'             => array(
					'type'        => 'object',
					'description' => __( 'Whether this is the latest PHP release of a supported branch, from php.net. state: vulnerable (affected by a critical advisory; see advisories), up_to_date, update_available, unsupported_branch, vendor_build (host or distribution build that may include backported fixes), or unknown.', 'php-compatibility-checker' ),
					'properties'  => array(
						'state'                => array(
							'type' => 'string',
							'enum' => array( 'vulnerable', 'up_to_date', 'update_available', 'unsupported_branch', 'vendor_build', 'unknown' ),
						),
						'current'              => array( 'type' => 'string' ),
						'branch'               => array( 'type' => 'string' ),
						'latest'               => array( 'type' => 'string' ),
						'latest_date'          => array( 'type' => 'string' ),
						'latest_is_security'   => array( 'type' => 'boolean' ),
						'security_support_end' => array( 'type' => 'string' ),
						'supported_branches'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'message'              => array( 'type' => 'string' ),
						'advisories'           => array(
							'type'        => 'array',
							'description' => __( 'Critical PHP security advisories that affect this server. fixed_in is the release that fixes it for this branch (empty if none). Advisories with conditions depend on PHP settings; follow mitigation.', 'php-compatibility-checker' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'         => array( 'type' => 'string' ),
									'name'       => array( 'type' => 'string' ),
									'cves'       => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'url'        => array( 'type' => 'string' ),
									'exploited'  => array( 'type' => 'boolean' ),
									'fixed_in'   => array( 'type' => 'string' ),
									'mitigation' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
		);
	}
}

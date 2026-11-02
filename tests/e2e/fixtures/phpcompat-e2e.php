<?php
/**
 * Plugin Name: PHP Compatibility Checker e2e fixtures
 * Description: Loaded only in the wp-env tests site. Provides a fixed list of items to scan and a switch for the WP Engine sidebar.
 *
 * @package WPEngine_PHPCompat
 */

// A known set of plugins, so scan results don't depend on what's installed.
add_filter(
	'phpcompat_plugins_to_scan',
	static function () {
		return array(
			array( 'slug' => 'passing-plugin', 'name' => 'Passing Plugin', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '', 'source' => 'wporg', 'file' => 'passing-plugin/passing-plugin.php' ),
			array( 'slug' => 'problem-plugin', 'name' => 'Problem Plugin', 'version' => '2.0.0', 'active' => 'yes', 'requires_php' => '8.0', 'source' => 'wporg', 'file' => 'problem-plugin/problem-plugin.php' ),
			array( 'slug' => 'new-php-plugin', 'name' => 'New PHP Plugin', 'version' => '3.0.0', 'active' => 'yes', 'requires_php' => '8.6', 'source' => 'wporg', 'file' => 'new-php-plugin/new-php-plugin.php' ),
			array( 'slug' => 'custom-plugin', 'name' => 'Custom Plugin', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '', 'source' => 'local', 'file' => 'custom-plugin/custom-plugin.php' ),
			array( 'slug' => 'inactive-plugin', 'name' => 'Inactive Plugin', 'version' => '1.0.0', 'active' => 'no', 'requires_php' => '', 'source' => 'wporg', 'file' => 'inactive-plugin/inactive-plugin.php' ),
		);
	}
);

add_filter(
	'phpcompat_themes_to_scan',
	static function () {
		return array(
			array( 'slug' => 'passing-theme', 'name' => 'Passing Theme', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '', 'source' => 'wporg', 'file' => 'passing-theme' ),
		);
	}
);

// Tests start from no stored results.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'phpcompat-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					\WPEngine_PHPCompat\Results::delete_all();
					return true;
				},
			)
		);
	}
);

// Tests toggle the WP Engine sidebar through the REST settings endpoint.
add_action(
	'init',
	static function () {
		foreach ( array( 'phpcompat_e2e_is_wpe', 'phpcompat_e2e_advisory' ) as $setting ) {
			register_setting(
				'general',
				$setting,
				array(
					'type'         => 'boolean',
					'default'      => false,
					'show_in_rest' => true,
				)
			);
		}
	}
);

if ( ! function_exists( 'is_wpe' ) ) {
	/**
	 * Stand-in for WP Engine's is_wpe().
	 *
	 * @return bool
	 */
	function is_wpe() {
		return (bool) get_option( 'phpcompat_e2e_is_wpe' );
	}
}

if ( get_option( 'phpcompat_e2e_is_wpe' ) && ! defined( 'PWP_NAME' ) ) {
	define( 'PWP_NAME', 'e2esite' );
}

// WP-CLI scans use the same fixture reports as the browser tests (the items have no files).
add_filter(
	'wpephpcompat_pre_scan_report',
	static function ( $report, $item ) {
		$fixtures = json_decode( file_get_contents( __DIR__ . '/phpcompat-e2e-scan.json' ), true );
		$fixture  = isset( $fixtures['items'][ $item['slug'] ] ) ? $fixtures['items'][ $item['slug'] ] : array( 'failing' => array() );
		$versions = array();

		foreach ( $fixtures['versions'] as $version ) {
			$fails                = in_array( $version, $fixture['failing'], true );
			$versions[ $version ] = array(
				'totals' => array( 'errors' => $fails ? 1 : 0, 'warnings' => 0 ),
				'files'  => $fails ? array(
					'includes/legacy.php' => array(
						'errors'   => 1,
						'warnings' => 0,
						'messages' => array(
							array(
								'message' => "Example problem on PHP $version",
								'source'  => 'PHPCompatibility.Example.Found',
								'type'    => 'ERROR',
								'line'    => 42,
								'column'  => 1,
							),
						),
					),
				) : array(),
			);
		}

		return array( 'versions' => $versions );
	},
	10,
	2
);

// Critical advisories depend on the PHP version wp-env happens to run. Off by
// default so tests see php.net's data alone; on, a fake advisory affects this server.
add_filter(
	'wpephpcompat_php_advisories',
	static function ( $advisories ) {
		if ( ! get_option( 'phpcompat_e2e_advisory' ) ) {
			return array();
		}
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		return array(
			array(
				'id'         => 'e2e',
				'name'       => 'E2E Shell',
				'cves'       => array( 'CVE-2099-0001' ),
				'url'        => 'https://example.com/e2e',
				'exploited'  => true,
				'since'      => '5.4.0',
				'fixed'      => array( $branch => $branch . '.999' ),
				'conditions' => array(),
				'mitigation' => '',
			),
		);
	}
);

// Fake php.net release data: a newer security release exists for this server's branch.
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$major  = (string) PHP_MAJOR_VERSION;
		$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		if ( 'https://www.php.net/releases/states.php' === $url ) {
			$body = array(
				$major => array(
					$branch => array(
						'state'                => 'security',
						'security_support_end' => '2099-12-31T00:00:00+00:00',
					),
				),
			);
		} elseif ( 'https://www.php.net/releases/active.php' === $url ) {
			$body = array(
				$major => array(
					$branch => array(
						'version' => $branch . '.999',
						'date'    => '01 Jan 2099',
						'tags'    => array( 'security' ),
					),
				),
			);
		} else {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

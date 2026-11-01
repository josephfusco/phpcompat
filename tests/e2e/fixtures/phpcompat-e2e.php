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
			array( 'slug' => 'passing-plugin', 'name' => 'Passing Plugin', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '' ),
			array( 'slug' => 'problem-plugin', 'name' => 'Problem Plugin', 'version' => '2.0.0', 'active' => 'yes', 'requires_php' => '7.2' ),
			array( 'slug' => 'new-php-plugin', 'name' => 'New PHP Plugin', 'version' => '3.0.0', 'active' => 'yes', 'requires_php' => '8.3' ),
			array( 'slug' => 'custom-plugin', 'name' => 'Custom Plugin', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '' ),
			array( 'slug' => 'inactive-plugin', 'name' => 'Inactive Plugin', 'version' => '1.0.0', 'active' => 'no', 'requires_php' => '' ),
		);
	}
);

add_filter(
	'phpcompat_themes_to_scan',
	static function () {
		return array(
			array( 'slug' => 'passing-theme', 'name' => 'Passing Theme', 'version' => '1.0.0', 'active' => 'yes', 'requires_php' => '' ),
		);
	}
);

// Tests toggle the WP Engine sidebar through the REST settings endpoint.
add_action(
	'init',
	static function () {
		register_setting(
			'general',
			'phpcompat_e2e_is_wpe',
			array(
				'type'         => 'boolean',
				'default'      => false,
				'show_in_rest' => true,
			)
		);
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

// Serve the same fake Tide responses to server-side requests (WP-CLI, abilities).
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://wptide.org/api/v1/audit/' ) ) {
			return $preempt;
		}

		$fixtures = json_decode( file_get_contents( __DIR__ . '/phpcompat-e2e-tide.json' ), true );
		$parts    = explode( '/', wp_parse_url( $url, PHP_URL_PATH ) );
		$slug     = isset( $parts[6] ) ? $parts[6] : '';
		$fixture  = isset( $fixtures['items'][ $slug ] ) ? $fixtures['items'][ $slug ] : array( 'status' => 404 );

		if ( 200 !== $fixture['status'] ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'message' => 'The audit requested does not exist.', 'status' => 404 ) ),
				'response' => array( 'code' => 404, 'message' => 'Not Found' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		$versions = array();
		foreach ( $fixtures['versions'] as $version ) {
			$fails                = in_array( $version, $fixture['failing'], true );
			$versions[ $version ] = array(
				'totals' => array( 'errors' => $fails ? 1 : 0, 'warnings' => 0 ),
				'files'  => $fails ? array(
					'includes/legacy.php' => array(
						'messages' => array(
							array(
								'message' => "Example problem on PHP $version",
								'source'  => 'PHPCompatibility.Example.Found',
								'type'    => 'ERROR',
								'line'    => 42,
							),
						),
					),
				) : array(),
			);
		}

		$body = array(
			'status'  => 'complete',
			'reports' => array(
				'phpcs_phpcompatibilitywp' => array(
					'report' => array(
						'compatible'   => array_values( array_diff( $fixtures['versions'], $fixture['failing'] ) ),
						'incompatible' => $fixture['failing'],
						'versions'     => $versions,
					),
				),
			),
		);

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

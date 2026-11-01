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

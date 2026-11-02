<?php
/**
 * Plugin Name:       PHP Compatibility Checker
 * Plugin URI:        https://wpengine.com
 * Description:       The WP Engine PHP Compatibility Checker can be used by any WordPress website on any web host to check PHP version compatibility.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            WP Engine
 * Author URI:        https://wpengine.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       php-compatibility-checker
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

define( 'WPEPHPCOMPAT_ADMIN_PAGE_SLUG', 'wpe-php-compat' );
define( 'WPEPHPCOMPAT_CAPABILITY', 'manage_options' );
define( 'WPEPHPCOMPAT_VERSION', '2.0.0' );
define( 'WPEPHPCOMPAT_PLUGIN_FILE', __FILE__ );
define( 'WPEPHPCOMPAT_PLUGIN_DIR', __DIR__ . '/' );

use WPEngine_PHPCompat\PHP_Compatibility_Checker;

/**
 * Load plugin functionality.
 *
 * @since 1.0.0
 */
function wpe_phpcompat_loader() {
	$register_phpcompat = new PHP_Compatibility_Checker();
	$register_phpcompat->init();

	// Add plugin action link.
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $register_phpcompat, 'filter_plugin_links' ) );

	// REST API for the scan page: scans files in batches and stores results.
	add_action(
		'rest_api_init',
		static function () {
			( new Rest_Controller() )->register_routes();
		}
	);

	// Abilities API (WordPress 6.9+): lets AI agents and other clients run scans.
	Abilities::init();

	// Site Health: a test for active plugins and the theme, and an Info section.
	Site_Health::init();

	// WP-CLI: wp php-compat scan|report|php-status.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'php-compat', CLI_Command::class );
	}
}

/**
 * Builds the class file name for the plugin
 *
 * @since 1.0.0
 *
 * @param string $class_name The name of the class to get.
 * @return string
 */
function wpe_phpcompat_get_class_file( $class_name ) {

	$prefix   = 'WPEngine_PHPCompat\\';
	$base_dir = __DIR__ . '/lib/';

	$len = strlen( $prefix );

	if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
		return '';
	}

	$relative_class = substr( $class_name, $len );
	$file           = $base_dir . str_replace( '\\', '/', 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) ) . '.php';

	$relative_class_parts = explode( '\\', $relative_class );

	if ( 1 < count( $relative_class_parts ) ) {
		$class_file = $relative_class_parts[0] . '/class-' . strtolower( str_replace( '_', '-', $relative_class_parts[1] ) );
		$file       = $base_dir . str_replace( '\\', '/', $class_file ) . '.php';
	}

	return $file;
}

/**
 * Auto-loading functionality for the plugin features
 *
 * @since 1.0.0
 *
 * @param string $class_name The class to load.
 */
function wpe_phpcompat_autoloader( $class_name ) {

	$file = wpe_phpcompat_get_class_file( $class_name );

	if ( ! empty( $file ) && file_exists( $file ) ) {
		include $file;
	}
}

spl_autoload_register( __NAMESPACE__ . '\wpe_phpcompat_autoloader' );

add_action( 'plugins_loaded', __NAMESPACE__ . '\wpe_phpcompat_loader' );

/**
 * Remove data left by versions before 1.6, which scanned on the server.
 *
 * Runs once; the flag records that it has.
 *
 * @return void
 */
function cleanup_legacy_data() {
	if ( get_option( 'wpephpcompat_legacy_cleaned', false ) ) {
		return;
	}

	// Options from the old server-side scanner, plus the 1.6.x "already cleaned" flag.
	foreach ( array( 'wpephpcompat.test_version', 'wpephpcompat.only_active', 'wpephpcompat.scan_results', 'wpephpcompat.lock', 'wpephpcompat.status', 'wpephpcompat.numdirs', 'wpephpcompat.show_notice', 'wpephpcompat_is_wptide' ) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'wpephpcompat_start_test_cron' );

	// Always the first page: each pass deletes what it found, so paging on
	// would skip every other batch.
	do {
		$jobs = get_posts(
			array(
				'posts_per_page' => 100,
				'post_type'      => 'wpephpcompat_jobs',
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		foreach ( $jobs as $job ) {
			wp_delete_post( $job, true );
		}
	} while ( $jobs );

	update_option( 'wpephpcompat_legacy_cleaned', 1 );
}

/**
 * Activate plugin
 *
 * @return void
 */
function activate() {
	cleanup_legacy_data();
}

/**
 * Uninstall plugin
 *
 * @return void
 */
function uninstall() {
	cleanup_legacy_data();
	delete_option( 'wpephpcompat_legacy_cleaned' );
	Results::delete_all();
	Rest_Controller::delete_transients();
}

/**
 * Perform operations when the plugin is upgraded
 *
 * @param WP_Upgrader $upgrader WordPress upgrader instance.
 * @param array       $hook_extra Options.
 * @return void
 */
function upgrade( $upgrader, $hook_extra ) {
	$current_plugin_path_name = plugin_basename( __FILE__ );

	if (
		isset( $hook_extra['action'], $hook_extra['type'] ) &&
		'update' === $hook_extra['action'] &&
		'plugin' === $hook_extra['type']
	) {
		// Single-plugin updates pass 'plugin'; bulk updates pass 'plugins'.
		$plugins = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}

		foreach ( $plugins as $plugin ) {
			if ( $plugin === $current_plugin_path_name ) {
				cleanup_legacy_data();
			}
		}
	}
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\uninstall' );
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\upgrade', 10, 2 );

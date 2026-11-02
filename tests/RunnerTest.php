<?php
/**
 * Tests for the bundled scanner (tools/phpcs-bundle/runner.php).
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use PHPUnit\Framework\TestCase;

/**
 * Runs real PHPCompatibilityWP scans on a fixture plugin. The same runner runs
 * in the browser (PHP WebAssembly) and under WP-CLI.
 */
class RunnerTest extends TestCase {

	/**
	 * Report for the fixture plugin, scanned once.
	 *
	 * @var array
	 */
	private static $report;

	public static function setUpBeforeClass(): void {
		$bundle = dirname( __DIR__ ) . '/tools/phpcs-bundle';
		if ( ! is_dir( $bundle . '/vendor/squizlabs/php_codesniffer' ) ) {
			self::markTestSkipped( 'Run composer install -d tools/phpcs-bundle first.' );
		}
		require_once $bundle . '/runner.php';
		self::$report = Bundle\scan( __DIR__ . '/fixtures/sample-plugin', array( '7.4', '8.0', '8.4' ) );
	}

	public function test_each_version_is_checked_on_its_own() {
		// each() was removed in 8.0, so 7.4 passes and 8.0 and later fail.
		$this->assertSame( array( '7.4' ), self::$report['compatible'] );
		$this->assertSame( array( '8.0', '8.4' ), self::$report['incompatible'] );
	}

	public function test_paths_are_relative_and_only_files_with_messages_are_listed() {
		$this->assertSame( array( 'includes/legacy.php' ), array_keys( self::$report['versions']['8.0']['files'] ) );
		// On 7.4 each() is only deprecated: listed as a warning, and the version passes.
		$this->assertSame( 0, self::$report['versions']['7.4']['totals']['errors'] );
		$this->assertSame( 1, self::$report['versions']['7.4']['totals']['warnings'] );
	}

	public function test_messages_have_line_type_and_source() {
		$message = self::$report['versions']['8.0']['files']['includes/legacy.php']['messages'][0];

		$this->assertSame( 9, $message['line'] );
		$this->assertSame( 'ERROR', $message['type'] );
		$this->assertStringContainsString( 'each()', $message['message'] );
		$this->assertStringStartsWith( 'PHPCompatibility.', $message['source'] );
	}

	public function test_deprecations_are_warnings_that_do_not_fail_a_version() {
		$warnings = array_filter(
			self::$report['versions']['8.4']['files']['includes/legacy.php']['messages'],
			static function ( $message ) {
				return 'WARNING' === $message['type'];
			}
		);

		$this->assertNotEmpty( $warnings );
		$this->assertSame( 1, self::$report['versions']['8.4']['totals']['errors'] );
	}

	public function test_a_single_file_is_reported_by_its_name() {
		$report = Bundle\scan( __DIR__ . '/fixtures/sample-plugin/includes/legacy.php', array( '8.0' ) );

		$this->assertSame( array( 'legacy.php' ), array_keys( $report['versions']['8.0']['files'] ) );
	}
}

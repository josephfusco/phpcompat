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
 * for the scan page (in batches) and under WP-CLI.
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

	public function test_batches_scan_only_the_given_files() {
		$base   = __DIR__ . '/fixtures/sample-plugin';
		$report = Bundle\scan_files( $base, array( 'includes/legacy.php', '../RunnerTest.php' ), array( '8.0' ) );

		// Paths outside the base are ignored; the given file is reported relative to it.
		$this->assertSame( array( 'includes/legacy.php' ), array_keys( $report['versions']['8.0']['files'] ) );
		$this->assertSame( array( '8.0' ), $report['incompatible'] );
	}

	public function test_npm_packages_are_not_scanned() {
		$dir = sys_get_temp_dir() . '/pcc-npm-' . uniqid();
		mkdir( $dir . '/node_modules/pkg', 0777, true );
		// Fails on PHP 7.4 (removed mysql extension), but WordPress never loads it.
		file_put_contents( $dir . '/node_modules/pkg/tool.php', "<?php\nmysql_connect( 'localhost' );\n" );
		file_put_contents( $dir . '/plugin.php', "<?php\n// Fine.\n" );

		try {
			$report = Bundle\scan( $dir, array( '7.4' ) );
		} finally {
			unlink( $dir . '/node_modules/pkg/tool.php' );
			unlink( $dir . '/plugin.php' );
			rmdir( $dir . '/node_modules/pkg' );
			rmdir( $dir . '/node_modules' );
			rmdir( $dir );
		}

		$this->assertSame( array(), $report['incompatible'] );
	}

	public function test_files_that_are_not_utf8_are_still_reported() {
		$dir = sys_get_temp_dir() . '/pcc-latin1-' . uniqid();
		mkdir( $dir );
		// Latin-1 bytes ("été") inside an ASP-style tag, which the sniff quotes, plus a removed function.
		file_put_contents( $dir . '/legacy.php', "<?php\n\$link = mysql_connect( 'localhost' );\n?>\n<%= \"\xE9t\xE9\" %>\n" );

		try {
			$report = Bundle\scan( $dir, array( '7.4' ) );
		} finally {
			unlink( $dir . '/legacy.php' );
			rmdir( $dir );
		}

		$messages = $report['versions']['7.4']['files']['legacy.php']['messages'];
		$sources  = array_column( $messages, 'source' );
		$this->assertContains( 'PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved', $sources );
		$this->assertSame( array( '7.4' ), $report['incompatible'] );
		foreach ( $messages as $message ) {
			$this->assertTrue( (bool) preg_match( '//u', $message['message'] ), 'Messages are valid UTF-8.' );
		}
		$this->assertNotFalse( json_encode( $report ) );
	}

	public function test_historical_false_positives_stay_fixed() {
		$dir = sys_get_temp_dir() . '/pcc-history-' . uniqid();
		mkdir( $dir );
		// Upstream issues #79/#139 (mysql_to_rfc3339 is a WordPress function), #141 (hash_equals is polyfilled by WordPress).
		file_put_contents( $dir . '/ok.php', "<?php\n\$date = mysql_to_rfc3339( '2020-01-01 00:00:00' );\n\$same = hash_equals( 'a', 'b' );\n" );

		try {
			$report = Bundle\scan( $dir, array( '7.4', '8.5' ) );
		} finally {
			unlink( $dir . '/ok.php' );
			rmdir( $dir );
		}

		$this->assertSame( array( '7.4', '8.5' ), $report['compatible'] );
		$this->assertSame( array(), $report['versions']['8.5']['files'] );
	}

	public function test_phpcs_ignore_annotations_are_honored() {
		$dir = sys_get_temp_dir() . '/pcc-ignore-' . uniqid();
		mkdir( $dir );
		// Upstream issues #215/#223: version-guarded code can opt out of a check.
		file_put_contents( $dir . '/guarded.php', "<?php\nif ( function_exists( 'each' ) ) {\n\t\$x = each( \$arr ); // phpcs:ignore PHPCompatibility\n}\n" );

		try {
			$report = Bundle\scan( $dir, array( '8.0' ) );
		} finally {
			unlink( $dir . '/guarded.php' );
			rmdir( $dir );
		}

		$this->assertSame( array( '8.0' ), $report['compatible'] );
	}

	public function test_pass_one_stops_at_a_deadline_after_at_least_one_file() {
		$base  = __DIR__ . '/fixtures/sample-plugin';
		$files = array( 'sample-plugin.php', 'includes/legacy.php' );

		// A deadline already passed: still checks one file, then stops.
		$early = Bundle\flag_files( $base, $files, array( '7.4', '8.0' ), microtime( true ) - 1 );
		$this->assertSame( 1, $early['checked'] );

		// No deadline: every file, and the one with each() is flagged.
		$all = Bundle\flag_files( $base, $files, array( '7.4', '8.0' ) );
		$this->assertSame( 2, $all['checked'] );
		$this->assertCount( 1, $all['flagged'] );
		$this->assertStringEndsWith( 'includes/legacy.php', str_replace( '\\', '/', $all['flagged'][0] ) );
	}

	public function test_reusing_the_engine_across_passes_gives_the_same_results() {
		$base = __DIR__ . '/fixtures/sample-plugin';

		// Run several passes with different versions first, then compare with the
		// result computed once at the start: state from one pass must not leak into the next.
		Bundle\scan_files( $base, array( 'includes/legacy.php' ), array( '8.4' ) );
		Bundle\scan_files( $base, array( 'includes/legacy.php' ), array( '7.4' ) );
		$again = Bundle\scan( $base, array( '7.4', '8.0', '8.4' ) );

		$this->assertSame( self::$report, $again );
	}
}

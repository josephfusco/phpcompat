<?php
/**
 * Tests for the server-side scanner and ability output shaping.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Scanner rules, stored results, and report sanitizing.
 */
class ScannerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function item( $requires = '' ) {
		return array(
			'type'         => 'plugin',
			'slug'         => 'example',
			'name'         => 'Example',
			'version'      => '1.0.0',
			'active'       => true,
			'requires_php' => $requires,
		);
	}

	/**
	 * A report where the given versions fail.
	 */
	private function report( array $failing ) {
		$versions = array();
		foreach ( array( '5.6', '7.0', '7.4', '8.0' ) as $version ) {
			$fails                = in_array( $version, $failing, true );
			$versions[ $version ] = array(
				'totals' => array(
					'errors'   => $fails ? 1 : 0,
					'warnings' => 0,
				),
				'files'  => $fails ? array(
					'legacy.php' => array(
						'messages' => array(
							array(
								'line'    => 7,
								'type'    => 'ERROR',
								'message' => "Problem on $version",
								'source'  => 'PHPCompatibility.Example',
							),
						),
					),
				) : array(),
			);
		}

		return array(
			'incompatible' => $failing,
			'versions'     => $versions,
		);
	}

	public function test_passes_when_no_versions_fail() {
		$result = ( new Scanner( new \stdClass() ) )->evaluate( $this->item(), $this->report( array() ) );

		$this->assertSame( 'passes', $result['status'] );
		$this->assertSame( '8.0', $result['max_php_checked'] );
		$this->assertSame( array(), $result['failing_versions'] );
	}

	public function test_versions_below_requires_php_are_not_counted() {
		$result = ( new Scanner( new \stdClass() ) )->evaluate( $this->item( '7.4' ), $this->report( array( '5.6', '7.0' ) ) );

		$this->assertSame( 'passes', $result['status'] );
		$this->assertSame( 'not_counted', $result['php']['5.6'] );
		$this->assertSame( 'pass', $result['php']['7.4'] );
	}

	public function test_problems_in_supported_versions_with_details() {
		$result = ( new Scanner( new \stdClass() ) )->evaluate( $this->item( '7.0' ), $this->report( array( '5.6', '7.4' ) ), true );

		$this->assertSame( 'problems', $result['status'] );
		$this->assertSame( array( '7.4' ), $result['failing_versions'] );
		$this->assertSame( 'Problem on 7.4', $result['problems']['7.4'][0]['message'] );
		$this->assertArrayNotHasKey( '5.6', $result['problems'] );
	}

	public function test_not_checked_when_every_version_is_below_the_minimum() {
		$result = ( new Scanner( new \stdClass() ) )->evaluate( $this->item( '8.3' ), $this->report( array( '5.6' ) ) );

		$this->assertSame( 'not_checked', $result['status'] );
		$this->assertStringContainsString( '8.3', $result['reason'] );
	}

	public function test_not_scanned_without_a_stored_result() {
		Monkey\Functions\when( 'get_option' )->justReturn( false );

		$result = ( new Scanner( new \stdClass() ) )->scan_item( $this->item() );

		$this->assertSame( 'not_scanned', $result['status'] );
		$this->assertSame( '', $result['scanned_at'] );
	}

	public function test_stored_result_is_evaluated() {
		Monkey\Functions\when( 'get_option' )->justReturn(
			array(
				'version'    => '1.0.0',
				'source'     => 'wporg',
				'ruleset'    => Scanner_Bundle::ruleset(),
				'scanned_at' => 1798000000,
				'report'     => $this->report( array( '8.0' ) ),
			)
		);

		$result = ( new Scanner( new \stdClass() ) )->scan_item( $this->item() );

		$this->assertSame( 'problems', $result['status'] );
		$this->assertSame( array( '8.0' ), $result['failing_versions'] );
		$this->assertSame( gmdate( 'c', 1798000000 ), $result['scanned_at'] );
	}

	public function test_warnings_alone_do_not_fail_a_version() {
		$report                                         = $this->report( array() );
		$report['versions']['8.0']['totals']['warnings'] = 3;

		$result = ( new Scanner( new \stdClass() ) )->evaluate( $this->item(), $report );

		$this->assertSame( 'passes', $result['status'] );
	}

	public function test_sanitized_report_derives_failures_from_messages() {
		$this->stub_sanitizers();
		$report                 = $this->report( array( '8.0' ) );
		$report['incompatible'] = array( '5.6', '7.0', '7.4', '8.0' ); // Lies; ignored.

		$clean = Results::sanitize_report( $report );

		$this->assertSame( array( '8.0' ), $clean['incompatible'] );
		$this->assertSame( array( '5.6', '7.0', '7.4' ), $clean['compatible'] );
		$this->assertSame( 1, $clean['versions']['8.0']['files']['legacy.php']['errors'] );
	}

	public function test_sanitized_report_drops_unsafe_paths_and_versions() {
		$this->stub_sanitizers();
		$message = array(
			'line'    => 1,
			'type'    => 'ERROR',
			'message' => '<b>Bad</b>',
			'source'  => 'PHPCompatibility.X; rm -rf',
		);
		$clean   = Results::sanitize_report(
			array(
				'versions' => array(
					'8.0'    => array(
						'files' => array(
							'../../wp-config.php' => array( 'messages' => array( $message ) ),
							'/etc/passwd'         => array( 'messages' => array( $message ) ),
							'inc/ok.php'          => array( 'messages' => array( $message ) ),
						),
					),
					'latest' => array( 'files' => array() ),
				),
			)
		);

		$this->assertSame( array( '8.0' ), array_keys( $clean['versions'] ) );
		$this->assertSame( array( 'inc/ok.php' ), array_keys( $clean['versions']['8.0']['files'] ) );
		$stored = $clean['versions']['8.0']['files']['inc/ok.php']['messages'][0];
		// Plain text: kept as is ("<b>" included) and escaped where it's shown.
		$this->assertSame( '<b>Bad</b>', $stored['message'] );
		$this->assertSame( 'PHPCompatibility.Xrmrf', $stored['source'] );
	}

	public function test_cap_keeps_errors_before_warnings() {
		$this->stub_sanitizers();
		$warning = array(
			'line'    => 1,
			'type'    => 'WARNING',
			'message' => 'Deprecated',
			'source'  => 'PHPCompatibility.W',
		);
		$error   = array(
			'line'    => 2,
			'type'    => 'ERROR',
			'message' => 'Removed',
			'source'  => 'PHPCompatibility.E',
		);
		$clean   = Results::sanitize_report(
			array(
				'versions' => array(
					'8.0' => array(
						'files' => array(
							'a.php' => array( 'messages' => array_fill( 0, Results::MAX_MESSAGES_PER_VERSION + 5, $warning ) ),
							'z.php' => array( 'messages' => array( $error ) ),
						),
					),
				),
			)
		);

		$this->assertSame( array( '8.0' ), $clean['incompatible'] );
		$this->assertSame( 1, $clean['versions']['8.0']['totals']['errors'] );
		$this->assertSame( Results::MAX_MESSAGES_PER_VERSION + 5, $clean['versions']['8.0']['totals']['warnings'] );
		$this->assertSame( 'Removed', $clean['versions']['8.0']['files']['z.php']['messages'][0]['message'] );
	}

	public function test_stored_result_only_matches_its_version_and_ruleset() {
		$stored = array(
			'version'    => '1.0.0',
			'source'     => 'wporg',
			'ruleset'    => 'rules-1',
			'scanned_at' => 1798000000,
			'report'     => $this->report( array() ),
		);
		Monkey\Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertSame( $stored, ( new Results( 'rules-1' ) )->get( $this->item() ) );
		$this->assertNull( ( new Results( 'rules-2' ) )->get( $this->item() ) );
		$this->assertNull( ( new Results( 'rules-1' ) )->get( array_merge( $this->item(), array( 'version' => '1.0.1' ) ) ) );
	}

	private function stub_sanitizers() {
		Monkey\Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Monkey\Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( wp_strip_all_tags_stub( $value ) );
			}
		);
	}

	public function test_ability_output_uses_lists() {
		$shaped = Abilities::shape_item(
			array(
				'php'      => array(
					'7.4' => 'pass',
					'8.0' => 'fail',
				),
				'problems' => array(
					'8.0' => array( array( 'message' => 'x' ) ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'version' => '7.4',
					'result'  => 'pass',
				),
				array(
					'version' => '8.0',
					'result'  => 'fail',
				),
			),
			$shaped['php']
		);
		$this->assertSame( '8.0', $shaped['problems'][0]['php'] );
	}
}

/**
 * strip_tags() for the sanitize_text_field() stub.
 *
 * @param string $value Value.
 * @return string
 */
function wp_strip_all_tags_stub( $value ) {
	return strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}

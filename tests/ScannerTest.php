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
 * Scanner rules and Tide response handling.
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
	 * A Tide report where the given versions fail.
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

	public function test_404_is_not_checked_and_cached() {
		Monkey\Functions\when( 'get_transient' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_get' )->justReturn( array() );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":404}' );
		Monkey\Functions\expect( 'set_transient' )->once();

		$audit = ( new Scanner( new \stdClass() ) )->fetch_audit( $this->item() );

		$this->assertSame( 'not_checked', $audit['state'] );
	}

	public function test_pending_is_not_cached() {
		Monkey\Functions\when( 'get_transient' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_get' )->justReturn( array() );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"pending"}' );
		Monkey\Functions\expect( 'set_transient' )->never();

		$audit = ( new Scanner( new \stdClass() ) )->fetch_audit( $this->item() );

		$this->assertSame( 'pending', $audit['state'] );
	}

	public function test_cached_audit_skips_the_request() {
		$cached = array(
			'state'  => 'complete',
			'reason' => '',
			'report' => $this->report( array() ),
		);
		Monkey\Functions\when( 'get_transient' )->justReturn( $cached );
		Monkey\Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( $cached, ( new Scanner( new \stdClass() ) )->fetch_audit( $this->item() ) );
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

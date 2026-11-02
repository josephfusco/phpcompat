<?php
/**
 * Tests for PHP release status.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Php_Releases::evaluate() against php.net-shaped data.
 */
class PhpReleasesTest extends TestCase {

	private $states = array(
		'8' => array(
			'8.4' => array(
				'state'                => 'stable',
				'security_support_end' => '2028-12-31T00:00:00+00:00',
			),
			'8.3' => array(
				'state'                => 'security',
				'security_support_end' => '2027-12-31T00:00:00+00:00',
			),
			'8.1' => array(
				'state'                => 'eol',
				'security_support_end' => '2025-12-31T00:00:00+00:00',
			),
		),
		'7' => array(
			'7.4' => array(
				'state'                => 'eol',
				'security_support_end' => '2022-11-28T00:00:00+00:00',
			),
		),
	);

	private function active( $tags = array( 'security' ) ) {
		return array(
			'8' => array(
				'8.3' => array(
					'version' => '8.3.36',
					'date'    => '21 Oct 2026',
					'tags'    => $tags,
				),
				'8.4' => array(
					'version' => '8.4.27',
					'date'    => '21 Oct 2026',
					'tags'    => $tags,
				),
			),
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_up_to_date() {
		$status = Php_Releases::evaluate( '8.3.36', '', $this->states, $this->active() );

		$this->assertSame( 'up_to_date', $status['state'] );
		$this->assertSame( array( '8.3', '8.4' ), $status['supported_branches'] );
	}

	public function test_security_update_available() {
		$status = Php_Releases::evaluate( '8.3.35', '', $this->states, $this->active() );

		$this->assertSame( 'update_available', $status['state'] );
		$this->assertSame( '8.3.36', $status['latest'] );
		$this->assertTrue( $status['latest_is_security'] );
		$this->assertStringContainsString( 'security fixes', $status['message'] );
	}

	public function test_non_security_update_available() {
		$status = Php_Releases::evaluate( '8.3.35', '', $this->states, $this->active( array() ) );

		$this->assertSame( 'update_available', $status['state'] );
		$this->assertFalse( $status['latest_is_security'] );
		$this->assertStringNotContainsString( 'security fixes', $status['message'] );
	}

	public function test_end_of_life_branch() {
		$status = Php_Releases::evaluate( '7.4.33', '', $this->states, $this->active() );

		$this->assertSame( 'unsupported_branch', $status['state'] );
		$this->assertSame( '2022-11-28', $status['security_support_end'] );
		$this->assertStringContainsString( '8.3, 8.4', $status['message'] );
	}

	public function test_end_of_life_vendor_build_mentions_host_patches() {
		$status = Php_Releases::evaluate( '8.1.2', '-1ubuntu2.20', $this->states, $this->active() );

		$this->assertSame( 'unsupported_branch', $status['state'] );
		$this->assertStringContainsString( 'security notices', $status['message'] );
	}

	public function test_vendor_build_is_not_reported_as_outdated() {
		// Ubuntu ships fixes under older-looking version numbers.
		$status = Php_Releases::evaluate( '8.3.6', '-0ubuntu0.24.04.13', $this->states, $this->active() );

		$this->assertSame( 'vendor_build', $status['state'] );
		$this->assertStringContainsString( '8.3.6-0ubuntu0.24.04.13', $status['message'] );
	}

	public function test_unknown_when_php_net_is_unavailable() {
		$status = Php_Releases::evaluate( '8.3.35', '', null, null );

		$this->assertSame( 'unknown', $status['state'] );
	}

	/**
	 * Advisories with the patched versions above (8.3.36, 8.4.27), plus a
	 * configuration-dependent one without a fix.
	 */
	private function advisories() {
		return Php_Releases::advisories();
	}

	private $safe_ini = array(
		'session.upload_progress.enabled' => '1',
		'session.serialize_handler'       => 'php',
	);

	public function test_exploited_advisory_makes_server_vulnerable() {
		$status = Php_Releases::evaluate( '8.3.35', '', $this->states, $this->active(), $this->advisories(), $this->safe_ini );

		$this->assertSame( 'vulnerable', $status['state'] );
		$this->assertSame( array( 'php2shell' ), array_column( $status['advisories'], 'id' ) );
		$this->assertSame( '8.3.36', $status['advisories'][0]['fixed_in'] );
		$this->assertStringContainsString( 'PHP2Shell (CVE-2026-109618, CVE-2026-109619)', $status['message'] );
		$this->assertStringContainsString( 'actively exploiting', $status['message'] );
		$this->assertStringContainsString( 'Update to PHP 8.3.36', $status['message'] );
	}

	public function test_patched_release_is_not_vulnerable() {
		$status = Php_Releases::evaluate( '8.3.36', '', $this->states, $this->active(), $this->advisories(), $this->safe_ini );

		$this->assertSame( 'up_to_date', $status['state'] );
		$this->assertSame( array(), $status['advisories'] );
	}

	public function test_vulnerable_even_when_php_net_is_unavailable() {
		$status = Php_Releases::evaluate( '8.4.20', '', null, null, $this->advisories(), $this->safe_ini );

		$this->assertSame( 'vulnerable', $status['state'] );
		$this->assertStringContainsString( '8.4.27', $status['message'] );
	}

	public function test_end_of_life_branch_names_unfixed_advisory() {
		$status = Php_Releases::evaluate( '7.4.33', '', $this->states, $this->active(), $this->advisories(), $this->safe_ini );

		$this->assertSame( 'unsupported_branch', $status['state'] );
		$this->assertSame( '', $status['advisories'][0]['fixed_in'] );
		$this->assertStringContainsString( 'no official fix', $status['message'] );
	}

	public function test_branch_released_after_the_fix_is_not_affected() {
		$status = Php_Releases::evaluate( '8.6.0', '', null, null, $this->advisories(), $this->safe_ini );

		$this->assertSame( array(), $status['advisories'] );
	}

	public function test_vendor_build_asks_host_to_confirm_fix() {
		$status = Php_Releases::evaluate( '8.3.6', '-0ubuntu0.24.04.13', $this->states, $this->active(), $this->advisories(), $this->safe_ini );

		$this->assertSame( 'vendor_build', $status['state'] );
		$this->assertStringContainsString( 'CVE-2026-109618', $status['message'] );
	}

	public function test_configuration_advisory_needs_every_condition() {
		$exposed = Php_Releases::affecting(
			'8.3.36',
			$this->advisories(),
			array(
				'session.upload_progress.enabled' => '1',
				'session.serialize_handler'       => 'php_serialize',
			)
		);
		$this->assertSame( array( 'session-upload-progress' ), array_column( $exposed, 'id' ) );
		$this->assertStringContainsString( 'session.upload_progress.enabled=0', $exposed[0]['mitigation'] );

		$disabled = Php_Releases::affecting(
			'8.3.36',
			$this->advisories(),
			array(
				'session.upload_progress.enabled' => '0',
				'session.serialize_handler'       => 'php_serialize',
			)
		);
		$this->assertSame( array(), $disabled );
	}

	public function test_configuration_advisory_does_not_change_state() {
		$status = Php_Releases::evaluate(
			'8.3.36',
			'',
			$this->states,
			$this->active(),
			$this->advisories(),
			array(
				'session.upload_progress.enabled' => '1',
				'session.serialize_handler'       => 'php_binary',
			)
		);

		$this->assertSame( 'up_to_date', $status['state'] );
		$this->assertSame( array( 'session-upload-progress' ), array_column( $status['advisories'], 'id' ) );
	}
}

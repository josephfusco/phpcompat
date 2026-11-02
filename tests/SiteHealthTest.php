<?php
/**
 * Tests for the Site Health test and Info section.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * A scanner with fixed stored results.
 */
class Fake_Scanner extends Scanner {

	/**
	 * Results to return.
	 *
	 * @var array[]
	 */
	public $results;

	/**
	 * @param array[] $results Results.
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	public function get_items( $args = array() ) {
		return $this->results;
	}

	public function scan( $items, $include_details = false, $mode = self::MODE_STORED, $progress = null ) {
		return array(
			'items'           => $this->results,
			'summary'         => array(),
			'max_php_checked' => '8.5',
			'source'          => '',
		);
	}
}

/**
 * Site Health.
 */
class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\when( 'admin_url' )->returnArg();
		Monkey\Functions\when( 'add_query_arg' )->justReturn( 'tools.php?page=wpe-php-compat' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function item( $status, $extra = array() ) {
		return array_merge(
			array(
				'type'             => 'plugin',
				'slug'             => $status . '-plugin',
				'name'             => ucfirst( $status ) . ' Plugin',
				'version'          => '1.0',
				'active'           => true,
				'status'           => $status,
				'reason'           => '',
				'failing_versions' => 'problems' === $status ? array( '8.4', '8.5' ) : array(),
				'max_php_checked'  => 'not_scanned' === $status ? '' : '8.5',
				'needs_php'        => null,
			),
			$extra
		);
	}

	private function run_test( array $items ) {
		return ( new Site_Health( new Fake_Scanner( $items ) ) )->test();
	}

	public function test_is_registered_as_a_direct_test() {
		$tests = ( new Site_Health( new Fake_Scanner( array() ) ) )->register_test( array( 'direct' => array() ) );

		$this->assertArrayHasKey( Site_Health::TEST, $tests['direct'] );
	}

	public function test_good_when_everything_passes() {
		$result = $this->run_test( array( $this->item( 'passes' ) ) );

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'PHP 8.5', $result['description'] );
	}

	public function test_recommends_a_scan_when_nothing_was_scanned() {
		$result = $this->run_test( array( $this->item( 'not_scanned' ) ) );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Check your plugins', $result['label'] );
		$this->assertStringContainsString( 'tools.php?page=wpe-php-compat', $result['actions'] );
	}

	public function test_lists_problems() {
		$result = $this->run_test( array( $this->item( 'passes' ), $this->item( 'problems' ) ) );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Problems Plugin 1.0: problems on PHP 8.4, 8.5', $result['description'] );
	}

	public function test_lists_a_wrong_requires_php() {
		$result = $this->run_test(
			array(
				$this->item(
					'passes',
					array(
						'needs_php' => array(
							'declared' => '7.4',
							'needed'   => '8.1',
							'source'   => 'composer',
							'bundled'  => false,
						),
					)
				),
			)
		);

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'need a newer PHP than they say', $result['label'] );
		$this->assertStringContainsString( 'Passes Plugin needs PHP 8.1, but says it requires PHP 7.4', $result['description'] );
	}

	public function test_mentions_items_not_scanned_yet() {
		$result = $this->run_test( array( $this->item( 'passes' ), $this->item( 'not_scanned' ) ) );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '1 active plugin or theme has not been scanned', $result['description'] );
	}

	public function test_info_section_lists_every_item() {
		Monkey\Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);
		$info = ( new Site_Health( new Fake_Scanner( array( $this->item( 'passes' ), $this->item( 'problems' ) ) ) ) )->debug_information( array() );

		$fields = $info['php-compatibility-checker']['fields'];
		$this->assertSame( 'Passes, up to PHP 8.5', $fields['plugin-passes-plugin']['value'] );
		$this->assertSame( 'problems (8.4, 8.5)', $fields['plugin-problems-plugin']['debug'] );
		$this->assertArrayHasKey( 'scanner', $fields );
	}
}

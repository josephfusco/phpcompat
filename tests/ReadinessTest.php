<?php
/**
 * Tests for Scanner::readiness().
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which PHP versions a site's results are ready for.
 */
class ReadinessTest extends TestCase {

	/**
	 * Cases shared with the JavaScript tests.
	 *
	 * @return array
	 */
	public static function readiness_cases(): array {
		$data  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/readiness-cases.json' ), true );
		$cases = array();
		foreach ( $data['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider readiness_cases
	 */
	#[DataProvider( 'readiness_cases' )]
	public function test_readiness( array $case ) {
		$results = array_map(
			static function ( $item ) {
				return array_merge( array( 'type' => 'plugin' ), $item );
			},
			$case['items']
		);

		$actual = Scanner::readiness( $results, $case['current'] );

		$blockers = array();
		foreach ( $actual['blockers'] as $version => $items ) {
			$blockers[ (string) $version ] = array_column( $items, 'slug' );
		}

		$expected = $case['expected'];
		$this->assertSame( $expected['complete'], $actual['complete'] );
		$this->assertSame( $expected['ready_for'], $actual['ready_for'] );
		$this->assertSame( $expected['next_ready'], $actual['next_ready'] );
		$this->assertSame( $expected['next_blocked'], $actual['next_blocked'] );
		$this->assertSame( $expected['blockers'], $blockers );
		$this->assertSame( $expected['unknown'], array_column( $actual['unknown'], 'slug' ) );
	}

	public function test_blockers_carry_name_and_version() {
		$actual = Scanner::readiness(
			array(
				array(
					'type'    => 'plugin',
					'slug'    => 'old',
					'name'    => 'Old Plugin',
					'version' => '1.2',
					'status'  => 'problems',
					'php'     => array(
						'8.2' => 'pass',
						'8.3' => 'fail',
					),
				),
			),
			'8.2'
		);

		$this->assertSame(
			array(
				'type'    => 'plugin',
				'slug'    => 'old',
				'name'    => 'Old Plugin',
				'version' => '1.2',
			),
			$actual['blockers']['8.3'][0]
		);
	}
}

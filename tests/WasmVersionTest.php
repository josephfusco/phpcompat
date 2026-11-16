<?php
/**
 * Keeps the PHP side pointed at the php-wasm build webpack actually copies.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scanner_Bundle::WASM_VERSION.
 *
 * webpack copies @php-wasm/web-8-3 into build/php-wasm/<its npm version>/, and
 * bundles @php-wasm/universal into the worker. If a dependency update changes
 * either version without WASM_VERSION, the browser asks for a folder that
 * doesn't exist and every scan fails, while the e2e tests (which mock the
 * scanner) still pass. This test is what stops that update.
 */
class WasmVersionTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function package_provider(): array {
		return array(
			'runtime copied to build/php-wasm' => array( '@php-wasm/web-8-3' ),
			'loader bundled in the worker'     => array( '@php-wasm/universal' ),
		);
	}

	#[DataProvider( 'package_provider' )]
	public function test_wasm_version_matches_package_json( string $package ) {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/package.json' ), true );
		$deps = array_merge( $json['dependencies'] ?? array(), $json['devDependencies'] ?? array() );

		$this->assertArrayHasKey( $package, $deps );
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$deps[ $package ],
			"Pin $package to an exact version: the build folder is named after it."
		);
		$this->assertSame(
			$deps[ $package ],
			Scanner_Bundle::WASM_VERSION,
			"Update Scanner_Bundle::WASM_VERSION to match $package, then run a real scan."
		);
	}
}

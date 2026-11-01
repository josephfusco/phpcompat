<?php
/**
 * Tests for the WP Engine sidebar helpers.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * WP Engine sidebar helpers.
 */
class WpeSidebarTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_portal_url_falls_back_to_sites_page() {
		$url = ( new PHP_Compatibility_Checker() )->get_wpe_portal_url( 'test_content' );

		$this->assertStringStartsWith( 'https://my.wpengine.com/sites?', $url );
		$this->assertStringContainsString( 'utm_content=test_content', $url );
		$this->assertStringContainsString( 'utm_campaign=php_7_compatibility_checker', $url );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_portal_url_links_to_environment_when_known() {
		define( 'PWP_NAME', 'MySite-Staging' );

		$url = ( new PHP_Compatibility_Checker() )->get_wpe_portal_url( 'x' );

		$this->assertStringStartsWith( 'https://my.wpengine.com/installs/mysite-staging?', $url );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_portal_url_ignores_malformed_environment_name() {
		define( 'PWP_NAME', '../evil?x=1' );

		$url = ( new PHP_Compatibility_Checker() )->get_wpe_portal_url( 'x' );

		$this->assertStringStartsWith( 'https://my.wpengine.com/sites?', $url );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function php_status_provider(): array {
		return array(
			'unavailable'      => array( false, '' ),
			'insecure'         => array(
				array(
					'is_secure'    => false,
					'is_supported' => false,
				),
				'no longer gets security updates',
			),
			'security only'    => array(
				array(
					'is_secure'           => true,
					'is_supported'        => false,
					'recommended_version' => '8.3',
				),
				'WordPress recommends PHP 8.3 or newer',
			),
			'meets recommended' => array(
				array(
					'is_secure'    => true,
					'is_supported' => true,
				),
				'meets WordPress\'s recommended version',
			),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'php_status_provider' )]
	public function test_php_status_message( $response, $expected ) {
		Monkey\Functions\when( 'wp_check_php_version' )->justReturn( $response );

		$message = ( new PHP_Compatibility_Checker() )->get_php_status_message();

		if ( '' === $expected ) {
			$this->assertSame( '', $message );
		} else {
			$this->assertStringContainsString( $expected, $message );
		}
	}

	public function test_sanitize_php_version() {
		$this->assertSame( '7.4', PHP_Compatibility_Checker::sanitize_php_version( '7.4' ) );
		$this->assertSame( '5.2.4', PHP_Compatibility_Checker::sanitize_php_version( ' 5.2.4 ' ) );
		$this->assertSame( '8', PHP_Compatibility_Checker::sanitize_php_version( '8' ) );
		$this->assertSame( '', PHP_Compatibility_Checker::sanitize_php_version( '' ) );
		$this->assertSame( '', PHP_Compatibility_Checker::sanitize_php_version( '7.4+' ) );
		$this->assertSame( '', PHP_Compatibility_Checker::sanitize_php_version( '<script>' ) );
		$this->assertSame( '', PHP_Compatibility_Checker::sanitize_php_version( null ) );
		$this->assertSame( '', PHP_Compatibility_Checker::sanitize_php_version( false ) );
	}

	/**
	 * The plugins filter must run even when no plugins are left to scan,
	 * e.g. a site where this plugin is the only one installed.
	 */
	public function test_plugins_filter_runs_when_no_plugins_remain() {
		Monkey\Functions\when( 'get_plugins' )->justReturn(
			array( 'php-compatibility-checker/wpengine-phpcompat.php' => array( 'Name' => 'PHP Compatibility Checker' ) )
		);
		Monkey\Filters\expectApplied( 'phpcompat_plugins_to_scan' )
			->once()
			->with( array() )
			->andReturn( array( array( 'slug' => 'added-by-filter' ) ) );

		$plugins = ( new PHP_Compatibility_Checker() )->get_plugins_to_scan();

		$this->assertSame( array( array( 'slug' => 'added-by-filter' ) ), $plugins );
	}
}

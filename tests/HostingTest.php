<?php
/**
 * Tests for the host panel details.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Hosting.
 */
class HostingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Monkey\Functions\when( 'wp_get_default_update_php_url' )->justReturn( 'https://wordpress.org/support/update-php/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_no_panel_when_nothing_is_known_about_the_host() {
		Monkey\Functions\when( 'wp_get_update_php_url' )->justReturn( 'https://wordpress.org/support/update-php/' );

		$this->assertNull( Hosting::get() );
	}

	public function test_host_php_guide_from_core_shows_a_generic_panel() {
		Monkey\Functions\when( 'wp_get_update_php_url' )->justReturn( 'https://example-host.test/php' );

		$host = Hosting::get();

		$this->assertSame( '', $host['name'] );
		$this->assertSame( 'https://example-host.test/php', $host['links'][0]['url'] );
		$this->assertSame( array( 'text' => '', 'links' => array() ), $host['help'] );
	}

	public function test_filter_can_describe_a_host_and_is_normalized() {
		Monkey\Functions\when( 'wp_get_update_php_url' )->justReturn( 'https://wordpress.org/support/update-php/' );
		Monkey\Filters\expectApplied( 'wpephpcompat_host' )
			->once()
			->with( false )
			->andReturn(
				array(
					'name'  => 'Example Hosting',
					'links' => array(
						array( 'label' => 'Control panel', 'url' => 'https://panel.example.test' ),
						array( 'label' => 'No URL' ),
						'not an array',
					),
					'help'  => array( 'text' => 'Ask support.' ),
				)
			);

		$host = Hosting::get();

		$this->assertSame( 'Example Hosting', $host['name'] );
		$this->assertSame( '', $host['change_php'] );
		$this->assertSame( array( array( 'label' => 'Control panel', 'url' => 'https://panel.example.test' ) ), $host['links'] );
		$this->assertSame( array( 'text' => 'Ask support.', 'links' => array() ), $host['help'] );
	}

	public function test_filter_returning_false_hides_the_panel() {
		Monkey\Functions\when( 'wp_get_update_php_url' )->justReturn( 'https://example-host.test/php' );
		Monkey\Filters\expectApplied( 'wpephpcompat_host' )->once()->andReturn( false );

		$this->assertNull( Hosting::get() );
	}

	public function test_wp_engine_provider_passes_through_elsewhere() {
		$this->assertSame( 'from-earlier', Hosting::wp_engine( 'from-earlier' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_engine_provider_on_wp_engine() {
		eval( 'function is_wpe() { return true; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- defines a global stand-in.
		define( 'PWP_NAME', 'mysite' );

		$host = Hosting::wp_engine( false );

		$this->assertSame( 'WP Engine', $host['name'] );
		$this->assertSame( 'Open this environment', $host['links'][0]['label'] );
		$this->assertStringStartsWith( 'https://my.wpengine.com/installs/mysite?', $host['links'][0]['url'] );
		$this->assertNotEmpty( $host['help']['links'] );
	}

	public function test_short_release_status() {
		$checker = new PHP_Compatibility_Checker();

		$this->assertSame( 'PHP 8.3.36 is available.', $checker->get_short_release_status( array( 'state' => 'update_available', 'latest' => '8.3.36' ) ) );
		$this->assertStringContainsString( 'no longer gets security fixes', $checker->get_short_release_status( array( 'state' => 'unsupported_branch' ) ) );
	}
}

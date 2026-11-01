<?php

/**
 * Test the primary plugin file
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Test the main plugin file
 */
class PluginFileTest extends TestCase {

	protected function tearDown(): void {

		Monkey\tearDown();
		parent::tearDown();

	}

	public function test_true()
	{
		$this->assertTrue(true);
	}
	/**
	 * Test loader function
	 */
	public function test_wpe_phpcompat_loader() {

		Monkey\Functions\expect( 'load_plugin_textdomain' )->never();
		Monkey\Functions\when( 'plugin_basename' )->justReturn( 'php-compatibility-checker/wpengine-phpcompat.php' );
		Monkey\Functions\when( 'add_action' )->justReturn( true );
		Monkey\Functions\when( 'add_filter' )->justReturn( true );

		wpe_phpcompat_loader();

		$this->assertTrue( defined( 'WPEPHPCOMPAT_VERSION' ) );

	}

	public function test_autoloader_registered() {
		$this->assertContains( 'WPEngine_PHPCompat\wpe_phpcompat_autoloader', spl_autoload_functions() );
	}

	public function test_autoloader() {

		$home = dirname( __DIR__ );
		$test_classes = array(
			'WPEngine_PHPCompat\Class_One' => $home. '/plugin/lib/class-class-one.php',
			'WPEngine_PHPCompat\Sub_Classes\Class_Two' => $home . '/plugin/lib/Sub_Classes/class-class-two.php',
			'Class_Three' => '',
		);

		foreach ( $test_classes as $test_class => $class_file ) {

			$file = wpe_phpcompat_get_class_file( $test_class );

			$this->assertEquals( $class_file, $file );

		}
	}

	/**
	 * Upgrades where the 'plugins' key is missing must not trigger warnings.
	 *
	 * @see https://github.com/wpengine/phpcompat/issues/319
	 */
	public function test_upgrade_without_plugins_key_does_not_warn() {
		Monkey\Functions\when( 'plugin_basename' )->justReturn( 'php-compatibility-checker/wpengine-phpcompat.php' );
		Monkey\Functions\expect( 'get_option' )->never();

		upgrade( null, array( 'action' => 'update', 'type' => 'plugin' ) );
		upgrade( null, array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'other/other.php' ) );
		upgrade( null, array() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Single-plugin updates of this plugin (the 'plugin' key) still run the migration check.
	 */
	public function test_upgrade_single_plugin_key_runs_migration() {
		Monkey\Functions\when( 'plugin_basename' )->justReturn( 'php-compatibility-checker/wpengine-phpcompat.php' );
		Monkey\Functions\expect( 'get_option' )->once()->with( 'wpephpcompat_is_wptide', false )->andReturn( 1 );

		upgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'php-compatibility-checker/wpengine-phpcompat.php',
			)
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The $page property must be declared to avoid PHP 8.2 dynamic property deprecations.
	 *
	 * @see https://github.com/wpengine/phpcompat/issues/318
	 */
	public function test_page_property_is_declared() {
		$this->assertTrue( property_exists( PHP_Compatibility_Checker::class, 'page' ) );
	}
}

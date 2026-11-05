<?php
/**
 * Tests for keeping scan load down: the site's scan lock and the host's off switch. (The time budget is tested with the real scanner in RunnerTest.)
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Scan_Lock.
 */
class ScanLoadTest extends TestCase {

	/**
	 * In-memory options table.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Fake clock (Unix time).
	 *
	 * @var int
	 */
	private $time = 1000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$options = &$this->options;
		Monkey\Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}
				$options[ $name ] = $value;
				return true;
			}
		);
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Monkey\Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function lock() {
		$time = &$this->time;
		return new Scan_Lock(
			static function () use ( &$time ) {
				return $time;
			}
		);
	}

	public function test_browser_runtime_is_served_from_the_plugin() {
		Monkey\Functions\expect( 'plugins_url' )->once()
			->with( 'build/php-wasm/' . Scanner_Bundle::WASM_VERSION . '/', WPEPHPCOMPAT_PLUGIN_FILE )
			->andReturn( 'https://example.org/wp-content/plugins/phpcompat/build/php-wasm/3.1.62/' );
		Monkey\Functions\expect( 'apply_filters' )->once()
			->with( 'wpephpcompat_wasm_base_url', 'https://example.org/wp-content/plugins/phpcompat/build/php-wasm/3.1.62/' )
			->andReturn( 'https://example.org/wp-content/plugins/phpcompat/build/php-wasm/3.1.62/' );
		Monkey\Functions\when( 'trailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( $url, '/' ) . '/';
			}
		);

		$this->assertSame( 'https://example.org/wp-content/plugins/phpcompat/build/php-wasm/3.1.62/', Scanner_Bundle::wasm_base_url() );
	}

	public function test_browser_runtime_url_filter_preserves_a_trailing_slash() {
		Monkey\Functions\when( 'plugins_url' )->justReturn( 'https://example.org/plugin/build/php-wasm/3.1.62/' );
		Monkey\Functions\expect( 'apply_filters' )->once()
			->with( 'wpephpcompat_wasm_base_url', 'https://example.org/plugin/build/php-wasm/3.1.62/' )
			->andReturn( 'https://example.org/local-runtime' );
		Monkey\Functions\when( 'trailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( $url, '/' ) . '/';
			}
		);

		$this->assertSame( 'https://example.org/local-runtime/', Scanner_Bundle::wasm_base_url() );
	}

	public function test_the_first_scan_takes_the_lock_and_renews_it() {
		$lock = $this->lock();

		$this->assertNull( $lock->acquire( 'tokenAAAAAAAAAAAAAAA', 1 ) );
		$this->time += 60;
		$this->assertNull( $lock->acquire( 'tokenAAAAAAAAAAAAAAA', 1 ), 'The same scan renews its lease.' );
		$this->assertSame( 1060 + Scan_Lock::TTL, $this->options[ Scan_Lock::OPTION ]['expires'] );
	}

	public function test_a_second_scan_is_refused_while_the_first_runs() {
		$lock = $this->lock();
		$lock->acquire( 'tokenAAAAAAAAAAAAAAA', 1 );

		$other = $lock->acquire( 'tokenBBBBBBBBBBBBBBB', 2 );

		$this->assertSame( 1, $other['user'] );
		$this->assertSame( 'tokenAAAAAAAAAAAAAAA', $this->options[ Scan_Lock::OPTION ]['token'] );
	}

	public function test_an_abandoned_lock_expires() {
		$lock = $this->lock();
		$lock->acquire( 'tokenAAAAAAAAAAAAAAA', 1 );
		$this->time += Scan_Lock::TTL + 1;

		$this->assertNull( $lock->acquire( 'tokenBBBBBBBBBBBBBBB', 2 ) );
		$this->assertSame( 'tokenBBBBBBBBBBBBBBB', $this->options[ Scan_Lock::OPTION ]['token'] );
	}

	public function test_only_the_holder_can_release() {
		$lock = $this->lock();
		$lock->acquire( 'tokenAAAAAAAAAAAAAAA', 1 );

		$lock->release( 'tokenBBBBBBBBBBBBBBB' );
		$this->assertArrayHasKey( Scan_Lock::OPTION, $this->options );

		$lock->release( 'tokenAAAAAAAAAAAAAAA' );
		$this->assertArrayNotHasKey( Scan_Lock::OPTION, $this->options );
		$this->assertNull( $lock->acquire( 'tokenBBBBBBBBBBBBBBB', 2 ), 'Released: the next scan can start.' );
	}

	public function test_scanning_is_on_unless_a_host_turns_it_off() {
		$this->assertTrue( Scanner_Bundle::scanning_enabled() );

		Monkey\Filters\expectApplied( 'wpephpcompat_scanning_enabled' )->once()->with( true )->andReturn( false );
		$this->assertFalse( Scanner_Bundle::scanning_enabled() );
	}

	public function test_a_corrupt_lock_is_replaced() {
		$this->options[ Scan_Lock::OPTION ] = 'garbage';

		$this->assertNull( $this->lock()->acquire( 'tokenAAAAAAAAAAAAAAA', 1 ) );
	}
}

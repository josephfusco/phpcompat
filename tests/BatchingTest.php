<?php
/**
 * Tests for the pieces of batched scanning that don't need WordPress.
 *
 * @package WPEngine_PHPCompat\PHP_Compatibility_Checker
 */

namespace WPEngine_PHPCompat;

use PHPUnit\Framework\TestCase;

/**
 * File lists, fingerprints, and batch selection.
 */
class BatchingTest extends TestCase {

	/**
	 * Temporary item directory.
	 *
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/pcc-batching-' . uniqid();
		mkdir( $this->dir . '/includes', 0777, true );
		file_put_contents( $this->dir . '/plugin.php', '<?php // main' );
		file_put_contents( $this->dir . '/includes/b.php', '<?php // b' );
		file_put_contents( $this->dir . '/includes/a.php', '<?php // a' );
		file_put_contents( $this->dir . '/readme.txt', 'not php' );
	}

	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	public function test_file_list_is_sorted_php_only() {
		$list = Scanner::file_list( $this->dir );

		$this->assertSame( array( 'includes/a.php', 'includes/b.php', 'plugin.php' ), $list['files'] );
		$this->assertSame( array(), $list['skipped'] );
		$this->assertFalse( $list['truncated'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $list['fingerprint'] );
	}

	public function test_build_tool_and_version_control_folders_are_skipped() {
		foreach ( array( 'node_modules/pkg', '.git/hooks', 'vendor/lib' ) as $folder ) {
			mkdir( $this->dir . '/' . $folder, 0777, true );
			file_put_contents( $this->dir . '/' . $folder . '/x.php', '<?php // x' );
		}

		$files = Scanner::file_list( $this->dir )['files'];

		$this->assertContains( 'vendor/lib/x.php', $files, 'Bundled libraries are still scanned; WordPress loads them.' );
		$this->assertNotContains( 'node_modules/pkg/x.php', $files );
		$this->assertNotContains( '.git/hooks/x.php', $files );
	}

	public function test_fingerprint_is_stable_until_files_change() {
		$first = Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'];

		$this->assertSame( $first, Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'] );
		$this->assertNotSame( $first, Scanner::file_list( $this->dir, '1.1|rules' )['fingerprint'], 'Another version or rule set never matches.' );

		// Edited (size changes).
		file_put_contents( $this->dir . '/includes/a.php', '<?php // a, edited' );
		$edited = Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'];
		$this->assertNotSame( $first, $edited );

		// Same size, newer modification time.
		file_put_contents( $this->dir . '/includes/b.php', '<?php // B' );
		touch( $this->dir . '/includes/b.php', time() + 10 );
		$touched = Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'];
		$this->assertNotSame( $edited, $touched );

		// Added.
		file_put_contents( $this->dir . '/includes/c.php', '<?php // c' );
		$this->assertNotSame( $touched, Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'] );

		// Non-PHP files don't matter.
		$before = Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'];
		file_put_contents( $this->dir . '/readme.txt', 'changed' );
		$this->assertSame( $before, Scanner::file_list( $this->dir, '1.0|rules' )['fingerprint'] );
	}

	public function test_one_file_plugin() {
		$list = Scanner::file_list( $this->dir . '/plugin.php' );

		$this->assertSame( array( 'plugin.php' ), $list['files'] );
	}

	public function test_big_files_are_skipped() {
		file_put_contents( $this->dir . '/data.php', '<?php // ' . str_repeat( 'x', Scanner::MAX_FILE_BYTES ) );

		$list = Scanner::file_list( $this->dir );

		$this->assertSame( array( 'data.php' ), $list['skipped'] );
		$this->assertNotContains( 'data.php', $list['files'] );
	}

	public function test_batches_walk_every_file_once() {
		$paths = Scanner::file_list( $this->dir )['files'];
		$seen  = array();

		for ( $cursor = 0; null !== $cursor; ) {
			$batch  = Rest_Controller::select_batch( $this->dir, $paths, $cursor, 2 );
			$seen   = array_merge( $seen, $batch['files'] );
			$cursor = $batch['next'];
			$this->assertLessThanOrEqual( 2, count( $batch['files'] ) );
		}

		$this->assertSame( $paths, $seen );
	}

	public function test_batch_limit_is_clamped() {
		$paths = array_map(
			static function ( $i ) {
				return "f$i.php";
			},
			range( 1, Rest_Controller::SCAN_MAX_FILES + 5 )
		);

		$this->assertCount( 1, Rest_Controller::select_batch( $this->dir, $paths, 0, 0 )['files'] );
		$this->assertCount( Rest_Controller::SCAN_MAX_FILES, Rest_Controller::select_batch( $this->dir, $paths, 0, 1000 )['files'] );
	}

	public function test_batch_stops_at_byte_cap_but_takes_at_least_one_file() {
		$big = str_repeat( 'x', (int) ( Rest_Controller::SCAN_MAX_BYTES * 0.6 ) );
		file_put_contents( $this->dir . '/big1.php', '<?php // ' . $big );
		file_put_contents( $this->dir . '/big2.php', '<?php // ' . $big );

		$first = Rest_Controller::select_batch( $this->dir, array( 'big1.php', 'big2.php' ), 0, 10 );
		$this->assertSame( array( 'big1.php' ), $first['files'] );
		$this->assertSame( 1, $first['next'] );

		$second = Rest_Controller::select_batch( $this->dir, array( 'big1.php', 'big2.php' ), 1, 10 );
		$this->assertSame( array( 'big2.php' ), $second['files'] );
		$this->assertNull( $second['next'] );
	}

	public function test_paths_are_compared_the_same_way_on_windows() {
		// Upstream issues #97, #128, #165, #201, #274: Windows paths.
		$this->assertTrue( Scanner::is_inside( 'C:\\www\\wp-content\\plugins\\akismet', 'C:/www/wp-content/plugins' ) );
		$this->assertTrue( Scanner::is_inside( 'C:/www/wp-content/plugins/akismet', 'C:\\www\\wp-content\\plugins\\' ) );
		$this->assertFalse( Scanner::is_inside( 'C:\\www\\wp-content\\plugins', 'C:/www/wp-content/plugins' ), 'The directory itself is not inside.' );
		$this->assertFalse( Scanner::is_inside( '/www/wp-content/plugins-old/x', '/www/wp-content/plugins' ), 'A sibling with the same prefix is not inside.' );
		$this->assertFalse( Scanner::is_inside( '/www/wp-content/plugins/../wp-config.php', '/www/wp-content/plugins' ) );
		$this->assertSame( 'C:/www/x', Scanner::normalize_path( 'C:\\www\\x' ) );
	}

	public function test_symlinked_files_are_not_followed() {
		if ( ! function_exists( 'symlink' ) || ! @symlink( __FILE__, $this->dir . '/linked.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'Symlinks are not available.' );
		}

		$this->assertNotContains( 'linked.php', Scanner::file_list( $this->dir )['files'] );
	}

	/**
	 * Report from a shared case: errors per version, in own or bundled code.
	 *
	 * @param array $case Case from needed-php-cases.json.
	 * @return array
	 */
	public static function case_report( array $case ): array {
		$versions = array();
		foreach ( $case['errors'] as $version => $errors ) {
			$path                         = empty( $case['bundled'] ) ? 'includes/a.php' : 'vendor/acme/lib/a.php';
			$versions[ (string) $version ] = array(
				'totals' => array(
					'errors'   => $errors,
					'warnings' => 0,
				),
				'files'  => $errors ? array( $path => array( 'messages' => array() ) ) : array(),
			);
		}
		return array(
			'versions'     => $versions,
			'platform_php' => $case['platform_php'] ?? '',
		);
	}

	public static function needed_php_cases(): array {
		$data  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/needed-php-cases.json' ), true );
		$cases = array();
		foreach ( $data['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider needed_php_cases
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'needed_php_cases' )]
	public function test_needed_php( array $case ) {
		$this->assertSame( $case['expected'], Scanner::needed_php( array( 'requires_php' => $case['requires_php'] ), self::case_report( $case ) ) );
	}

	public function test_composer_platform_check_sets_the_minimum() {
		mkdir( $this->dir . '/vendor/composer', 0777, true );
		file_put_contents( $this->dir . '/vendor/composer/platform_check.php', "<?php\n\$issues = array();\nif (!(PHP_VERSION_ID >= 80100)) {\n    \$issues[] = 'Your Composer dependencies require a PHP version \">= 8.1.0\".';\n}\n" );

		$this->assertSame( '8.1', Scanner::file_list( $this->dir )['platform_php'] );
	}

	public function test_no_platform_check_means_no_composer_minimum() {
		$this->assertSame( '', Scanner::file_list( $this->dir )['platform_php'] );
	}
}

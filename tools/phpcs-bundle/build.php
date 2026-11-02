<?php
/**
 * Copy PHP_CodeSniffer and PHPCompatibilityWP into the plugin for the scanner.
 *
 * Writes plugin/build/scanner/ (runner.php plus the vendor files phpcs loads)
 * and plugin/build/scanner.json (rule set, used to key stored results, plus
 * package versions, the file list, and a SHA-256 over every file).
 *
 * Plain files, not an archive: WordPress.org doesn't accept compressed or
 * phar files in plugins (Plugin Check's File_Type_Check).
 *
 * Run `composer install --no-dev` in this directory first (npm run build:phpcs
 * does both).
 *
 * @package WPEngine_PHPCompat
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Build script, runs outside WordPress.

$root   = __DIR__ . '/vendor';
$build  = dirname( __DIR__, 2 ) . '/plugin/build';
$out    = $build . '/scanner';
$lock   = json_decode( (string) file_get_contents( __DIR__ . '/composer.lock' ), true );
$needed = array(
	'squizlabs/php_codesniffer',
	'phpcsstandards/phpcsutils',
	'phpcompatibility/php-compatibility',
	'phpcompatibility/phpcompatibility-paragonie',
	'phpcompatibility/phpcompatibility-wp',
);

if ( ! is_dir( $root . '/squizlabs/php_codesniffer' ) ) {
	fwrite( STDERR, "Run composer install --no-dev in tools/phpcs-bundle first.\n" );
	exit( 1 );
}

$versions = array();
foreach ( $lock['packages'] as $package ) {
	$versions[ $package['name'] ] = $package['version'];
}

// Start clean, so files removed upstream don't linger.
if ( is_dir( $out ) ) {
	$old = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $out, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $old as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $out );
}

$files = array();
foreach ( $needed as $package ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $package, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		// Each package's license, which BSD-3 and LGPL require shipping with it.
		$is_license = (bool) preg_match( '#^[^/]+/[^/]+/(LICEN[CS]E|COPYING)[^/]*$#i', $relative );
		// Otherwise only what phpcs loads at runtime. phpcs's git filters shell
		// out to git; the scanner never uses them, and Plugin Check flags exec().
		if ( ! $is_license && ( preg_match( '#/(tests?|docs?|\.github)/#i', $relative ) || preg_match( '#/src/Filters/Git(Modified|Staged)\.php$#', $relative ) || ! preg_match( '/\.(php|xml|conf)$/', $relative ) ) ) {
			continue;
		}
		$files[ 'vendor/' . $relative ] = $file->getPathname();
	}
}
$files['runner.php']         = __DIR__ . '/runner.php';
$files['collect-report.php'] = __DIR__ . '/collect-report.php';
ksort( $files );

$hash = hash_init( 'sha256' );
foreach ( $files as $relative => $source ) {
	$target = $out . '/' . $relative;
	if ( ! is_dir( dirname( $target ) ) ) {
		mkdir( dirname( $target ), 0755, true );
	}
	copy( $source, $target );
	hash_update( $hash, $relative . "\0" . hash_file( 'sha256', $source ) . "\n" );
}

// No directory listing. (No .htaccess: WordPress.org doesn't accept hidden
// files, and the files only define classes or exit outside WordPress.)
file_put_contents( $out . '/index.php', "<?php\n// Silence is golden.\n" );

$manifest = array(
	'ruleset'  => sprintf(
		'phpcs-%s+phpcompatibility-%s+wp-%s',
		$versions['squizlabs/php_codesniffer'],
		$versions['phpcompatibility/php-compatibility'],
		$versions['phpcompatibility/phpcompatibility-wp']
	),
	'packages' => array_intersect_key( $versions, array_flip( $needed ) ),
	'sha256'   => hash_final( $hash ),
	// Lets the plugin check its copy is complete (Scanner_Bundle::verify()).
	'files'    => array_keys( $files ),
);

file_put_contents( $build . '/scanner.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

// Remove the archive older builds wrote.
foreach ( array( 'phpcs-bundle.zip', 'phpcs-bundle.json' ) as $legacy ) {
	if ( file_exists( $build . '/' . $legacy ) ) {
		unlink( $build . '/' . $legacy );
	}
}

$bytes = array_sum( array_map( 'filesize', $files ) );
printf( "build/scanner: %d files, %.1f MB, %s\n", count( $files ), $bytes / 1048576, $manifest['ruleset'] );

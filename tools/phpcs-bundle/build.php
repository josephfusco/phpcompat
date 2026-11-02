<?php
/**
 * Zip PHP_CodeSniffer and PHPCompatibilityWP for the browser scanner.
 *
 * Writes plugin/build/phpcs-bundle.zip and plugin/build/phpcs-bundle.json
 * (rule set versions, used to key cached results, and the zip's SHA-256,
 * checked by the browser before it runs anything).
 *
 * Run `composer install --no-dev` in this directory first (npm run build:phpcs
 * does both).
 *
 * @package WPEngine_PHPCompat
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Build script, runs outside WordPress.

$root   = __DIR__ . '/vendor';
$out    = dirname( __DIR__, 2 ) . '/plugin/build';
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

if ( ! is_dir( $out ) ) {
	mkdir( $out, 0755, true );
}

$zip_path = $out . '/phpcs-bundle.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create $zip_path\n" );
	exit( 1 );
}

$files = array();
foreach ( $needed as $package ) {
	$dir      = $root . '/' . $package;
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		// Only what phpcs loads at runtime.
		if ( preg_match( '#/(tests?|docs?|\.github)/#i', $relative ) || ! preg_match( '/\.(php|xml|conf)$/', $relative ) ) {
			continue;
		}
		$files[] = $relative;
	}
}

// Stable order and timestamps, so the same inputs give the same zip.
sort( $files );
foreach ( $files as $relative ) {
	$zip->addFile( $root . '/' . $relative, 'vendor/' . $relative );
	$zip->setMtimeName( 'vendor/' . $relative, 315532800 );
}
$zip->addFile( __DIR__ . '/runner.php', 'runner.php' );
$zip->setMtimeName( 'runner.php', 315532800 );
$zip->close();

$manifest = array(
	'ruleset' => sprintf(
		'phpcs-%s+phpcompatibility-%s+wp-%s',
		$versions['squizlabs/php_codesniffer'],
		$versions['phpcompatibility/php-compatibility'],
		$versions['phpcompatibility/phpcompatibility-wp']
	),
	'packages' => array_intersect_key( $versions, array_flip( $needed ) ),
	'sha256'   => hash_file( 'sha256', $zip_path ),
	'files'    => count( $files ),
);

file_put_contents( $out . '/phpcs-bundle.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

printf( "phpcs-bundle.zip: %d files, %.1f KB, %s\n", $manifest['files'], filesize( $zip_path ) / 1024, $manifest['ruleset'] );

<?php
/**
 * Removed in PHP 8.0: each(). Deprecated in 8.4: implicitly nullable parameters.
 *
 * @package WPEngine_PHPCompat
 */

function sample_legacy( array $items ) {
	while ( list( $key, $value ) = each( $items ) ) {
		echo $key, $value;
	}
}

function sample_nullable( string $name = null ) {
	return $name;
}

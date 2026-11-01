<?php
use Brain\Monkey;

require_once dirname( dirname( __FILE__ ) ) . '/vendor/autoload.php';

Monkey\setUp();

// WordPress time constants used by the scanner.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

// The plugin file registers hooks at load time; stub those WordPress functions.
Monkey\Functions\stubs(
	array(
		'add_action',
		'add_filter',
		'register_activation_hook',
		'register_uninstall_hook',
	)
);

/**
 * Now we include any plugin files that we need to be able to run the tests. This
 * should be files that define the functions and classes you're going to test.
 */
require_once dirname( dirname( __FILE__ ) )  . '/plugin/wpengine-phpcompat.php';

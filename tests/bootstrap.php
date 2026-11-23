<?php
/**
 * PHPUnit bootstrap.
 *
 * Uses the composer-installed WordPress test library (wp-phpunit/wp-phpunit)
 * rather than a checkout in /tmp. Point WP_PHPUNIT__TESTS_CONFIG at a
 * wp-tests-config.php describing a test database.
 */

$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	fwrite( STDERR, "Set WP_PHPUNIT__TESTS_CONFIG to your wp-tests-config.php\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin under test.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/secure-passkeys.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";

<?php
/**
 * Sample configuration for the WordPress test library.
 *
 * Copy to tests/wp-tests-config.php, fill in a throwaway database, and point
 * WP_PHPUNIT__TESTS_CONFIG at it. Keep the copy inside tests/ so the relative
 * path to vendor/ still resolves:
 *
 *   cp tests/wp-tests-config.sample.php tests/wp-tests-config.php
 *   WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php composer test
 *
 * The database is wiped on every run, so never point this at a real site.
 *
 * @package Secure_Passkeys
 */

// WordPress core, installed by composer (roots/wordpress-full) so that no svn
// checkout or manual download is needed.
define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-full/' );

define( 'DB_NAME', 'secure_passkeys_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Secure Passkeys Tests' );

define( 'WP_PHP_BINARY', 'php' );

$table_prefix = 'wptests_';

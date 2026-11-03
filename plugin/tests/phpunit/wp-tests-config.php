<?php
/**
 * Test database configuration for the SDC Page Builder test suite.
 *
 * The WordPress test library drops and recreates the tables in this database on
 * every run, so it must never point at a site you care about. Credentials come
 * from the environment; the defaults match the wp-env container, which is where
 * `npm run test:php` runs.
 *
 * @package SdcPageBuilder
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test bootstrap, not plugin code.

// SDC_TEST_WP_DIR is <plugin>/wordpress, i.e. a sibling of tests/: resolve it
// against this file so the suite never boots the WordPress of whatever
// directory phpunit happened to be started from.
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/wordpress/' );

define( 'DB_NAME', getenv( 'SDC_TEST_DB_NAME' ) ? getenv( 'SDC_TEST_DB_NAME' ) : 'wp_tests' );
define( 'DB_USER', getenv( 'SDC_TEST_DB_USER' ) ? getenv( 'SDC_TEST_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'SDC_TEST_DB_PASSWORD' ) ? getenv( 'SDC_TEST_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'SDC_TEST_DB_HOST' ) ? getenv( 'SDC_TEST_DB_HOST' ) : 'mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- required by the test library.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'SDC Page Builder Tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// The test suite is expected to complain loudly, not to send mail or phone home.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );

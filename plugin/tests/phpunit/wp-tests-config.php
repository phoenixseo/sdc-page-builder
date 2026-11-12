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

// The core to boot. An explicit SDC_TEST_WP_DIR wins, because the minimum
// WordPress run points it at a second checkout; otherwise the copy vendored
// next to this file. It must be resolved here rather than in phpunit.xml: a
// relative path there depends on the working directory, and a run that
// silently boots the wrong core proves nothing about the version it claims.
$sdc_wp_dir = getenv( 'SDC_TEST_WP_DIR' );
if ( ! $sdc_wp_dir || ! is_dir( $sdc_wp_dir ) ) {
	$sdc_wp_dir = dirname( __DIR__, 2 ) . '/wordpress';
}

$sdc_wp_dir = realpath( $sdc_wp_dir );
if ( ! $sdc_wp_dir || ! is_file( $sdc_wp_dir . '/wp-includes/version.php' ) ) {
	// A thrown exception, not a printed warning: this file is loaded before
	// phpunit owns the run, so a warning would scroll past hundreds of passing
	// lines and the suite would still exit 0 on the wrong core. The message
	// carries no path — this file runs before WordPress is loaded, so there is
	// no esc_html() to escape it with, and the caller already sees the value.
	throw new RuntimeException( 'SDC_TEST_WP_DIR does not point at a WordPress checkout.' );
}

define( 'ABSPATH', $sdc_wp_dir . '/' );

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

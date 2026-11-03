<?php
/**
 * PHPUnit bootstrap for the SDC Page Builder suite.
 *
 * Loads the plugin under test from the directory above `tests/`, so the suite
 * always evaluates the code that ships, not a copy in wp-content/plugins.
 *
 * Environment:
 *   WP_TESTS_DIR                  path to the WordPress test library (required)
 *   SDC_TEST_WP_DIR               path to a WordPress core checkout (required)
 *   SDC_TEST_DB_*                 database credentials, see wp-tests-config.php
 *   SDC_TEST_HOST_PLUGIN          `acf` (default) or `scf`, the field-plugin
 *                                 flavour to install for this run
 *   SDC_TEST_ACF_ZIP              directory holding an ACF Free checkout, for
 *                                 runs that must not touch wp.org
 *
 * @package SdcPageBuilder
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- test bootstrap.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR diagnostics before WordPress loads; WP_Filesystem does not exist yet.

$sdc_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $sdc_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. See plugin/tests/phpunit/README.md.\n" );
	exit( 1 );
}

$sdc_wp_dir = getenv( 'SDC_TEST_WP_DIR' );
if ( ! $sdc_wp_dir || ! is_dir( $sdc_wp_dir ) ) {
	fwrite( STDERR, "SDC_TEST_WP_DIR does not point at a WordPress checkout.\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Pin the config file by absolute path. The library accepts a relative path
// only by luck: if it does not resolve, it falls back to whatever
// wp-tests-config.php it finds next to itself, which is not ours.
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

// tests_add_filter() and friends live here; the library's bootstrap.php loads
// this file itself, but only after we have already needed the helper.
require_once $sdc_tests_dir . '/includes/functions.php';

$sdc_plugin_dir = dirname( __DIR__, 2 );

// The plugin lives outside the WordPress tree during tests; link it in so that
// plugin_dir_path(), plugin_basename() and activation behave normally.
$sdc_plugin_install_dir = $sdc_wp_dir . '/wp-content/plugins';
if ( ! is_dir( $sdc_plugin_install_dir ) ) {
	mkdir( $sdc_plugin_install_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test bootstrap.
}
$sdc_link = $sdc_plugin_install_dir . '/sdc-page-builder';
if ( ! file_exists( $sdc_link ) ) {
	symlink( $sdc_plugin_dir, $sdc_link );
}

/**
 * Stage the host field plugin before the suite boots, because the plugin
 * refuses to start without one.
 *
 * ACF Free and SCF ship the same API under different names; the suite runs
 * against whichever is requested so host parity is tested, not assumed.
 */
$sdc_host = getenv( 'SDC_TEST_HOST_PLUGIN' ) ? getenv( 'SDC_TEST_HOST_PLUGIN' ) : 'acf';

/**
 * Link one directory into the test install.
 *
 * @param string $source     Directory to link.
 * @param string $link_name  Name inside wp-content/plugins.
 * @param string $env_name   Environment variable, for the error message.
 */
$sdc_stage = static function ( $source, $link_name, $env_name ) use ( $sdc_plugin_install_dir ) {
	if ( ! $source || ! is_dir( $source ) ) {
		fwrite( STDERR, "$env_name must point at an unpacked field plugin.\n" );
		exit( 1 );
	}
	$target = $sdc_plugin_install_dir . '/' . $link_name;
	if ( is_link( $target ) ) {
		unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test bootstrap.
	}
	if ( ! is_dir( $target ) ) {
		symlink( $source, $target );
	}
};

// Only one host may be present: two field plugins active at once fight over
// the same API names.
$sdc_other      = 'scf' === $sdc_host ? 'advanced-custom-fields' : 'secure-custom-fields';
$sdc_other_link = $sdc_plugin_install_dir . '/' . $sdc_other;
if ( is_link( $sdc_other_link ) ) {
	unlink( $sdc_other_link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test bootstrap.
}

if ( 'scf' === $sdc_host ) {
	// SCF is not in wp.org's free directory; it is taken from the install that
	// ships it.
	$sdc_stage( getenv( 'SDC_TEST_SCF_DIR' ), 'secure-custom-fields', 'SDC_TEST_SCF_DIR' );
} else {
	$sdc_stage( getenv( 'SDC_TEST_ACF_ZIP' ), 'advanced-custom-fields', 'SDC_TEST_ACF_ZIP' );
}

/**
 * Activate the plugins the suite needs, right after the test install exists.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $sdc_host ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$to_enable = array( 'sdc-page-builder/sdc-page-builder.php' );

		if ( 'scf' === $sdc_host ) {
			$to_enable[] = 'secure-custom-fields/secure-custom-fields.php';
		} else {
			$to_enable[] = 'advanced-custom-fields/acf.php';
		}

		foreach ( $to_enable as $plugin ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				activate_plugin( $plugin );
			}
		}
	}
);

require $sdc_tests_dir . '/includes/bootstrap.php';

// WP_UnitTestCase only exists once the library has booted.
require_once __DIR__ . '/class-sdc-test-case.php';

<?php
/**
 * Plugin Name:       SDC Page Builder
 * Plugin URI:        https://github.com/phoenixseo/sdc-page-builder
 * Description:       Komponentenbasierter Page-Builder für WordPress, der ACF-Felder und Secure Custom Fields als Bausteine nutzt. Speichert Seiten als ein JSON-Dokument mit Revisionen und Schema-Versionierung.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            PHOENIXSEO
 * Author URI:        https://phoenixseo.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sdc-page-builder
 * Domain Path:       /languages
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'SDC_PB_VERSION', '0.1.0' );
define( 'SDC_PB_FILE', __FILE__ );
define( 'SDC_PB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDC_PB_URL', plugin_dir_url( __FILE__ ) );

/*
 * `array_is_list()` is PHP 8.1+. The plugin header promises 7.4, and the
 * validator leans on the list-vs-map distinction, so provide it rather than
 * raise the minimum requirement of a plugin that otherwise works on 7.4.
 */
if ( ! function_exists( 'array_is_list' ) ) {
	/**
	 * Whether an array is a list with sequential 0-based integer keys.
	 *
	 * @param array $value Value to inspect.
	 * @return bool
	 */
	function array_is_list( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, PHPCompatibility.FunctionNameRestrictions.NewGlobalFunction
		if ( ! is_array( $value ) ) {
			return false;
		}
		$expected = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}
}

require_once SDC_PB_DIR . 'includes/class-sdc-field-adapter.php';
require_once SDC_PB_DIR . 'includes/class-sdc-store.php';
require_once SDC_PB_DIR . 'includes/class-sdc-schema.php';
require_once SDC_PB_DIR . 'includes/class-sdc-document.php';
require_once SDC_PB_DIR . 'includes/class-sdc-rest.php';
require_once SDC_PB_DIR . 'includes/class-sdc-renderer.php';
require_once SDC_PB_DIR . 'includes/class-sdc-editor.php';
require_once SDC_PB_DIR . 'includes/class-sdc-frontend.php';

/**
 * Boot the plugin once all plugins are loaded, so the host field plugin
 * (ACF or SCF) has registered its API.
 */
function sdc_pb_boot() {
	if ( ! Sdc_Field_Adapter::is_available() ) {
		add_action( 'admin_notices', 'sdc_pb_missing_host_notice' );
		return;
	}

	Sdc_Store::init();
	Sdc_Schema::init();
	Sdc_Rest::init();
	Sdc_Frontend::init();

	if ( is_admin() ) {
		Sdc_Editor::init();
	}
}
add_action( 'plugins_loaded', 'sdc_pb_boot' );

/**
 * Warn instead of fatal when neither ACF nor SCF is active.
 */
function sdc_pb_missing_host_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'SDC Page Builder benötigt Advanced Custom Fields oder Secure Custom Fields. Bitte eines der beiden Plugins aktivieren.', 'sdc-page-builder' )
	);
}

/**
 * Let the builder's component schemas take part in ACF/SCF Local JSON loading.
 *
 * @param array $paths Directories ACF reads JSON from.
 * @return array
 */
function sdc_pb_json_load_path( $paths ) {
	$paths   = (array) $paths;
	$paths[] = Sdc_Schema::dir();
	return $paths;
}
add_filter( 'acf/json/load_paths', 'sdc_pb_json_load_path' );

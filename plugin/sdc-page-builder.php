<?php
/**
 * Plugin Name:       SDC Page Builder
 * Plugin URI:        https://github.com/phoenixseo/sdc-page-builder
 * Description:       Komponentenbasierter Page Builder: ACF- oder SCF-Felder als Bausteine, ein Block pro Komponententyp, im Editor serverseitig gerendert.
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
require_once SDC_PB_DIR . 'includes/class-sdc-rest.php';
require_once SDC_PB_DIR . 'includes/class-sdc-renderer.php';
require_once SDC_PB_DIR . 'includes/class-sdc-editor.php';
require_once SDC_PB_DIR . 'includes/class-sdc-field-editor.php';

/*
 * The view layer registers the `sdc_*` template helpers at file scope, which
 * is why there is no `Sdc_View::init()` below: a view is included by the
 * frontend, not hooked into it, and the helpers have to exist before the first
 * page renders.
 */
require_once SDC_PB_DIR . 'includes/class-sdc-view.php';
require_once SDC_PB_DIR . 'includes/class-sdc-view-editor.php';
require_once SDC_PB_DIR . 'includes/class-sdc-style-editor.php';
require_once SDC_PB_DIR . 'includes/class-sdc-tabs.php';
require_once SDC_PB_DIR . 'includes/class-sdc-admin.php';
require_once SDC_PB_DIR . 'includes/class-sdc-frontend.php';
require_once SDC_PB_DIR . 'includes/class-sdc-block.php';
require_once SDC_PB_DIR . 'includes/class-sdc-migration.php';
require_once SDC_PB_DIR . 'includes/class-sdc-repair.php';

/**
 * Load the plugin's translations.
 *
 * Attached to `init`, not called at file scope: WordPress 6.7 warns — and
 * `_load_textdomain_just_in_time()` fires a notice in the log — when a
 * textdomain is touched before then. Measured in the container, the notice
 * appeared for every request while the domain was loaded early.
 *
 * No arguments on purpose: the `Domain Path` header and the plugin directory
 * are what `load_plugin_textdomain()` uses when called with the textdomain
 * alone, and staying argument-free means wp.org's language packs work without
 * a hardcoded path.
 */
function sdc_pb_load_textdomain() {
	load_plugin_textdomain( 'sdc-page-builder' );
}
add_action( 'init', 'sdc_pb_load_textdomain' );

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
	Sdc_Block::init();
	Sdc_Migration::init();

	if ( is_admin() ) {
		Sdc_Field_Editor::init();
		Sdc_Admin::init();
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
 * The plugin's own `schemas/` holds the examples. The site's components live
 * in the theme (`sdc-components/`) or in `wp-content/sdc-components/`, one
 * directory per component — see `Sdc_Schema::json_paths()`.
 *
 * @param array $paths Directories ACF reads JSON from.
 * @return array
 */
function sdc_pb_json_load_path( $paths ) {
	$paths = (array) $paths;

	foreach ( Sdc_Schema::json_paths() as $path ) {
		$paths[] = $path;
	}

	return $paths;
}
add_filter( 'acf/json/load_paths', 'sdc_pb_json_load_path' );

/**
 * Write a component's field group back to the file that defines it.
 *
 * @param string[] $paths Paths the host proposed.
 * @param array    $post  Field group being saved.
 * @return string[]
 */
function sdc_pb_json_save_paths( $paths, $post ) {
	return Sdc_Schema::save_paths( (array) $paths, (array) $post );
}
add_filter( 'acf/json/save_paths', 'sdc_pb_json_save_paths', 10, 2 );

/**
 * Keep a component's file name when the host writes it back.
 *
 * ACF names JSON files after the group key, so saving `definition.json` from
 * the admin would rename it. A component that came from a file keeps that
 * file's name.
 *
 * @param string $filename  Name the host proposed.
 * @param array  $post      Field group being saved.
 * @param string $load_path File it was loaded from, if any.
 * @return string
 */
function sdc_pb_json_save_file_name( $filename, $post, $load_path ) {
	return Sdc_Schema::save_file_name( $filename, (array) $post, (string) $load_path );
}
add_filter( 'acf/json/save_file_name', 'sdc_pb_json_save_file_name', 10, 3 );

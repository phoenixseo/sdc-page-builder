<?php
/**
 * Field adapter: the only place that talks to the host field plugin.
 *
 * Works against both Advanced Custom Fields (free) and Secure Custom Fields,
 * because both expose the same public `acf_*` API. Nothing here assumes a
 * specific host — swapping the host must never require touching other files.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin facade over the ACF/SCF field API.
 */
class Sdc_Field_Adapter {

	/**
	 * Function that must exist for the host to be usable.
	 *
	 * `acf_get_field_group()` is part of the shared public API and is present
	 * in ACF 5/6 as well as SCF.
	 */
	const PROBE = 'acf_get_field_group';

	/**
	 * Is a supported host plugin active?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( self::PROBE );
	}

	/**
	 * Which host is running — for diagnostics and admin notices only.
	 *
	 * Never branch behaviour on this: both hosts must work identically.
	 *
	 * SCF reports itself as ACF (it defines `ACF_VERSION` and `ACF_PRO` to stay
	 * drop-in compatible), so the plugin folder of the active plugin is the only
	 * reliable signal.
	 *
	 * @return string 'acf', 'scf', 'unknown' or 'none'.
	 */
	public static function host() {
		if ( ! self::is_available() ) {
			return 'none';
		}

		if ( self::is_plugin_active( 'secure-custom-fields/secure-custom-fields.php' ) ) {
			return 'scf';
		}
		if ( defined( 'ACF_VERSION' ) ) {
			return 'acf';
		}
		return 'unknown';
	}

	/**
	 * Is a plugin active, including network-activated ones?
	 *
	 * @param string $plugin Plugin basename, e.g. `secure-custom-fields/secure-custom-fields.php`.
	 * @return bool
	 */
	private static function is_plugin_active( $plugin ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin, $active, true ) ) {
			return true;
		}

		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $network[ $plugin ] );
	}

	/**
	 * Host version string, empty when unknown.
	 *
	 * @return string
	 */
	public static function host_version() {
		if ( defined( 'ACF_VERSION' ) ) {
			return (string) ACF_VERSION;
		}
		return '';
	}

	/**
	 * Read a field group definition by key.
	 *
	 * @param string $key Field group key, e.g. `group_sdc_faq`.
	 * @return array|null Field group array or null when unknown.
	 */
	public static function get_field_group( $key ) {
		if ( ! self::is_available() ) {
			return null;
		}
		$group = acf_get_field_group( $key );
		return is_array( $group ) ? $group : null;
	}

	/**
	 * All field groups, optionally filtered.
	 *
	 * @param array $filter Filter args as understood by `acf_get_field_groups()`.
	 * @return array[]
	 */
	public static function get_field_groups( $filter = array() ) {
		if ( ! self::is_available() ) {
			return array();
		}
		$groups = acf_get_field_groups( $filter );
		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * Fields belonging to a group or another field.
	 *
	 * @param string|array $parent Field group key, group array, or parent field.
	 * @return array[]
	 */
	public static function get_fields( $parent ) {
		if ( ! self::is_available() ) {
			return array();
		}
		$fields = acf_get_fields( $parent );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Read a stored field value through the host.
	 *
	 * Kept here so no other file calls `get_field()` directly — that function
	 * name is exactly what caused the historical alias collision.
	 *
	 * @param string   $selector Field name or key.
	 * @param int      $post_id  Post ID.
	 * @param bool     $format   Whether to format the value.
	 * @return mixed
	 */
	public static function get_value( $selector, $post_id, $format = true ) {
		if ( ! self::is_available() || ! function_exists( 'get_field' ) ) {
			return null;
		}
		return get_field( $selector, $post_id, $format );
	}

	/**
	 * Render the input markup for a single field.
	 *
	 * @param array $field Field array from `get_fields()`.
	 * @return string HTML.
	 */
	public static function render_field( $field ) {
		if ( ! self::is_available() || ! function_exists( 'acf_render_field' ) ) {
			return '';
		}
		ob_start();
		acf_render_field( $field );
		return (string) ob_get_clean();
	}

	/**
	 * Render several fields, wrapped with their labels.
	 *
	 * @param array[] $fields  Field arrays.
	 * @param int     $post_id Post ID the values belong to.
	 * @return string HTML.
	 */
	public static function render_fields( $fields, $post_id = 0 ) {
		if ( ! self::is_available() || ! function_exists( 'acf_render_fields' ) ) {
			return '';
		}
		ob_start();
		acf_render_fields( $fields, $post_id, 'div', 'label' );
		return (string) ob_get_clean();
	}

	/**
	 * Reduce a field definition to what the builder needs.
	 *
	 * Keeps the builder independent of host internals: only the public
	 * definition shape is passed on.
	 *
	 * @param array $field Field array.
	 * @return array
	 */
	public static function normalize_field( array $field ) {
		return array(
			'key'      => isset( $field['key'] ) ? (string) $field['key'] : '',
			'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
			'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
			'type'     => isset( $field['type'] ) ? (string) $field['type'] : 'text',
			'required' => ! empty( $field['required'] ),
		);
	}
}

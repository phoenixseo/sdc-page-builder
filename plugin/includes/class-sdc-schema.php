<?php
/**
 * Schema loader: components are ACF/SCF field groups stored as Local JSON.
 *
 * A component is a field group whose key carries the prefix
 * `group_sdc_component_`. The rest of the key is the component type:
 *
 *   group_sdc_component_faq  ->  type "faq"
 *
 * Field groups are marked with a location rule that no admin screen matches
 * (`sdc_component == 1`), so the host never renders them as a normal metabox —
 * the builder renders them itself.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds and describes the available components.
 */
class Sdc_Schema {

	/**
	 * Prefix that marks a field group as a builder component.
	 */
	const KEY_PREFIX = 'group_sdc_component_';

	/**
	 * Cache of resolved components for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Register the load path for schema files.
	 */
	public static function init() {
		// Path is registered from the main plugin file via the ACF filter,
		// because that filter must exist before ACF reads its paths.
	}

	/**
	 * Directory holding the component JSON files.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function dir() {
		return SDC_PB_DIR . 'schemas/';
	}

	/**
	 * All components, keyed by type.
	 *
	 * @return array[] Type => descriptor.
	 */
	public static function components() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$components = array();

		foreach ( Sdc_Field_Adapter::get_field_groups() as $group ) {
			if ( empty( $group['key'] ) || 0 !== strpos( $group['key'], self::KEY_PREFIX ) ) {
				continue;
			}

			$type = substr( $group['key'], strlen( self::KEY_PREFIX ) );
			if ( '' === $type ) {
				continue;
			}

			$components[ $type ] = self::describe( $type, $group );
		}

		self::$cache = $components;

		return $components;
	}

	/**
	 * One component by type.
	 *
	 * @param string $type Component type.
	 * @return array|null
	 */
	public static function get( $type ) {
		$components = self::components();
		return $components[ $type ] ?? null;
	}

	/**
	 * Field definitions of one component, normalized for the builder.
	 *
	 * @param string $type Component type.
	 * @return array[]
	 */
	public static function fields( $type ) {
		$component = self::get( $type );
		return $component ? $component['fields'] : array();
	}

	/**
	 * Build the descriptor for a component.
	 *
	 * @param string $type  Component type.
	 * @param array  $group Field group array from the host.
	 * @return array
	 */
	private static function describe( $type, array $group ) {
		$fields = array();
		foreach ( Sdc_Field_Adapter::get_fields( $group ) as $field ) {
			$fields[] = Sdc_Field_Adapter::normalize_field( $field );
		}

		return array(
			'type'        => $type,
			'key'         => (string) $group['key'],
			'label'       => isset( $group['title'] ) ? (string) $group['title'] : $type,
			'description' => isset( $group['sdc_description'] ) ? (string) $group['sdc_description'] : '',
			'icon'        => isset( $group['sdc_icon'] ) ? (string) $group['sdc_icon'] : '',
			'version'     => isset( $group['sdc_version'] ) ? (int) $group['sdc_version'] : 1,
			'fields'      => $fields,
		);
	}

	/**
	 * Machine-readable schema string stored per component instance, e.g. `faq@1`.
	 *
	 * @param string $type Component type.
	 * @return string
	 */
	public static function version_string( $type ) {
		$component = self::get( $type );
		$version   = $component ? (int) $component['version'] : 1;
		return $type . '@' . max( 1, $version );
	}

	/**
	 * Clear the per-request cache (used by tests).
	 */
	public static function flush() {
		self::$cache = null;
	}
}

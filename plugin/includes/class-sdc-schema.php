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
	 * Folder a site owner uses for their own components.
	 *
	 * The plugin ships examples in `schemas/`, but that directory belongs to
	 * the plugin: a component written there is gone after the next update.
	 * Site components live in the theme (or in `wp-content/`), one directory
	 * per component, and are read through the same loader.
	 */
	const USER_DIR = 'sdc-components';

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
	 * Directories a site owner can drop components into, before filtering.
	 *
	 * Child theme first: a component the parent theme ships has to be
	 * overridable. `wp-content/sdc-components/` comes last because it survives
	 * a theme switch — the only place that keeps components when the theme
	 * changes.
	 *
	 * @return string[]
	 */
	public static function default_user_dirs() {
		$dirs = array();

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$dirs[] = get_stylesheet_directory() . '/' . self::USER_DIR;
			$dirs[] = get_template_directory() . '/' . self::USER_DIR;
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dirs[] = WP_CONTENT_DIR . '/' . self::USER_DIR;
		}

		return $dirs;
	}

	/**
	 * Site-owned component directories that exist, in load order.
	 *
	 * Filterable so a plugin can supply components from its own directory
	 * instead of the theme.
	 *
	 * @return string[]
	 */
	public static function user_dirs() {
		/**
		 * Filters the directories scanned for site-owned components.
		 *
		 * @param string[] $dirs Absolute paths.
		 */
		$dirs = (array) apply_filters( 'sdc_pb_component_dirs', self::default_user_dirs() );

		$found = array();
		foreach ( $dirs as $dir ) {
			$dir = untrailingslashit( (string) $dir );
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}
			$found[] = $dir;
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Every directory the host must scan for component JSON.
	 *
	 * ACF's Local JSON loader reads exactly one level — its `scan_files()`
	 * skips sub directories outright (read in ACF 6.8.10) — so a component in
	 * `sdc-components/hero/` would never be found. Adding each immediate sub
	 * directory as its own path is what makes one directory per component
	 * work without touching the loader.
	 *
	 * @return string[]
	 */
	public static function json_paths() {
		$paths = array( self::dir() );

		foreach ( self::user_dirs() as $dir ) {
			$paths[] = $dir;

			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Reading a possibly unreadable theme directory must not warn.
			foreach ( (array) $entries as $entry ) {
				$entry = (string) $entry;
				if ( '' === $entry || '.' === $entry[0] ) {
					continue;
				}
				if ( is_dir( $dir . '/' . $entry ) ) {
					$paths[] = $dir . '/' . $entry;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Theme render template for a component type, if the site ships one.
	 *
	 * Convention: `sdc-components/<type>/render.php`, next to the JSON that
	 * defines the component. The first match wins, so a child theme overrides
	 * the parent theme and both override `wp-content/sdc-components/`.
	 *
	 * @param string $type Component type.
	 * @return string Absolute path, or an empty string when there is none.
	 */
	public static function template( $type ) {
		$type = (string) $type;

		// The type comes out of a stored document; a path separator in it
		// would turn a template lookup into a file inclusion outside the
		// component directories.
		if ( '' === $type || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $type ) ) {
			return '';
		}

		foreach ( self::user_dirs() as $dir ) {
			foreach ( array( $dir . '/' . $type . '/render.php', $dir . '/' . $type . '.php' ) as $file ) {
				if ( is_readable( $file ) ) {
					return $file;
				}
			}
		}

		return '';
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

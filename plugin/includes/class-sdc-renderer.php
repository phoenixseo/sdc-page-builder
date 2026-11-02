<?php
/**
 * Renders a component's fields inside the builder.
 *
 * Design rule that this class exists to enforce:
 *
 *   Container fields (list / group) are rendered by the builder.
 *   Leaf fields are rendered by the host (ACF or SCF).
 *
 * Why: ACF Free ships 32 field types but NOT `repeater`, `flexible_content`
 * or `clone`. Measured on ACF 6.8.10: rendering a repeatable field returns
 * **0 bytes and no error** — the field silently disappears. Any design that
 * delegates list rendering to the host therefore works on SCF and vanishes on
 * ACF Free, without a single log line.
 *
 * So the builder owns the row container, and asks the host only for leaves,
 * where both hosts are complete and identical.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side rendering of builder components.
 */
class Sdc_Renderer {

	/**
	 * Maximum nesting depth for containers.
	 *
	 * Not a technical limit — the editing UI becomes unusable beyond this.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Render one component instance.
	 *
	 * @param string $type     Component type.
	 * @param array  $data     Stored values for this instance.
	 * @param string $context  CSS/JS context, e.g. `preview` or `editor`.
	 * @param string $instance Instance id; gives the inputs a unique name prefix.
	 * @return string HTML.
	 */
	public static function render_component( $type, $data, $context = 'editor', $instance = '' ) {
		$component = Sdc_Schema::get( $type );
		if ( ! $component ) {
			return '';
		}

		$fields = Sdc_Field_Adapter::get_fields( Sdc_Field_Adapter::get_field_group( $component['key'] ) );

		// One prefix per instance. Two components of the same type otherwise
		// produce identical input names — measured: 18 of 18 collided — and the
		// later one would overwrite the earlier one on any form-style save.
		$prefix = 'acf';
		if ( '' !== $instance ) {
			$prefix .= '[' . preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $instance ) . ']';
		}

		return sprintf(
			'<div class="sdc-component sdc-component--%1$s" data-sdc-type="%1$s" data-sdc-context="%2$s" data-sdc-instance="%4$s">%3$s</div>',
			esc_attr( $type ),
			esc_attr( $context ),
			self::render_fields( $fields, (array) $data, $prefix, 1 ),
			esc_attr( $instance )
		);
	}

	/**
	 * Render a set of field definitions against a data array.
	 *
	 * @param array[] $fields Field definitions from the host.
	 * @param array   $data   Values keyed by field name.
	 * @param string  $prefix Form-name prefix for nested inputs.
	 * @param int     $depth  Current nesting depth.
	 * @return string HTML.
	 */
	public static function render_fields( array $fields, array $data, $prefix = 'acf', $depth = 1 ) {
		$html = '';

		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$value = $data[ $name ] ?? ( $field['default_value'] ?? '' );

			if ( 'repeater' === $field['type'] || 'flexible_content' === $field['type'] ) {
				$html .= self::render_list( $field, $value, $prefix, $depth );
				continue;
			}
			if ( 'group' === $field['type'] ) {
				$html .= self::render_group( $field, $value, $prefix, $depth );
				continue;
			}

			$html .= self::render_leaf( $field, $value, $prefix );
		}

		return $html;
	}

	/**
	 * A single leaf field, rendered by the host.
	 *
	 * `acf_prepare_field()` forces the input name to the field key and wraps it
	 * in `prefix[...]` — that is how nesting is expressed in this API.
	 *
	 * @param array  $field  Field definition.
	 * @param mixed  $value  Current value.
	 * @param string $prefix Name prefix.
	 * @return string HTML.
	 */
	private static function render_leaf( array $field, $value, $prefix ) {
		$field['value']  = $value;
		$field['prefix'] = $prefix;
		$field['_prepare'] = false; // Let the host prepare it itself.

		return '<div class="sdc-field sdc-field--' . esc_attr( $field['type'] ) . '" data-sdc-name="' . esc_attr( $field['name'] ) . '">'
			. Sdc_Field_Adapter::render_field( $field )
			. '</div>';
	}

	/**
	 * A list container with rows — built here, never by the host.
	 *
	 * @param array  $field  Container definition (has `sub_fields`).
	 * @param mixed  $value  Array of rows.
	 * @param string $prefix Name prefix.
	 * @param int    $depth  Nesting depth.
	 * @return string HTML.
	 */
	private static function render_list( array $field, $value, $prefix, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			// Measured before this guard existed: depth 4 rendered anyway.
			// Silently cutting a list would look like an empty list, so say so.
			error_log( sprintf( 'SDC Page Builder: Verschachtelungstiefe %d überschreitet das Maximum %d (Feld "%s").', (int) $depth, self::MAX_DEPTH, (string) $field['name'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$rows = is_array( $value ) ? array_values( $value ) : array();
		$sub  = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		$key  = isset( $field['key'] ) ? (string) $field['key'] : (string) $field['name'];

		$out = sprintf(
			'<div class="sdc-list" data-sdc-container="list" data-sdc-key="%s" data-sdc-name="%s" data-sdc-depth="%d">',
			esc_attr( $key ),
			esc_attr( $field['name'] ),
			(int) $depth
		);

		$out .= '<div class="sdc-list__label">' . esc_html( $field['label'] ?? $field['name'] ) . '</div>';
		$out .= '<div class="sdc-list__rows" data-sdc-rows>';

		$row_prefix_base = $prefix . '[' . $key . ']';

		foreach ( $rows as $index => $row ) {
			$out .= self::render_row( $sub, (array) $row, $row_prefix_base . '[' . $index . ']', $depth + 1, $index );
		}

		// Template row for the client-side "add" action, never submitted as-is.
		$out .= sprintf(
			'<template data-sdc-row-template>%s</template>',
			self::render_row( $sub, array(), $row_prefix_base . '[__i__]', $depth + 1, '__i__' )
		);

		$out .= '</div>';
		$out .= '<button type="button" class="button" data-sdc-add-row>'
			. esc_html( $field['button_label'] ?? __( 'Zeile hinzufügen', 'sdc-page-builder' ) )
			. '</button>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * One row of a list container.
	 *
	 * @param array[]    $sub    Sub-field definitions.
	 * @param array      $row    Row values.
	 * @param string     $prefix Name prefix for this row.
	 * @param int        $depth  Nesting depth.
	 * @param int|string $index  Row index.
	 * @return string HTML.
	 */
	private static function render_row( array $sub, array $row, $prefix, $depth, $index ) {
		$id = isset( $row['_id'] ) ? (string) $row['_id'] : '';

		// `data-sdc-prefix` is the row's own name prefix; the client rewrites it
		// when rows are added, removed or moved. Without it the browser would
		// have to reverse-engineer prefixes from input names.
		return sprintf(
			'<div class="sdc-row" data-sdc-row data-sdc-row-index="%1$s" data-sdc-row-id="%2$s" data-sdc-prefix="%6$s">'
				. '<div class="sdc-row__handle" data-sdc-drag-handle aria-hidden="true">⋮⋮</div>'
				. '<div class="sdc-row__body">%3$s</div>'
				. '<button type="button" class="sdc-row__remove" data-sdc-remove-row aria-label="%4$s">×</button>'
				. '<input type="hidden" name="%5$s[_id]" value="%2$s" />'
				. '</div>',
			esc_attr( (string) $index ),
			esc_attr( $id ),
			self::render_fields( $sub, $row, $prefix, $depth ),
			esc_attr__( 'Zeile entfernen', 'sdc-page-builder' ),
			esc_attr( $prefix ),
			esc_attr( $prefix )
		);
	}

	/**
	 * A group container with a fixed set of sub-fields.
	 *
	 * @param array  $field  Group definition.
	 * @param mixed  $value  Values.
	 * @param string $prefix Name prefix.
	 * @param int    $depth  Nesting depth.
	 * @return string HTML.
	 */
	private static function render_group( array $field, $value, $prefix, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			error_log( sprintf( 'SDC Page Builder: Verschachtelungstiefe %d überschreitet das Maximum %d (Feld "%s").', (int) $depth, self::MAX_DEPTH, (string) $field['name'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$sub = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		$key = isset( $field['key'] ) ? (string) $field['key'] : (string) $field['name'];

		return sprintf(
			'<fieldset class="sdc-group" data-sdc-container="group" data-sdc-key="%1$s"><legend>%2$s</legend>%3$s</fieldset>',
			esc_attr( $key ),
			esc_html( $field['label'] ?? $field['name'] ),
			self::render_fields( $sub, (array) $value, $prefix . '[' . $key . ']', $depth + 1 )
		);
	}
}

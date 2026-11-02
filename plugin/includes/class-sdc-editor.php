<?php
/**
 * The editing surface: metabox, assets, and the bridge to the REST save.
 *
 * The metabox does NOT save through the post form. Measured: a builder page
 * with 30 components renders 1373 form inputs, while PHP truncates POST data
 * at max_input_vars = 1000 — silent, without an error. So the browser collects
 * the inputs into one JSON document and posts it to `sdc-pb/v1/pages/{id}`.
 *
 * Each component instance renders under its own name prefix
 * (`acf[<component-id>]`). Without that, two components of the same type
 * produce identical input names and overwrite each other — measured, 18 of 18
 * names collided.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin integration.
 */
class Sdc_Editor {

	/**
	 * Metabox id.
	 */
	const METABOX = 'sdc-page-builder';

	/**
	 * Hook the admin side.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Post types that get the builder.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = (array) apply_filters( 'sdc_pb_post_types', array( 'page' ) );
		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * Register the metabox for every supported post type.
	 */
	public static function add_metabox() {
		foreach ( self::post_types() as $type ) {
			add_meta_box(
				self::METABOX,
				__( 'Page Builder', 'sdc-page-builder' ),
				array( __CLASS__, 'render_metabox' ),
				$type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Load the editor assets on the screens that need them.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'sdc-pb-editor',
			SDC_PB_URL . 'assets/editor.css',
			array(),
			SDC_PB_VERSION
		);

		wp_enqueue_script(
			'sdc-pb-editor',
			SDC_PB_URL . 'assets/editor.js',
			array(),
			SDC_PB_VERSION,
			true
		);

		wp_localize_script(
			'sdc-pb-editor',
			'SDC_PB',
			array(
				'restUrl' => esc_url_raw( rest_url( Sdc_Rest::NAMESPACE_V1 . '/pages/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'fields'  => self::field_map(),
				'labels'  => array(
					'saved'    => __( 'Gespeichert.', 'sdc-page-builder' ),
					'saving'   => __( 'Speichert …', 'sdc-page-builder' ),
					'failed'   => __( 'Speichern fehlgeschlagen.', 'sdc-page-builder' ),
					'dirty'    => __( 'Nicht gespeicherte Änderungen — wird beim Aktualisieren mitgeschrieben.', 'sdc-page-builder' ),
					'empty'    => __( 'Noch keine Komponenten. Unten eine Komponente hinzufügen.', 'sdc-page-builder' ),
					'confirm'  => __( 'Komponente samt Inhalt entfernen?', 'sdc-page-builder' ),
				),
			)
		);
	}

	/**
	 * Field key => field name, for every component, nested fields included.
	 *
	 * The host always names inputs by field key (`acf_prepare_field` forces
	 * `name = key`), while the stored document is keyed by field name. This map
	 * is how the browser translates one into the other.
	 *
	 * @return array<string,string>
	 */
	private static function field_map() {
		$map = array();

		foreach ( Sdc_Schema::components() as $component ) {
			$fields = Sdc_Field_Adapter::get_fields( $component['key'] );
			self::collect_field_map( $fields, $map );
		}

		return $map;
	}

	/**
	 * Walk a field list and record key => name.
	 *
	 * @param array[]              $fields Field definitions.
	 * @param array<string,string> $map    Map, by reference.
	 */
	private static function collect_field_map( array $fields, array &$map ) {
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) || empty( $field['name'] ) ) {
				continue;
			}
			$map[ (string) $field['key'] ] = (string) $field['name'];

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				self::collect_field_map( $field['sub_fields'], $map );
			}
		}
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_metabox( $post ) {
		$document   = Sdc_Store::get_data( $post->ID );
		$components = (array) $document['components'];
		$catalogue  = Sdc_Schema::components();

		echo '<div class="sdc-builder" data-sdc-builder data-sdc-post="' . esc_attr( (string) $post->ID ) . '">';

		if ( empty( $catalogue ) ) {
			printf(
				'<p class="sdc-builder__hint">%s</p>',
				esc_html__( 'Keine Komponenten gefunden. Komponenten sind Feldgruppen mit dem Schlüssel-Präfix group_sdc_component_.', 'sdc-page-builder' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p class="sdc-builder__hint%s" data-sdc-empty>%s</p>',
			empty( $components ) ? '' : ' is-hidden',
			esc_html__( 'Noch keine Komponenten. Unten eine Komponente hinzufügen.', 'sdc-page-builder' )
		);

		echo '<div class="sdc-builder__list" data-sdc-components>';
		foreach ( $components as $component ) {
			echo self::component_shell( // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped below.
				(string) $component['type'],
				(string) $component['_id'],
				isset( $catalogue[ $component['type'] ] ) ? (string) $component['schema'] : '',
				(array) $component['data']
			);
		}
		echo '</div>';

		echo '<div class="sdc-builder__add">';
		echo '<select data-sdc-add-select>';
		foreach ( $catalogue as $type => $component ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $type ),
				esc_html( $component['label'] )
			);
		}
		echo '</select>';
		printf(
			'<button type="button" class="button" data-sdc-add-component>%s</button>',
			esc_html__( 'Komponente hinzufügen', 'sdc-page-builder' )
		);
		echo '</div>';

		// Templates for newly added components, one per type. Rendered with a
		// placeholder instance id that the client swaps for a real one.
		//
		// Inside the builder root on purpose: the client looks them up from
		// there (`builder.querySelector`). Emitted outside the root they are
		// invisible to it and "add component" silently does nothing.
		foreach ( $catalogue as $type => $component ) {
			printf(
				'<template data-sdc-component-template="%s">%s</template>',
				esc_attr( $type ),
				self::component_shell( $type, '__c__', Sdc_Schema::version_string( $type ), array() ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}

		echo '<p class="sdc-builder__status" data-sdc-status aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Markup of one component instance.
	 *
	 * @param string $type     Component type.
	 * @param string $id       Instance id, or `__c__` for a template.
	 * @param string $schema   Stored schema string, e.g. `faq@1`.
	 * @param array  $data     Stored values.
	 * @return string HTML.
	 */
	private static function component_shell( $type, $id, $schema, array $data ) {
		$component = Sdc_Schema::get( $type );
		if ( ! $component ) {
			return '';
		}

		$label = $component['label'];

		return sprintf(
			'<div class="sdc-component" data-sdc-component data-sdc-id="%1$s" data-sdc-type="%2$s" data-sdc-schema="%3$s">'
				. '<div class="sdc-component__bar">'
				. '<span class="sdc-component__handle" data-sdc-drag-handle draggable="true" aria-hidden="true">⋮⋮</span>'
				. '<strong class="sdc-component__title">%4$s</strong>'
				. '<button type="button" class="sdc-component__remove" data-sdc-remove-component aria-label="%5$s">×</button>'
				. '</div>'
				. '<div class="sdc-component__body">%6$s</div>'
				. '</div>',
			esc_attr( $id ),
			esc_attr( $type ),
			esc_attr( $schema ),
			esc_html( $label ),
			esc_attr__( 'Komponente entfernen', 'sdc-page-builder' ),
			Sdc_Renderer::render_component( $type, $data, 'editor', $id ) // phpcs:ignore WordPress.Security.EscapeOutput -- renderer escapes its own output.
		);
	}
}

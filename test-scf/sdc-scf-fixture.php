<?php
/**
 * Plugin Name: SDC SCF Fixture
 * Description: Test-Fixture: drei SDC-Komponenten als SCF-Feldgruppen, um Speicherkosten und max_input_vars zu messen.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Die drei Komponenten. Bewusst so geschnitten, wie ein Page-Builder sie
 * wirklich braucht: Text-Repeater, Medien + Links, und eine Komponente mit
 * verschachtelter Struktur (Preisplan -> Leistungszeilen).
 */
function sdc_fixture_components() {
	return array(
		'faq'     => array(
			'title' => 'SDC: FAQ',
			'rows'  => 5,
			'fields' => array(
				array( 'name' => 'frage',    'type' => 'text',     'label' => 'Frage' ),
				array( 'name' => 'antwort',  'type' => 'textarea', 'label' => 'Antwort' ),
			),
		),
		'team'    => array(
			'title' => 'SDC: Team',
			'rows'  => 6,
			'fields' => array(
				array( 'name' => 'foto',     'type' => 'image',    'label' => 'Foto' ),
				array( 'name' => 'name',     'type' => 'text',     'label' => 'Name' ),
				array( 'name' => 'rolle',    'type' => 'text',     'label' => 'Rolle' ),
				array( 'name' => 'email',    'type' => 'email',    'label' => 'E-Mail' ),
				array( 'name' => 'telefon',  'type' => 'text',     'label' => 'Telefon' ),
				array( 'name' => 'linkedin', 'type' => 'url',      'label' => 'LinkedIn' ),
			),
		),
		'preise'  => array(
			'title' => 'SDC: Preistabelle',
			'rows'  => 3,
			'fields' => array(
				array( 'name' => 'paket',      'type' => 'text',       'label' => 'Paket' ),
				array( 'name' => 'preis',      'type' => 'text',       'label' => 'Preis' ),
				array( 'name' => 'zeitraum',   'type' => 'select',     'label' => 'Zeitraum', 'choices' => array( 'monat' => 'pro Monat', 'jahr' => 'pro Jahr', 'einmalig' => 'einmalig' ) ),
				array( 'name' => 'leistungen', 'type' => 'textarea',   'label' => 'Leistungen' ),
				array( 'name' => 'cta_text',   'type' => 'text',       'label' => 'Button-Text' ),
				array( 'name' => 'cta_url',    'type' => 'url',        'label' => 'Button-Ziel' ),
				array( 'name' => 'hervorheben','type' => 'true_false', 'label' => 'Hervorheben' ),
			),
		),
	);
}

/**
 * Stress-Komponente: 12 Subfelder, Zeilenzahl frei — um die
 * max_input_vars-Grenze (PHP-Default 1000) real zu erreichen.
 */
function sdc_fixture_stress_fields( $count = 12 ) {
	$types = array( 'text', 'text', 'text', 'textarea', 'text', 'text', 'text', 'text', 'url', 'text', 'text', 'text' );
	$out   = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = array(
			'name'  => 'feld_' . $i,
			'type'  => $types[ $i % count( $types ) ],
			'label' => 'Feld ' . $i,
		);
	}
	return $out;
}

/**
 * Feldgruppen registrieren. key = UUID-Format wie ACF es anlegt.
 */
function sdc_fixture_register() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$components = sdc_fixture_components();
	$i          = 0;

	foreach ( $components as $slug => $component ) {
		$i++;
		$sub_fields = array();
		$j          = 0;
		foreach ( $component['fields'] as $field ) {
			$j++;
			$def = array(
				'key'   => sprintf( 'field_sdc_%s_%02d', $slug, $j ),
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);
			if ( isset( $field['choices'] ) ) {
				$def['choices'] = $field['choices'];
			}
			$sub_fields[] = $def;
		}

		acf_add_local_field_group( array(
			'key'      => 'group_sdc_' . $slug,
			'title'    => $component['title'],
			'fields'   => array(
				array(
					'key'        => 'field_sdc_' . $slug,
					'name'       => 'sdc_' . $slug,
					'label'      => $component['title'],
					'type'       => 'repeater',
					'layout'     => 'table',
					'sub_fields' => $sub_fields,
				),
			),
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		) );
	}

	// Stress-Komponente.
	acf_add_local_field_group( array(
		'key'      => 'group_sdc_stress',
		'title'    => 'SDC: Stress',
		'fields'   => array(
			array(
				'key'        => 'field_sdc_stress',
				'name'       => 'sdc_stress',
				'label'      => 'Stress-Repeater',
				'type'       => 'repeater',
				'layout'     => 'table',
				'sub_fields' => sdc_fixture_stress_fields(),
			),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	) );
}
add_action( 'acf/init', 'sdc_fixture_register' );

/**
 * Realistischer Builder-Aufbau: EINE Flexible-Content-Fläche, in die beliebig
 * viele Komponenten-Instanzen gelegt werden. Genau so muss ein Page-Builder
 * arbeiten — mehrere Instanzen derselben Komponente auf einer Seite.
 */
function sdc_fixture_builder_layouts() {
	$components = sdc_fixture_components();

	// Zusätzlich eine große Komponente (12 Felder) — realistisch für
	// Hero/Feature-Grid mit Bild, Badge, mehreren Links.
	$components['feature'] = array(
		'title'  => 'SDC: Feature-Grid',
		'rows'   => 5,
		'fields' => sdc_fixture_stress_fields( 12 ),
	);

	$layouts = array();
	foreach ( $components as $slug => $component ) {
		$sub = array();
		$j   = 0;
		foreach ( $component['fields'] as $field ) {
			$j++;
			$def = array(
				'key'   => sprintf( 'field_sdc_%s_%02d', $slug, $j ),
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);
			if ( isset( $field['choices'] ) ) {
				$def['choices'] = $field['choices'];
			}
			$sub[] = $def;
		}

		$layouts[ $slug ] = array(
			'key'        => 'layout_sdc_' . $slug,
			'name'       => $slug,
			'label'      => $component['title'],
			'display'    => 'block',
			'sub_fields' => array(
				array(
					'key'        => 'field_sdc_b_' . $slug,
					'name'       => 'zeilen',
					'label'      => 'Zeilen',
					'type'       => 'repeater',
					'layout'     => 'table',
					'sub_fields' => $sub,
				),
			),
		);
	}

	acf_add_local_field_group( array(
		'key'      => 'group_sdc_builder',
		'title'    => 'SDC: Seite',
		'fields'   => array(
			array(
				'key'          => 'field_sdc_builder',
				'name'         => 'sdc_builder',
				'label'        => 'Komponenten',
				'type'         => 'flexible_content',
				'layouts'      => $layouts,
				'button_label' => 'Komponente hinzufügen',
			),
		),
		'location' => array( array( array( 'param' => 'page_template', 'operator' => '==', 'value' => 'default' ) ) ),
	) );
}
add_action( 'acf/init', 'sdc_fixture_builder_layouts' );

/**
 * Eine Zeile mit Beispieldaten für eine Komponente.
 */
function sdc_fixture_row( $slug, $r ) {
	$werte = array(
		'frage'       => "Frage $r: Wie läuft das ab?",
		'antwort'     => "Antwort $r: Wir starten mit einem Audit und setzen die Maßnahmen in Sprints um.",
		'name'        => "Person $r",
		'rolle'       => 'SEO-Beraterin',
		'email'       => "person$r@example.com",
		'telefon'     => '+49 451 1234567',
		'linkedin'    => "https://www.linkedin.com/in/person-$r/",
		'foto'        => '',
		'paket'       => "Paket $r",
		'preis'       => (string) ( 490 * $r ),
		'zeitraum'    => 'monat',
		'leistungen'  => "Leistung A\nLeistung B\nLeistung C",
		'cta_text'    => 'Jetzt anfragen',
		'cta_url'     => 'https://example.com/kontakt',
		'hervorheben' => '0',
	);

	$row = array();
	foreach ( array_keys( sdc_fixture_row_keys( $slug ) ) as $key ) {
		$row[ $key ] = $werte[ $key ] ?? "Wert $r";
	}
	return $row;
}

/**
 * Die Subfeld-Namen einer Komponente (nur die Namen, für den Zeilenaufbau).
 */
function sdc_fixture_row_keys( $slug ) {
	$components = sdc_fixture_components();
	$fields     = $components[ $slug ]['fields'] ?? array();
	// 'feature' ist nicht in sdc_fixture_components() — 12 Felder feld_0 … feld_11.
	if ( 'feature' === $slug ) {
		return array_fill_keys( array_map( fn( $i ) => 'feld_' . $i, range( 0, 11 ) ), true );
	}
	return array_fill_keys( array_column( $fields, 'name' ), true );
}

/**
 * Hilfsfunktion: eine Seite mit gegebenen Komponenten-Daten füllen.
 * Wird von den Messskripten benutzt.
 */
function sdc_fixture_seed_page( $title, $data ) {
	$post_id = wp_insert_post( array(
		'post_title'  => $title,
		'post_status' => 'publish',
		'post_type'   => 'page',
	) );
	foreach ( $data as $field_name => $rows ) {
		update_field( $field_name, $rows, $post_id );
	}
	return $post_id;
}

<?php
/**
 * Der Punkt, an dem M1 zu billig war: Felder RENDERN.
 *
 * Ein FAQ-Schema nutzt den Feldtyp `repeater`. Den gibt es in ACF Free nicht.
 * Hier wird gemessen, was der Wirt damit tut — nicht vermutet.
 */

echo 'Host: ' . Sdc_Field_Adapter::host() . ' ' . Sdc_Field_Adapter::host_version() . "\n\n";

// 1. Welcher Typ steht im Schema, was liefert der Wirt?
$fields = Sdc_Schema::fields( 'faq' );
echo "== Schema-Felder (was wir definiert haben) ==\n";
foreach ( $fields as $f ) {
	echo "  {$f['name']}: type={$f['type']}\n";
}

// 2. Der rohe Wirt: gibt es den Typ überhaupt, ist die Feldklasse da?
echo "\n== Feldtyp-Registry des Wirtes ==\n";
$registry = function_exists( 'acf_get_field_types' ) ? acf_get_field_types() : array();
$has      = isset( $registry['repeater'] );
echo 'Feldtypen insgesamt: ' . count( $registry ) . "\n";
echo 'repeater registriert: ' . ( $has ? 'JA (' . get_class( $registry['repeater'] ) . ')' : 'NEIN' ) . "\n";
foreach ( array( 'text', 'textarea', 'image', 'select', 'group', 'flexible_content', 'clone' ) as $t ) {
	printf( "  %-18s %s\n", $t, isset( $registry[ $t ] ) ? 'vorhanden' : '— fehlt' );
}

// 3. Was passiert beim Rendern?
echo "\n== Rendern des repeater-Feldes ==\n";
$group = Sdc_Field_Adapter::get_field_group( 'group_sdc_component_faq' );
$raw   = Sdc_Field_Adapter::get_fields( $group );
foreach ( $raw as $rf ) {
	printf( "  %-10s type=%-12s key=%s\n", $rf['name'], $rf['type'], $rf['key'] );
}

$repeater = null;
foreach ( $raw as $rf ) {
	if ( 'repeater' === $rf['type'] ) {
		$repeater = $rf;
	}
}

if ( $repeater ) {
	ob_start();
	try {
		acf_render_field( $repeater );
		$html = ob_get_clean();
		echo 'HTML-Länge: ' . strlen( $html ) . " Bytes\n";
		echo 'enthält <input: ' . ( false !== strpos( $html, '<input' ) ? 'ja' : 'NEIN' ) . "\n";
		echo 'enthält "Repeater": ' . ( false !== stripos( $html, 'repeater' ) ? 'ja' : 'NEIN' ) . "\n";
		if ( strlen( $html ) < 400 ) {
			echo "HTML:\n" . $html . "\n";
		}
	} catch ( Throwable $e ) {
		ob_end_clean();
		echo 'AUSNAHME: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	}
} else {
	echo "kein repeater-Feld im Schema gefunden\n";
}

// 4. Der Stresstest: rendert der Wirt ein Textfeld, das garantiert existiert?
echo "\n== Kontrolle: ein text-Feld rendern ==\n";
$text_field = array(
	'key'   => 'field_probe_text',
	'name'  => 'probe_text',
	'label' => 'Probe',
	'type'  => 'text',
	'value' => 'Hallo',
);
ob_start();
try {
	acf_render_field( $text_field );
	$h = ob_get_clean();
	echo 'HTML-Länge: ' . strlen( $h ) . " Bytes, <input: " . ( false !== strpos( $h, '<input' ) ? 'ja' : 'NEIN' ) . "\n";
} catch ( Throwable $e ) {
	ob_end_clean();
	echo 'AUSNAHME: ' . $e->getMessage() . "\n";
}

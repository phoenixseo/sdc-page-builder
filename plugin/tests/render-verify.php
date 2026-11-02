<?php
/**
 * Beweist die Zusage „ein Code, zwei Wirte" auf der Render-Ebene.
 *
 * M1 prüfte nur Speichern — dort sind beide Wirte identisch, weil wir gar
 * nicht mit ihnen reden. Der Unterschied liegt beim Rendern: ACF Free hat
 * keinen `repeater`. Hier wird gemessen, dass unser Renderer in beiden Wirten
 * dieselbe Zahl Inputs mit denselben Namen erzeugt.
 *
 * Aufruf: wp eval-file tests/render-verify.php
 */

$fails = 0;
$check = function ( $label, $ok, $note = '' ) use ( &$fails ) {
	if ( ! $ok ) {
		$fails++;
	}
	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $note ? " — $note" : '' );
};

printf( "Host: %s %s\n\n", Sdc_Field_Adapter::host(), Sdc_Field_Adapter::host_version() );

$data = array(
	'heading' => 'Häufige Fragen',
	'items'   => array(
		array( '_id' => 'r_1', 'q' => 'Erste Frage?', 'a' => 'Erste Antwort.' ),
		array( '_id' => 'r_2', 'q' => 'Zweite Frage?', 'a' => 'Zweite Antwort.' ),
		array( '_id' => 'r_3', 'q' => 'Dritte Frage?', 'a' => 'Dritte Antwort.' ),
	),
);

echo "== Komponente rendern ==\n";
$html = Sdc_Renderer::render_component( 'faq', $data );
$check( 'HTML nicht leer', strlen( $html ) > 500, strlen( $html ) . ' Bytes' );

echo "\n== Struktur ==\n";
$check( 'Komponenten-Wrapper vorhanden', false !== strpos( $html, 'data-sdc-type="faq"' ) );
$check( 'Listen-Container vom Builder gerendert', false !== strpos( $html, 'data-sdc-container="list"' ) );
$check( 'Zeilen-Template für JS vorhanden', false !== strpos( $html, 'data-sdc-row-template' ) );
// 3 Zeilen + 1 Template-Zeile (für den "hinzufügen"-Knopf im Browser).
$check( 'Zeilen-Handle für Sortieren vorhanden', 4 === substr_count( $html, 'data-sdc-drag-handle' ), substr_count( $html, 'data-sdc-drag-handle' ) . ' Handles (3 Zeilen + Template)' );

$rows = substr_count( $html, 'data-sdc-row data-sdc-row-index' ) - 1; // minus Template
$check( '3 Zeilen gerendert', 3 === $rows, "$rows Zeilen" );

echo "\n== Blattfelder ==\n";
preg_match_all( '/<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"/', $html, $m );
$names = $m[1];
$check( 'mindestens 7 Eingaben (1 heading + 3×2 Blätter)', count( $names ) >= 7, count( $names ) . ' Eingaben' );
$check(
	'heading korrekt benannt',
	in_array( 'acf[field_sdc_faq_heading]', $names, true ),
	'acf[field_sdc_faq_heading]'
);
$check(
	'erste Zeile, erstes Feld korrekt verschachtelt',
	in_array( 'acf[field_sdc_faq_items][0][field_sdc_faq_item_q]', $names, true ),
	'acf[field_sdc_faq_items][0][field_sdc_faq_item_q]'
);
$check(
	'dritte Zeile, zweites Feld korrekt verschachtelt',
	in_array( 'acf[field_sdc_faq_items][2][field_sdc_faq_item_a]', $names, true )
);

echo "\n== Werte kommen an ==\n";
$check( 'Antworttext im HTML', false !== strpos( $html, 'Zweite Antwort.' ) );
$check( 'Umlaute im HTML', false !== strpos( $html, 'Häufige Fragen' ) );
$check( 'textarea statt input für Antwort', 4 === substr_count( $html, '<textarea' ), substr_count( $html, '<textarea' ) . ' <textarea> (3 Zeilen + Template)' );

echo "\n== Row-IDs durchgereicht ==\n";
$check( 'r_1 im HTML', false !== strpos( $html, 'r_1' ) );
$check( '_id-Feld je Zeile', 4 === substr_count( $html, '[_id]' ), substr_count( $html, '[_id]' ) . ' Felder (3 Zeilen + Template)' );

echo "\n== Gleicher Output bei beiden Wirten? ==\n";
$signature = md5( preg_replace( '/\s+/', ' ', $html ) );
printf( "SIGNATUR %s (%d Bytes, %d Eingaben)\n", $signature, strlen( $html ), count( $names ) );

printf( "\n%s (%d Fehler)\n", 0 === $fails ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE', $fails );

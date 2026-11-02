<?php
/**
 * M3 verification: proves the frontend output path end to end.
 *
 * Run: wp eval-file tests/m3-frontend-verify.php
 *
 * What is checked, and why each check is here:
 *   1. The page's stored document renders: every heading and every row value
 *      of both components appears in the output.
 *   2. The output is content, not a control surface: no `data-sdc-*` attributes,
 *      no `acf[` input names, no input/textarea/select/button/template element,
 *      no `<label for>` — a page with inputs is a form the reader cannot use.
 *   3. Leaves render as HTML text with the value escaped: an `<b>` in a value
 *      stays text.
 *   4. Edges behave: empty document, unknown type (skipped + logged, no fatal),
 *      empty rows dropped, depth > MAX_DEPTH capped.
 *   5. The output is well-formed: every opened <div> is closed, no stray
 *      `</div>`, and the whole thing survives a DOM parse unchanged.
 *
 * Boundary, stated up front: this runs against the real plugin, the real host
 * and the real stored document on page 2 — but it calls the renderer directly,
 * it does not drive a browser. The HTTP path is verified separately by curl
 * (see the sprint report); this script proves the markup, not the routing.
 *
 * @package SdcPageBuilder
 */

$fails   = 0;
$checked = 0;
$check   = function ( $label, $ok, $note = '' ) use ( &$fails, &$checked ) {
	$checked++;
	if ( ! $ok ) {
		$fails++;
	}
	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $note ? " — $note" : '' );
};

printf( "Host: %s %s\n", Sdc_Field_Adapter::host(), Sdc_Field_Adapter::host_version() );
echo "Seite: 2\n\n";

// ---------------------------------------------------------------- 1. content

echo "== 1. Gespeichertes Dokument rendert ==\n";

$doc = Sdc_Store::get_data( 2 );
$check( 'Dokument hat 2 Komponenten', 2 === count( $doc['components'] ), count( $doc['components'] ) . ' Komponenten' );

$html = Sdc_Frontend::render_post( 2 );
$check( 'Ausgabe nicht leer', strlen( $html ) > 200, strlen( $html ) . ' Bytes' );

// Erwartung direkt aus dem gespeicherten Dokument ableiten, nicht aus dem Code:
// alles, was für Menschen sichtbar ist, muss im Ergebnis stehen.
$expected = array();
foreach ( $doc['components'] as $component ) {
	foreach ( $component['data'] as $key => $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $row ) {
				foreach ( $row as $field => $cell ) {
					if ( '_id' !== $field && is_scalar( $cell ) && '' !== trim( (string) $cell ) ) {
						$expected[] = trim( (string) $cell );
					}
				}
			}
			continue;
		}
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$expected[] = trim( (string) $value );
		}
	}

	// Jede gespeicherte Zeile muss genau eine Ausgabezeile ergeben.
	$rows = isset( $component['data']['items'] ) && is_array( $component['data']['items'] ) ? count( $component['data']['items'] ) : 0;
	$check( 'Zeilen im Dokument: ' . $component['_id'], $rows > 0, $rows . ' Zeilen' );
}

$missing = array();
foreach ( $expected as $needle ) {
	if ( false === strpos( $html, esc_html( $needle ) ) ) {
		$missing[] = $needle;
	}
}
$check(
	'alle ' . count( $expected ) . ' gespeicherten Werte im HTML',
	empty( $missing ),
	empty( $missing ) ? esc_html( implode( ' | ', $expected ) ) : 'fehlt: ' . implode( ' | ', $missing )
);

$rendered_rows = substr_count( $html, '<div class="sdc-row">' );
$stored_rows   = 0;
foreach ( $doc['components'] as $component ) {
	$stored_rows += isset( $component['data']['items'] ) ? count( $component['data']['items'] ) : 0;
}
$check( 'Ausgabezeilen = gespeicherte Zeilen', $rendered_rows === $stored_rows, $rendered_rows . ' vs ' . $stored_rows );

$check( 'zwei Komponenten-Wrapper', 2 === substr_count( $html, 'class="sdc-component' ), substr_count( $html, 'class="sdc-component' ) . ' Wrapper' );
$check( 'beide Überschriften getrennt gerendert', 2 === substr_count( $html, 'sdc-field--heading' ), substr_count( $html, 'sdc-field--heading' ) . ' Überschriften' );

// ------------------------------------------------------- 2. no controls, no admin

echo "\n== 2. Content, keine Bedienoberfläche ==\n";

$check( 'kein data-sdc-* im Frontend-HTML', 0 === preg_match( '/\sdata-sdc-[a-z-]+/', $html ), (int) preg_match_all( '/\sdata-sdc-[a-z-]+/', $html ) . ' Vorkommen' );
$check( 'kein acf[ im Frontend-HTML', 0 === preg_match( '/acf\[/', $html ), (int) preg_match_all( '/acf\[/', $html ) . ' Vorkommen' );
$check( 'keine Formularelemente', 0 === preg_match( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $html ), (int) preg_match_all( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $html ) . ' Elemente' );
$check( 'keine Labels für Eingaben', 0 === preg_match( '/<label\b/i', $html ) );
$check( 'keine Admin-Klassen', 0 === preg_match( '/\b(button|acf-|wp-admin|sdc-builder)/', $html ) );
$check( 'kein aria-hidden Dekorationselement (Drag-Handle)', false === strpos( $html, '⋮⋮' ) );

// ------------------------------------------------------------- 3. escaping

echo "\n== 3. Werte werden escaped ==\n";

$evil = Sdc_Frontend::render_post( 0 ); // no document → empty, proves the empty case too.
$check( 'Post ohne Dokument liefert leeren String', '' === $evil );

// Eine Komponente mit HTML in jedem Wert über die echte Renderfunktion.
$probe_id = wp_insert_post(
	array(
		'post_title'  => 'sdc-m3-probe',
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_author' => 1,
	)
);
$check( 'Probe-Seite angelegt', $probe_id > 0, 'ID ' . $probe_id );

Sdc_Store::save_data(
	$probe_id,
	array(
		'components' => array(
			array(
				'_id'    => 'c_m3probe',
				'type'   => 'faq',
				'schema' => 'faq@1',
				'data'   => array(
					'heading' => '<script>alert(1)</script>Kopf & "Zitat"',
					'items'   => array(
						array( '_id' => 'p_1', 'q' => '<b>fett</b> & <i>kursiv</i>', 'a' => 'Zeile 1' . "\n" . 'Zeile 2' ),
					),
				),
			),
		),
	)
);
Sdc_Schema::flush();

global $wpdb;
$probe_html = Sdc_Frontend::render_post( $probe_id );
$check( 'Probe-Script nicht ausgeführt, sondern escaped', false === strpos( $probe_html, '<script>' ) && false !== strpos( $probe_html, '&lt;script&gt;' ) );
$check( 'HTML im Feldwert bleibt Text', false === strpos( $probe_html, '<b>fett</b>' ) && false !== strpos( $probe_html, '&lt;b&gt;fett&lt;/b&gt;' ) );
$check( 'Anführungszeichen escaped', false !== strpos( $probe_html, '&quot;Zitat&quot;' ) );
$check( 'Ampersand escaped', false !== strpos( $probe_html, '&amp;' ) );
$check( 'Zeilenumbruch als <br>', false !== strpos( $probe_html, "Zeile 1<br />\nZeile 2" ) );

// ---------------------------------------------------------------- 4. edges

echo "\n== 4. Kanten ==\n";

// 4a. leeres Dokument
Sdc_Store::save_data( $probe_id, array( 'components' => array() ) );
Sdc_Schema::flush();
$empty_html = Sdc_Frontend::render_post( $probe_id );
$check( 'leeres Dokument: leerer String, kein Wrapper', '' === $empty_html, '"" (' . strlen( $empty_html ) . ' Bytes)' );

// 4b. unbekannter Typ: übersprungen, geloggt, kein Fatal
$log = tempnam( sys_get_temp_dir(), 'sdc-log' );
$previous_log = ini_set( 'error_log', $log );

Sdc_Store::save_data(
	$probe_id,
	array(
		'components' => array(
			array(
				'_id'    => 'c_unknown',
				'type'   => 'gibtesnicht',
				'schema' => 'gibtesnicht@1',
				'data'   => array( 'heading' => 'Darf nicht erscheinen' ),
			),
			array(
				'_id'    => 'c_afterunknown',
				'type'   => 'faq',
				'schema' => 'faq@1',
				'data'   => array(
					'heading' => 'Nach der unbekannten Komponente',
					'items'   => array( array( '_id' => 'u_1', 'q' => 'Steht noch da?', 'a' => 'Ja.' ) ),
				),
			),
		),
	)
);
Sdc_Schema::flush();

$unknown_html = Sdc_Frontend::render_post( $probe_id );
$log_text     = (string) @file_get_contents( $log );
ini_set( 'error_log', $previous_log );

$check( 'unbekannter Typ: kein Fatal, Folgekomponente rendert', false !== strpos( $unknown_html, 'Nach der unbekannten Komponente' ) );
$check( 'unbekannter Typ: keine Ausgabe für die unbekannte Komponente', false === strpos( $unknown_html, 'Darf nicht erscheinen' ) );
$check( 'unbekannter Typ: im error_log vermerkt', false !== strpos( $log_text, 'gibtesnicht' ), trim( str_replace( $log, '', $log_text ) ) );
$check( 'unbekannter Typ: nur eine Komponente ausgegeben', 1 === substr_count( $unknown_html, 'class="sdc-component' ), substr_count( $unknown_html, 'class="sdc-component' ) . ' Wrapper' );

// 4c. leere Zeilen werden verworfen
Sdc_Store::save_data(
	$probe_id,
	array(
		'components' => array(
			array(
				'_id'    => 'c_emptyrows',
				'type'   => 'faq',
				'schema' => 'faq@1',
				'data'   => array(
					'heading' => '',
					'items'   => array(
						array( '_id' => 'e_1', 'q' => '', 'a' => '' ),
						array( '_id' => 'e_2', 'q' => 'Die einzige echte Frage', 'a' => 'Antwort' ),
						array( '_id' => 'e_3', 'q' => '', 'a' => '' ),
					),
				),
			),
		),
	)
);
Sdc_Schema::flush();
$emptyrows_html = Sdc_Frontend::render_post( $probe_id );
$check( 'leere Zeilen verworfen: 1 von 3 Zeilen ausgegeben', 1 === substr_count( $emptyrows_html, 'class="sdc-row"' ), substr_count( $emptyrows_html, 'class="sdc-row"' ) . ' Zeilen' );
$check( 'leere Überschrift erzeugt kein leeres Element', false === strpos( $emptyrows_html, 'sdc-field--heading' ) );

// 4d. Tiefe > MAX_DEPTH wird gekappt — auf beiden Rendenwegen.
//
// Achtung Zählweise: der äußerste Container läuft als depth 1, das Blatt in
// ihm ist depth 2. "Tiefe 3" heißt also DREI verschachtelte Container über dem
// Blatt — nicht drei Ebenen. Der erste Lauf dieser Prüfung war rot, weil das
// Fixture nur zwei Container hatte (Blatt = depth 3, also legitim) und ich
// trotzdem eine Kappung erwartet habe. Fixture korrigiert, nicht der Code.
$leaf = array( 'key' => 'field_probe_leaf', 'name' => 'leaf', 'label' => 'Blatt', 'type' => 'text' );

$depth3_field = array(
	'key'        => 'field_probe_d1',
	'name'       => 'd1',
	'label'      => 'Ebene 1',
	'type'       => 'repeater',
	'sub_fields' => array(
		array(
			'key'        => 'field_probe_d2',
			'name'       => 'd2',
			'label'      => 'Ebene 2',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'        => 'field_probe_d3',
					'name'       => 'd3',
					'label'      => 'Ebene 3',
					'type'       => 'repeater',
					'sub_fields' => array( $leaf ),
				),
			),
		),
	),
);
$depth3_value = array( array( 'd2' => array( array( 'd3' => array( array( 'leaf' => 'TIEF-3' ) ) ) ) ) );

$log2         = tempnam( sys_get_temp_dir(), 'sdc-log' );
$previous_log = ini_set( 'error_log', $log2 );
$depth_ok_renderer = Sdc_Renderer::render_fields( array( $depth3_field ), array( 'd1' => $depth3_value ), 'acf', 1 );
$depth_ok_log      = (string) @file_get_contents( $log2 );
ini_set( 'error_log', $previous_log );

$check( 'Tiefe 3 rendert (Renderer)', false !== strpos( $depth_ok_renderer, 'TIEF-3' ) );
$check( 'Tiefe 3 ohne Kappen-Meldung', false === strpos( $depth_ok_log, 'überschreitet das Maximum' ) );

// Eine vierte Containerebene erzwingt depth 5 am Container d4 -> muss gekappt werden.
// Pfad-Korrektur (erster Versuch war rot): d3 endet bei ['sub_fields'][0] = Blatt.
// Die vierte Ebene entsteht, indem das Blatt durch den Container d4 ERSETZT wird,
// nicht indem noch eine Ebene tiefer geschrieben wird.
$depth4_field = $depth3_field;
$depth4_field['sub_fields'][0]['sub_fields'][0]['sub_fields'] = array(
	array(
		'key'        => 'field_probe_d4',
		'name'       => 'd4',
		'label'      => 'Ebene 4',
		'type'       => 'repeater',
		'sub_fields' => array( $leaf ),
	),
);

// Selbstkontrolle am Fixture: 4 Container-Ebenen, sonst ist der Test wertlos.
$count_levels = function ( array $field ) {
	$levels = 1;
	$cursor = $field;
	while ( isset( $cursor['sub_fields'][0]['type'] ) && 'repeater' === $cursor['sub_fields'][0]['type'] ) {
		$levels++;
		$cursor = $cursor['sub_fields'][0];
	}
	return $levels;
};
$check( 'Fixture: Tiefe-3-Feld hat genau 3 Container-Ebenen', 3 === $count_levels( $depth3_field ), $count_levels( $depth3_field ) . ' Ebenen' );
$check( 'Fixture: Tiefe-4-Feld hat genau 4 Container-Ebenen', 4 === $count_levels( $depth4_field ), $count_levels( $depth4_field ) . ' Ebenen' );

$depth4_value = array( array( 'd2' => array( array( 'd3' => array( array( 'd4' => array( array( 'leaf' => 'TIEF-4' ) ) ) ) ) ) ) );

$log3         = tempnam( sys_get_temp_dir(), 'sdc-log' );
$previous_log = ini_set( 'error_log', $log3 );
$deep_renderer = Sdc_Renderer::render_fields( array( $depth4_field ), array( 'd1' => $depth4_value ), 'acf', 1 );
$deep_log      = (string) @file_get_contents( $log3 );
ini_set( 'error_log', $previous_log );

$check( 'Tiefe 4 gekappt (Renderer): kein TIEF-4 in der Ausgabe', false === strpos( $deep_renderer, 'TIEF-4' ) );
$check( 'Tiefe 4 wird gemeldet, nicht still verschluckt', false !== strpos( $deep_log, 'überschreitet das Maximum 3' ), trim( str_replace( $log3, '', $deep_log ) ) );

// Dieselbe Grenze auf dem Frontend-Weg: dort greift der eigene Guard der Klasse.
$log4         = tempnam( sys_get_temp_dir(), 'sdc-log' );
$previous_log = ini_set( 'error_log', $log4 );
$frontend_deep = Sdc_Frontend::render_fields_probe( array( $depth4_field ), array( 'd1' => $depth4_value ), 1 );
$frontend_log  = (string) @file_get_contents( $log4 );
ini_set( 'error_log', $previous_log );

$check( 'Tiefe 4 gekappt (Frontend): kein TIEF-4 in der Ausgabe', false === strpos( $frontend_deep, 'TIEF-4' ) );
$check( 'Tiefe 4 Frontend meldet ebenfalls', false !== strpos( $frontend_log, 'überschreitet das Maximum 3' ), trim( str_replace( $log4, '', $frontend_log ) ) );

// ------------------------------------------------------- 5. Grundstruktur

echo "\n== 5. Grundstruktur ==\n";

$wellformed = function ( $markup ) {
	// Ein DOM-Parse ist die ehrlichste Prüfung: was der Parser reparieren muss,
	// war nicht wohlgeformt. Verglichen wird die Zahl der <div> vorher/nachher
	// und ob der Parser Elemente hinzufügen musste.
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?><div id="probe">' . $markup . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$errors = libxml_get_errors();
	libxml_clear_errors();
	return array( $dom, $errors );
};

list( $dom, $errors ) = $wellformed( $html );
$check( 'HTML parst ohne Parser-Fehler', empty( $errors ), count( $errors ) . ' Fehler' );

$xpath      = new DOMXPath( $dom );
$root       = $xpath->query( '//*[@id="probe"]' )->item( 0 );
$open_divs  = substr_count( $html, '<div' );
$close_divs = substr_count( $html, '</div>' );
$check( 'alle <div> geschlossen', $open_divs === $close_divs, $open_divs . ' offen / ' . $close_divs . ' geschlossen' );
// Der Probe-Wrapper <div id="probe"> ist selbst ein div; er wird abgezogen.
$check( 'DOM findet ebenso viele <div> wie das HTML', $root && $open_divs === $xpath->query( '//div', $root )->length - 1, $root ? ( $xpath->query( '//div', $root )->length - 1 ) . ' im DOM (ohne Probe-Wrapper)' : 'Root fehlt' );

$check( 'Wurzel-Wrapper sdc-page vorhanden', false !== strpos( $html, 'class="sdc-page ' ) );
$check( 'Komponente trägt Typ-Klasse', false !== strpos( $html, 'sdc-component--faq' ) );

// ---------------------------------------------------------------- aufräumen

echo "\n== Aufräumen ==\n";
wp_delete_post( $probe_id, true );
@unlink( $log );
@unlink( $log2 );
@unlink( $log3 );
@unlink( $log4 );
clean_post_cache( $probe_id );
// Gegen die DB prüfen, nicht gegen den Objekt-Cache: get_post() kann innerhalb
// derselben Anfrage noch das Objekt von vor dem Löschen halten (erster Lauf war
// hier rot, obwohl die Zeile weg war — Harness-Defekt, nicht Code).
$still_there = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $probe_id ) );
$check( 'Probe-Seite aus der DB entfernt', 0 === $still_there, $still_there . ' Zeilen' );
$check( 'Testdaten Seite 2 unverändert', 2 === count( Sdc_Store::get_data( 2 )['components'] ) );

printf( "\n%s (%d geprüft, %d Fehler)\n", 0 === $fails ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE', $checked, $fails );
exit( 0 === $fails ? 0 : 1 );

<?php
/**
 * Beweist die Zusage „ein Code, zwei Wirte" auf der Render-Ebene.
 *
 * M1 prüfte nur Speichern — dort sind beide Wirte identisch, weil wir gar
 * nicht mit ihnen reden. Der Unterschied liegt beim Rendern: ACF Free hat
 * keinen `repeater`. Hier wird gemessen, dass unser Renderer in beiden Wirten
 * dieselbe Zahl Inputs mit denselben Namen erzeugt.
 *
 * Gemessen wird die aktuelle Namensform mit Instanz-Präfix: `acf[<instance>]`.
 * Zwei Instanzen desselben Typs müssen sich in jedem Eingabenamen und in ihrer
 * Signatur unterscheiden — sonst wäre das Präfix wirkungslos und die spätere
 * Instanz würde die frühere beim Speichern überschreiben.
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

/**
 * Die eine Komponente, zweimal: gleicher Typ, unterschiedliche Instanz.
 * Jede Instanz trägt eine eigene Überschrift und drei Zeilen × zwei Felder.
 */
$instances = array(
	'c_one' => array(
		'heading' => 'Häufige Fragen',
		'items'   => array(
			array( '_id' => 'r_1', 'q' => 'Erste Frage?', 'a' => 'Erste Antwort.' ),
			array( '_id' => 'r_2', 'q' => 'Zweite Frage?', 'a' => 'Zweite Antwort.' ),
			array( '_id' => 'r_3', 'q' => 'Dritte Frage?', 'a' => 'Dritte Antwort.' ),
		),
	),
	'c_two' => array(
		'heading' => 'Weitere Fragen',
		'items'   => array(
			array( '_id' => 's_1', 'q' => 'Vierte Frage?', 'a' => 'Vierte Antwort.' ),
			array( '_id' => 's_2', 'q' => 'Fünfte Frage?', 'a' => 'Fünfte Antwort.' ),
			array( '_id' => 's_3', 'q' => 'Sechste Frage?', 'a' => 'Sechste Antwort.' ),
		),
	),
);

$results = array();

foreach ( $instances as $instance => $data ) {
	echo "== Instanz {$instance} rendern ==\n";

	// $context wird mitgemessen: er steht im Wrapper und damit in der Signatur.
	$html = Sdc_Renderer::render_component( 'faq', $data, 'editor', $instance );
	$check( 'HTML nicht leer', strlen( $html ) > 500, strlen( $html ) . ' Bytes' );

	echo "\n== Struktur ==\n";
	$check( 'Komponenten-Wrapper vorhanden', false !== strpos( $html, 'data-sdc-type="faq"' ) );
	$check( 'Instanz im Wrapper vermerkt', false !== strpos( $html, 'data-sdc-instance="' . $instance . '"' ), $instance );
	$check( 'Listen-Container vom Builder gerendert', false !== strpos( $html, 'data-sdc-container="list"' ) );
	$check( 'Zeilen-Template für JS vorhanden', false !== strpos( $html, 'data-sdc-row-template' ) );
	// 3 Zeilen + 1 Template-Zeile (für den "hinzufügen"-Knopf im Browser).
	$check( 'Zeilen-Handle für Sortieren vorhanden', 4 === substr_count( $html, 'data-sdc-drag-handle' ), substr_count( $html, 'data-sdc-drag-handle' ) . ' Handles (3 Zeilen + Template)' );

	$rows = substr_count( $html, 'data-sdc-row data-sdc-row-index' ) - 1; // minus Template
	$check( '3 Zeilen gerendert', 3 === $rows, "$rows Zeilen" );

	echo "\n== Blattfelder ==\n";
	preg_match_all( '/<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"/', $html, $m );
	$names = $m[1];
	$check( '13 Eingaben (1 heading + 3×3 Zeilenfelder + 3×1 _id)', 13 === count( $names ), count( $names ) . ' Eingaben' );

	$prefix = 'acf[' . $instance . ']';
	$check(
		'heading trägt Instanz-Präfix',
		in_array( $prefix . '[field_sdc_faq_heading]', $names, true ),
		$prefix . '[field_sdc_faq_heading]'
	);
	$check(
		'erste Zeile, erstes Feld korrekt verschachtelt',
		in_array( $prefix . '[field_sdc_faq_items][0][field_sdc_faq_item_q]', $names, true ),
		$prefix . '[field_sdc_faq_items][0][field_sdc_faq_item_q]'
	);
	$check(
		'dritte Zeile, zweites Feld korrekt verschachtelt',
		in_array( $prefix . '[field_sdc_faq_items][2][field_sdc_faq_item_a]', $names, true )
	);
	$check(
		'alle Eingaben tragen das Präfix',
		0 === count(
			array_filter(
				$names,
				function ( $name ) use ( $prefix ) {
					return 0 !== strpos( $name, $prefix . '[' );
				}
			)
		),
		'0 ohne Präfix'
	);

	echo "\n== Werte kommen an ==\n";
	$check( 'Antworttext im HTML', false !== strpos( $html, $data['items'][1]['a'] ) );
	$check( 'Umlaute im HTML', false !== strpos( $html, $data['heading'] ) );
	$check( 'textarea statt input für Antwort', 4 === substr_count( $html, '<textarea' ), substr_count( $html, '<textarea' ) . ' <textarea> (3 Zeilen + Template)' );

	echo "\n== Row-IDs durchgereicht ==\n";
	$check( 'erste Row-ID im HTML', false !== strpos( $html, $data['items'][0]['_id'] ) );
	$check( '_id-Feld je Zeile', 4 === substr_count( $html, '[_id]' ), substr_count( $html, '[_id]' ) . ' Felder (3 Zeilen + Template)' );

	$results[ $instance ] = array(
		'html'  => $html,
		'names' => $names,
		'md5'   => md5( preg_replace( '/\s+/', ' ', $html ) ),
	);

	printf( "\nSIGNATUR %s (%d Bytes, %d Eingaben)\n", $results[ $instance ]['md5'], strlen( $html ), count( $names ) );

	// Vollständige Namensliste je Instanz, sortiert: so belegt ein `sort | uniq -d`
	// in der Abnahme, dass das Präfix innerhalb einer Instanz keine Dublette erzeugt.
	// Fehlt hier ein Name, erzeugt der Lauf bereits keinen Input — dann ist ohnehin rot.
	echo "NAMEN {$instance}\n";
	$sorted = $names;
	sort( $sorted );
	echo implode( "\n", $sorted ) . "\n\n";
}

echo "== Präfix wirksam? ==\n";
$a = $results['c_one'];
$b = $results['c_two'];

$check(
	'Signaturen der beiden Instanzen unterscheiden sich',
	$a['md5'] !== $b['md5'],
	$b['md5']
);
$check(
	'kein Eingabename in beiden Instanzen gleich',
	0 === count( array_intersect( $a['names'], $b['names'] ) ),
	count( array_intersect( $a['names'], $b['names'] ) ) . ' gemeinsame Namen'
);
// Die Länge darf NICHT auf Gleichheit geprüft werden: die beiden Instanzen tragen
// verschiedene Daten (Überschrift, 3×2 Texte) und die Feld-IDs wiederholen den
// Instanznamen — die Längen unterscheiden sich also zwangsläufig. Verglichen wird
// die Gerüstlänge: HTML ohne Instanzname und ohne die Nutztexte. Weicht die ab,
// rendert der Code die Instanzen unterschiedlich — das wäre ein echter Befund.
$scrub = function ( $html, $instance, $data ) {
	$out = str_replace( $instance, 'INST', $html );
	$out = str_replace( $data['heading'], 'HEADING', $out );
	foreach ( $data['items'] as $row ) {
		$out = str_replace( array( $row['q'], $row['a'], $row['_id'] ), array( 'Q', 'A', 'ID' ), $out );
	}
	return $out;
};
$a_skel = $scrub( $a['html'], 'c_one', $instances['c_one'] );
$b_skel = $scrub( $b['html'], 'c_two', $instances['c_two'] );
$check(
	'gleiches Gerüst: Länge ohne Instanzname und Nutztexte',
	strlen( $a_skel ) === strlen( $b_skel ),
	strlen( $a_skel ) . ' vs ' . strlen( $b_skel ) . ' Bytes'
);
$check(
	'gleiches Gerüst: HTML ohne Instanzname und Nutztexte identisch',
	$a_skel === $b_skel,
	$a_skel === $b_skel ? 'identisch' : 'abweichend (erste Differenz bei Byte ' . strspn( $a_skel ^ $b_skel, "\0" ) . ')'
);
$check(
	'gleiche Eingabenzahl bei beiden Instanzen',
	count( $a['names'] ) === count( $b['names'] ),
	count( $a['names'] ) . ' vs ' . count( $b['names'] )
);

// Innerhalb einer Instanz darf kein Name doppelt vorkommen: ein doppelter Name
// heißt, dass beim Absenden eine Eingabe die andere überschreibt.
$all      = array_merge( $a['names'], $b['names'] );
$dup      = array_keys( array_filter( array_count_values( $all ), function ( $n ) { return $n > 1; } ) );
$check(
	'keine zwei Eingabenamen doppelt über beide Instanzen',
	empty( $dup ),
	empty( $dup ) ? '0 Duplikate' : implode( ', ', $dup )
);

echo "\n== Beide Instanzen zusammen ==\n";
printf( "SIGNATUR %s (%d Bytes, %d Eingaben)\n", md5( $a['html'] . $b['html'] ), strlen( $a['html'] . $b['html'] ), count( $all ) );
echo "\nNAMEN\n";
sort( $all );
echo implode( "\n", $all ) . "\n";

printf( "\n%s (%d Fehler)\n", 0 === $fails ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE', $fails );

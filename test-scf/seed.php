<?php
/** Messung 1: Seite mit den drei SDC-Komponenten füllen, Input-Zahl der Edit-Maske zählen. */

$faq = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$faq[] = array(
		'frage'   => "Frage $i: Wie funktioniert die Zusammenarbeit?",
		'antwort' => "Antwort $i: Wir starten mit einem Audit, leiten daraus Maßnahmen ab und setzen sie in Sprints um.",
	);
}

$team = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$team[] = array(
		'foto'     => '',
		'name'     => "Person $i",
		'rolle'    => 'SEO-Beraterin',
		'email'    => "person$i@example.com",
		'telefon'  => '+49 451 1234567',
		'linkedin' => "https://www.linkedin.com/in/person-$i/",
	);
}

$preise = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$preise[] = array(
		'paket'       => "Paket $i",
		'preis'       => (string) ( 490 * $i ),
		'zeitraum'    => 'monat',
		'leistungen'  => "Leistung A\nLeistung B\nLeistung C",
		'cta_text'    => 'Jetzt anfragen',
		'cta_url'     => 'https://example.com/kontakt',
		'hervorheben' => $i === 2 ? '1' : '0',
	);
}

$post_id = sdc_fixture_seed_page( 'SDC Messseite', array(
	'sdc_faq'    => $faq,
	'sdc_team'   => $team,
	'sdc_preise' => $preise,
) );

echo "Post-ID: $post_id\n";
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT meta_key, LENGTH(meta_value) AS len FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key", $post_id
), ARRAY_A );
echo "Meta-Zeilen: " . count( $rows ) . "\n";
$bytes = 0;
foreach ( $rows as $r ) { $bytes += $r['len']; }
echo "Bytes gesamt: $bytes\n\n";
foreach ( array_slice( $rows, 0, 10 ) as $r ) {
	printf( "  %-34s %5d B\n", $r['meta_key'], $r['len'] );
}
echo "  … (insgesamt " . count( $rows ) . " Zeilen)\n\n";

// Aufschlüsselung: wie viele Meta-Zeilen pro Komponente?
foreach ( array( 'sdc_faq', 'sdc_team', 'sdc_preise' ) as $f ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND (meta_key LIKE %s OR meta_key LIKE %s)",
		$post_id, $wpdb->esc_like( $f ) . '%', $wpdb->esc_like( '_' . $f ) . '%'
	) );
	printf( "%-12s %3d Meta-Zeilen\n", $f, $n );
}
echo "\nPOST_ID=$post_id\n";

// Zurücklesen und Struktur prüfen.
$back = get_field( 'sdc_team', $post_id, false );
echo "\nget_field(sdc_team) -> " . count( (array) $back ) . " Zeilen, Typ " . gettype( $back ) . "\n";
echo "Erste Zeile: " . wp_json_encode( $back[0] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";

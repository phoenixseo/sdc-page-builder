<?php
/**
 * M1 verification: proves the foundation end to end inside a real WP install.
 *
 * Run: wp eval-file tests/m1-verify.php [component-count]
 *
 * Checks, in order:
 *   1. host detection (ACF or SCF)
 *   2. component schemas load from Local JSON without the host rendering them
 *   3. 30 components save as ONE meta row within a sane time/query budget
 *   4. unknown component types are rejected instead of silently dropped
 *   5. the document survives a full read-back unchanged
 *   6. WordPress versions the document, so undo/restore is possible
 *
 * @package SdcPageBuilder
 */

$count = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 30;
$fails = 0;

/**
 * Minimal assertion helper.
 *
 * @param string $label What is being checked.
 * @param bool   $ok    Result.
 * @param string $note  Detail shown either way.
 */
$check = function ( $label, $ok, $note = '' ) use ( &$fails ) {
	if ( ! $ok ) {
		$fails++;
	}
	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $note ? " — $note" : '' );
};

echo "== 1. Host ==\n";
$check( 'Feld-Plugin erkannt', Sdc_Field_Adapter::is_available(), Sdc_Field_Adapter::host() . ' ' . Sdc_Field_Adapter::host_version() );

echo "\n== 2. Schemas ==\n";
$components = Sdc_Schema::components();
$check( 'mindestens eine Komponente gefunden', count( $components ) > 0, implode( ', ', array_keys( $components ) ) );

$faq = Sdc_Schema::get( 'faq' );
$check( 'FAQ-Komponente vorhanden', null !== $faq );
if ( $faq ) {
	$check( 'FAQ hat 2 Felder', 2 === count( $faq['fields'] ), count( $faq['fields'] ) . ' Felder' );
	$check( 'Schema-Version lesbar', 'faq@1' === Sdc_Schema::version_string( 'faq' ), Sdc_Schema::version_string( 'faq' ) );
}

// The component group must not show up as a normal metabox anywhere.
$visible = 0;
foreach ( Sdc_Field_Adapter::get_field_groups() as $group ) {
	if ( 0 === strpos( (string) $group['key'], Sdc_Schema::KEY_PREFIX ) && ! empty( $group['location'] ) ) {
		foreach ( $group['location'] as $ruleset ) {
			foreach ( $ruleset as $rule ) {
				if ( 'sdc_component' !== $rule['param'] ) {
					$visible++;
				}
			}
		}
	}
}
$check( 'Komponenten-Gruppen sind nirgends sichtbar', 0 === $visible, "$visible fremde Location-Regeln" );

echo "\n== 3. Speichern von $count Komponenten ==\n";
$post_id = wp_insert_post(
	array(
		'post_title'  => 'SDC M1 Verify ' . gmdate( 'His' ),
		'post_status' => 'draft',
		'post_type'   => 'page',
	)
);
$check( 'Testseite angelegt', is_int( $post_id ) && $post_id > 0, "post $post_id" );

$payload = array();
for ( $i = 0; $i < $count; $i++ ) {
	$payload[] = array(
		'type' => 'faq',
		'data' => array(
			'heading' => "Häufige Fragen $i",
			'items'   => array(
				array( 'q' => "Frage $i.1", 'a' => 'Antwort mit Umlauten: äöüß' ),
				array( 'q' => "Frage $i.2", 'a' => str_repeat( 'Antworttext. ', 10 ) ),
			),
		),
	);
}

global $wpdb;
$before_q = $wpdb->num_queries;
$t0       = microtime( true );
$saved    = Sdc_Store::save_data( $post_id, array( 'components' => $payload ) );
$ms       = ( microtime( true ) - $t0 ) * 1000;
$queries  = $wpdb->num_queries - $before_q;

$check( 'alle Komponenten gespeichert', count( $saved['components'] ) === $count, count( $saved['components'] ) . "/$count" );
$check( 'jede Komponente hat eine _id', count( array_unique( array_column( $saved['components'], '_id' ) ) ) === $count );
$check( 'Save unter 100 ms', $ms < 100, sprintf( '%.1f ms / %d Queries', $ms, $queries ) );

$meta_rows = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Sdc_Store::META_KEY )
);
$all_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );
$bytes    = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT LENGTH(meta_value) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Sdc_Store::META_KEY )
);
$check( 'genau EINE Meta-Zeile für das Dokument', 1 === $meta_rows, "$meta_rows Zeile(n), $all_rows postmeta-Zeilen gesamt, $bytes Bytes" );

echo "\n== 4. Unbekannter Typ wird abgewiesen ==\n";
$rejected = false;
try {
	$req = new WP_REST_Request( 'POST', '/sdc-pb/v1/pages/' . $post_id );
	$req->set_param( 'components', array( array( 'type' => 'gibt_es_nicht', 'data' => array() ) ) );
	$res = Sdc_Rest::save_page( $req );
	$rejected = is_wp_error( $res ) && 'sdc_unknown_component' === $res->get_error_code();
} catch ( Throwable $e ) {
	$rejected = false;
}
$check( 'REST lehnt unbekannten Typ ab', $rejected );

echo "\n== 5. Roundtrip ==\n";
$back = Sdc_Store::get_data( $post_id );
$check( 'gleiche Anzahl nach dem Lesen', count( $back['components'] ) === $count );
$check( 'IDs stabil', array_column( $back['components'], '_id' ) === array_column( $saved['components'], '_id' ) );
$check(
	'Nutzdaten unverändert',
	$back['components'][0]['data']['items'][0]['a'] === 'Antwort mit Umlauten: äöüß',
	$back['components'][0]['data']['items'][0]['a']
);
$check( 'Formatversion gesetzt', Sdc_Store::FORMAT_VERSION === $back['v'], 'v=' . $back['v'] );

echo "\n== 6. Revisionen ==\n";
$rev_id = wp_save_post_revision( $post_id );
$check( 'Revision angelegt', is_int( $rev_id ) && $rev_id > 0, "revision $rev_id" );
$rev_meta = $rev_id ? get_post_meta( $rev_id, Sdc_Store::META_KEY, true ) : null;
$check( 'Revision enthält das Dokument', ! empty( $rev_meta ) && is_array( $rev_meta ) );
$keys = apply_filters( 'wp_post_revision_meta_keys', array(), 'page' );
$check( 'Meta-Key ist für Revisionen registriert', in_array( Sdc_Store::META_KEY, (array) $keys, true ) );

echo "\n== 7. REST-Katalog ==\n";
$res  = Sdc_Rest::get_components();
$data = $res->get_data();
$check( 'Katalog liefert Komponenten', ! empty( $data['components'] ), count( $data['components'] ) . ' Komponenten, host=' . $data['host'] );

// Clean up.
wp_delete_post( $post_id, true );

printf( "\n%s (%d Fehler)\n", 0 === $fails ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE', $fails );
if ( $fails > 0 ) {
	exit( 1 );
}

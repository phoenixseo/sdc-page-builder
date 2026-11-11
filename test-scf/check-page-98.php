<?php
/**
 * Report page 98's block composition and the state of the migration.
 *
 * Run inside the wp-env CLI container:
 *   wp --path=/var/www/html eval-file /var/www/html/wp-content/plugins/test-scf/check-page-98.php
 */

$id = 98;
$post = get_post( $id );

if ( ! $post ) {
	echo "Seite {$id} nicht vorhanden.\n";
	exit( 1 );
}

echo "Titel: {$post->post_title} ({$post->post_type})\n";
echo 'Markiert: ' . ( Sdc_Migration::is_migrated( $id ) ? 'ja' : 'nein' ) . "\n";

$before = array();
preg_match_all( '/wp:sdc\/([a-z0-9_-]+)/', (string) $post->post_content, $before );
echo 'Blöcke vorher: ' . implode( ',', $before[1] ) . "\n";

$changed = Sdc_Migration::migrate_post( $id, true );

$after = array();
preg_match_all( '/wp:sdc\/([a-z0-9_-]+)/', (string) get_post_field( 'post_content', $id, 'raw' ), $after );
echo 'Blöcke nachher: ' . implode( ',', $after[1] ) . "\n";
echo 'Schreibzugriff: ' . ( $changed ? 'ja' : 'nein' ) . "\n";

$document = Sdc_Store::get_data( $id );
$types    = array();
if ( isset( $document['components'] ) && is_array( $document['components'] ) ) {
	foreach ( $document['components'] as $component ) {
		$types[] = isset( $component['type'] ) ? $component['type'] : '?';
	}
}
echo 'Dokument: ' . implode( ',', $types ) . "\n";

$counts = array_count_values( $after[1] );
$doc    = array_count_values( $types );
ksort( $counts );
ksort( $doc );
echo 'Soll: ' . wp_json_encode( $doc ) . ' Ist: ' . wp_json_encode( $counts ) . "\n";
echo ( $doc === $counts ? "OK: Dokument und Inhalt deckungsgleich\n" : "FEHLT: Abweichung\n" );

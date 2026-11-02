<?php
/**
 * Messung 2: Builder-Seite mit N Flexible-Content-Komponenten füllen.
 * Aufruf: wp eval-file /tmp/seed-builder.php <anzahl_komponenten>
 */

$count = isset( $args[0] ) ? (int) $args[0] : 12;

$komponenten = array( 'faq', 'team', 'preise', 'feature' );
$zeilen      = array( 'faq' => 5, 'team' => 6, 'preise' => 3, 'feature' => 5 );

$rows = array();
for ( $i = 0; $i < $count; $i++ ) {
	$slug = $komponenten[ $i % count( $komponenten ) ];
	$n    = $zeilen[ $slug ];
	$zeil = array();

	for ( $r = 1; $r <= $n; $r++ ) {
		$zeil[] = sdc_fixture_row( $slug, $r );
	}

	$rows[] = array(
		'acf_fc_layout' => $slug,
		'zeilen'        => $zeil,
	);
}

$post_id = wp_insert_post( array(
	'post_title'  => "SDC Builder $count Komponenten",
	'post_status' => 'publish',
	'post_type'   => 'page',
) );
update_field( 'sdc_builder', $rows, $post_id );

// Wie viele Subfeld-Werte stecken drin?
$subfelder = array( 'faq' => 2, 'team' => 6, 'preise' => 7, 'feature' => 12 );
$werte     = 0;
for ( $i = 0; $i < $count; $i++ ) {
	$slug   = $komponenten[ $i % count( $komponenten ) ];
	$werte += $zeilen[ $slug ] * $subfelder[ $slug ];
}

global $wpdb;
$meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d", $post_id ) );
$bytes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(LENGTH(meta_value)) FROM {$wpdb->postmeta} WHERE post_id=%d", $post_id ) );

echo "Post-ID:        $post_id\n";
echo "Komponenten:    $count\n";
echo "Subfeld-Werte:  $werte\n";
echo "Meta-Zeilen:    $meta\n";
echo "Bytes:          $bytes\n";
echo "EDIT_URL:       " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";

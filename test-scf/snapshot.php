<?php
/**
 * Snapshot auf Meta-Ebene: zählt Meta-Zeilen, Bytes und belegte Zeilenindizes.
 * Unabhängig von der ACF-API — zeigt, was wirklich in der DB steht.
 */
$pid = isset( $args[0] ) ? (int) $args[0] : 8;

global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",
	$pid
), ARRAY_A );

$bytes   = 0;
$indices = array();
$filled  = 0;

foreach ( $rows as $r ) {
	$bytes += strlen( $r['meta_value'] );
	if ( '' !== $r['meta_value'] ) {
		$filled++;
	}
	// Zeilenindizes aus Schlüsseln wie _sdc_builder_0_zeilen_3_feld_1
	if ( preg_match( '/^_?sdc_builder_(\d+)_zeilen_(\d+)_/', $r['meta_key'], $m ) ) {
		$indices[ $m[1] ][ $m[2] ] = true;
	}
}

$komponenten = array();
foreach ( $indices as $ci => $zi ) {
	$komponenten[ $ci ] = count( $zi );
}

$out = array(
	'meta_zeilen'   => count( $rows ),
	'nicht_leer'    => $filled,
	'bytes'         => $bytes,
	'komponenten'   => count( $komponenten ),
	'zeilen_je_kom' => array_values( $komponenten ),
);

echo 'SNAPSHOT ' . wp_json_encode( $out ) . "\n";

// Die letzten 5 Meta-Keys — zeigt, wo PHP abgeschnitten hat.
echo "LETZTE-KEYS " . implode( ',', array_slice( array_column( $rows, 'meta_key' ), -5 ) ) . "\n";

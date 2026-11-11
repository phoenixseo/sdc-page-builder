<?php
/**
 * K5-Probe: erscheint die Logzeile für einen Typ ohne Ansicht?
 *
 * Läuft in der Dev-Instanz über `wp eval-file`. Legt eine eigene Wegwerf-Seite
 * an und räumt sie wieder ab — Seite 98 und die übrigen Bestandsseiten werden
 * nicht angefasst.
 *
 * @package Sdc_Page_Builder
 */

$log = ini_get( 'error_log' );
if ( ! $log ) {
	$log = sys_get_temp_dir() . '/sdc-k5-probe.log';
	ini_set( 'error_log', $log );
}

$before = is_readable( $log ) ? (string) file_get_contents( $log ) : '';

$page_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => 'SDC-K5-PROBE',
	)
);

if ( ! $page_id || is_wp_error( $page_id ) ) {
	echo "Seite konnte nicht angelegt werden.\n";
	return;
}

$unknown = 'k5-gone-' . substr( md5( (string) microtime( true ) ), 0, 6 );

Sdc_Store::save_data(
	$page_id,
	array(
		'v'          => 1,
		'components' => array(
			array(
				'type' => $unknown,
				'data' => array( 'heading' => 'weg' ),
			),
			array(
				'type' => $unknown,
				'data' => array( 'heading' => 'nochmal weg' ),
			),
			array(
				'type' => 'faq',
				'data' => array( 'heading' => 'bleibt' ),
			),
		),
	)
);

wp_set_current_user( 0 );
$html = Sdc_Frontend::render_post( $page_id );

echo "Seite: {$page_id}\n";
echo 'Testtyp: ' . $unknown . "\n";
echo 'has_view(unbekannt): ' . ( Sdc_Frontend::has_view( $unknown ) ? 'true' : 'false' ) . "\n";
echo 'Marker im Besucher-HTML: ' . ( false === strpos( (string) $html, '<!-- sdc:' ) ? 'nein' : 'JA' ) . "\n";

$editor_id = 0;
foreach ( get_users( array( 'role' => 'administrator', 'number' => 1 ) ) as $user ) {
	$editor_id = (int) $user->ID;
}
if ( $editor_id ) {
	wp_set_current_user( $editor_id );
	$editor_html = Sdc_Frontend::render_post( $page_id );
	echo 'Marker im Redakteurs-HTML: ' . ( false === strpos( (string) $editor_html, '<!-- sdc:' ) ? 'nein' : 'ja' ) . "\n";
	echo '  ' . trim( (string) preg_replace( '/\s+/', ' ', (string) $editor_html ) ) . "\n";
}

$after = is_readable( $log ) ? (string) file_get_contents( $log ) : '';
$delta = substr( $after, strlen( $before ) );

$lines = array();
foreach ( explode( "\n", $delta ) as $line ) {
	if ( false !== strpos( $line, $unknown ) ) {
		$lines[] = trim( $line );
	}
}

echo 'Neue Logzeilen für den Testtyp: ' . count( $lines ) . "\n";
foreach ( $lines as $line ) {
	echo "  LOG: {$line}\n";
}

echo "logdatei: {$log}\n";

wp_delete_post( $page_id, true );
echo "Wegwerf-Seite wieder gelöscht.\n";

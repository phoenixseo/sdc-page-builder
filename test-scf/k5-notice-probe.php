<?php
/**
 * K5-Probe zum Admin-Hinweis: zählt die Seitenliste die betroffene Seite?
 *
 * Legt eine Wegwerf-Seite mit einem unbekannten Typ an, fragt den Zähler und
 * räumt wieder ab. Die Bestandsseiten bleiben unverändert.
 *
 * @package Sdc_Page_Builder
 */

delete_transient( Sdc_Admin::BROKEN_TRANSIENT );

$page_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => 'SDC-K5-NOTICE-PROBE',
	)
);

if ( ! $page_id || is_wp_error( $page_id ) ) {
	echo "Seite konnte nicht angelegt werden.\n";
	return;
}

Sdc_Store::save_data(
	$page_id,
	array(
		'v'          => 1,
		'components' => array(
			array(
				'type' => 'k5-gone-notice',
				'data' => array(),
			),
		),
	)
);

$count = Sdc_Admin::count_broken_pages();
echo "Betroffene Seiten: {$count}\n";
echo 'Transient: ' . var_export( get_transient( Sdc_Admin::BROKEN_TRANSIENT ), true ) . "\n";

$admin = 0;
foreach ( get_users( array( 'role' => 'administrator', 'number' => 1 ) ) as $user ) {
	$admin = (int) $user->ID;
}
wp_set_current_user( $admin );
set_current_screen( 'edit-page' );

ob_start();
Sdc_Admin::render_broken_notice();
$notice = trim( (string) ob_get_clean() );

echo 'Hinweis: ' . ( '' === $notice ? '(leer)' : $notice ) . "\n";

wp_delete_post( $page_id, true );
delete_transient( Sdc_Admin::BROKEN_TRANSIENT );
echo "Wegwerf-Seite gelöscht, Transient geleert.\n";

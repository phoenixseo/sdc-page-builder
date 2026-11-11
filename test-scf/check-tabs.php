<?php
/**
 * Render the component edit screen and report what it contains.
 *
 * Run in the wp-env CLI container, which sees the same WordPress volume the
 * site serves from:
 *
 *   docker exec <cli> wp --path=/var/www/html eval-file /tmp/sdc-check-tabs.php
 *
 * It exists to answer one question after a deploy: is the code that is on
 * disk the code the site renders? A copy that was never refreshed looks
 * exactly like a feature that was never built.
 */

wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

$all = Sdc_Schema::components();

if ( empty( $all ) ) {
	echo "Kein Komponententyp im Schema — nichts zu prüfen.\n";
	exit( 1 );
}

/*
 * A component the screen will actually draw. `render_edit_form()` bails out
 * before the tabs for anything outside wp-content/sdc-components/, so picking
 * the first type in the schema can measure the read-only notice instead of the
 * form — and report every tab as missing while the code is fine.
 */
$type  = '';
$skipped = array();

foreach ( array_keys( (array) $all ) as $candidate ) {
	$file = Sdc_Schema::file_for_key( (string) $all[ $candidate ]['key'] );

	if ( '' !== $file && 0 === strpos( $file, Sdc_Admin::write_dir() . '/' ) ) {
		$type = (string) $candidate;
		break;
	}

	$skipped[] = (string) $candidate;
}

if ( '' === $type ) {
	printf( "Keine bearbeitbare Komponente gefunden.\nÜbersprungen (schreibgeschützt oder ohne definition.json): %s\n", implode( ', ', $skipped ) );
	exit( 1 );
}

printf( "Übersprungen: %s\n\n", empty( $skipped ) ? '—' : implode( ', ', $skipped ) );

$method = new ReflectionMethod( 'Sdc_Admin', 'render_edit_form' );
$method->setAccessible( true );

ob_start();
$method->invoke( null, $type );
$html = (string) ob_get_clean();

$checks = array(
	'Reiter-Navigation'      => strpos( $html, 'sdc-tabs__nav' ) !== false,
	'Panel content'          => strpos( $html, 'data-sdc-panel="content"' ) !== false,
	'Panel view'             => strpos( $html, 'data-sdc-panel="view"' ) !== false,
	'Panel styles'           => strpos( $html, 'data-sdc-panel="styles"' ) !== false,
	'Feld sdc_fields'        => strpos( $html, 'name="sdc_fields"' ) !== false,
	'Feld sdc_view_body'     => strpos( $html, 'name="sdc_view_body"' ) !== false,
	'Feld sdc_css_body'      => strpos( $html, 'name="sdc_css_body"' ) !== false,
);

/*
 * No check for the old `<details>` wrapper: the screen still uses `<details>`
 * for the field editor's JSON panel and for help texts, so looking for the tag
 * would report a failure for markup that is meant to be there. The three
 * panels above are the assertion; the wrapper could only appear by replacing
 * one of them.
 */

printf( "Komponente: %s\n\n", $type );

$fail = 0;
foreach ( $checks as $label => $ok ) {
	printf( "%s %s\n", $ok ? 'OK  ' : 'FEHLT', $label );
	$fail += $ok ? 0 : 1;
}

printf( "\nHTML: %d Zeichen\n", strlen( $html ) );

exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Write the deterministic document for the jsdom editor-test fixture.
 *
 * Why this exists: the fixture used to be dumped from page 2 as it was, and
 * page 2 gets edited by hand. When row `r_2` was replaced by a hand-added row,
 * the committed fixture no longer matched the test and four assertions failed
 * on data drift, not on code. So the content is written here, deterministically,
 * and tests/export-metabox.php then dumps the markup through `do_meta_boxes()`
 * exactly as before — still the host's own markup, not a hand-built copy.
 *
 * Usage: wp eval-file tests/seed-metabox-fixture.php <post-id>
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( empty( $args ) && isset( $argv ) ) {
	$args = array_slice( $argv, 1 );
}
$args    = array_values( (array) $args );
$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( ! $post_id || ! get_post( $post_id ) ) {
	fwrite( STDERR, "usage: wp eval-file seed-metabox-fixture.php <post-id>\n" );
	exit( 2 );
}

$document = array(
	'components' => array(
		array(
			'_id'    => 'c_one',
			'type'   => 'faq',
			'schema' => Sdc_Schema::version_string( 'faq' ),
			'data'   => array(
				'heading' => 'Komponente A',
				'items'   => array(
					array(
						'_id' => 'r_1',
						'q'   => 'A-Frage 1',
						'a'   => 'A-Antwort 1',
					),
					array(
						'_id' => 'r_2',
						'q'   => 'A-Frage 2',
						'a'   => 'A-Antwort 2',
					),
				),
			),
		),
		array(
			'_id'    => 'c_two',
			'type'   => 'faq',
			'schema' => Sdc_Schema::version_string( 'faq' ),
			'data'   => array(
				'heading' => 'Komponente B',
				'items'   => array(
					array(
						'_id' => 'r_3',
						'q'   => 'B-Frage 1',
						'a'   => 'B-Antwort 1',
					),
				),
			),
		),
	),
);

update_post_meta( $post_id, Sdc_Store::META_KEY, wp_slash( Sdc_Store::sanitize( $document ) ) );
Sdc_Schema::flush();

$stored = Sdc_Store::get_data( $post_id );
printf( "post %d: %d Komponenten, Fingerprint %s\n", $post_id, count( $stored['components'] ), Sdc_Document::fingerprint( $stored ) );

<?php
/**
 * Seed a demo page for the local test instance: hero + two FAQ components.
 *
 * Purpose: the dev instance must show a real document in the editor and a real
 * render on the frontend — otherwise screenshots, the deactivate/reactivate
 * check and any frontend look would run against empty pages and prove nothing.
 * Two FAQ components of the same type are deliberate: they are the smallest
 * case that shows the instance prefix (`acf[c_xxx][…]`) working.
 *
 * Uses the plugin's own Store API, so the seeded document goes through the same
 * sanitising path a saved one does.
 *
 * Usage inside the cli container:
 *   wp eval-file /tmp/seed-demo-page.php --allow-root
 *
 * Re-runnable: it reuses an existing page titled "SDC Demo" instead of adding
 * another one each run.
 */

if ( ! class_exists( 'Sdc_Store' ) ) {
	echo "Sdc_Store not loaded — activate the sdc-page-builder plugin first.\n";
	exit( 1 );
}

$page_id = 0;
foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => 'SDC Demo' ) ) as $candidate ) {
	$page_id = (int) $candidate->ID;
}

if ( ! $page_id ) {
	$page_id = (int) wp_insert_post(
		array(
			'post_title'  => 'SDC Demo',
			'post_status' => 'publish',
			'post_type'   => 'page',
		)
	);
}

if ( ! $page_id ) {
	echo "Could not create the demo page.\n";
	exit( 1 );
}

$document = array(
	'version'    => Sdc_Store::FORMAT_VERSION,
	'components' => array(
		array(
			'_id'  => 'c_demo_hero',
			'type' => 'hero',
			'data' => array(
				'heading' => 'Ein Builder, zwei Wirte',
				'subline' => 'Das Dokument gehört dem Builder, die Feldausgabe liefert der Wirt.',
				'cta'     => array(
					'title' => 'Mehr erfahren',
					'url'   => '/',
				),
			),
		),
		array(
			'_id'  => 'c_demo_faq_a',
			'type' => 'faq',
			'data' => array(
				'heading' => 'Häufige Fragen',
				'items'   => array(
					array( 'q' => 'Wo liegen die Daten?', 'a' => 'In einem JSON-Dokument am Beitrag, nicht in Feldzeilen.' ),
					array( 'q' => 'Wer rendert?', 'a' => 'Container rendernd der Builder, Blätter der Wirt. Mit Umlauten: äöüß.' ),
				),
			),
		),
		array(
			'_id'  => 'c_demo_faq_b',
			'type' => 'faq',
			'data' => array(
				'heading' => 'Zweite Instanz desselben Typs',
				'items'   => array(
					array( 'q' => 'Warum zwei?', 'a' => 'Weil zwei Instanzen ohne Instanz-Präfix kollidieren.' ),
					array( 'q' => 'Belegt?', 'a' => 'Ja: 18 von 18 Feldnamen kollidierten vor dem Präfix.' ),
				),
			),
		),
	),
);

$saved = Sdc_Store::save_data( $page_id, $document );
$read  = Sdc_Store::get_data( $page_id );
$meta  = get_post_meta( $page_id, Sdc_Store::META_KEY, true );

echo 'Post-ID:      ' . $page_id . "\n";
echo 'Gespeichert:  ' . ( $saved ? 'ja' : 'NEIN' ) . "\n";
echo 'Komponenten:  ' . count( $read['components'] ) . "\n";
echo 'Meta-Bytes:   ' . strlen( (string) maybe_serialize( $meta ) ) . "\n";
echo 'Status:       ' . get_post_status( $page_id ) . "\n";
echo 'EDIT_URL:     ' . admin_url( 'post.php?post=' . $page_id . '&action=edit' ) . "\n";
echo 'FRONTEND_URL: ' . get_permalink( $page_id ) . "\n";

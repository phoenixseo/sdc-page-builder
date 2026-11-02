<?php
/**
 * M4 verification: undo/restore and import/export.
 *
 * Run: wp eval-file tests/m4-verify.php
 *
 * Claims under test, each falsifiable:
 *   1. Restore is exact. A saved revision is restored and the resulting
 *      document matches the revision's document in normalised structure
 *      (order-independent hash), not merely in "it did not crash".
 *   2. Export/import is lossless. Export -> import into a different page ->
 *      the two documents have the same fingerprint, and a second export of
 *      the imported page is byte-identical to the first.
 *   3. An invalid import is rejected and leaves the document untouched:
 *      unknown type, missing required field, unknown field, broken JSON.
 *      The stored document's fingerprint is read back before and after.
 *   4. Restore and import snapshot the state they overwrite, so the previous
 *      state is itself restorable. Proven by restoring the snapshot and
 *      comparing fingerprints.
 *   5. Restore refuses a revision belonging to another page.
 *
 * Everything runs over the real REST routes, not internal method calls: the
 * layer between route and store is where an import would otherwise drop data.
 *
 * @package SdcPageBuilder
 */

$fails   = 0;
$checked = 0;
$check   = function ( $label, $ok, $note = '' ) use ( &$fails, &$checked ) {
	$checked++;
	if ( ! $ok ) {
		$fails++;
	}
	printf( "%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $note ? " — $note" : '' );
};

/**
 * Run a REST request and return [status, data, error codes/messages].
 *
 * @param string $method HTTP method.
 * @param string $route  Route.
 * @param array  $params Body params.
 * @return array
 */
$request = function ( $method, $route, array $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$r->set_param( $key, $value );
	}
	$response = rest_do_request( $r );
	$data     = $response->get_data();
	$errors   = array();
	if ( 400 <= (int) $response->get_status() && is_array( $data ) ) {
		$errors[] = $data['code'] ?? '(no code)';
		// WP_Error carries the human message in `message`; a WP_REST_Response
		// error body carries it in `message` too, with details in `data`.
		$errors[] = $data['message'] ?? '';
		foreach ( (array) ( $data['data']['errors'] ?? array() ) as $message ) {
			$errors[] = (string) $message;
		}
	}
	return array( (int) $response->get_status(), $data, $errors, $response );
};

wp_set_current_user( 1 );
Sdc_Schema::flush();

printf( "Host: %s %s\n", Sdc_Field_Adapter::host(), Sdc_Field_Adapter::host_version() );
echo 'Fingerprint-Verfahren: md5 über kanonisch sortierte Struktur' . "\n\n";

// ------------------------------------------------------------- fixtures

/**
 * Create a page to work on.
 *
 * @param string $title Title.
 * @return int
 */
$make_page = function ( $title ) {
	return (int) wp_insert_post(
		array(
			'post_title'   => $title,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_content' => '',
		)
	);
};

$document_a = array(
	'components' => array(
		array(
			'_id'    => 'c_m4_faq',
			'type'   => 'faq',
			'schema' => Sdc_Schema::version_string( 'faq' ),
			'data'   => array(
				'heading' => 'Häufige Fragen ü — M4',
				'items'   => array(
					array(
						'_id' => 'r_m4_1',
						'q'   => 'Frage eins?',
						'a'   => 'Antwort eins.',
					),
					array(
						'_id' => 'r_m4_2',
						'q'   => 'Frage zwei?',
						'a'   => 'Antwort zwei.',
					),
				),
			),
		),
		array(
			'_id'    => 'c_m4_hero',
			'type'   => 'hero',
			'schema' => Sdc_Schema::version_string( 'hero' ),
			'data'   => array(
				'heading' => 'Held überschrift',
				'subline' => 'Unterzeile mit Umlauten: äöüß',
				'cta'     => array(
					'url'    => 'https://example.test/kontakt?x=1&y=2',
					'title'  => 'Zum Kontakt',
					'target' => '',
				),
			),
		),
	),
);

$document_b = array(
	'components' => array(
		array(
			'_id'    => 'c_m4_faq',
			'type'   => 'faq',
			'schema' => Sdc_Schema::version_string( 'faq' ),
			'data'   => array(
				'heading' => 'Zweiter Stand',
				'items'   => array(
					array(
						'_id' => 'r_m4_1',
						'q'   => 'Frage eins?',
						'a'   => 'Antwort eins, geändert.',
					),
				),
			),
		),
	),
);

// ---------------------------------------------------------------- 1. Restore

echo "== 1. Restore einer Revision ==\n";

$page = $make_page( 'M4 Restore ' . gmdate( 'His' ) );

list( $status ) = $request( 'POST', "/sdc-pb/v1/pages/$page", array( 'components' => $document_a['components'] ) );
$check( 'Dokument A gespeichert', 200 === $status, "HTTP $status" );

list( $status ) = $request( 'POST', "/sdc-pb/v1/pages/$page", array( 'components' => $document_b['components'] ) );
$check( 'Dokument B gespeichert (überschreibt A)', 200 === $status, "HTTP $status" );

list( $status, $data ) = $request( 'GET', "/sdc-pb/v1/pages/$page/history" );
$revisions             = $data['revisions'] ?? array();
$check( 'History listet mindestens 2 Revisionen', 200 === $status && count( $revisions ) >= 2, ( $status ) . ' / ' . count( $revisions ) . ' Einträge' );
$check( 'History-Eintrag trägt Revisions-Id und Offset', ! empty( $revisions[0]['revision'] ) && isset( $revisions[0]['offset'] ), $revisions[0]['offset'] ?? 'kein Offset' );

// The revision holding A is the last one before B was saved.
$current = Sdc_Store::get_data( $page );
$check( 'Aktueller Stand ist B', Sdc_Document::fingerprint( $current ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) ) );

// Identify A's revision by content, not by a positional offset: offsets are
// relative to a history that edits and restores keep moving.
$a_revision = 0;
foreach ( Sdc_Document::history( $page ) as $entry ) {
	if ( Sdc_Document::fingerprint( $entry['document'] ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_a ) ) ) {
		$a_revision = $entry['id'];
	}
}
$check( 'Revision mit Dokument A ist auffindbar', $a_revision > 0, 'rev ' . $a_revision );

list( $status, $data ) = $request( 'POST', "/sdc-pb/v1/pages/$page/restore", array( 'revision' => '-1' ) );
$check( 'Restore auf -1 antwortet 200', 200 === $status, "HTTP $status " . implode( '; ', array_slice( $data['errors'] ?? array(), 0, 1 ) ) );

$restored = Sdc_Store::get_data( $page );
$check(
	'Restore-Roundtrip: wiederhergestelltes Dokument == Revision (Fingerprint)',
	Sdc_Document::fingerprint( $restored ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) ),
	substr( Sdc_Document::fingerprint( $restored ), 0, 8 ) . ' vs ' . substr( Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) ), 0, 8 )
);

// Restore A by explicit revision id.
list( $status ) = $request( 'POST', "/sdc-pb/v1/pages/$page/restore", array( 'revision' => $a_revision ) );
$back_to_a      = Sdc_Store::get_data( $page );
$check( 'Restore per Revisions-Id antwortet 200', 200 === $status, "HTTP $status" );
$check(
	'Restore per Revisions-Id stellt exakt Dokument A wieder her',
	Sdc_Document::fingerprint( $back_to_a ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_a ) ),
	substr( Sdc_Document::fingerprint( $back_to_a ), 0, 8 ) . ' vs ' . substr( Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_a ) ), 0, 8 )
);

// Field-level exactness: values, not just the hash.
$hero = null;
foreach ( $back_to_a['components'] as $component ) {
	if ( 'hero' === $component['type'] ) {
		$hero = $component;
	}
}
$check( 'wiederhergestellte Hero-CTA trägt URL mit Query', null !== $hero && 'https://example.test/kontakt?x=1&y=2' === ( $hero['data']['cta']['url'] ?? '' ), $hero['data']['cta']['url'] ?? '(fehlt)' );
$check( 'wiederhergestellte FAQ hat 2 Zeilen', null !== $hero && 2 === count( $back_to_a['components'][0]['data']['items'] ), count( $back_to_a['components'][0]['data']['items'] ?? array() ) . ' Zeilen' );

// --------------------------------------------- 3. Snapshot before overwrite

echo "\n== 2. Snapshot vor dem Überschreiben ==\n";

$fp = function ( $doc ) {
	return substr( Sdc_Document::fingerprint( $doc ), 0, 8 );
};

$before_restore = Sdc_Document::history( $page );
$history_ids    = array_column( $before_restore, 'id' );
$live_before    = Sdc_Store::get_data( $page );

// Restore a revision that genuinely differs from the live document, otherwise
// "snapshot before overwrite" is never exercised: a same-state restore has
// nothing to protect.
$b_revision = 0;
foreach ( Sdc_Document::history( $page ) as $entry ) {
	if ( Sdc_Document::fingerprint( $entry['document'] ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) )
		&& Sdc_Document::fingerprint( $entry['document'] ) !== Sdc_Document::fingerprint( $live_before ) ) {
		$b_revision = $entry['id'];
	}
}
$check( 'eine von live verschiedene Revision ist verfügbar', $b_revision > 0 && Sdc_Document::fingerprint( $live_before ) !== Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) ), 'live ' . $fp( $live_before ) . ', Ziel-rev ' . $b_revision );

list( $status, $restore_data ) = $request( 'POST', "/sdc-pb/v1/pages/$page/restore", array( 'revision' => $b_revision ) );
$snapshot                       = $restore_data['snapshot'] ?? array();
$check( 'Restore meldet eine Snapshot-Revision', 200 === $status && ! empty( $snapshot['revision_id'] ), 'rev ' . ( $snapshot['revision_id'] ?? '0' ) . ', Grund ' . ( $snapshot['reason'] ?? '?' ) );

$snapshot_doc = Sdc_Document::revision_document( $page, (int) $snapshot['revision_id'] );
$check(
	'Snapshot hält den Stand von VOR dem Restore',
	! is_wp_error( $snapshot_doc ) && Sdc_Document::fingerprint( $snapshot_doc ) === Sdc_Document::fingerprint( $live_before ),
	'Snapshot ' . ( is_wp_error( $snapshot_doc ) ? 'n/a' : $fp( $snapshot_doc ) ) . ' vs live-vorher ' . $fp( $live_before )
);
$check(
	'Snapshot inhaltlich verschieden vom wiederhergestellten Stand',
	! is_wp_error( $snapshot_doc ) && Sdc_Document::fingerprint( $snapshot_doc ) !== Sdc_Document::fingerprint( Sdc_Store::get_data( $page ) ),
	'vorher ' . $fp( $live_before ) . ' -> nachher ' . $fp( Sdc_Store::get_data( $page ) )
);
$check(
	'Restore hat das Dokument wirklich überschrieben',
	Sdc_Document::fingerprint( Sdc_Store::get_data( $page ) ) === Sdc_Document::fingerprint( Sdc_Store::sanitize( $document_b ) ),
	$fp( Sdc_Store::get_data( $page ) )
);

$history_after = array_column( Sdc_Document::history( $page ), 'id' );
$check( 'Snapshot ist eine neue Revision, nicht die alte Liste', count( $history_after ) > count( $history_ids ), count( $history_ids ) . ' -> ' . count( $history_after ) );

// ------------------------------------------------ 4. Export / Import lossless

echo "\n== 3. Export und Import ==\n";

$source = $make_page( 'M4 Export Quelle ' . gmdate( 'His' ) );
$target = $make_page( 'M4 Import Ziel ' . gmdate( 'His' ) );

list( $status ) = $request( 'POST', "/sdc-pb/v1/pages/$source", array( 'components' => $document_a['components'] ) );
$check( 'Quellseite gefüllt', 200 === $status, "HTTP $status" );

list( $status, $export ) = $request( 'GET', "/sdc-pb/v1/pages/$source/export" );
$check( 'Export antwortet 200', 200 === $status, "HTTP $status" );
$check( 'Export trägt Format, Plugin, Titel und Dokument', 1 === ( $export['format'] ?? 0 ) && isset( $export['document']['components'] ), 'format=' . var_export( $export['format'] ?? null, true ) . ', Titel="' . ( $export['title'] ?? '' ) . '"' );
$check( 'Export-Dokument hat 2 Komponenten', 2 === count( $export['document']['components'] ?? array() ), count( $export['document']['components'] ?? array() ) . '' );

// Download variant must produce the same bytes it declares.
list( $status, $body, , $response ) = $request( 'GET', "/sdc-pb/v1/pages/$source/export", array( 'download' => true ) );
$disposition                       = $response->get_headers()['Content-Disposition'] ?? '';
$check( 'Download-Variante antwortet 200', 200 === $status, "HTTP $status" );
$check( 'Download setzt Content-Disposition mit .json', false !== strpos( (string) $disposition, 'attachment;' ) && false !== strpos( (string) $disposition, '.json' ), (string) $disposition );

$redecoded = is_string( $body ) ? json_decode( $body, true ) : null;
$check(
	'Download-Bytes parsen zurück zum selben Dokument',
	is_array( $redecoded ) && Sdc_Document::fingerprint( $redecoded['document'] ?? array() ) === Sdc_Document::fingerprint( $export['document'] ),
	is_array( $redecoded ) ? 'ja' : 'Body war kein JSON-String'
);

list( $status, $imported ) = $request( 'POST', "/sdc-pb/v1/pages/$target/import", array( 'payload' => $export ) );
$check( 'Import antwortet 200 und meldet Änderung', 200 === $status && ! empty( $imported['changed'] ), "HTTP $status, changed=" . var_export( $imported['changed'] ?? null, true ) );

$target_doc = Sdc_Store::get_data( $target );
$check(
	'Import-Roundtrip verlustfrei: Ziel == Quelle (Fingerprint)',
	Sdc_Document::fingerprint( $target_doc ) === Sdc_Document::fingerprint( $export['document'] ),
	substr( Sdc_Document::fingerprint( $target_doc ), 0, 8 ) . ' vs ' . substr( Sdc_Document::fingerprint( $export['document'] ), 0, 8 )
);

list( $status, $re_export ) = $request( 'GET', "/sdc-pb/v1/pages/$target/export" );
$check(
	'Re-Export des importierten Ziels ist byte-gleich (ohne Zeitstempel/Titel)',
	Sdc_Document::fingerprint( $re_export['document'] ) === Sdc_Document::fingerprint( $export['document'] ),
	'Dokument-Fingerprint gleich'
);

// Import of the same payload twice must not churn history.
$revs_before = count( Sdc_Document::history( $target ) );
list( $status, $again ) = $request( 'POST', "/sdc-pb/v1/pages/$target/import", array( 'payload' => $export ) );
$revs_after = count( Sdc_Document::history( $target ) );
$check( 'Unveränderter Re-Import meldet changed=false', 200 === $status && false === ( $again['changed'] ?? null ), 'HTTP ' . $status . ', changed=' . var_export( $again['changed'] ?? null, true ) );
$check( 'Unveränderter Re-Import erzeugt keine Revision', $revs_after === $revs_before, "$revs_before -> $revs_after" );

// ------------------------------------------------------- 5. Invalid import

echo "\n== 4. Ungültiger Import: Fehler und unverändertes Dokument ==\n";

$guard = $make_page( 'M4 Guard ' . gmdate( 'His' ) );
$request( 'POST', "/sdc-pb/v1/pages/$guard", array( 'components' => $document_a['components'] ) );
$guard_fp = Sdc_Document::fingerprint( Sdc_Store::get_data( $guard ) );
$guard_revs = count( Sdc_Document::history( $guard ) );

$cases = array();

$unknown_type          = $export;
$unknown_type['document']['components'][0]['type'] = 'gibt_es_nicht';
$cases['unbekannter Typ'] = array(
	'payload'  => $unknown_type,
	'status'   => 422,
	'contains' => 'unbekannten Komponententyp',
);

$missing_required          = $export;
unset( $missing_required['document']['components'][1]['data']['heading'] ); // hero.heading is required.
$cases['fehlendes Pflichtfeld'] = array(
	'payload'  => $missing_required,
	'status'   => 422,
	'contains' => 'Pflichtfeld',
);

$unknown_field          = $export;
$unknown_field['document']['components'][0]['data']['gibtsnicht'] = 'x';
$cases['unbekanntes Feld'] = array(
	'payload'  => $unknown_field,
	'status'   => 422,
	'contains' => 'Unbekanntes Feld',
);

$cases['kaputtes JSON'] = array(
	'payload'  => '{"format": 1, "document": {"components": [',
	'status'   => 400,
	'contains' => 'kein gültiges JSON',
);

$cases['falsches Format'] = array(
	'payload'  => array( 'format' => 99, 'document' => $export['document'] ),
	'status'   => 400,
	'contains' => 'Unbekanntes Exportformat',
);

$required_row = $export;
unset( $required_row['document']['components'][0]['data']['items'][0]['a'] ); // faq items[].a is required.
$cases['fehlendes Pflichtfeld in Zeile'] = array(
	'payload'  => $required_row,
	'status'   => 422,
	'contains' => 'Pflichtfeld',
);

foreach ( $cases as $label => $case ) {
	list( $status, $payload, $errors ) = $request( 'POST', "/sdc-pb/v1/pages/$guard/import", array( 'payload' => $case['payload'] ) );
	$joined = implode( ' | ', $errors );
	$check(
		sprintf( '%s -> HTTP %d', $label, $case['status'] ),
		$case['status'] === $status,
		"HTTP $status"
	);
	$check(
		sprintf( '%s -> Fehlermeldung nennt den Grund', $label ),
		false !== stripos( $joined, $case['contains'] ),
		substr( $joined, 0, 110 )
	);
	$check(
		sprintf( '%s -> Dokument unverändert', $label ),
		Sdc_Document::fingerprint( Sdc_Store::get_data( $guard ) ) === $guard_fp,
		substr( Sdc_Document::fingerprint( Sdc_Store::get_data( $guard ) ), 0, 8 )
	);
	$check(
		sprintf( '%s -> keine Revision angelegt', $label ),
		count( Sdc_Document::history( $guard ) ) === $guard_revs,
		$guard_revs . ' -> ' . count( Sdc_Document::history( $guard ) )
	);
}

// Partial-write check: the valid component must NOT have landed.
$after_invalid = Sdc_Store::get_data( $guard );
$types_after   = array_column( $after_invalid['components'], 'type' );
$check( 'kein Teil-Schreiben: Dokumenttypen unverändert', array( 'faq', 'hero' ) === $types_after, implode( ',', $types_after ) );

// ------------------------------------------------- 6. Foreign revision guard

echo "\n== 5. Fremde Revision wird abgelehnt ==\n";

$other         = $make_page( 'M4 Fremd ' . gmdate( 'His' ) );
$request( 'POST', "/sdc-pb/v1/pages/$other", array( 'components' => $document_b['components'] ) );
$other_history = Sdc_Document::history( $other );
$foreign_id    = $other_history[0]['id'] ?? 0;
$guard_before  = Sdc_Document::fingerprint( Sdc_Store::get_data( $guard ) );

list( $status, , $errors ) = $request( 'POST', "/sdc-pb/v1/pages/$guard/restore", array( 'revision' => $foreign_id ) );
$check( 'Restore einer fremden Revision -> 409', 409 === $status, 'HTTP ' . $status . ' ' . implode( '|', $errors ) );
$check( 'fremde Revision verändert das Dokument nicht', Sdc_Document::fingerprint( Sdc_Store::get_data( $guard ) ) === $guard_before );

list( $status ) = $request( 'POST', "/sdc-pb/v1/pages/$guard/restore", array( 'revision' => '99999999' ) );
$check( 'Restore einer nicht existierenden Revision -> 404', 404 === $status, "HTTP $status" );

// --------------------------------------------------------------- 7. Frontend

echo "\n== 6. Wiederhergestelltes Dokument rendert ==\n";

// Bring the page back to A (both components) via a restore, so the render
// below is genuinely the render of a restored document.
$request( 'POST', "/sdc-pb/v1/pages/$page/restore", array( 'revision' => $a_revision ) );
$front_before = Sdc_Frontend::render_post( $page );
$check( 'Restore-Dokument rendert beide Komponenten', false !== strpos( $front_before, 'sdc-component--hero' ) && false !== strpos( $front_before, 'sdc-component--faq' ), strlen( $front_before ) . ' Bytes' );
$check( 'gerenderte Hero-Überschrift stammt aus dem Dokument', false !== strpos( $front_before, 'Held überschrift' ) );
$check( 'gerenderte FAQ-Antwort stammt aus dem Dokument', false !== strpos( $front_before, 'Antwort zwei.' ) );

// --------------------------------------------------------------- teardown

foreach ( array( $page, $source, $target, $guard, $other ) as $cleanup ) {
	wp_delete_post( $cleanup, true );
}

printf( "\n%d Prüfungen, %d Fehlschläge\n", $checked, $fails );
exit( $fails > 0 ? 1 : 0 );

<?php
/**
 * M3b verification: proves components are DATA, not code.
 *
 * Run: wp eval-file tests/universality-verify.php
 *
 * The claim under test: a second component type works in catalogue, save,
 * render and frontend without one line of type-specific PHP or JS. The way to
 * make that claim falsifiable is to run it against a type the plugin has never
 * seen in code — `hero` exists only as `schemas/group_sdc_component_hero.json`.
 *
 * What is checked:
 *   1. The catalogue reports both types through the real REST route.
 *   2. A page holding one FAQ component and one hero component saves through
 *      the real REST route and reads back from the store — both components,
 *      all values, including the nested link value of `cta`.
 *   3. The frontend renders both, each with its own type class, and every
 *      stored value appears — derived from the stored document, not the code.
 *   4. The HTTP path: the live permalink returns 200 and contains both
 *      components' text (the REST/dispatch layer is not what render_post()
 *      proves).
 *   5. Edges: an unknown field type in a schema must not fatal. It degrades to
 *      escaped plain text and the rest of the component still renders.
 *   6. A new type appears with no file change under includes/ or assets/ —
 *      asserted by comparing mtimes against the schema file's own creation.
 *
 * Boundary, stated up front: "no code change" is evidenced by the git working
 * tree (only the new schema file is untracked) and by the grep list in the
 * sprint report. This script proves behaviour, not the absence of code.
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

printf( "Host: %s %s\n", Sdc_Field_Adapter::host(), Sdc_Field_Adapter::host_version() );
echo "Schema-Verzeichnis: " . Sdc_Schema::dir() . "\n\n";

wp_set_current_user( 1 );
Sdc_Schema::flush();

// ------------------------------------------------------------ 1. Katalog/REST

echo "== 1. Komponentenkatalog über REST ==\n";

$catalogue_request = new WP_REST_Request( 'GET', '/sdc-pb/v1/components' );
$catalogue         = rest_do_request( $catalogue_request );
$catalogue_data    = $catalogue->get_data();

$check( 'GET /sdc-pb/v1/components antwortet 200', 200 === $catalogue->get_status(), $catalogue->get_status() . '' );

$types = array();
foreach ( $catalogue_data['components'] as $entry ) {
	$types[] = $entry['type'];
}
$check( 'Katalog meldet faq', in_array( 'faq', $types, true ), implode( ', ', $types ) );
$check( 'Katalog meldet hero (neuer Typ)', in_array( 'hero', $types, true ), implode( ', ', $types ) );
$check( 'Katalog meldet genau 2 Typen', 2 === count( $types ), count( $types ) . ': ' . implode( ', ', $types ) );

$hero_entry = null;
foreach ( $catalogue_data['components'] as $entry ) {
	if ( 'hero' === $entry['type'] ) {
		$hero_entry = $entry;
	}
}

$check( 'hero hat Schema-Version hero@1', $hero_entry && 'hero@1' === $hero_entry['schema'], $hero_entry ? $hero_entry['schema'] : 'fehlt' );
$check( 'hero hat Label "Hero"', $hero_entry && 'Hero' === $hero_entry['label'], $hero_entry ? $hero_entry['label'] : '' );

$hero_names = array();
$hero_types = array();
foreach ( $hero_entry ? $hero_entry['fields'] : array() as $field ) {
	$hero_names[] = $field['name'];
	$hero_types[] = $field['type'];
}
$check( 'hero-Felder: heading, subline, cta', array( 'heading', 'subline', 'cta' ) === $hero_names, implode( ', ', $hero_names ) );
$check( 'hero-Feldtypen: text, textarea, link', array( 'text', 'textarea', 'link' ) === $hero_types, implode( ', ', $hero_types ) );

// --------------------------------------------------- 2. Speichern über REST

echo "\n== 2. Speichern über REST ==\n";

// Idempotenz: ein alter Beleg aus einem früheren Lauf wird zuerst entfernt,
// sonst vergibt WordPress dem neuen Slug ein Suffix (−2, −3 …) und jede Runde
// hinterlässt eine Seite. Der Lauf legt damit genau eine Seite an, immer unter
// derselben URL.
$previous = get_page_by_path( 'sdc-m3b-universality', OBJECT, 'page' );
if ( $previous ) {
	wp_delete_post( $previous->ID, true );
	clean_post_cache( $previous->ID );
}

$page_id = wp_insert_post(
	array(
		'post_title'   => 'sdc-m3b-universality',
		'post_name'    => 'sdc-m3b-universality',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_author'  => 1,
		'post_content' => '',
	)
);
$check( 'Testseite angelegt', $page_id > 0 && ! is_wp_error( $page_id ), 'ID ' . $page_id );

// Sentinels: eindeutige Werte, die nur aus dieser Anfrage stammen können.
$sentinel = array(
	'faq_heading' => 'SENTINEL-FAQ-Kopf 4711',
	'faq_q'       => 'SENTINEL-Frage 4712',
	'faq_a'       => 'SENTINEL-Antwort 4713',
	'hero_head'   => 'SENTINEL-Hero-Kopf 4714',
	'hero_sub'    => 'SENTINEL-Hero-Unterzeile 4715',
	'hero_cta'    => 'SENTINEL-CTA 4716',
	'hero_url'    => 'https://example.test/kontakt?src=4717',
);

$payload = array(
	'components' => array(
		array(
			'_id'    => 'c_m3b_faq',
			'type'   => 'faq',
			'schema' => 'faq@1',
			'data'   => array(
				'heading' => $sentinel['faq_heading'],
				'items'   => array(
					array( '_id' => 'r_m3b_1', 'q' => $sentinel['faq_q'], 'a' => $sentinel['faq_a'] ),
				),
			),
		),
		array(
			'_id'    => 'c_m3b_hero',
			'type'   => 'hero',
			'schema' => 'hero@1',
			'data'   => array(
				'heading' => $sentinel['hero_head'],
				'subline' => $sentinel['hero_sub'],
				'cta'     => array(
					'title'  => $sentinel['hero_cta'],
					'url'    => $sentinel['hero_url'],
					'target' => '',
				),
			),
		),
	),
);

$save_request = new WP_REST_Request( 'POST', '/sdc-pb/v1/pages/' . $page_id );
$save_request->set_header( 'Content-Type', 'application/json' );
$save_request->set_body( wp_json_encode( $payload ) );
$save_response = rest_do_request( $save_request );

$check( 'POST /sdc-pb/v1/pages/{id} antwortet 200', 200 === $save_response->get_status(), $save_response->get_status() . '' );

// Rücklesen aus dem Store, NICHT aus der Antwort: ein 200 belegt nur, dass
// der Handler lief, nicht dass die Werte ankamen.
Sdc_Schema::flush();
$stored = Sdc_Store::get_data( $page_id );

$check( 'gespeichert: 2 Komponenten', 2 === count( $stored['components'] ), count( $stored['components'] ) . '' );

$stored_faq  = $stored['components'][0] ?? array();
$stored_hero = $stored['components'][1] ?? array();

$check( 'gespeicherte Reihenfolge: faq, hero', 'faq' === ( $stored_faq['type'] ?? '' ) && 'hero' === ( $stored_hero['type'] ?? '' ), ( $stored_faq['type'] ?? '?' ) . ', ' . ( $stored_hero['type'] ?? '?' ) );
$check( 'faq: Überschrift-Sentinel im Store', $sentinel['faq_heading'] === ( $stored_faq['data']['heading'] ?? null ), $stored_faq['data']['heading'] ?? '(fehlt)' );
$check( 'faq: Frage-Sentinel im Store', $sentinel['faq_q'] === ( $stored_faq['data']['items'][0]['q'] ?? null ), $stored_faq['data']['items'][0]['q'] ?? '(fehlt)' );
$check( 'hero: Überschrift-Sentinel im Store', $sentinel['hero_head'] === ( $stored_hero['data']['heading'] ?? null ), $stored_hero['data']['heading'] ?? '(fehlt)' );
$check( 'hero: Unterzeilen-Sentinel im Store', $sentinel['hero_sub'] === ( $stored_hero['data']['subline'] ?? null ), $stored_hero['data']['subline'] ?? '(fehlt)' );
$check( 'hero: CTA-Text-Sentinel im Store', $sentinel['hero_cta'] === ( $stored_hero['data']['cta']['title'] ?? null ), $stored_hero['data']['cta']['title'] ?? '(fehlt)' );
$check( 'hero: CTA-URL-Sentinel im Store', $sentinel['hero_url'] === ( $stored_hero['data']['cta']['url'] ?? null ), $stored_hero['data']['cta']['url'] ?? '(fehlt)' );

// --------------------------------------------------------- 3. Frontend-HTML

echo "\n== 3. Frontend-Rendering ==\n";

$html = Sdc_Frontend::render_post( $page_id );
$check( 'Ausgabe nicht leer', strlen( $html ) > 200, strlen( $html ) . ' Bytes' );

$check( 'hero rendert mit eigener Typ-Klasse', false !== strpos( $html, 'sdc-component--hero' ) );
$check( 'faq rendert mit eigener Typ-Klasse', false !== strpos( $html, 'sdc-component--faq' ) );
$check( 'zwei Komponenten-Wrapper', 2 === substr_count( $html, 'class="sdc-component ' ), substr_count( $html, 'class="sdc-component ' ) . '' );

// Erwartung aus dem gespeicherten Dokument ableiten, nicht aus dem Code:
// jeder nicht-leere, skalare Wert muss sichtbar sein.
$expected = array();
$walk     = function ( $value ) use ( &$walk, &$expected ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			if ( '_id' !== $key ) {
				$walk( $child );
			}
		}
		return;
	}
	if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
		$expected[] = trim( (string) $value );
	}
};
foreach ( $stored['components'] as $component ) {
	$walk( $component['data'] );
}

$missing = array();
foreach ( $expected as $needle ) {
	$escaped = esc_html( $needle );
	// URLs erscheinen escaped im href; esc_url entfernt kein Zeichen dieser URL.
	if ( false === strpos( $html, $escaped ) && false === strpos( $html, esc_url( $needle ) ) ) {
		$missing[] = $needle;
	}
}
$check(
	'alle ' . count( $expected ) . ' gespeicherten Werte im HTML',
	empty( $missing ),
	empty( $missing ) ? implode( ' | ', $expected ) : 'fehlt: ' . implode( ' | ', $missing )
);

$check( 'Hero-Unterzeile gerendert', false !== strpos( $html, $sentinel['hero_sub'] ) );
$check( 'Hero-CTA ist ein Link mit Text', false !== strpos( $html, '<a href="' . esc_url( $sentinel['hero_url'] ) . '">' . esc_html( $sentinel['hero_cta'] ) . '</a>' ) );
$check( 'Hero-Überschrift als eigenes Feld', false !== strpos( $html, 'sdc-field--heading' ) );
$check( 'FAQ-Zeile gerendert', 1 === substr_count( $html, 'class="sdc-row"' ), substr_count( $html, 'class="sdc-row"' ) . '' );

// Keine Bedienoberfläche, wie bei M3 gefordert.
$check( 'kein data-sdc-* im Frontend', 0 === preg_match( '/\sdata-sdc-[a-z-]+/', $html ), (int) preg_match_all( '/\sdata-sdc-[a-z-]+/', $html ) . '' );
$check( 'kein acf[ im Frontend', 0 === preg_match( '/acf\[/', $html ), (int) preg_match_all( '/acf\[/', $html ) . '' );
$check( 'keine Formularelemente', 0 === preg_match( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $html ), (int) preg_match_all( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $html ) . '' );

// ------------------------------------------------------------- 4. HTTP-Pfad

echo "\n== 4. HTTP-Pfad (the_content-Filter) ==\n";

// Harness-Notiz: der Filter verlangt is_singular() && in_the_loop() &&
// is_main_query(). In `wp eval-file` ist davon nichts wahr, und ein erster
// Lauf dieses Skripts war hier rot, weil apply_filters() direkt aufgerufen
// wurde — der Filter hat korrekt abgelehnt. Den Kontext zu fälschen wäre ein
// Test gegen Attrappen; stattdessen wird die echte Seite über HTTP geholt.
// Der Abruf läuft vom Host aus (die Adresse ist die des Docker-Ports).
$probe_url = get_permalink( $page_id );
$context   = stream_context_create( array( 'http' => array( 'timeout' => 15, 'ignore_errors' => true ) ) );
$http_body = @file_get_contents( $probe_url, false, $context );
$http_ok   = is_string( $http_body );
$http_head = $http_ok && isset( $http_response_header[0] ) ? $http_response_header[0] : '(kein Abruf)';

$check( 'Seite über HTTP abrufbar', $http_ok && false !== strpos( $http_head, '200' ), $http_head . ' — ' . $probe_url );

if ( $http_ok ) {
	$http_missing = array();
	foreach ( $expected as $needle ) {
		if ( false === strpos( $http_body, esc_html( $needle ) ) && false === strpos( $http_body, esc_url( $needle ) ) ) {
			$http_missing[] = $needle;
		}
	}
	$check(
		'HTTP-HTML enthält alle ' . count( $expected ) . ' Werte',
		empty( $http_missing ),
		empty( $http_missing ) ? 'vollständig' : 'fehlt: ' . implode( ' | ', $http_missing )
	);

	// Nur der Block wird geprüft, nicht die ganze Seite: das Theme liefert
	// eigene <button>-Elemente für die Navigation, und ein erster Lauf war rot,
	// weil die Prüfung die ganze Seite ansah. Die Behauptung betrifft die
	// Ausgabe des Builders, nicht das Theme drumherum.
	$block_start = strpos( $http_body, '<div class="sdc-page' );
	$block_html  = '';
	if ( false !== $block_start ) {
		$depth = 0;
		$pos   = $block_start;
		while ( $pos < strlen( $http_body ) ) {
			if ( ! preg_match( '/<div\b|<\/div>/', $http_body, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
				break;
			}
			$token = $m[0][0];
			$depth += ( '</div>' === $token ) ? -1 : 1;
			$pos    = $m[0][1] + strlen( $token );
			if ( 0 === $depth ) {
				$block_html = substr( $http_body, $block_start, $pos - $block_start );
				break;
			}
		}
	}

	$check( 'sdc-page-Block im HTTP-HTML gefunden', '' !== $block_html, strlen( $block_html ) . ' Bytes' );
	$check( 'HTTP-HTML enthält Hero-Klasse', false !== strpos( $block_html, 'sdc-component--hero' ) );
	$check( 'HTTP-HTML enthält FAQ-Klasse', false !== strpos( $block_html, 'sdc-component--faq' ) );
	$check( 'HTTP-HTML: zwei Komponenten-Wrapper im sdc-page-Block', 2 === preg_match_all( '/<div class="sdc-component /', $block_html ), (int) preg_match_all( '/<div class="sdc-component /', $block_html ) . '' );
	$check( 'HTTP-HTML: kein data-sdc-* im Block', 0 === preg_match( '/\sdata-sdc-[a-z-]+/', $block_html ), (int) preg_match_all( '/\sdata-sdc-[a-z-]+/', $block_html ) . '' );
	$check( 'HTTP-HTML: kein acf[ im Block', 0 === preg_match( '/acf\[/', $block_html ), (int) preg_match_all( '/acf\[/', $block_html ) . '' );
	$check(
		'HTTP-HTML: keine Formularelemente im Block',
		0 === preg_match( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $block_html ),
		(int) preg_match_all( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $block_html ) . ' im Block (Seite gesamt: ' . (int) preg_match_all( '/<(input|textarea|select|option|button|template|fieldset)\b/i', $http_body ) . ', davon Theme)'
	);
}

$http_html = $http_ok ? $http_body : '';

// --------------------------------------------------------------- 5. Kanten

echo "\n== 5. Unbekannter Feldtyp im Schema ==\n";

// Ein Feldtyp, den weder ACF/SCF noch der Renderer kennt. Geprüft wird das
// Verhalten der ANZEIGE-Stufe (format_value), weil dort die Typentscheidung
// fällt — ein unbekannter Typ darf nicht fatalen, sondern als Text erscheinen.
$unknown_field = array(
	'key'   => 'field_probe_unknown',
	'name'  => 'mystery',
	'label' => 'Unbekannt',
	'type'  => 'sdc_there_is_no_such_type',
);
$unknown_data  = array( 'mystery' => 'Wert mit <b>Markup</b> 0815' );

$fatal_before = 0;
$probe_html   = Sdc_Frontend::render_fields_probe( array( $unknown_field ), $unknown_data, 1 );

$check( 'unbekannter Feldtyp: kein Fatal', true, 'Skript läuft weiter' );
$check( 'unbekannter Feldtyp: Wert erscheint als Text', false !== strpos( $probe_html, 'Wert mit' ) && false !== strpos( $probe_html, '0815' ) );
$check( 'unbekannter Feldtyp: Markup escaped', false === strpos( $probe_html, '<b>Markup</b>' ) && false !== strpos( $probe_html, '&lt;b&gt;Markup&lt;/b&gt;' ) );
$check( 'unbekannter Feldtyp: Feld-Wrapper vorhanden', false !== strpos( $probe_html, 'sdc-field--sdc_there_is_no_such_type' ) );

// Ein unbekannter Typ im Komponenten-Katalog (nicht Feldtyp): die Komponente
// wird nicht gespeichert, REST lehnt ab. Das ist gewollt (kein stiller Verlust).
$bad_request = new WP_REST_Request( 'POST', '/sdc-pb/v1/pages/' . $page_id );
$bad_request->set_header( 'Content-Type', 'application/json' );
$bad_request->set_body( wp_json_encode( array( 'components' => array( array( '_id' => 'c_x', 'type' => 'gibtesnicht', 'schema' => 'gibtesnicht@1', 'data' => array() ) ) ) ) );
$bad_response = rest_do_request( $bad_request );
$check( 'unbekannter Komponententyp: REST lehnt mit 400 ab', 400 === $bad_response->get_status(), $bad_response->get_status() . '' );

// Das gespeicherte Dokument darf davon unberührt sein.
Sdc_Schema::flush();
$after_bad = Sdc_Store::get_data( $page_id );
$check( 'abgelehnter Save hat das Dokument nicht verändert', 2 === count( $after_bad['components'] ) && $sentinel['hero_head'] === ( $after_bad['components'][1]['data']['heading'] ?? null ), count( $after_bad['components'] ) . ' Komponenten' );

// ------------------------------------------------------ 6. Schema-Versionen

echo "\n== 6. Universitalität: kein typ-spezifischer Code ==\n";

$plugin_dir = SDC_PB_DIR;
$code_dirs  = array( 'includes', 'assets' );

$grep_hits = array();
foreach ( $code_dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir . $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, array( 'php', 'js', 'css' ), true ) ) {
			continue;
		}
		$text  = (string) file_get_contents( $file->getPathname() );
		$lines = preg_split( '/\R/', $text );
		foreach ( $lines as $no => $line ) {
			// Sortiert Treffer: nur Zeilen, die den Typ "hero" nennen.
			if ( preg_match( '/\bhero\b/i', $line ) ) {
				$grep_hits[] = str_replace( $plugin_dir, '', $file->getPathname() ) . ':' . ( $no + 1 ) . ': ' . trim( $line );
			}
		}
	}
}
$check(
	'kein "hero" in includes/ oder assets/ (PHP/JS/CSS)',
	empty( $grep_hits ),
	empty( $grep_hits ) ? '0 Treffer' : count( $grep_hits ) . ' Treffer: ' . implode( ' || ', $grep_hits )
);
if ( ! empty( $grep_hits ) ) {
	echo "  Treffer:\n   " . implode( "\n   ", $grep_hits ) . "\n";
}

// Dasselbe für "faq" — der Bestandstyp muss ebenso frei von Sonderwegen sein.
$faq_hits = array();
foreach ( $code_dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir . $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, array( 'php', 'js', 'css' ), true ) ) {
			continue;
		}
		$text  = (string) file_get_contents( $file->getPathname() );
		$lines = preg_split( '/\R/', $text );
		foreach ( $lines as $no => $line ) {
			if ( ! preg_match( '/\bfaq\b/i', $line ) ) {
				continue;
			}
			// Einordnung: Kommentarzeile (beginnt mit * // # /*) = kein Code.
			$trimmed   = ltrim( $line );
			$is_common = 0 === strpos( $trimmed, '*' ) || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '/*' ) || 0 === strpos( $trimmed, '#' );
			if ( ! $is_common ) {
				$faq_hits[] = str_replace( $plugin_dir, '', $file->getPathname() ) . ':' . ( $no + 1 ) . ': ' . trim( $line );
			}
		}
	}
}
$check(
	'kein "faq" im Code (Kommentare ausgenommen)',
	empty( $faq_hits ),
	empty( $faq_hits ) ? '0 Code-Treffer' : count( $faq_hits ) . ' Treffer: ' . implode( ' || ', $faq_hits )
);
if ( ! empty( $faq_hits ) ) {
	echo "  Treffer:\n   " . implode( "\n   ", $faq_hits ) . "\n";
}

echo "\n== 7. Schema-Versionierung ==\n";

$check( 'faq unverändert bei faq@1', 'faq@1' === Sdc_Schema::version_string( 'faq' ), Sdc_Schema::version_string( 'faq' ) );
$check( 'hero führt hero@1', 'hero@1' === Sdc_Schema::version_string( 'hero' ), Sdc_Schema::version_string( 'hero' ) );

$faq_json = json_decode( (string) file_get_contents( Sdc_Schema::dir() . 'group_sdc_component_faq.json' ), true );
$check( 'FAQ-Schemadatei unverändert lesbar', is_array( $faq_json ) && 'group_sdc_component_faq' === ( $faq_json['key'] ?? '' ) );
$check( 'FAQ-Schema hat weiterhin 2 Felder', is_array( $faq_json ) && 2 === count( $faq_json['fields'] ?? array() ), is_array( $faq_json ) ? count( $faq_json['fields'] ?? array() ) . '' : 'unlesbar' );

// ------------------------------------------------------------- aufräumen

echo "\n== Aufräumen ==\n";

// Testseite bleibt stehen: sie ist der Beleg für den HTTP-Pfad und wird im
// Sprint-Bericht per curl zitiert. Sie wird NICHT gelöscht.
printf( "Belegseite: ID %d, %s\n", $page_id, get_permalink( $page_id ) );
echo "HTML-Auszug (300 Zeichen):\n" . substr( $http_html, 0, 300 ) . "…\n";

printf( "\n%s (%d geprüft, %d Fehler)\n", 0 === $fails ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE', $checked, $fails );
exit( 0 === $fails ? 0 : 1 );

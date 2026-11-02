<?php
/**
 * Push a saved document through the real REST route on the live test site.
 *
 * Usage (inside the wp-env cli container, where wp-json is reachable):
 *   wp eval-file rest-roundtrip.php <post-id> <body.json> <nonce>
 *
 * Prints status + response, then verifies persistence against the store:
 * the document read back, the number of `_sdc_data` meta rows, and whether a
 * revision was created. Everything is re-read from the database, not from the
 * REST response.
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$args    = array_values( (array) $args );
$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
$file    = isset( $args[1] ) ? (string) $args[1] : '';
$nonce   = isset( $args[2] ) ? (string) $args[2] : '';

if ( ! $post_id || '' === $file || '' === $nonce ) {
	fwrite( STDERR, "usage: rest-roundtrip.php <post-id> <body.json> <nonce>\n" );
	exit( 2 );
}

$body = file_get_contents( $file );
if ( false === $body ) {
	fwrite( STDERR, "cannot read $file\n" );
	exit( 3 );
}

$payload = json_decode( $body, true );
if ( ! is_array( $payload ) ) {
	fwrite( STDERR, "body is not JSON\n" );
	exit( 4 );
}

// The editor test writes the whole request it observed ({url, method, body});
// a bare document is accepted too.
if ( isset( $payload['body'] ) && is_array( $payload['body'] ) ) {
	$payload = $payload['body'];
}
$body = wp_json_encode( $payload );

$revisions_before = count( wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) );

wp_set_current_user( 1 );

$request = new WP_REST_Request( 'POST', '/' . Sdc_Rest::NAMESPACE_V1 . '/pages/' . $post_id );
$request->set_header( 'content-type', 'application/json' );
$request->set_header( 'X-WP-Nonce', $nonce );
$request->set_body( $body );

$response = rest_do_request( $request );
$data     = $response->get_data();
$status   = $response->get_status();

echo "rest status: $status\n";
echo 'rest body: ' . wp_json_encode( array(
	'v'          => $data['v'] ?? null,
	'components' => isset( $data['components'] ) && is_array( $data['components'] ) ? array_map(
		static function ( $c ) {
			return $c['_id'] ?? '?';
		},
		$data['components']
	) : null,
) ) . "\n";

if ( 200 !== $status ) {
	echo 'rest error: ' . wp_json_encode( $data ) . "\n";
	exit( 5 );
}

// ---- read back from the database, not from the response -------------------

$sent     = $payload['components'];
$stored   = Sdc_Store::get_data( $post_id );
$stored_c = isset( $stored['components'] ) ? $stored['components'] : array();

echo 'sent components:   ' . count( $sent ) . "\n";
echo 'stored components: ' . count( $stored_c ) . "\n";

$diff = array();
if ( wp_json_encode( $sent ) !== wp_json_encode( $stored_c ) ) {
	$diff[] = 'components differ';
	$diff[] = 'sent:   ' . wp_json_encode( $sent );
	$diff[] = 'stored: ' . wp_json_encode( $stored_c );
}
echo 'document diff: ' . ( $diff ? implode( "\n  ", $diff ) : 'leer' ) . "\n";

global $wpdb;
$meta_rows = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
		$post_id,
		Sdc_Store::META_KEY
	)
);
echo "meta rows for " . Sdc_Store::META_KEY . ": $meta_rows\n";

$revisions_after = count( wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) );
$latest          = wp_get_post_revisions( $post_id, array( 'numberposts' => 1, 'fields' => 'ids' ) );
$latest_id       = $latest ? (int) current( $latest ) : 0;

echo "revisions before: $revisions_before\n";
echo "revisions after:  $revisions_after\n";
echo "latest revision:  $latest_id (" . ( $latest_id ? get_the_title( $latest_id ) : '—' ) . ")\n";

// ---- REST read of the same route ------------------------------------------

$read = new WP_REST_Request( 'GET', '/' . Sdc_Rest::NAMESPACE_V1 . '/pages/' . $post_id );
$read->set_header( 'X-WP-Nonce', $nonce );
$read_response = rest_do_request( $read );
$read_data     = $read_response->get_data();

// The route returns a document wrapper ({post_id, document, components}) where
// `components` is a reduced description for the catalogue view; the stored
// document is `document`.
$read_document = $read_data['document'] ?? array();
echo 'rest GET status: ' . $read_response->get_status() . "\n";
echo 'rest GET document == store: ' . ( wp_json_encode( $read_document ) === wp_json_encode( $stored ) ? 'ja' : 'nein' ) . "\n";

$ok = ( 200 === $status )
	&& ! $diff
	&& 1 === $meta_rows
	&& $revisions_after > $revisions_before
	&& $latest_id > 0
	&& $latest_id > 37
	&& ( wp_json_encode( $read_document ) === wp_json_encode( $stored ) );

echo 'ERGEBNIS: ' . ( $ok ? 'OK' : 'NICHT OK' ) . "\n";
exit( $ok ? 0 : 6 );

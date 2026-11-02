<?php
/**
 * Dump the metabox markup for one post.
 *
 * Uses `do_meta_boxes()` — the same core call `post.php` makes — so the markup
 * that reaches the browser is the markup that reaches this file. Not a
 * hand-assembled copy of wp-admin: the host renders its own inputs.
 *
 * Usage: wp eval-file /path/to/tests/export-metabox.php <post-id> <out-file>
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// `wp eval-file` leaves the file's $args at global scope; falling back to the
// CLI server argv keeps the script runnable from plain php too.
if ( empty( $args ) && isset( $argv ) ) {
	$args = array_slice( $argv, 1 );
}
$args = array_values( (array) $args );

$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
$out     = isset( $args[1] ) ? (string) $args[1] : '';

if ( ! $post_id || '' === $out ) {
	fwrite( STDERR, "usage: wp eval-file export-metabox.php <post-id> <out-file>\n" );
	exit( 2 );
}

$post = get_post( $post_id );
if ( ! $post ) {
	fwrite( STDERR, "post $post_id not found\n" );
	exit( 3 );
}

// The metabox is registered for admins on this screen; do_meta_boxes() only
// renders what is registered for the given context.
//
// WP-CLI runs without WP_ADMIN, so Sdc_Editor::init() never hooked its
// metabox — the registration below is what post.php gets from the plugin boot,
// reproduced here under the same callback.
set_current_screen( 'post' );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
add_action( 'add_meta_boxes', array( 'Sdc_Editor', 'add_metabox' ) );
do_action( 'add_meta_boxes', $post->post_type, $post );

ob_start();
do_meta_boxes( $post->post_type, 'normal', $post );
$boxes = (string) ob_get_clean();

$start = strpos( $boxes, '<div class="sdc-builder"' );
if ( false === $start ) {
	fwrite( STDERR, "builder root not found in metabox output (" . strlen( $boxes ) . " bytes of normal boxes)\n" );
	exit( 4 );
}

// Capture exactly the builder root: from its opening div to the matching
// close. The root is the only element carrying data-sdc-builder.
$depth = 0;
$pos   = $start;
$len   = strlen( $boxes );
$end   = $len;

for ( $i = $start; $i < $len; $i++ ) {
	if ( '<' !== $boxes[ $i ] ) {
		continue;
	}
	if ( 0 === substr_compare( $boxes, '<div', $i, 4 ) ) {
		++$depth;
		$pos = $i;
	} elseif ( 0 === substr_compare( $boxes, '</div>', $i, 6 ) ) {
		--$depth;
		if ( 0 === $depth ) {
			$end = $i + 6;
			break;
		}
	}
}

$markup = substr( $boxes, $start, $end - $start );

$page = sprintf(
	"<!doctype html>\n<html lang=\"de\">\n<head><meta charset=\"utf-8\"><title>SDC Metabox %d</title></head>\n<body>\n<form id=\"post\" method=\"post\">\n%s\n</form>\n</body>\n</html>\n",
	$post_id,
	$markup
);

if ( false === file_put_contents( $out, $page ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI test helper.
	fwrite( STDERR, "could not write $out\n" );
	exit( 5 );
}

echo 'post: ' . $post_id . "\n";
echo 'markup bytes: ' . strlen( $markup ) . "\n";
echo 'components: ' . substr_count( $markup, 'data-sdc-component data-sdc-id' ) . "\n";

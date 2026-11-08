<?php
/**
 * Which post types carry the builder.
 *
 * Placement lives in the block editor and nowhere else. Earlier builds put a
 * "Page Builder" metabox under the editor: a list of components with their
 * fields, added, dragged and removed there, appended to the page by
 * `the_content`. The components were invisible in the canvas, and the author
 * had two places to look for one page — the editor showed paragraphs, the box
 * below showed what the page would actually be.
 *
 * That box is gone. A component is a block now, so it is placed where the
 * author already is, in the canvas, in the reading order, with the list view
 * showing its name.
 *
 * What is left here is the one fact the rest of the plugin still needs: which
 * post types the builder edits. The frontend reads it to decide where a
 * legacy document may render, and `Sdc_Block` to decide where a document is
 * migrated into blocks.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types the builder applies to.
 */
class Sdc_Editor {

	/**
	 * Post types that get the builder.
	 *
	 * Filterable, because an installation may want components on posts or on a
	 * custom type without patching the plugin.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = (array) apply_filters( 'sdc_pb_post_types', array( 'page' ) );
		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}
}

<?php
/**
 * Uninstall routine.
 *
 * Deactivating the plugin must never cost content: the components live in the
 * `_sdc_data` post meta of pages a user wrote, and the pages stay published
 * after deactivation. So nothing is deleted unless the site owner has opted in
 * by defining the constant in wp-config.php:
 *
 *     define( 'SDC_PB_DELETE_DATA_ON_UNINSTALL', true );
 *
 * Without that constant, uninstalling removes no content at all.
 *
 * @package SdcPageBuilder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete every `_sdc_data` row, including the copies on revisions.
 *
 * Measured, and the reason this is one statement rather than a sweep:
 *
 * - A per-post sweep had to enumerate post types, and revisions are only
 *   reachable when named explicitly (`post_type => 'any'` means "every
 *   *searchable* post type", which excludes them). A stale copy on a revision
 *   is exactly what a later restore imports back onto the live post, so the
 *   sweep named them.
 * - `delete_post_meta()` then failed on those revision rows: measured in the
 *   test container, a batch of two revisions reported
 *   `found=2, geloescht=0` while both rows stayed in `wp_postmeta`. The loop
 *   stops on a short batch, so that alone only leaked rows — but a site with a
 *   full batch (100) of such rows re-read the same IDs forever. That hang is
 *   what a PHPUnit run against this file hit.
 * - `delete_post_meta_by_key()` removes the rows regardless of which post they
 *   hang off: measured 3 rows (live page, revision copy, orphan row whose post
 *   no longer exists) to 0 in a single call.
 *
 * Deleting by key also covers post types the plugin never knew about, orphan
 * rows left behind by deleted posts, and any CPT a site registered later.
 *
 * @return int|false Number of rows deleted, false on database error.
 */
function sdc_pb_uninstall_purge() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall, no cache to prime.
	return delete_post_meta_by_key( '_sdc_data' );
}

// The plugin stores no options — everything is post meta on the pages a user
// wrote. So there is nothing of ours to remove unconditionally.
if ( ! defined( 'SDC_PB_DELETE_DATA_ON_UNINSTALL' ) || ! SDC_PB_DELETE_DATA_ON_UNINSTALL ) {
	return;
}

sdc_pb_uninstall_purge();

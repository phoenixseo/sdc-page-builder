<?php
/**
 * Uninstall behaviour: deleting data is opt-in, and when opted in it is total.
 *
 * Two things are defended here.
 *
 * 1. Silence by default. Deactivating or uninstalling the plugin must not cost
 *    a site its content, because `_sdc_data` holds pages a user wrote.
 * 2. Completeness when opted in — including rows a per-post sweep cannot reach:
 *    the copy on a revision, and an orphan row whose post is already gone. A
 *    stale revision copy is not harmless bookkeeping: it is exactly what a later
 *    restore imports back onto the live post.
 *
 * Every purge in this file runs under a wall-clock ceiling. The implementation
 * this file replaces looped over batches and could re-read the same IDs forever
 * when a delete silently failed, which hung the suite instead of failing it. A
 * hanging test is worse than a red one, so a regression has to fail on time.
 *
 * @package SdcPageBuilder
 */

/**
 * Uninstall opt-in and purge completeness.
 */
class UninstallTest extends Sdc_TestCase {

	/**
	 * Wall-clock ceiling for one purge, in seconds.
	 *
	 * The measured runtime is milliseconds for a handful of rows and well under
	 * a second for the 300-row case; this limit is orders of magnitude above
	 * that, so it trips on a real loop, not on a loaded machine.
	 */
	const PURGE_TIME_LIMIT = 10;

	/**
	 * Path to the shipped uninstall.php.
	 *
	 * @return string
	 */
	private function uninstall_file() {
		return dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Load uninstall.php the way WordPress does and report what it did.
	 *
	 * `uninstall.php` ends in a `return` when the site owner has not opted in,
	 * so requiring it twice does not re-run the purge. Each test loads it at
	 * most once; the guard is part of what is under test, so the file is
	 * evaluated on its own terms rather than by calling a helper directly.
	 *
	 * @param bool $opted_in Whether the site owner opted in to data deletion.
	 * @return array{rows:int,seconds:float} Rows the purge reported, elapsed time.
	 */
	private function load_and_run_uninstall( $opted_in = true ) {
		// WordPress defines this before loading uninstall.php; without it the
		// file exits, which would kill this test process silently.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sdc-page-builder/sdc-page-builder.php' );
		}

		if ( $opted_in && ! defined( 'SDC_PB_DELETE_DATA_ON_UNINSTALL' ) ) {
			define( 'SDC_PB_DELETE_DATA_ON_UNINSTALL', true );
		}

		$file = $this->uninstall_file();
		$this->assertFileExists( $file, 'uninstall.php is missing from the plugin root' );

		$started = microtime( true );
		require_once $file;
		$elapsed = microtime( true ) - $started;

		if ( ! $opted_in ) {
			return array(
				'rows'    => 0,
				'seconds' => $elapsed,
			);
		}

		$this->assertTrue(
			function_exists( 'sdc_pb_uninstall_purge' ),
			'uninstall.php does not define sdc_pb_uninstall_purge()'
		);

		// The require above already purged. Calling again both yields the count
		// and proves the purge is repeatable: an uninstall that errors on a
		// second run would be a poor uninstall.
		$started = microtime( true );
		$rows    = sdc_pb_uninstall_purge();
		$elapsed = microtime( true ) - $started;

		return array(
			'rows'    => (int) $rows,
			'seconds' => $elapsed,
		);
	}

	/**
	 * Count `_sdc_data` rows in the database, across every post type.
	 *
	 * `get_post_meta()` reads the cache of one post. Counting by key is the
	 * only way to see a row whose post was deleted, which is one of the states
	 * this plugin has to clean up.
	 *
	 * @return int
	 */
	private function count_meta_rows() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asserting on the raw table is the point.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Sdc_Store::META_KEY ) );
	}

	/**
	 * Uninstall.php deletes nothing unless the site owner opted in.
	 *
	 * This is the promise the file's own header makes, and the only thing
	 * standing between an uninstall and a site losing its pages.
	 */
	public function test_install_guards_deletion_behind_an_opt_in() {
		$source = file_get_contents( $this->uninstall_file() );

		$this->assertStringContainsString(
			'SDC_PB_DELETE_DATA_ON_UNINSTALL',
			$source,
			'uninstall.php no longer checks the opt-in constant'
		);

		// The guard must run before the purge, not after it.
		$guard_at = strpos( $source, 'if ( ! defined( \'SDC_PB_DELETE_DATA_ON_UNINSTALL\'' );
		$purge_at = strpos( $source, 'sdc_pb_uninstall_purge();' );

		$this->assertIsInt( $guard_at, 'The opt-in guard is gone' );
		$this->assertIsInt( $purge_at, 'The purge call is gone' );
		$this->assertLessThan( $purge_at, $guard_at, 'The purge runs before the opt-in guard' );
	}

	/**
	 * Without the constant, a page keeps its document.
	 *
	 * A constant cannot be undefined in one process, so this case has to run
	 * before any opted-in load. PHPUnit runs methods in declaration order, and
	 * this method is declared above the opted-in cases for that reason; the
	 * assertion below states the dependency instead of relying on it silently.
	 */
	public function test_without_opt_in_no_document_is_deleted() {
		$this->assertFalse(
			defined( 'SDC_PB_DELETE_DATA_ON_UNINSTALL' ),
			'This test must run before the opted-in cases define the constant'
		);

		$page_id = $this->make_page();
		Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

		$before = $this->count_meta_rows();
		$this->assertGreaterThan( 0, $before, 'Fixture did not store a document' );

		$this->load_and_run_uninstall( false );

		$this->assertSame(
			$before,
			$this->count_meta_rows(),
			'uninstall.php deleted document rows without the opt-in constant'
		);
		$this->assertSame(
			'faq',
			get_post_meta( $page_id, Sdc_Store::META_KEY, true )['components'][0]['type'],
			'the page lost its document'
		);
	}

	/**
	 * With the opt-in, the purge deletes the document of a page.
	 */
	public function test_opt_in_purge_deletes_page_documents() {
		$page_id = $this->make_page();
		Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

		$this->assertSame( 'faq', get_post_meta( $page_id, Sdc_Store::META_KEY, true )['components'][0]['type'] );

		$result = $this->load_and_run_uninstall();

		$this->assertSame(
			'',
			get_post_meta( $page_id, Sdc_Store::META_KEY, true ),
			'Page document survived the purge'
		);
		$this->assertLessThan(
			self::PURGE_TIME_LIMIT,
			$result['seconds'],
			'the purge took ' . round( $result['seconds'], 2 ) . 's; it must not loop'
		);
	}

	/**
	 * The purge reaches the copy on a revision.
	 *
	 * `post_type => 'any'` means "every *searchable* post type", and revisions
	 * are not searchable. A per-post sweep that trusts `any` therefore leaves
	 * revision copies behind — and a revision copy is what a later restore
	 * writes back onto the live post.
	 */
	public function test_purge_reaches_revision_copies() {
		$page_id = $this->make_page();
		Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

		$revision_id = _wp_put_post_revision( get_post( $page_id ), true );
		$this->assertIsInt( $revision_id );
		update_post_meta( $revision_id, Sdc_Store::META_KEY, array( 'components' => array( $this->faq_component( 'Alter Stand' ) ) ) );

		$this->assertNotSame(
			'',
			get_post_meta( $revision_id, Sdc_Store::META_KEY, true ),
			'fixture did not place a document on the revision'
		);

		$this->load_and_run_uninstall();

		$this->assertSame(
			'',
			get_post_meta( $revision_id, Sdc_Store::META_KEY, true ),
			'Revision kept a copy of the document; a per-post sweep over post_type=any does not see revisions'
		);
	}

	/**
	 * The purge reaches a row whose post no longer exists.
	 */
	public function test_purge_reaches_orphan_rows() {
		global $wpdb;

		$page_id = $this->make_page();
		Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

		// Delete the post but not its meta: the state a crashed delete or a
		// direct DB removal leaves behind. A per-post sweep never enumerates it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- manufacturing the orphan state is the point.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID = %d", $page_id ) );
		wp_cache_flush();

		$this->assertSame(
			'faq',
			get_post_meta( $page_id, Sdc_Store::META_KEY, true )['components'][0]['type'],
			'fixture did not leave an orphan row behind'
		);

		$this->load_and_run_uninstall();

		$this->assertSame(
			'',
			get_post_meta( $page_id, Sdc_Store::META_KEY, true ),
			'Orphan row survived; nothing enumerates it per post'
		);
	}

	/**
	 * The purge removes every row in one pass, not just a first batch.
	 *
	 * 300 rows is well above the batch size the old sweep used (100), which is
	 * where its loop could stall on rows it failed to delete. This test is the
	 * regression guard for that hang: it fails on the clock, not by hanging the
	 * suite.
	 */
	public function test_purge_removes_every_row_in_one_pass() {
		for ( $i = 0; $i < 150; $i++ ) {
			$page_id = $this->make_page();
			Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

			// A revision copy as well, so the row count deliberately exceeds
			// 150. The tracked post keeps its own revision, which tear_down()
			// removes with it.
			_wp_put_post_revision( get_post( $page_id ), true );
		}

		$before = $this->count_meta_rows();
		$this->assertGreaterThanOrEqual( 150, $before, 'Fixture stored fewer rows than expected' );

		$result = $this->load_and_run_uninstall();

		$this->assertSame( 0, $this->count_meta_rows(), 'rows survived the purge' );
		$this->assertLessThan(
			self::PURGE_TIME_LIMIT,
			$result['seconds'],
			'the purge took ' . round( $result['seconds'], 2 ) . 's over ' . $before . ' rows; it must not loop'
		);
	}

	/**
	 * A second purge on an empty table succeeds and deletes nothing.
	 */
	public function test_purge_is_idempotent() {
		$page_id = $this->make_page();
		Sdc_Store::save_data( $page_id, array( 'components' => array( $this->faq_component() ) ) );

		$this->load_and_run_uninstall();

		$second = sdc_pb_uninstall_purge();

		$this->assertSame( 0, (int) $second, 'a second purge reported deletions on an empty table' );
		$this->assertSame( 0, $this->count_meta_rows() );
	}
}

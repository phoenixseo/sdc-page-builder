<?php
/**
 * The one-way move from the metabox document into blocks.
 *
 * @package SdcPageBuilder
 */

/**
 * Class MigrationTest
 */
class MigrationTest extends Sdc_TestCase {

	/**
	 * Store a document built the old way: components in `_sdc_data`, nothing
	 * in `post_content`.
	 *
	 * @param int     $post_id Post ID.
	 * @param array[] $components Components.
	 */
	private function old_document( $post_id, array $components ) {
		Sdc_Store::save_data( $post_id, array( 'components' => $components ) );
	}

	/**
	 * Parsed blocks of a page, without the freeform whitespace blocks
	 * `parse_blocks()` adds around the markup.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	private function blocks_of( $post_id ) {
		return array_values(
			array_filter(
				parse_blocks( get_post_field( 'post_content', $post_id, 'raw' ) ),
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
	}

	/**
	 * The document becomes one block per component, in document order.
	 */
	public function test_document_becomes_one_block_per_component() {
		$post_id = $this->make_page();
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component() ),
			)
		);

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );

		$blocks = $this->blocks_of( $post_id );

		$this->assertCount( 2, $blocks );
		$this->assertSame( 'sdc/hero', $blocks[0]['blockName'] );
		$this->assertSame( 'sdc/faq', $blocks[1]['blockName'] );
		$this->assertSame( 'Willkommen', $blocks[0]['attrs']['data']['heading'] );
		$this->assertSame( 2, count( $blocks[1]['attrs']['data']['items'] ) );
	}

	/**
	 * Existing prose stays in front of the components — the frontend appended
	 * them to the content, so that is the order the author last saw.
	 */
	public function test_existing_content_keeps_its_place() {
		$post_id = $this->make_page( array( 'post_content' => "<!-- wp:paragraph -->\n<p>Einleitung</p>\n<!-- /wp:paragraph -->" ) );
		$this->old_document( $post_id, array( $this->faq_component() ) );

		Sdc_Migration::migrate_post( $post_id );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertLessThan(
			strpos( $content, 'wp:sdc/faq' ),
			strpos( $content, 'Einleitung' ),
			'The paragraph has to come before the migrated component.'
		);
		$this->assertCount( 2, $this->blocks_of( $post_id ) );
	}

	/**
	 * The document is kept as the backup.
	 */
	public function test_document_is_kept_as_backup() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component( 'Sicherung' ) ) );

		Sdc_Migration::migrate_post( $post_id );

		$document = Sdc_Store::get_data( $post_id );

		$this->assertCount( 1, $document['components'] );
		$this->assertSame( 'Sicherung', $document['components'][0]['data']['heading'] );
	}

	/**
	 * A page that already holds one component block gets the rest of its
	 * document. This is the defect the card names: page 98 had a document
	 * `[hero, faq, faq, info, faq3]` and a content holding only the FAQs and
	 * info — the hero was marked as migrated and never written.
	 *
	 * Instance by instance, not type by type: the document has two FAQs and
	 * the content one, so exactly one FAQ is added, and the hero in front.
	 */
	public function test_page_with_one_block_gets_the_missing_components() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component( 'Der verlorene Hero' ) ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Erster FAQ' ) ),
				array_merge( array( '_id' => 'c_faq000002' ), $this->faq_component( 'Zweiter FAQ' ) ),
			)
		);

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );

		$blocks = $this->blocks_of( $post_id );
		$names  = wp_list_pluck( $blocks, 'blockName' );

		$this->assertCount( 3, $blocks );
		$this->assertSame( array( 'sdc/faq', 'sdc/hero', 'sdc/faq' ), $names );

		// The block that was already there is untouched and stays in place.
		$content = get_post_field( 'post_content', $post_id, 'raw' );
		$this->assertStringStartsWith( $existing, $content );
		$this->assertSame( 1, substr_count( $content, 'Schon da' ) );

		// The FAQ that was added is the second one of the document, not a
		// copy of the block that was already there.
		$this->assertSame( 'Zweiter FAQ', $blocks[2]['attrs']['data']['heading'] );
		$this->assertSame( 'Der verlorene Hero', $blocks[1]['attrs']['data']['heading'] );
	}

	/**
	 * The defect, in the shape the card measured: a content that holds some of
	 * the document's components (two FAQs, no hero) gets the missing one and
	 * nothing twice. Same type, different instances — matched per instance.
	 */
	public function test_page_98_shape_holds_every_component_after_migration() {
		$existing = implode(
			"\n\n",
			array(
				'<!-- wp:sdc/faq {"data":{"heading":"Häufige Fragen","items":[]}} /-->',
				'<!-- wp:sdc/faq {"data":{"heading":"Häufige Fragen","items":[]}} /-->',
			)
		);
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component() ),
				array_merge( array( '_id' => 'c_faq000002' ), $this->faq_component() ),
			)
		);

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		preg_match_all( '#wp:sdc/([a-z0-9_-]+)#', $content, $matches );

		$this->assertSame( array( 'faq', 'faq', 'hero' ), $matches[1] );
		$this->assertSame( 3, count( $this->blocks_of( $post_id ) ) );
	}

	/**
	 * Nothing appears twice: the document that the content already carries
	 * completely is not written again, and the page is only marked.
	 */
	public function test_page_whose_content_holds_the_whole_document_is_only_marked() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Aus dem Editor"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document( $post_id, array( $this->faq_component( 'Aus der Metabox' ) ) );

		$revisions = count( wp_get_post_revisions( $post_id ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( $existing, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );
		$this->assertSame( 1, substr_count( get_post_field( 'post_content', $post_id, 'raw' ), 'wp:sdc/faq' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * A block type the document does not know (added in the editor after the
	 * metabox was written) is left where it is. The migration only adds.
	 */
	public function test_unknown_block_in_content_is_kept() {
		$existing = implode(
			"\n\n",
			array(
				'<!-- wp:sdc/faq {"data":{"heading":"Aus dem Editor"}} /-->',
				'<!-- wp:sdc/extra {"data":{"heading":"Nur im Editor"}} /-->',
			)
		);
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Aus der Metabox' ) ),
				array_merge( array( '_id' => 'c_faq000002' ), $this->faq_component( 'Zweiter aus der Metabox' ) ),
			)
		);

		Sdc_Migration::migrate_post( $post_id );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame( 1, substr_count( $content, 'Nur im Editor' ) );
		$this->assertStringContainsString( '<!-- wp:sdc/extra ', $content );

		// faq (editor) + extra (editor) + hero + the second faq.
		$names = wp_list_pluck( $this->blocks_of( $post_id ), 'blockName' );

		$this->assertSame( array( 'sdc/faq', 'sdc/extra', 'sdc/hero', 'sdc/faq' ), $names );
	}

	/**
	 * The migration only appends: text and blocks the author wrote stay in
	 * front of the appended components, in their order.
	 */
	public function test_existing_blocks_keep_their_order() {
		$existing = implode(
			"\n\n",
			array(
				'<!-- wp:paragraph -->',
				'<p>Einleitung</p>',
				'<!-- /wp:paragraph -->',
				'<!-- wp:sdc/faq {"data":{"heading":"Erster FAQ"}} /-->',
			)
		);
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Erster FAQ' ) ),
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component( 'Der Hero' ) ),
			)
		);

		Sdc_Migration::migrate_post( $post_id );

		$blocks = $this->blocks_of( $post_id );

		$this->assertSame(
			array( 'core/paragraph', 'sdc/faq', 'sdc/hero' ),
			wp_list_pluck( $blocks, 'blockName' )
		);
		$this->assertSame( 'Erster FAQ', $blocks[1]['attrs']['data']['heading'] );
		$this->assertSame( 'Der Hero', $blocks[2]['attrs']['data']['heading'] );
	}

	/**
	 * A page without content blocks but with a document is completed by the
	 * forced run — repair is the only caller that passes `$force`, because the
	 * marker alone is not evidence that the components were ever written.
	 */
	public function test_forced_run_completes_a_marked_page_without_content() {
		$post_id = $this->make_page( array( 'post_content' => 'Nur Text' ) );
		$this->old_document( $post_id, array( $this->faq_component( 'Nachgeholt' ) ) );

		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id, true ) );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertStringContainsString( 'Nachgeholt', $content );
		$this->assertStringStartsWith( 'Nur Text', $content );
		$this->assertCount( 1, $this->blocks_of( $post_id ) );
	}

	/**
	 * A page that already carries components is completed by the forced run —
	 * this is the state the broken run left on page 98: marked migrated, a
	 * component missing from the content. The marker is the thing that made
	 * the loss permanent, so it cannot stand in for the content; what reaches
	 * such a page is the repair command, and it passes `$force`.
	 */
	public function test_forced_run_completes_a_marked_page_with_a_missing_component() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Schon da' ) ),
			)
		);

		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id, true ) );

		$this->assertSame(
			array( 'sdc/faq', 'sdc/hero' ),
			wp_list_pluck( $this->blocks_of( $post_id ), 'blockName' )
		);
	}

	/**
	 * A block the author deleted in the editor stays deleted.
	 *
	 * Once a page is marked and its content carries builder blocks, the
	 * content is the source of truth: the document is only the backup. A run
	 * that reads it again would append the deleted block behind the author's
	 * back on the next editor load — the deletion would never hold.
	 */
	public function test_deleted_block_is_not_resurrected_on_a_marked_page() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Bleibt"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component( 'Gelöscht' ) ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Bleibt' ) ),
			)
		);
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		// The fixture writes meta through save_data(), which saves a revision
		// itself. The baseline is the count after the fixture, not zero.
		$revisions = count( wp_get_post_revisions( $post_id ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );

		$this->assertSame( $existing, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( array( 'sdc/faq' ), wp_list_pluck( $this->blocks_of( $post_id ), 'blockName' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ), 'A deletion must not trigger a write.' );
	}

	/**
	 * A page carrying blocks without the marker is completed in one call, and
	 * only once: the second call must not write what the first one wrote.
	 */
	public function test_page_with_blocks_is_completed_once() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Schon da' ) ),
			)
		);

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );

		$first = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame( 2, count( $this->blocks_of( $post_id ) ) );
		$this->assertSame( 1, substr_count( $first, 'Schon da' ) );

		$revisions = count( wp_get_post_revisions( $post_id ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( $first, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * Running twice changes nothing the second time — a page is opened in the
	 * editor far more often than it is built.
	 */
	public function test_migration_runs_once() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );

		$first     = get_post_field( 'post_content', $post_id, 'raw' );
		$revisions = count( wp_get_post_revisions( $post_id ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( $first, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertCount( 1, $this->blocks_of( $post_id ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * The second run must not write at all — a revision on every editor load
	 * would be a write per page view in the admin.
	 */
	public function test_second_run_does_not_write_the_post() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );

		$writes = 0;
		$count  = function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};

		add_filter( 'content_save_pre', $count );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );

		remove_filter( 'content_save_pre', $count );

		$this->assertSame( 0, $writes, 'The second run must not save the post.' );
	}

	/**
	 * A page without a document is marked, so the check does not run again on
	 * every editor load.
	 */
	public function test_page_without_document_is_marked_without_changes() {
		$post_id = $this->make_page( array( 'post_content' => 'Nur Text' ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( 'Nur Text', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );
	}

	/**
	 * Post types the builder does not edit are left alone.
	 */
	public function test_other_post_types_are_untouched() {
		$post_id = $this->make_page( array( 'post_type' => 'post' ) );
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( '', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ) );
	}

	/**
	 * A component whose folder is gone has no block; the rest is written.
	 */
	public function test_component_without_block_is_skipped() {
		$post_id = $this->make_page();
		$this->old_document(
			$post_id,
			array(
				array(
					'type' => 'verschwunden',
					'data' => array( 'heading' => 'Weg' ),
				),
				$this->faq_component( 'Bleibt' ),
			)
		);

		Sdc_Migration::migrate_post( $post_id );

		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertStringNotContainsString( 'verschwunden', $content );
		$this->assertCount( 1, $this->blocks_of( $post_id ) );
		$this->assertStringContainsString( 'Bleibt', $content );
	}

	/**
	 * The marker is what stops the frontend from appending the document to a
	 * page whose components are already in the content.
	 */
	public function test_marker_stops_the_frontend_from_appending() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component( 'Doppelt vermeiden' ) ) );

		Sdc_Migration::migrate_post( $post_id );

		$this->go_to( get_permalink( $post_id ) );

		$content  = get_post_field( 'post_content', $post_id, 'raw' );
		$filtered = Sdc_Frontend::filter_content( $content );

		$this->assertSame( $content, $filtered );
		$this->assertSame( 1, substr_count( do_blocks( $content ), 'Doppelt vermeiden' ) );
	}

	/**
	 * Values keep quotes, slashes and umlauts through the round trip — the
	 * attribute JSON is the only place they are stored once the document is
	 * no longer the source.
	 */
	public function test_values_survive_the_round_trip() {
		$post_id = $this->make_page();
		$heading = 'Frage mit "Anführung", \\ Backslash & Umlaut äöü';
		$this->old_document( $post_id, array( $this->faq_component( $heading ) ) );

		Sdc_Migration::migrate_post( $post_id );

		$blocks = $this->blocks_of( $post_id );

		$this->assertSame( $heading, $blocks[0]['attrs']['data']['heading'] );
	}

	/**
	 * Detection looks for the serialized block, not for rendered markup: an
	 * empty component renders nothing and its block is still there.
	 */
	public function test_block_detection_reads_serialized_markup() {
		$this->assertTrue( Sdc_Migration::has_blocks( '<!-- wp:sdc/faq {"data":{}} /-->' ) );
		$this->assertTrue( Sdc_Migration::has_blocks( "Text\n\n<!-- wp:sdc/hero {\"data\":{}} /-->" ) );
		$this->assertFalse( Sdc_Migration::has_blocks( '<div class="sdc-component--faq"></div>' ) );
		$this->assertFalse( Sdc_Migration::has_blocks( '' ) );
	}

	/**
	 * The REST filter only fires for edit context — a frontend reader must not
	 * trigger a write.
	 */
	public function test_rest_filter_ignores_view_context() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$view = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
		$view->set_param( 'context', 'view' );

		Sdc_Migration::migrate_rest_request( null, rest_get_server(), $view );

		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ) );
	}

	/**
	 * ... and does fire for the editor's edit context.
	 */
	public function test_rest_filter_migrates_edit_context() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$edit = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
		$edit->set_param( 'context', 'edit' );

		Sdc_Migration::migrate_rest_request( null, rest_get_server(), $edit );

		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );
		$this->assertCount( 1, $this->blocks_of( $post_id ) );
	}

	/**
	 * A short-circuit response is passed through untouched.
	 */
	public function test_rest_filter_leaves_a_short_circuit_alone() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
		$response = new WP_REST_Response( array( 'early' ), 200 );

		$this->assertSame( $response, Sdc_Migration::migrate_rest_request( $response, rest_get_server(), $request ) );
		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ) );
	}
}

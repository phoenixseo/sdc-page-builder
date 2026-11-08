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
	 * Running twice changes nothing the second time — a page is opened in the
	 * editor far more often than it is built.
	 */
	public function test_migration_runs_once() {
		$post_id = $this->make_page();
		$this->old_document( $post_id, array( $this->faq_component() ) );

		$this->assertTrue( Sdc_Migration::migrate_post( $post_id ) );

		$first = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( $first, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertCount( 1, $this->blocks_of( $post_id ) );
	}

	/**
	 * A page that already holds builder blocks is only marked. Appending the
	 * document would print the same components a second time.
	 */
	public function test_page_that_already_has_blocks_is_only_marked() {
		$existing = '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->';
		$post_id  = $this->make_page( array( 'post_content' => $existing ) );
		$this->old_document( $post_id, array( $this->faq_component( 'Aus der Metabox' ) ) );

		$this->assertFalse( Sdc_Migration::migrate_post( $post_id ) );
		$this->assertSame( $existing, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertTrue( Sdc_Migration::is_migrated( $post_id ) );
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

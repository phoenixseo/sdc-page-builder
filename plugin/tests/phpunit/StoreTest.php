<?php
/**
 * Store: the document roundtrip and the guarantees around it.
 *
 * @package SdcPageBuilder
 */

/**
 * The store is the only writer of `_sdc_data`. Everything the builder promises
 * about history, ids and payload shape is decided here.
 */
class StoreTest extends Sdc_TestCase {

	/**
	 * A page with no document reads as an empty document, not as null.
	 */
	public function test_missing_meta_reads_as_empty_document() {
		$post_id = $this->make_page();

		$doc = Sdc_Store::get_data( $post_id );

		$this->assertSame( Sdc_Store::empty_document(), $doc );
		$this->assertSame( array(), $doc['components'] );
		$this->assertSame( Sdc_Store::FORMAT_VERSION, $doc['v'] );
	}

	/**
	 * Save and read back: same types, same data, same order.
	 */
	public function test_roundtrip_preserves_components_and_order() {
		$post_id = $this->make_page();
		$input   = array(
			'components' => array(
				$this->hero_component( 'Erster' ),
				$this->faq_component( 'Zweiter', 3 ),
			),
		);

		Sdc_Store::save_data( $post_id, $input );
		$doc = Sdc_Store::get_data( $post_id );

		$this->assertSame( Sdc_Store::FORMAT_VERSION, $doc['v'] );
		$this->assertCount( 2, $doc['components'] );
		$this->assertSame( 'hero', $doc['components'][0]['type'] );
		$this->assertSame( 'faq', $doc['components'][1]['type'] );
		$this->assertSame( 'Erster', $doc['components'][0]['data']['heading'] );
		$this->assertCount( 3, $doc['components'][1]['data']['items'] );
	}

	/**
	 * Umlauts and quotes survive the meta write byte for byte.
	 */
	public function test_roundtrip_preserves_utf8_and_special_characters() {
		$post_id = $this->make_page();
		$text    = 'Größenwahn & "Anführungszeichen" — 100 % äöüß';

		Sdc_Store::save_data(
			$post_id,
			array( 'components' => array( $this->faq_component( $text, 1 ) ) )
		);

		$doc = Sdc_Store::get_data( $post_id );
		$this->assertSame( $text, $doc['components'][0]['data']['heading'] );
	}

	/**
	 * Every component gets an id, and no id appears twice.
	 */
	public function test_ids_are_assigned_and_unique() {
		$post_id = $this->make_page();

		$doc = Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					$this->faq_component(),
					$this->faq_component( 'Zweite' ),
					$this->hero_component(),
				),
			)
		);

		$ids = array_column( $doc['components'], '_id' );
		$this->assertCount( 3, $ids );
		$this->assertCount( 3, array_unique( $ids ), 'Duplicate component ids: ' . implode( ', ', $ids ) );

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^c_[a-f0-9]{8}$/', $id );
		}
	}

	/**
	 * Re-saving what was read back keeps the ids — that is exactly what the
	 * editor does: it loads the document, then posts it back.
	 */
	public function test_ids_survive_a_load_and_save_cycle() {
		$post_id = $this->make_page();

		$first = Sdc_Store::save_data(
			$post_id,
			array( 'components' => array( $this->faq_component(), $this->hero_component() ) )
		);
		$ids   = array_column( $first['components'], '_id' );

		// The editor's cycle: read the stored document, save it unchanged.
		$second = Sdc_Store::save_data( $post_id, Sdc_Store::get_data( $post_id ) );

		$this->assertSame( $ids, array_column( $second['components'], '_id' ) );
	}

	/**
	 * A supplied id is kept. Documents without one get a fresh id on every save
	 * — measured, and the reason the editor round-trips the stored document.
	 */
	public function test_supplied_id_is_kept() {
		$post_id                          = $this->make_page();
		$document                         = array( 'components' => array( $this->faq_component() ) );
		$document['components'][0]['_id'] = 'c_fixed001';

		$first  = Sdc_Store::save_data( $post_id, $document );
		$second = Sdc_Store::save_data( $post_id, $document );

		$this->assertSame( 'c_fixed001', $first['components'][0]['_id'] );
		$this->assertSame( 'c_fixed001', $second['components'][0]['_id'] );
	}

	/**
	 * A duplicate id is replaced, not both accepted.
	 */
	public function test_duplicate_id_is_replaced() {
		$post_id                     = $this->make_page();
		$dup                         = array( 'components' => array( $this->faq_component(), $this->faq_component() ) );
		$dup['components'][0]['_id'] = 'c_fixed001';
		$dup['components'][1]['_id'] = 'c_fixed001';

		$doc = Sdc_Store::save_data( $post_id, $dup );

		$this->assertSame( 'c_fixed001', $doc['components'][0]['_id'] );
		$this->assertNotSame( 'c_fixed001', $doc['components'][1]['_id'] );
	}

	/**
	 * Hostile ids are stripped to safe characters.
	 */
	public function test_id_is_stripped_to_safe_characters() {
		$post_id = $this->make_page();

		$doc = Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					array(
						'_id'  => 'c_<script>alert(1)</script>',
						'type' => 'faq',
						'data' => array(),
					),
				),
			)
		);

		$this->assertDoesNotMatchRegularExpression( '/[<>()\/]/', $doc['components'][0]['_id'] );
	}

	/**
	 * Components without a type are dropped instead of stored unusable.
	 */
	public function test_components_without_type_are_dropped() {
		$post_id = $this->make_page();

		$doc = Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					array( 'data' => array( 'heading' => 'ohne Typ' ) ),
					$this->faq_component(),
					'nicht mal ein Array',
				),
			)
		);

		$this->assertCount( 1, $doc['components'] );
		$this->assertSame( 'faq', $doc['components'][0]['type'] );
	}

	/**
	 * Broken JSON in meta never reaches the renderer as a string.
	 */
	public function test_broken_json_in_meta_reads_as_empty_document() {
		$post_id = $this->make_page();
		update_post_meta( $post_id, Sdc_Store::META_KEY, '{"components": [ this is not json' );

		$this->assertSame( Sdc_Store::empty_document(), Sdc_Store::get_data( $post_id ) );
	}

	/**
	 * A JSON string is accepted as input, because that is what the REST route
	 * and the editor send.
	 */
	public function test_sanitize_accepts_json_string() {
		$clean = Sdc_Store::sanitize( wp_json_encode( array( 'components' => array( $this->faq_component() ) ) ) );

		$this->assertCount( 1, $clean['components'] );
		$this->assertSame( 'faq', $clean['components'][0]['type'] );
	}

	/**
	 * Emptying a page is a real save, not a no-op that leaves the old document.
	 */
	public function test_empty_document_can_be_saved_over_content() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component() ) ) );

		Sdc_Store::save_data( $post_id, array( 'components' => array() ) );

		$this->assertSame( array(), Sdc_Store::get_data( $post_id )['components'] );
	}

	/**
	 * Saving through the store creates a revision. Measured: a lone meta write
	 * does not, and without this the builder has nothing to restore.
	 */
	public function test_save_creates_a_revision() {
		$post_id = $this->make_page();
		$before  = count( wp_get_post_revisions( $post_id ) );

		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component() ) ) );

		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * The document is stored as one meta row, not one per component.
	 */
	public function test_document_is_one_meta_row_regardless_of_component_count() {
		$post_id = $this->make_page();

		Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					$this->faq_component( 'A', 5 ),
					$this->faq_component( 'B', 5 ),
					$this->hero_component(),
				),
			)
		);

		$rows = get_post_meta( $post_id, Sdc_Store::META_KEY, false );
		$this->assertCount( 1, $rows );
	}

	/**
	 * The storage key itself is a compatibility contract and must not drift.
	 *
	 * Every other test reads through `Sdc_Store::META_KEY`, so changing the
	 * constant keeps the whole suite green while silently orphaning the
	 * documents of every site that already runs the plugin. Measured: renaming
	 * the constant left 53/53 tests passing. This is the only test that fails
	 * when that happens.
	 */
	public function test_meta_key_is_the_published_contract() {
		$this->assertSame( '_sdc_data', Sdc_Store::META_KEY );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component() ) ) );

		// Read back with the literal key: proves the document really lands
		// under `_sdc_data` in the database, not just under the constant.
		// `get_post_meta( ..., true )` returns the unserialized value, which
		// is the decoded array — not a JSON string.
		$data = get_post_meta( $post_id, '_sdc_data', true );

		$this->assertIsArray( $data, 'No document stored under _sdc_data' );
		$this->assertSame( 1, $data['v'] );
		$this->assertSame( 'faq', $data['components'][0]['type'] );
	}

	/**
	 * The format version is persisted, so an old document stays readable.
	 */
	public function test_format_version_is_written_to_storage() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component() ) ) );

		$data = get_post_meta( $post_id, '_sdc_data', true );

		$this->assertIsArray( $data );
		$this->assertSame( 1, $data['v'], 'The stored format version changed; stored documents would need a migration' );
	}
}

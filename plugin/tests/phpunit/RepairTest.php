<?php
/**
 * The repair command: what it reports, what it writes, what it leaves alone.
 *
 * The command class lives in `class-sdc-repair.php` and is defined only when
 * WP-CLI is loaded. The bootstrap provides the recording `WP_CLI` stub
 * (`class-wp-cli-stub.php`) *before* the plugin boots, so the plugin's own
 * include defines the real command and registers it — this file tests the
 * class the plugin loaded, not a copy it loaded itself.
 *
 * @package SdcPageBuilder
 */

/**
 * Class RepairTest
 */
class RepairTest extends Sdc_TestCase {

	/**
	 * Marker prefix for pages this class creates, so a leftover page can be
	 * recognised on a live install.
	 */
	const TITLE = 'SDC-CREW-Reparatur';

	/**
	 * The command under test.
	 *
	 * @var Sdc_Repair
	 */
	private $command;

	/**
	 * Prepare: the command, and an empty recording.
	 */
	public function set_up() {
		parent::set_up();

		$this->command = new Sdc_Repair();
		WP_CLI::capture();
	}

	/**
	 * The command is registered, and only the plugin could have registered it:
	 * the bootstrap only supplies the stub, the plugin boot does the rest.
	 */
	public function test_command_is_registered_by_the_plugin() {
		// The namespace is registered under its own name; `wp sdc-pb repair`
		// comes from the class's `repair()` method. Pinning `sdc-pb repair`
		// here would let `wp sdc-pb repair repair` pass this test.
		$this->assertArrayHasKey( 'sdc-pb', WP_CLI::$commands );
		$this->assertSame( 'Sdc_Repair', WP_CLI::$commands['sdc-pb'] );
		$this->assertTrue( class_exists( 'Sdc_Repair' ) );
		$this->assertTrue( method_exists( 'Sdc_Repair', 'repair' ) );
	}

	/**
	 * Store a document the way the metabox did.
	 *
	 * @param int     $post_id    Post ID.
	 * @param array[] $components Components.
	 */
	private function old_document( $post_id, array $components ) {
		Sdc_Store::save_data( $post_id, array( 'components' => $components ) );
	}

	/**
	 * A page titled for this class.
	 *
	 * @param array $args Overrides for wp_insert_post().
	 * @return int Post ID.
	 */
	private function page( array $args = array() ) {
		return $this->make_page(
			array_merge(
				array( 'post_title' => self::TITLE ),
				$args
			)
		);
	}

	/**
	 * Component types in a page's content, in content order.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function types_in_content( $post_id ) {
		$types = array();

		foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id, 'raw' ) ) as $block ) {
			if ( ! empty( $block['blockName'] ) && 0 === strpos( $block['blockName'], 'sdc/' ) ) {
				$types[] = substr( $block['blockName'], 4 );
			}
		}

		return $types;
	}

	/**
	 * Run the command the way WP-CLI would.
	 *
	 * @param array $assoc_args Flags.
	 * @return array Recorded calls.
	 */
	private function run_command( array $assoc_args = array() ) {
		WP_CLI::capture();
		$this->command->repair( array(), $assoc_args );

		return WP_CLI::$calls;
	}

	/**
	 * The page the task describes: a marked page whose content holds the FAQ
	 * block and whose document also holds a hero.
	 *
	 * @return int Post ID.
	 */
	private function affected_page() {
		$post_id = $this->page(
			array(
				'post_content' => '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->',
			)
		);
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component( 'Fehlender Hero' ) ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Schon da' ) ),
			)
		);
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		return $post_id;
	}

	/**
	 * Case 1: the dry run reports the page and writes nothing — not the
	 * content, not the marker, not a revision, not even the meta of a page
	 * that is not marked yet.
	 */
	public function test_dry_run_reports_the_missing_component_and_writes_nothing() {
		$post_id = $this->page(
			array(
				'post_content' => '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->',
			)
		);
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Schon da' ) ),
			)
		);

		// Not marked: the dry run must not set the marker either, otherwise it
		// would consume the page for the automatic migration.
		$content   = get_post_field( 'post_content', $post_id, 'raw' );
		$revisions = count( wp_get_post_revisions( $post_id ) );

		$this->run_command( array( 'post' => (string) $post_id ) );
		$lines = WP_CLI::lines( 'log' );

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( '#' . $post_id . ' ' . self::TITLE, $lines[0] );
		// The document lists hero and faq; only the faq block is in the
		// content. The report names what has no block — not what has no
		// component.
		$this->assertStringContainsString( 'fehlt=[hero]', $lines[0] );

		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ) );
		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ), 'The dry run must not mark the page.' );
	}

	/**
	 * Case 2: `--apply` appends the missing component, exactly once.
	 */
	public function test_apply_adds_the_missing_component_once() {
		$post_id = $this->affected_page();

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		$this->assertSame( array( 'faq', 'hero' ), $this->types_in_content( $post_id ) );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $post_id, 'raw' ), 'Fehlender Hero' ) );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $post_id, 'raw' ), 'Schon da' ) );
		$this->assertSame( array( '1 betroffene Seite(n), 1 ergänzt.' ), WP_CLI::lines( 'success' ) );
		$this->assertSame( array(), WP_CLI::lines( 'warning' ) );
	}

	/**
	 * Case 3: a second `--apply` finds nothing — no line, no write, no
	 * revision.
	 */
	public function test_second_apply_finds_nothing() {
		$post_id = $this->affected_page();

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		$content   = get_post_field( 'post_content', $post_id, 'raw' );
		$revisions = count( wp_get_post_revisions( $post_id ) );

		$writes = 0;
		$count  = function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		add_filter( 'content_save_pre', $count );

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		remove_filter( 'content_save_pre', $count );

		$this->assertSame( array(), WP_CLI::lines( 'log' ) );
		$this->assertSame( array( 'Keine betroffene Seite gefunden.' ), WP_CLI::lines( 'success' ) );
		$this->assertSame( 0, $writes, 'The second run must not save the post.' );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( $revisions, count( wp_get_post_revisions( $post_id ) ) );
	}

	/**
	 * Case 4: a page whose content holds the whole document is not reported.
	 */
	public function test_unaffected_page_is_not_reported() {
		$post_id = $this->page(
			array(
				'post_content' => '<!-- wp:sdc/hero {"data":{"heading":"Da"}} /-->',
			)
		);
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component( 'Da' ) ),
			)
		);
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->run_command( array( 'post' => (string) $post_id ) );

		$this->assertSame( array(), WP_CLI::lines( 'log' ) );
		$this->assertSame( array( 'Keine betroffene Seite gefunden.' ), WP_CLI::lines( 'success' ) );
	}

	/**
	 * Case 5: `--post` limits the run to that page — a second affected page
	 * stays untouched and unreported.
	 */
	public function test_post_flag_limits_the_run() {
		$first  = $this->affected_page();
		$second = $this->affected_page();

		$second_content = get_post_field( 'post_content', $second, 'raw' );

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $first,
			)
		);

		$this->assertSame( array( 'faq', 'hero' ), $this->types_in_content( $first ) );
		$this->assertSame( $second_content, get_post_field( 'post_content', $second, 'raw' ) );

		$lines = WP_CLI::lines( 'log' );
		$this->assertCount( 1, $lines );
		$this->assertStringStartsWith( '#' . $first . ' ', $lines[0] );
	}

	/**
	 * Case 6: a page without a document is not touched — not reported, not
	 * marked, not rewritten.
	 */
	public function test_page_without_a_document_is_not_touched() {
		$post_id = $this->page( array( 'post_content' => 'Nur Text' ) );

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		$this->assertSame( array(), WP_CLI::lines( 'log' ) );
		$this->assertSame( 'Nur Text', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ) );
		$this->assertCount( 0, wp_get_post_revisions( $post_id ) );
	}

	/**
	 * The report and the write agree: every type the dry run lists is the
	 * type `--apply` writes, and nothing else. Two implementations of the
	 * comparison would be free to disagree; this pins them to one.
	 */
	public function test_report_lists_exactly_what_apply_writes() {
		$post_id = $this->page(
			array(
				'post_content' => '<!-- wp:sdc/faq {"data":{"heading":"Eins"}} /-->',
			)
		);
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Eins' ) ),
				array_merge( array( '_id' => 'c_faq000002' ), $this->faq_component( 'Zwei' ) ),
				array_merge( array( '_id' => 'c_faq000003' ), $this->faq_component( 'Drei' ) ),
			)
		);
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->run_command( array( 'post' => (string) $post_id ) );

		$lines = WP_CLI::lines( 'log' );
		$this->assertCount( 1, $lines );
		// Document `[hero, faq, faq, faq]`, content carries one `faq` block:
		// three components are short, and the report names exactly those.
		$this->assertStringContainsString( 'fehlt=[hero,faq,faq]', $lines[0] );

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		// The write produces the document: the faq that was there stays in
		// front, the three missing ones are appended.
		$this->assertSame( array( 'faq', 'hero', 'faq', 'faq' ), $this->types_in_content( $post_id ) );
	}

	/**
	 * A page that is marked but whose content holds no builder block is
	 * reported too: that is the page an older run marked without writing.
	 */
	public function test_marked_page_without_blocks_is_reported() {
		$post_id = $this->page( array( 'post_content' => 'Nur Text' ) );
		$this->old_document( $post_id, array( $this->faq_component( 'Nachzuholen' ) ) );
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->run_command( array( 'post' => (string) $post_id ) );

		$lines = WP_CLI::lines( 'log' );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'fehlt=[faq]', $lines[0] );
	}

	/**
	 * Blocks of another plugin in the content survive the write untouched.
	 */
	public function test_foreign_blocks_survive_the_repair() {
		$foreign = '<!-- wp:paragraph {"className":"fremd"} --><p class="fremd">Fremdtext</p><!-- /wp:paragraph -->';
		$post_id = $this->page(
			array(
				'post_content' => '<!-- wp:sdc/faq {"data":{"heading":"Schon da"}} /-->' . "\n\n" . $foreign,
			)
		);
		$this->old_document(
			$post_id,
			array(
				array_merge( array( '_id' => 'c_hero00001' ), $this->hero_component() ),
				array_merge( array( '_id' => 'c_faq000001' ), $this->faq_component( 'Schon da' ) ),
			)
		);
		update_post_meta( $post_id, Sdc_Migration::META_KEY, Sdc_Migration::VERSION );

		$this->run_command(
			array(
				'apply' => true,
				'post'  => (string) $post_id,
			)
		);

		$content = (string) get_post_field( 'post_content', $post_id, 'raw' );
		$types   = array();

		foreach ( parse_blocks( $content ) as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$types[] = $block['blockName'];
			}
		}

		$this->assertStringContainsString( $foreign, $content );
		$this->assertSame( array( 'sdc/faq', 'core/paragraph', 'sdc/hero' ), $types );
	}

	/**
	 * A page that is not a builder post type is refused by `--post`, not
	 * repaired.
	 */
	public function test_post_of_another_type_is_refused() {
		$post_id = $this->page( array( 'post_type' => 'post' ) );
		$this->old_document( $post_id, array( $this->faq_component() ) );

		try {
			$this->run_command( array( 'post' => (string) $post_id ) );
			$this->fail( 'The command must refuse a post that is not a builder post type.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Seite nicht gefunden oder kein unterstützter Inhaltstyp.', $e->getMessage() );
		}

		$this->assertSame( '', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertFalse( Sdc_Migration::is_migrated( $post_id ) );
	}
}

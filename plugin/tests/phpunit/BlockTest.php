<?php
/**
 * Blocks: one per component, rendered through the same path as the document.
 *
 * @package SdcPageBuilder
 */

/**
 * Covers the seam between block attributes and the frontend renderer.
 *
 * A hand-written `render.php` puts its values where its author decided, so the
 * tests check two different promises:
 *
 * - the *empty* path, which uses the generic markup, must name every field it
 *   prints (`data-sdc-name`). That is what lets a click in the canvas find the
 *   matching control in the sidebar.
 * - *every* component, handwritten or not, must render identically through the
 *   block and through the document. Otherwise the canvas and the page disagree
 *   and nobody notices until a client does.
 */
class BlockTest extends Sdc_TestCase {

	/**
	 * Every component type gets its own block, named and titled after it.
	 *
	 * The generic block of the first build put "SDC-Komponente" on every
	 * instance, which made three FAQ blocks on a page indistinguishable.
	 *
	 * Registration runs once per request on `init`, so the list checked here is
	 * what the schema held then — a component created later in the same request
	 * (a copy, a new folder) gets its block on the next request. That is the
	 * correct trade: `register_block_type()` after the editor has loaded its
	 * settings would do nothing anyway.
	 */
	public function test_each_component_gets_its_own_block() {
		$registry = WP_Block_Type_Registry::get_instance();

		$checked = 0;

		foreach ( array_keys( Sdc_Block::schema() ) as $type ) {
			$name = 'sdc/' . $type;

			if ( ! $registry->is_registered( $name ) ) {
				continue;
			}

			++$checked;
			$block = $registry->get_registered( $name );

			$this->assertSame( Sdc_Schema::get( $type )['label'], $block->title );
			$this->assertNotFalse(
				has_action( 'init', array( 'Sdc_Block', 'register' ) ),
				'Die Registrierung hängt an init.'
			);
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'Mindestens eine Komponente der Testumgebung hat einen eigenen Block.'
		);

		// The reverse direction, which is the promise that actually matters
		// for the inserter: no `sdc/*` block exists that is not a component.
		// A leftover registration would offer the author a block that cannot
		// render.
		foreach ( $registry->get_all_registered() as $name => $block ) {
			if ( 0 !== strpos( $name, 'sdc/' ) || 'sdc/component' === $name ) {
				continue;
			}

			$type = substr( $name, 4 );

			$this->assertArrayHasKey(
				$type,
				Sdc_Block::schema(),
				'Block "' . $name . '" gehört zu einer Komponente.'
			);
			$this->assertSame( Sdc_Schema::get( $type )['label'], $block->title );
		}
	}

	/**
	 * The fixture components specifically — the ones every other test uses —
	 * resolve to their own block names.
	 */
	public function test_the_fixture_components_have_blocks_of_their_own() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'hero', 'faq' ) as $type ) {
			$this->assertTrue(
				$registry->is_registered( 'sdc/' . $type ),
				'sdc/' . $type . ' ist registriert.'
			);
		}
	}

	/**
	 * The blocks sit in the plugin's own category, not in `design` beside the
	 * theme's blocks.
	 */
	public function test_blocks_live_in_the_plugin_category() {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'sdc/hero', 'sdc/faq', Sdc_Block::LEGACY ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				continue;
			}

			$this->assertSame(
				Sdc_Block::CATEGORY,
				$registry->get_registered( $name )->category,
				'Block "' . $name . '" liegt in der eigenen Kategorie.'
			);
		}
	}

	/**
	 * The category exists and comes first.
	 *
	 * Position is the point: a category below the theme's own is one the author
	 * scrolls past to reach the thing the editor exists for.
	 */
	public function test_the_category_is_registered_first() {
		$categories = apply_filters(
			'block_categories_all',
			array(
				array(
					'slug'  => 'text',
					'title' => 'Text',
				),
				array(
					'slug'  => 'design',
					'title' => 'Design',
				),
			),
			null
		);

		$this->assertSame( Sdc_Block::CATEGORY, $categories[0]['slug'] );
		$this->assertSame( 'SDC-Komponenten', $categories[0]['title'] );

		// The theme's categories survive, in their order.
		$this->assertSame( 'text', $categories[1]['slug'] );
		$this->assertSame( 'design', $categories[2]['slug'] );
	}

	/**
	 * Applying the filter twice must not produce two entries.
	 *
	 * `init` can run twice and some setups apply both category filters; a
	 * duplicate slug would show the author two identically named groups.
	 */
	public function test_the_category_is_added_once() {
		$once  = apply_filters( 'block_categories_all', array(), null );
		$twice = apply_filters( 'block_categories_all', $once, null );

		$this->assertCount( 1, $once );
		$this->assertCount( 1, $twice );
	}

	/**
	 * The one-block-for-everything name is still registered — old content
	 * carries it — but it is not offered in the inserter.
	 *
	 * Making it disappear entirely was the first attempt and it was wrong: an
	 * unregistered dynamic block has no stored markup to fall back on, so a
	 * page written with it rendered nothing at all. Verified on the test site:
	 * page 128 went empty.
	 */
	public function test_the_legacy_block_is_registered_but_hidden() {
		$registry = WP_Block_Type_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( Sdc_Block::LEGACY ) );

		$block = $registry->get_registered( Sdc_Block::LEGACY );

		$this->assertFalse(
			$block->supports['inserter'] ?? true,
			'Der Altlast-Block steht nicht im Inserter.'
		);

		// It also must not count as a component of its own.
		$this->assertNotContains( 'component', Sdc_Block::types() );
	}

	/**
	 * …and it renders the component named in its `type` attribute, which is
	 * what keeps an old page alive.
	 */
	public function test_the_legacy_block_renders_its_type() {
		$hero = $this->hero_component( 'Alter Block' );

		$html = Sdc_Block::render( $hero['type'], array( 'data' => $hero['data'] ) );

		$this->assertStringContainsString( 'Alter Block', $html );

		$callback = WP_Block_Type_Registry::get_instance()->get_registered( Sdc_Block::LEGACY )->render_callback;

		$this->assertIsCallable( $callback );

		$from_callback = call_user_func( $callback, $hero );

		$this->assertSame( $html, $from_callback );
	}

	/**
	 * Without a type the legacy block prints nothing rather than guessing.
	 */
	public function test_the_legacy_block_prints_nothing_without_a_type() {
		$callback = WP_Block_Type_Registry::get_instance()->get_registered( Sdc_Block::LEGACY )->render_callback;

		$this->assertSame( '', call_user_func( $callback, array() ) );
		$this->assertSame( '', call_user_func( $callback, array( 'type' => '' ) ) );
	}

	/**
	 * The block callback reads the post from the block's context, so a lost
	 * component is marked on the page it actually sits on — not on the
	 * wrong page, and not nowhere.
	 *
	 * `$block->context['postId']` is the only place the block knows which
	 * post it is rendered into. If the callback dropped it, the marker
	 * would silently vanish from exactly the pages that need it most.
	 */
	public function test_the_block_callback_reports_the_post_it_renders_in() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertGreaterThan( 0, $post_id );

		$unknown = 'k5-block-' . substr( md5( (string) $post_id ), 0, 6 );
		$block   = new WP_Block(
			array(
				'blockName'    => Sdc_Block::LEGACY,
				'attrs'        => array( 'type' => $unknown ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $post_id )
		);

		$this->assertSame( $post_id, $block->context['postId'], 'The type must declare postId in uses_context, or WP drops it.' );

		$callback = WP_Block_Type_Registry::get_instance()->get_registered( Sdc_Block::LEGACY )->render_callback;
		$html     = (string) call_user_func( $callback, array( 'type' => $unknown ), '', $block );

		$this->assertStringContainsString( '<!-- sdc:', $html, 'The block path must mark a lost component for an editor.' );
	}

	/**
	 * A registered, valid type renders through the frontend path.
	 */
	public function test_render_prints_the_component() {
		$hero = $this->hero_component( 'Block-Kopfzeile' );

		$html = Sdc_Block::render( $hero['type'], array( 'data' => $hero['data'] ) );

		$this->assertStringContainsString( 'sdc-component--hero', $html );
		$this->assertStringContainsString( 'Block-Kopfzeile', $html );
		$this->assertStringContainsString( 'Mehr erfahren', $html );
	}

	/**
	 * Block output and document output are the same markup.
	 *
	 * This is the whole point of `render_instance()`: one renderer, two
	 * placement paths. A refactor that gives the block its own formatting
	 * fails here.
	 */
	public function test_block_output_matches_the_document_output() {
		$faq = $this->faq_component( 'Fragen aus dem Block', 2 );

		$from_block = Sdc_Block::render( $faq['type'], array( 'data' => $faq['data'] ) );

		$from_document = Sdc_Frontend::render_instance( $faq['type'], $faq['data'] );

		$this->assertSame( $from_document, $from_block );
	}

	/**
	 * The generic markup names every field it prints, so a click in the canvas
	 * can be mapped to a control.
	 */
	public function test_rendered_fields_carry_their_name() {
		$faq = $this->faq_component( 'Mit Namen' );

		$html = Sdc_Block::render( $faq['type'], array( 'data' => $faq['data'] ) );

		$this->assertStringContainsString( 'data-sdc-name="heading"', $html );
	}

	/**
	 * An unknown type is dropped, not guessed at.
	 */
	public function test_render_with_an_unknown_type_prints_nothing() {
		$this->assertSame(
			'',
			Sdc_Block::render(
				'gibt-es-nicht-' . wp_generate_password( 6, false ),
				array( 'data' => array( 'heading' => 'egal' ) )
			)
		);
	}

	/**
	 * A component without values prints nothing on the page — no empty
	 * wrapper. The canvas is the exception and says why (see below).
	 */
	public function test_render_without_values_prints_nothing() {
		$html = Sdc_Block::render( 'hero', array() );

		$this->assertIsString( $html );
		$this->assertSame( '', $html, 'Ein leerer Block schreibt nichts in die Seite.' );
	}

	/**
	 * In the editor the same empty block explains itself instead of showing
	 * an empty box — the difference between "kaputt" and "noch leer".
	 */
	public function test_an_empty_block_explains_itself_in_the_editor() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulates the block-renderer request.
		$_GET['context'] = 'edit';

		try {
			$html = Sdc_Block::render( 'hero', array() );
		} finally {
			unset( $_GET['context'] );
		}

		$this->assertStringContainsString( 'sdc-block__empty', $html );
		$this->assertStringContainsString( 'noch keine Inhalte', $html );
	}

	/**
	 * …and the hint never replaces real content: with values the block renders
	 * normally, even in the editor context.
	 */
	public function test_the_editor_hint_disappears_once_values_exist() {
		$hero = $this->hero_component( 'Mit Inhalt' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simulates the block-renderer request.
		$_GET['context'] = 'edit';

		try {
			$html = Sdc_Block::render( $hero['type'], array( 'data' => $hero['data'] ) );
		} finally {
			unset( $_GET['context'] );
		}

		$this->assertStringNotContainsString( 'sdc-block__empty', $html );
		$this->assertStringContainsString( 'Mit Inhalt', $html );
	}

	/**
	 * Types placed in block content are found, including inside a container.
	 */
	public function test_types_in_finds_blocks_and_inner_blocks() {
		$content = '<!-- wp:sdc/faq {"data":{}} /-->'
			. '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:sdc/hero {"data":{}} /-->'
			. '</div><!-- /wp:column --></div><!-- /wp:columns -->';

		$types = Sdc_Block::types_in( $content );

		sort( $types );

		$this->assertSame( array( 'faq', 'hero' ), $types );
	}

	/**
	 * Content written with the generic block still yields its types, so a page
	 * built with the first build keeps its styles.
	 */
	public function test_types_in_reads_the_legacy_block() {
		$this->assertSame(
			array( 'faq' ),
			Sdc_Block::types_in( '<!-- wp:sdc/component {"type":"faq","data":{}} /-->' )
		);
	}

	/**
	 * Content without a component block is not parsed at all — the early exit
	 * keeps `parse_blocks()` off every ordinary page.
	 */
	public function test_types_in_is_a_no_op_without_component_blocks() {
		$this->assertSame( array(), Sdc_Block::types_in( '<p>Nur Text</p>' ) );
	}

	/**
	 * The inspector's field list carries `sub_fields` — a repeater without
	 * its rows would render as a plain text input in the sidebar.
	 */
	public function test_schema_keeps_repeater_sub_fields() {
		$schema = Sdc_Block::schema();

		$repeater = null;
		foreach ( $schema['faq']['fields'] as $field ) {
			if ( 'items' === $field['name'] ) {
				$repeater = $field;
			}
		}

		$this->assertIsArray( $repeater, 'Das FAQ-Schema hat ein Feld "items".' );
		$this->assertSame( 'repeater', $repeater['type'] );
		$this->assertNotEmpty( $repeater['sub_fields'] );
		$this->assertSame( 'q', $repeater['sub_fields'][0]['name'] );
		$this->assertSame( 'a', $repeater['sub_fields'][1]['name'] );
	}

	/**
	 * …and the same for every repeater, not just the one somebody looked at.
	 */
	public function test_every_repeater_has_sub_fields() {
		foreach ( Sdc_Block::schema() as $type => $component ) {
			foreach ( $component['fields'] as $field ) {
				if ( 'repeater' !== $field['type'] ) {
					continue;
				}

				$this->assertNotEmpty(
					$field['sub_fields'],
					$type . '/' . $field['name'] . ': ein Repeater braucht Felder.'
				);
			}
		}
	}

	/**
	 * Every component in the schema is reachable from the block, and each
	 * field has the two things a control cannot work without.
	 */
	public function test_schema_describes_every_component() {
		$schema = Sdc_Block::schema();

		$this->assertArrayHasKey( 'hero', $schema );
		$this->assertArrayHasKey( 'faq', $schema );

		foreach ( $schema as $type => $component ) {
			$this->assertNotEmpty( $component['label'], $type . ' hat eine Bezeichnung.' );

			foreach ( $component['fields'] as $field ) {
				$this->assertNotSame( '', $field['name'], $type . ': jedes Feld hat einen Namen.' );
				$this->assertNotSame( '', $field['type'], $type . '/' . $field['name'] . ': jeder Feldtyp ist gesetzt.' );
			}
		}
	}

	/*
	---------------------------------------------------------------------
	 * Styles
	 * ------------------------------------------------------------------
	 */

	/**
	 * The page's component CSS lands in the head as one inline stylesheet.
	 *
	 * This is the answer to caching plugins: Autoptimize, WP Rocket and peers
	 * combine, defer and drop unused *external* stylesheets, but they cannot
	 * defer CSS that is already in the document. So the test asserts the CSS
	 * is in the registered handle's inline block — not merely that a file was
	 * enqueued.
	 */
	public function test_the_pages_component_css_is_inlined_into_the_head() {
		$this->style_fixture( 'hero', '.sdc-hero{color:red}' );

		$post_id = $this->make_page(
			array( 'post_content' => '<!-- wp:sdc/hero {"data":{"heading":"X"}} /-->' )
		);

		$this->go_to( get_permalink( $post_id ) );

		Sdc_Block::print_component_styles();

		$this->assertTrue( wp_style_is( Sdc_Block::STYLES, 'enqueued' ) );

		$inline = wp_styles()->registered[ Sdc_Block::STYLES ]->extra['after'];

		$this->assertStringContainsString( '.sdc-hero{color:red}', implode( "\n", (array) $inline ) );
	}

	/**
	 * The same styles come for a page built with the metabox document, without
	 * a block in the content. Both placement paths feed the same collector.
	 */
	public function test_types_here_covers_the_metabox_document() {
		$post_id = $this->make_page();

		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component() ) ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( array( 'hero' ), Sdc_Block::types_here() );
	}

	/**
	 * A page with both placements asks for the union, each type once.
	 */
	public function test_types_here_merges_blocks_and_document() {
		$post_id = $this->make_page(
			array( 'post_content' => '<!-- wp:sdc/faq {"data":{}} /-->' )
		);

		Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					$this->hero_component(),
					$this->faq_component(),
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$types = Sdc_Block::types_here();
		sort( $types );

		$this->assertSame( array( 'faq', 'hero' ), $types );
	}

	/**
	 * The inline route and the file route never run for the same component:
	 * once inlined, a block does not also enqueue its stylesheet.
	 */
	public function test_an_inlined_component_is_not_enqueued_as_a_file() {
		$this->style_fixture( 'hero', '.sdc-hero{color:red}' );

		$post_id = $this->make_page(
			array( 'post_content' => '<!-- wp:sdc/hero {"data":{"heading":"X"}} /-->' )
		);

		$this->go_to( get_permalink( $post_id ) );

		Sdc_Block::print_component_styles();
		Sdc_Block::render( 'hero', array( 'data' => array( 'heading' => 'X' ) ) );

		$this->assertFalse( wp_style_is( 'sdc-component-hero', 'enqueued' ) );
	}

	/**
	 * With inlining switched off the collector returns nothing, which is what
	 * sends the page down the per-file path.
	 */
	public function test_inlining_can_be_switched_off() {
		$this->style_fixture( 'hero', '.sdc-hero{color:red}' );

		add_filter( 'sdc_pb_inline_component_styles', '__return_false' );

		$this->assertSame( '', Sdc_Block::styles( array( 'hero' ) ) );
	}

	/**
	 * The version is a hash of the CSS, not a timestamp: identical styles must
	 * not look like a change to a caching layer on every page load.
	 */
	public function test_the_inline_stylesheet_is_versioned_by_content() {
		$this->style_fixture( 'hero', '.sdc-hero{color:red}' );

		$first = Sdc_Block::styles( array( 'hero' ) );
		$again = Sdc_Block::styles( array( 'hero' ) );

		$this->assertSame( $first, $again );
		$this->assertNotSame( '', $first );
	}

	/*
	---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------
	 */

	/**
	 * A stylesheet in the site's own component directory, removed afterwards.
	 *
	 * The lookup in `Sdc_View::style()` only needs `<dir>/<type>/component.css`
	 * to exist — the component's definition may live elsewhere, which keeps
	 * this fixture independent of where the suite's components come from.
	 *
	 * @param string $type Component type.
	 * @param string $css  Stylesheet contents.
	 */
	private function style_fixture( $type, $css ) {
		$dir = untrailingslashit( WP_CONTENT_DIR ) . '/' . Sdc_Schema::USER_DIR . '/' . $type;

		$this->assertTrue( wp_mkdir_p( $dir ) );

		// Remembered so tear_down() removes exactly what this test created.
		$this->style_dirs[] = $dir;

		file_put_contents( $dir . '/component.css', $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}

	/**
	 * Directories created by style_fixture(), removed in tear_down().
	 *
	 * @var string[]
	 */
	private $style_dirs = array();

	/**
	 * Remove the stylesheet fixtures and the inlined-style memory.
	 */
	public function tear_down() {
		foreach ( $this->style_dirs as $dir ) {
			$file = $dir . '/component.css';

			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			}

			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture, empty by now.
			}
		}

		$this->style_dirs = array();

		parent::tear_down();
	}
}

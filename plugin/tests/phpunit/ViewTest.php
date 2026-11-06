<?php
/**
 * The view layer: the `render.php` next to a component, the helpers it calls.
 *
 * @package SdcPageBuilder
 */

/**
 * Covers `Sdc_View` and the `sdc_*` helpers: the path rules, the body round
 * trip through the admin, `lint()` as the gate in front of the disk, the
 * context the helpers read from, and the two places that consume all of it —
 * `Sdc_Frontend` rendering a component, and the stylesheet enqueue.
 *
 * The fixtures live in their own scratch directory under the system temp dir
 * and are removed in `tear_down()`; the one exception is the stylesheet test,
 * which has to sit under a root WordPress knows a URL for and cleans up after
 * itself.
 */
class ViewTest extends Sdc_TestCase {

	/**
	 * Scratch root for the current test.
	 *
	 * @var string
	 */
	private $root = '';

	/**
	 * Component base directory inside the scratch root.
	 *
	 * @var string
	 */
	private $components = '';

	/**
	 * Directories created under WP_CONTENT_DIR, removed in tear_down().
	 *
	 * @var string[]
	 */
	private $content_dirs = array();

	/**
	 * Attachments created by the current test: `array( id, file )` pairs.
	 *
	 * @var array[]
	 */
	private $attachments = array();

	/**
	 * A scratch directory and a clean context stack.
	 */
	public function set_up() {
		parent::set_up();

		$this->root       = untrailingslashit( get_temp_dir() ) . '/sdc-view-' . wp_generate_password( 8, false );
		$this->components = $this->root . '/' . Sdc_Schema::USER_DIR;

		$this->assertTrue( wp_mkdir_p( $this->components ) );

		// A test that dies mid-render must not poison the next one.
		while ( null !== Sdc_View::context() ) {
			Sdc_View::pop();
		}
	}

	/**
	 * Remove the scratch directories and the filters this suite installed.
	 */
	public function tear_down() {
		while ( null !== Sdc_View::context() ) {
			Sdc_View::pop();
		}

		remove_all_filters( 'sdc_pb_component_dirs' );
		remove_all_filters( 'sdc_pb_component_style_src' );
		remove_all_filters( 'sdc_pb_can_edit_view' );

		foreach ( array_reverse( $this->content_dirs ) as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->content_dirs = array();

		foreach ( $this->attachments as $pair ) {
			wp_delete_attachment( $pair[0], true );
			if ( file_exists( $pair[1] ) ) {
				wp_delete_file( $pair[1] );
			}
		}
		$this->attachments = array();

		if ( '' !== $this->root ) {
			$this->remove_dir( $this->root );
		}

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------ */

	/**
	 * The view of a component is `render.php` in that component's directory.
	 */
	public function test_file_path_is_the_component_directory_plus_render_php() {
		$this->assertSame(
			'/srv/components/hero/render.php',
			Sdc_View::file( '/srv/components/', 'hero' )
		);
	}

	/**
	 * A type is a path segment, so anything with a separator is refused.
	 */
	public function test_file_rejects_a_type_with_path_parts() {
		$this->assertSame( '', Sdc_View::file( '/srv/components', '../../etc/passwd' ) );
		$this->assertSame( '', Sdc_View::file( '/srv/components', 'hero/../../x' ) );
		$this->assertSame( '', Sdc_View::file( '/srv/components', '' ) );
		$this->assertSame( '', Sdc_View::file( '/srv/components', 'mit leerzeichen' ) );
	}

	/**
	 * Without a root directory there is no path to build.
	 */
	public function test_file_needs_a_directory() {
		$this->assertSame( '', Sdc_View::file( '', 'hero' ) );
	}

	/**
	 * The stylesheet is the view's file name replaced by `component.css`.
	 */
	public function test_style_file_sits_next_to_the_view() {
		$this->assertSame(
			'/srv/components/hero/component.css',
			Sdc_View::style_file( '/srv/components', 'hero' )
		);
		$this->assertSame( '', Sdc_View::style_file( '/srv/components', '../x' ) );
	}

	/**
	 * The stylesheet is found in whichever component directory the site uses.
	 */
	public function test_style_finds_the_stylesheet_in_a_component_directory() {
		$css = $this->components . '/hero/component.css';

		$this->assertTrue( wp_mkdir_p( dirname( $css ) ) );
		file_put_contents( $css, '.sdc-hero{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->use_components();

		$this->assertSame( $css, Sdc_View::style( 'hero' ) );
	}

	/**
	 * A component without a stylesheet has none, and a bad type has no path.
	 */
	public function test_style_returns_empty_without_a_stylesheet() {
		$this->use_components();

		$this->assertSame( '', Sdc_View::style( 'hero' ) );
		$this->assertSame( '', Sdc_View::style( '../hero' ) );
		$this->assertSame( '', Sdc_View::style( '' ) );
	}

	/* ---------------------------------------------------------------------
	 * Body round trip
	 * ------------------------------------------------------------------ */

	/**
	 * The wrapper writes a header, the marker, and then the body as a template.
	 */
	public function test_wrap_marks_the_body_and_holds_a_docblock() {
		$code = Sdc_View::wrap( 'hero', '<p><?php sdc_text( \'heading\' ); ?></p>' );

		$this->assertStringStartsWith( '<?php', $code );
		$this->assertStringContainsString( 'Ansicht der Komponente "hero"', $code );
		$this->assertStringContainsString( Sdc_View::BODY_MARKER . ' ?>', $code );
		$this->assertStringEndsWith( '<p><?php sdc_text( \'heading\' ); ?></p>' . "\n", $code );
	}

	/**
	 * A type cannot break out of the header comment it is printed into.
	 */
	public function test_wrap_neutralises_a_closing_comment_in_the_type() {
		$code = Sdc_View::wrap( 'he*/ro', '<p>x</p>' );

		// The header's closing comment and the marker's — nothing else.
		$this->assertSame( 2, substr_count( $code, '*/' ) );
		$this->assertSame( true, Sdc_View::lint( Sdc_View::body_of( $code ) ) );
	}

	/**
	 * Reading a file returns everything below the marker, not the header.
	 */
	public function test_body_of_reads_everything_below_the_marker() {
		$code = "<?php\n/**\n * Header.\n */\n" . Sdc_View::BODY_MARKER . " ?>\n<p>Inhalt</p>\n";

		$this->assertSame( "<p>Inhalt</p>\n", Sdc_View::body_of( $code ) );
	}

	/**
	 * A hand-written file has no marker; its body starts after the open tag.
	 */
	public function test_body_of_falls_back_to_the_opening_tag() {
		$this->assertSame( "<p>Hand</p>\n", Sdc_View::body_of( "<?php\n<p>Hand</p>\n" ) );
	}

	/**
	 * Pasting a whole file into the editor must not paste its header twice.
	 */
	public function test_normalize_strips_bom_and_pasted_header() {
		$body = "\xEF\xBB\xBF<?php\n/**\n * Ansicht.\n */\n" . Sdc_View::BODY_MARKER . "\n<p>x</p>";

		$normalized = Sdc_View::normalize( $body );

		$this->assertSame( "<p>x</p>\n", $normalized );
		$this->assertSame( 1, substr_count( Sdc_View::wrap( 'hero', $normalized ), Sdc_View::BODY_MARKER ) );
	}

	/**
	 * The round trip through the file system keeps the body byte for byte.
	 */
	public function test_read_returns_the_body_of_a_written_file() {
		$body = "<div class=\"hero\">\n\t<?php sdc_text( 'heading' ); ?>\n</div>";

		$this->assertTrue( Sdc_View::write( $this->components, 'hero', $body ) );

		$file = Sdc_View::file( $this->components, 'hero' );

		$this->assertFileExists( $file );
		$this->assertSame( $body . "\n", Sdc_View::read( $file ) );
	}

	/**
	 * A missing file reads as an empty body, not as a warning.
	 */
	public function test_read_returns_empty_for_a_missing_file() {
		$this->assertSame( '', Sdc_View::read( $this->components . '/nichts/render.php' ) );
		$this->assertSame( '', Sdc_View::read( '' ) );
	}

	/* ---------------------------------------------------------------------
	 * Lint
	 * ------------------------------------------------------------------ */

	/**
	 * A normal view is markup with PHP islands, exactly like a theme file.
	 */
	public function test_lint_accepts_a_template_with_php_islands() {
		$body = "<article>\n<?php if ( sdc_has( 'heading' ) ) : ?>\n<h2><?php sdc_text( 'heading' ); ?></h2>\n<?php endif; ?>\n</article>";

		$this->assertTrue( Sdc_View::lint( $body ) );
	}

	/**
	 * An empty body is valid: it is the way back to the generic output.
	 */
	public function test_lint_accepts_an_empty_body() {
		$this->assertTrue( Sdc_View::lint( '' ) );
		$this->assertTrue( Sdc_View::lint( "   \n\t" ) );
	}

	/**
	 * A syntax error is refused, with the line the author has to look at.
	 */
	public function test_lint_rejects_a_syntax_error_with_its_line() {
		$result = Sdc_View::lint( "<p>ok</p>\n<?php if ( : ?>\n" );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_view_syntax', $result->get_error_code() );
		$this->assertStringContainsString( 'Zeile 2', $result->get_error_message() );
	}

	/**
	 * A template may close and reopen PHP; the lint sees a template, not an
	 * expression, so a leading `?>` is not an error.
	 */
	public function test_lint_accepts_a_body_that_closes_php_first() {
		$this->assertTrue( Sdc_View::lint( "?>\n<p>x</p>\n<?php echo 'y';" ) );
	}

	/* ---------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * Writing a view creates the component directory it belongs in.
	 */
	public function test_write_creates_the_component_directory() {
		$result = Sdc_View::write( $this->components, 'neu', '<p>neu</p>' );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $this->components . '/neu' );
		$this->assertSame( "<p>neu</p>\n", Sdc_View::read( $this->components . '/neu/render.php' ) );
	}

	/**
	 * A second save replaces the body instead of appending to it.
	 */
	public function test_write_replaces_an_existing_view() {
		Sdc_View::write( $this->components, 'hero', '<p>alt</p>' );
		Sdc_View::write( $this->components, 'hero', '<p>neu</p>' );

		$this->assertSame( "<p>neu</p>\n", Sdc_View::read( Sdc_View::file( $this->components, 'hero' ) ) );
	}

	/**
	 * A body with a syntax error never reaches the disk.
	 */
	public function test_write_refuses_a_syntax_error_and_writes_nothing() {
		$result = Sdc_View::write( $this->components, 'hero', '<?php if ( ; ?>' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_view_syntax', $result->get_error_code() );
		$this->assertFileDoesNotExist( Sdc_View::file( $this->components, 'hero' ) );
	}

	/**
	 * An empty body deletes the view: no view means the generic output.
	 */
	public function test_write_with_an_empty_body_removes_the_view() {
		$file = Sdc_View::file( $this->components, 'hero' );

		$this->assertTrue( Sdc_View::write( $this->components, 'hero', '<p>x</p>' ) );
		$this->assertFileExists( $file );

		$this->assertTrue( Sdc_View::write( $this->components, 'hero', '' ) );
		$this->assertFileDoesNotExist( $file );

		// Deleting a view that is already gone is not an error.
		$this->assertTrue( Sdc_View::write( $this->components, 'hero', '' ) );
	}

	/**
	 * A type that is not a file name is refused before anything is written.
	 */
	public function test_write_rejects_a_type_with_path_parts() {
		$result = Sdc_View::write( $this->components, '../boese', '<p>x</p>' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_view_type', $result->get_error_code() );
	}

	/* ---------------------------------------------------------------------
	 * Context
	 * ------------------------------------------------------------------ */

	/**
	 * The stack exists because a view may render another component.
	 */
	public function test_push_pop_returns_to_the_previous_context() {
		Sdc_View::push( 'hero', array( 'heading' => 'Außen' ) );
		Sdc_View::push( 'faq', array( 'heading' => 'Innen' ) );

		$this->assertSame( 'faq', Sdc_View::type() );
		$this->assertSame( array( 'heading' => 'Innen' ), Sdc_View::data() );

		Sdc_View::pop();

		$this->assertSame( 'hero', Sdc_View::type() );
		$this->assertSame( array( 'heading' => 'Außen' ), Sdc_View::data() );

		Sdc_View::pop();

		$this->assertNull( Sdc_View::context() );
	}

	/**
	 * Outside a render there is no component, and the helpers print nothing.
	 */
	public function test_context_is_empty_outside_a_render() {
		$this->assertNull( Sdc_View::context() );
		$this->assertNull( Sdc_View::data() );
		$this->assertSame( '', Sdc_View::type() );
		$this->assertSame( '', $this->capture( 'sdc_text', 'heading' ) );
		$this->assertNull( Sdc_View::resolve( 'heading' ) );
	}

	/**
	 * Dotted paths reach a group's sub-field.
	 */
	public function test_resolve_reads_dotted_paths() {
		Sdc_View::push( 'hero', array( 'cta' => array( 'title' => 'Mehr erfahren' ) ) );

		$this->assertSame( 'Mehr erfahren', Sdc_View::resolve( 'cta.title' ) );
		$this->assertSame( array( 'title' => 'Mehr erfahren' ), Sdc_View::resolve( 'cta' ) );
	}

	/**
	 * A path that does not exist is null, never a warning.
	 */
	public function test_resolve_returns_null_for_a_missing_path() {
		$data = array( 'heading' => 'x', 'cta' => array( 'title' => 'y' ) );

		$this->assertNull( Sdc_View::resolve( 'nichts', $data ) );
		$this->assertNull( Sdc_View::resolve( 'cta.url', $data ) );
		$this->assertNull( Sdc_View::resolve( 'heading.deep', $data ) );
		$this->assertNull( Sdc_View::resolve( '', $data ) );
		$this->assertNull( Sdc_View::resolve( 'heading', 'kein array' ) );
	}

	/**
	 * An explicit context wins over the component being rendered.
	 */
	public function test_resolve_prefers_the_given_context() {
		Sdc_View::push( 'hero', array( 'heading' => 'Komponente' ) );

		$this->assertSame( 'Zeile', Sdc_View::resolve( 'heading', array( 'heading' => 'Zeile' ) ) );
		$this->assertSame( 'Zeile', $this->capture( 'sdc_text', 'heading', array( 'heading' => 'Zeile' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Value normalisation
	 * ------------------------------------------------------------------ */

	/**
	 * Text output has one shape, whatever the field returned.
	 */
	public function test_to_text_flattens_arrays_and_bools() {
		$this->assertSame( 'Text', Sdc_View::to_text( 'Text' ) );
		$this->assertSame( '42', Sdc_View::to_text( 42 ) );
		$this->assertSame( '1', Sdc_View::to_text( true ) );
		$this->assertSame( '', Sdc_View::to_text( false ) );
		$this->assertSame( '', Sdc_View::to_text( null ) );
		$this->assertSame( '', Sdc_View::to_text( new stdClass() ) );
		$this->assertSame( 'a, b', Sdc_View::to_text( array( 'a', 'b' ) ) );
	}

	/**
	 * Hosts return labels, values or objects for a choice; all are readable.
	 */
	public function test_choices_text_reads_labels_and_urls() {
		$value = array(
			array( 'label' => 'Rot' ),
			array( 'name' => 'Grün' ),
			array( 'title' => 'Blau' ),
			array( 'url' => 'https://example.org/x' ),
			array( 'ohne' => 'nichts' ),
			'Gelb',
		);

		$this->assertSame( 'Rot, Grün, Blau, https://example.org/x, Gelb', Sdc_View::choices_text( $value ) );
		$this->assertSame( 'einzeln', Sdc_View::choices_text( 'einzeln' ) );
		$this->assertSame( '', Sdc_View::choices_text( null ) );
	}

	/**
	 * A link value is an array from the host and a string from a document that
	 * was written by hand.
	 */
	public function test_link_parts_normalises_array_string_and_nothing() {
		$this->assertSame(
			array(
				'url'    => 'https://example.org/ziel',
				'title'  => 'Los',
				'target' => '_blank',
			),
			Sdc_View::link_parts(
				array(
					'url'    => 'https://example.org/ziel',
					'title'  => 'Los',
					'target' => '_blank',
				)
			)
		);

		$this->assertSame(
			array(
				'url'    => 'https://example.org/ziel',
				'title'  => '',
				'target' => '',
			),
			Sdc_View::link_parts( 'https://example.org/ziel' )
		);

		$this->assertNull( Sdc_View::link_parts( '' ) );
		$this->assertNull( Sdc_View::link_parts( array( 'title' => 'ohne Ziel' ) ) );
		$this->assertNull( Sdc_View::link_parts( null ) );
	}

	/**
	 * An attachment array without alt text is completed from the media library.
	 */
	public function test_attachment_parts_reads_the_media_library_for_alt_and_title() {
		$id = self::factory()->attachment->create( array( 'file' => 'sdc-view-test.jpg' ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Ein Alt' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Titel im Medienarchiv',
			)
		);

		$parts = Sdc_View::attachment_parts(
			array(
				'ID'  => $id,
				'url' => 'https://example.org/bild.jpg',
			)
		);

		$this->assertSame( 'https://example.org/bild.jpg', $parts['url'] );
		$this->assertSame( 'Ein Alt', $parts['alt'] );
		$this->assertSame( 'Titel im Medienarchiv', $parts['title'] );
		$this->assertSame( (int) $id, $parts['id'] );
	}

	/**
	 * A plain URL is an image value too; nothing usable is null.
	 */
	public function test_attachment_parts_accepts_a_url_and_rejects_nothing() {
		$parts = Sdc_View::attachment_parts( 'https://example.org/bild.jpg' );

		$this->assertSame( 'https://example.org/bild.jpg', $parts['url'] );
		$this->assertSame( 0, $parts['id'] );
		$this->assertSame( '', $parts['alt'] );

		$this->assertNull( Sdc_View::attachment_parts( '' ) );
		$this->assertNull( Sdc_View::attachment_parts( null ) );
		$this->assertNull( Sdc_View::attachment_parts( 0 ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The documented helper list is the helper list that exists.
	 */
	public function test_helpers_list_matches_the_registered_functions() {
		$helpers = Sdc_View::helpers();

		$this->assertCount( 12, $helpers );

		$names = array();
		foreach ( $helpers as $helper ) {
			$this->assertArrayHasKey( 'signature', $helper );
			$this->assertArrayHasKey( 'description', $helper );
			$this->assertNotSame( '', $helper['description'] );
			$names[] = strtok( $helper['signature'], ' (' );
		}

		$this->assertSame(
			array(
				'sdc_text',
				'sdc_html',
				'sdc_attr',
				'sdc_url',
				'sdc_image',
				'sdc_link',
				'sdc_file',
				'sdc_has',
				'sdc_value',
				'sdc_each',
				'sdc_fields',
				'sdc_class',
			),
			$names
		);

		foreach ( $names as $name ) {
			$this->assertTrue( function_exists( $name ), $name . ' fehlt' );
		}
	}

	/**
	 * Text is escaped, not printed raw.
	 */
	public function test_sdc_text_escapes_the_value() {
		Sdc_View::push( 'hero', array( 'heading' => 'H <b>fett</b> & "Anführungszeichen"' ) );

		$this->assertSame(
			'H &lt;b&gt;fett&lt;/b&gt; &amp; &quot;Anführungszeichen&quot;',
			$this->capture( 'sdc_text', 'heading' )
		);
	}

	/**
	 * An attribute value escapes quotes, so it cannot end the attribute.
	 */
	public function test_sdc_attr_escapes_quotes() {
		Sdc_View::push( 'hero', array( 'id' => 'a" onmouseover="alert(1)' ) );

		$out = $this->capture( 'sdc_attr', 'id' );

		$this->assertStringNotContainsString( '" onmouseover="', $out );
		$this->assertStringContainsString( '&quot;', $out );
	}

	/**
	 * A URL field is printed through `esc_url`, which drops foreign schemes.
	 */
	public function test_sdc_url_drops_unsafe_schemes() {
		Sdc_View::push(
			'hero',
			array(
				'cta'    => array( 'url' => 'https://example.org/ziel?mit=query' ),
				'boese'  => 'javascript:alert(1)',
				'relativ' => '/kontakt',
			)
		);

		$this->assertSame( 'https://example.org/ziel?mit=query', $this->capture( 'sdc_url', 'cta' ) );
		$this->assertStringNotContainsString( 'javascript', $this->capture( 'sdc_url', 'boese' ) );
		$this->assertSame( '/kontakt', $this->capture( 'sdc_url', 'relativ' ) );
	}

	/**
	 * Formatting that a post may carry survives; a script tag does not.
	 */
	public function test_sdc_html_survives_kses() {
		Sdc_View::push( 'hero', array( 'body' => '<p>Absatz mit <b>fett</b></p><script>alert(1)</script>' ) );

		$out = $this->capture( 'sdc_html', 'body' );

		$this->assertStringContainsString( '<p>Absatz mit <b>fett</b></p>', $out );
		$this->assertStringNotContainsString( '<script', $out );
	}

	/**
	 * `0` is a value: a select with the option 0 is not an empty field.
	 */
	public function test_sdc_has_treats_zero_as_a_value() {
		Sdc_View::push(
			'hero',
			array(
				'null'  => '0',
				'leer'  => '   ',
				'falsch' => false,
				'liste' => array( array( 'q' => 'x' ) ),
				'leerliste' => array(),
			)
		);

		$this->assertTrue( sdc_has( 'null' ) );
		$this->assertFalse( sdc_has( 'leer' ) );
		$this->assertFalse( sdc_has( 'falsch' ) );
		$this->assertTrue( sdc_has( 'liste' ) );
		$this->assertFalse( sdc_has( 'leerliste' ) );
		$this->assertFalse( sdc_has( 'fehlt' ) );
	}

	/**
	 * `sdc_value()` is the way out of the escaping, so it returns raw data.
	 */
	public function test_sdc_value_returns_raw_values_and_fallbacks() {
		Sdc_View::push( 'hero', array( 'items' => array( 'a', 'b' ) ) );

		$this->assertSame( array( 'a', 'b' ), sdc_value( 'items' ) );
		$this->assertSame( 'Ersatz', sdc_value( 'fehlt', null, 'Ersatz' ) );
		$this->assertNull( sdc_value( 'fehlt' ) );
	}

	/**
	 * An image becomes one `<img>` with the attributes a tag needs.
	 */
	public function test_sdc_image_builds_one_img_tag() {
		Sdc_View::push(
			'hero',
			array(
				'photo' => array(
					'url' => 'https://example.org/bild.jpg',
					'alt' => 'Beschreibung',
				),
			)
		);

		$out = $this->capture( 'sdc_image', 'photo', 'large', array( 'class' => 'hero__img' ) );

		$this->assertStringStartsWith( '<img ', $out );
		$this->assertStringContainsString( 'src="https://example.org/bild.jpg"', $out );
		$this->assertStringContainsString( 'alt="Beschreibung"', $out );
		$this->assertStringContainsString( 'class="hero__img"', $out );
		$this->assertStringContainsString( 'loading="lazy"', $out );
		$this->assertSame( 1, substr_count( $out, 'src=' ) );

		// The attribute list cannot replace the source.
		$this->assertStringNotContainsString(
			'boese.jpg',
			$this->capture( 'sdc_image', 'photo', 'large', array( 'src' => 'boese.jpg' ) )
		);
	}

	/**
	 * An empty image field prints nothing at all, not an empty tag.
	 */
	public function test_sdc_image_prints_nothing_without_a_source() {
		Sdc_View::push( 'hero', array( 'photo' => '' ) );

		$this->assertSame( '', $this->capture( 'sdc_image', 'photo' ) );
		$this->assertSame( '', $this->capture( 'sdc_image', 'fehlt' ) );
	}

	/**
	 * A link takes its label from the field and opens safely in a new tab.
	 */
	public function test_sdc_link_uses_the_field_title() {
		Sdc_View::push(
			'hero',
			array(
				'cta' => array(
					'url'    => 'https://example.org/ziel',
					'title'  => 'Mehr erfahren',
					'target' => '_blank',
				),
			)
		);

		$out = $this->capture( 'sdc_link', 'cta', 'button' );

		$this->assertStringContainsString( 'href="https://example.org/ziel"', $out );
		$this->assertStringContainsString( 'class="button"', $out );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $out );
		$this->assertStringContainsString( '>Mehr erfahren</a>', $out );

		$this->assertStringContainsString(
			'>Anderer Text</a>',
			$this->capture( 'sdc_link', 'cta', '', 'Anderer Text' )
		);

		$this->assertSame( '', $this->capture( 'sdc_link', 'fehlt' ) );
	}

	/**
	 * A file without a title is labelled with its file name.
	 */
	public function test_sdc_file_falls_back_to_the_file_name() {
		Sdc_View::push( 'hero', array( 'doc' => array( 'url' => 'https://example.org/download/preisliste.pdf' ) ) );

		$out = $this->capture( 'sdc_file', 'doc', 'download' );

		$this->assertStringContainsString( 'href="https://example.org/download/preisliste.pdf"', $out );
		$this->assertStringContainsString( '>preisliste.pdf</a>', $out );
		$this->assertSame( '', $this->capture( 'sdc_file', 'fehlt' ) );
	}

	/**
	 * The loop hands the row and its index to the callback.
	 */
	public function test_sdc_each_passes_row_and_index() {
		Sdc_View::push(
			'faq',
			array(
				'items' => array(
					array( 'q' => 'Erste' ),
					array( 'q' => 'Zweite' ),
				),
			)
		);

		ob_start();
		sdc_each(
			'items',
			function ( $row, $index ) {
				echo $index . ':' . $row['q'] . '|';
			}
		);
		$out = (string) ob_get_clean();

		$this->assertSame( '0:Erste|1:Zweite|', $out );
	}

	/**
	 * An empty or missing list is not a loop.
	 */
	public function test_sdc_each_ignores_empty_lists() {
		Sdc_View::push( 'faq', array( 'items' => array() ) );

		$called = 0;
		$count  = function () use ( &$called ) {
			++$called;
		};

		ob_start();
		sdc_each( 'items', $count );
		sdc_each( 'fehlt', $count );
		sdc_each( 'items', 'keine_funktion_xyz' );
		$out = (string) ob_get_clean();

		$this->assertSame( '', $out );
		$this->assertSame( 0, $called );
	}

	/**
	 * A class attribute is built from the parts that carry something.
	 */
	public function test_sdc_class_drops_empty_parts() {
		$this->assertSame( 'class="hero hero--gross"', $this->capture( 'sdc_class', 'hero', '', '  ', 'hero--gross' ) );
		$this->assertSame( '', $this->capture( 'sdc_class', '', '' ) );
	}

	/**
	 * A view that only wants its own wrapper calls `sdc_fields()`.
	 */
	public function test_sdc_fields_prints_the_generic_markup() {
		Sdc_View::push( 'hero', $this->hero_component()['data'] );

		$out = $this->capture( 'sdc_fields' );

		$this->assertStringContainsString( 'Willkommen', $out );
		$this->assertStringContainsString( 'sdc-field', $out );

		Sdc_View::pop();

		$this->assertSame( '', $this->capture( 'sdc_fields' ) );
	}

	/* ---------------------------------------------------------------------
	 * Scaffold
	 * ------------------------------------------------------------------ */

	/**
	 * The generated view uses the helper that fits each field type.
	 */
	public function test_scaffold_uses_the_helper_for_every_field_type() {
		$body = Sdc_View::scaffold( 'hero', $this->scaffold_fields() );

		$this->assertStringContainsString( "sdc_text( 'heading' )", $body );
		$this->assertStringContainsString( "sdc_html( 'body' )", $body );
		$this->assertStringContainsString( "sdc_image( 'photo', 'large' )", $body );
		$this->assertStringContainsString( "sdc_file( 'doc' )", $body );
		$this->assertStringContainsString( "sdc_link( 'cta', 'button' )", $body );
		$this->assertStringContainsString( "sdc_has( 'flag' )", $body );
		$this->assertStringContainsString( "sdc_text( 'group1.title' )", $body );
		$this->assertStringContainsString( 'class="hero__heading"', $body );
	}

	/**
	 * Inside a loop the row travels as the helper's context argument.
	 */
	public function test_scaffold_of_a_repeater_passes_the_row() {
		$body = Sdc_View::scaffold( 'faq', $this->scaffold_fields() );

		// The generated code says `$row`, not `\$row`: it is PHP that runs.
		$this->assertStringContainsString( 'sdc_each( \'items\', function ( $row ) {', $body );
		$this->assertStringContainsString( "sdc_text( 'q', \$row )", $body );

		/*
		 * The row is the *last* parameter of every helper, so a helper with
		 * options in between needs those spelled out. Passing `, $row` right
		 * after `'large'` put the row into `$attr` and rendered nothing.
		 */
		$this->assertStringContainsString( "sdc_image( 'img', 'large', array(), \$row )", $body );
		$this->assertStringContainsString( "sdc_file( 'doc', '', \$row )", $body );
		$this->assertStringContainsString( "sdc_link( 'cta', 'button', '', \$row )", $body );
		$this->assertStringContainsString( "sdc_has( 'flag', \$row )", $body );
		$this->assertStringContainsString( "sdc_html( 'txt', \$row )", $body );
	}

	/**
	 * The generated view has to parse: it is saved through `write()`, which
	 * lints, so a scaffold that does not lint cannot be saved at all.
	 */
	public function test_scaffold_is_valid_php() {
		$this->assertTrue( Sdc_View::lint( Sdc_View::scaffold( 'hero', $this->scaffold_fields() ) ) );
		$this->assertTrue( Sdc_View::lint( Sdc_View::scaffold( 'leer', array() ) ) );
	}

	/**
	 * Without fields the generated view is the generic output.
	 */
	public function test_scaffold_without_fields_calls_sdc_fields() {
		$body = Sdc_View::scaffold( 'leer', array() );

		$this->assertStringContainsString( '<?php sdc_fields(); ?>', $body );
	}

	/**
	 * The hint names the component whose fields were used.
	 */
	public function test_scaffold_hint_names_the_component() {
		$this->assertStringContainsString( 'hero', Sdc_View::scaffold_hint( 'hero' ) );
	}

	/* ---------------------------------------------------------------------
	 * The generated view, executed
	 *
	 * The tests above compare the generator's output as text, and a string
	 * comparison cannot see a comment that only becomes visible once PHP has
	 * been closed. These render the scaffold through the frontend, which is
	 * the only way such a bug shows up.
	 * ------------------------------------------------------------------ */

	/**
	 * Fields with a repeat, so the scaffold has to produce real markup.
	 *
	 * @return array[]
	 */
	private function generated_fields() {
		return array(
			array(
				'name'  => 'heading',
				'label' => 'Überschrift',
				'type'  => 'text',
			),
			array(
				'name'       => 'items',
				'label'      => 'Einträge',
				'type'       => 'repeater',
				'sub_fields' => array(
					array(
						'name'  => 'q',
						'label' => 'Frage',
						'type'  => 'text',
					),
				),
			),
		);
	}

	/**
	 * What the generator hands to the textarea must not print itself.
	 *
	 * The body is a template, so the wrapper has already closed PHP for it.
	 * A bare block comment therefore ends up in HTML mode and every generated
	 * view showed its own header to visitors.
	 */
	public function test_a_generated_view_prints_no_header() {
		$this->component_fixture( 'faq', Sdc_View::wrap( 'faq', Sdc_View::scaffold( 'faq', $this->generated_fields() ) ) );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component( 'Fragen & Antworten', 2 ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringNotContainsString( 'Ansicht von', $html );
		$this->assertStringNotContainsString( 'Frei umbaubar', $html );
	}

	/**
	 * The scaffold is usable as-is: fields, repeat and escaping all land.
	 */
	public function test_a_generated_view_renders_its_fields() {
		$this->component_fixture( 'faq', Sdc_View::wrap( 'faq', Sdc_View::scaffold( 'faq', $this->generated_fields() ) ) );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component( 'Fragen & Antworten', 2 ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( 'class="faq__heading"', $html );
		$this->assertStringContainsString( 'Fragen &amp; Antworten', $html );
		$this->assertStringContainsString( 'Frage 1', $html );
		$this->assertStringContainsString( 'Frage 2', $html );
	}

	/**
	 * A file field stores an ID, and the file need not be an image.
	 *
	 * `wp_get_attachment_image_src()` answers false for a PDF, so the lookup
	 * that only asked for an image source returned null and the helper
	 * printed nothing — a document link silently missing from every page.
	 */
	public function test_a_file_field_renders_a_non_image_attachment() {
		$id = $this->attachment_fixture( 'application/pdf', 'probe.pdf', 'Probe PDF' );

		ob_start();
		sdc_file( 'doc', '', array( 'doc' => $id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<a href=', $html );
		$this->assertStringContainsString( 'Probe PDF', $html );
	}

	/**
	 * An image field still refuses a document: a PDF is not a picture.
	 */
	public function test_an_image_field_skips_a_non_image_attachment() {
		$id = $this->attachment_fixture( 'application/pdf', 'probe.pdf', 'Probe PDF' );

		ob_start();
		sdc_image( 'img', 'large', array(), array( 'img' => $id ) );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/* ---------------------------------------------------------------------
	 * Capability
	 * ------------------------------------------------------------------ */

	/**
	 * A view is PHP on the server, so the gate is the theme editor's gate.
	 */
	public function test_can_edit_follows_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Sdc_View::can_edit() );
		$this->assertTrue( Sdc_View::can_edit( 'hero' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Sdc_View::can_edit( 'hero' ) );
	}

	/**
	 * A site can switch view editing off without touching the code.
	 */
	public function test_can_edit_can_be_refused_by_filter() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'sdc_pb_can_edit_view', '__return_false' );

		$this->assertFalse( Sdc_View::can_edit( 'hero' ) );

		remove_filter( 'sdc_pb_can_edit_view', '__return_false' );

		$this->assertTrue( Sdc_View::can_edit( 'hero' ) );
	}

	/* ---------------------------------------------------------------------
	 * Runtime: the frontend as the caller
	 * ------------------------------------------------------------------ */

	/**
	 * A view reads its component through the helpers — no `$data` access, no
	 * escaping to remember.
	 *
	 * This is the seam the whole class exists for: the frontend pushes the
	 * context, the template calls `sdc_text()`, the value comes out escaped.
	 */
	public function test_a_view_reads_its_component_through_the_helpers() {
		$this->component_fixture(
			'faq',
			'<article class="v-faq">'
			. "<?php sdc_text( 'heading' ); ?>"
			. "<?php sdc_each( 'items', function ( \$row ) { ?><i><?php sdc_text( 'q', \$row ); ?></i><?php } ); ?>"
			. '</article>'
		);

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component( 'Fragen & Antworten', 2 ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( '<article class="v-faq">', $html );
		$this->assertStringContainsString( 'Fragen &amp; Antworten', $html );
		$this->assertStringContainsString( '<i>Frage 1</i>', $html );
		$this->assertStringContainsString( '<i>Frage 2</i>', $html );
	}

	/**
	 * The context is gone once the component has been rendered.
	 */
	public function test_the_context_is_dropped_after_a_render() {
		$this->component_fixture( 'hero', "<?php sdc_text( 'heading' ); ?>" );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Kopf' ) ) ) );

		$this->assertStringContainsString( 'Kopf', Sdc_Frontend::render_post( $post_id ) );
		$this->assertNull( Sdc_View::context() );
	}

	/**
	 * A view may render another page; the outer context survives it.
	 */
	public function test_a_nested_render_keeps_the_outer_context() {
		$inner = $this->make_page();
		Sdc_Store::save_data( $inner, array( 'components' => array( $this->hero_component( 'Innen' ) ) ) );

		$this->component_fixture( 'hero', "<?php sdc_text( 'heading' ); ?>" );
		$this->component_fixture(
			'faq',
			'<?php echo Sdc_Frontend::render_post( ' . (int) $inner . ' ); ?>'
			. "<?php sdc_text( 'heading' ); ?>"
		);

		$outer = $this->make_page();
		Sdc_Store::save_data( $outer, array( 'components' => array( $this->faq_component( 'Außen', 1 ) ) ) );

		$html = Sdc_Frontend::render_post( $outer );

		$this->assertStringContainsString( 'Innen', $html );
		$this->assertStringContainsString( 'Außen', $html );
		$this->assertNull( Sdc_View::context() );
	}

	/**
	 * A view that throws must not leave its context or an output buffer behind.
	 */
	public function test_a_dying_view_does_not_leak_context_or_buffer() {
		$this->component_fixture( 'hero', "<?php throw new RuntimeException( 'kaputt' ); ?>" );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Egal' ) ) ) );

		$level  = ob_get_level();
		$caught = '';

		try {
			Sdc_Frontend::render_post( $post_id );
		} catch ( RuntimeException $error ) {
			$caught = $error->getMessage();
		}

		$this->assertSame( 'kaputt', $caught );
		$this->assertNull( Sdc_View::context() );
		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * A component's own stylesheet is enqueued with the page that renders it.
	 */
	public function test_component_css_is_enqueued_for_a_rendered_page() {
		$css = $this->content_fixture( 'hero', '.sdc-hero{color:red}' );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Mit CSS' ) ) ) );

		$this->go_to( get_permalink( $post_id ) );

		Sdc_Frontend::enqueue_component_styles();

		$this->assertTrue( wp_style_is( 'sdc-component-hero', 'enqueued' ) );

		$registered = wp_styles()->registered['sdc-component-hero'];

		$this->assertSame(
			untrailingslashit( content_url() ) . '/' . Sdc_Schema::USER_DIR . '/hero/component.css',
			$registered->src
		);
		$this->assertSame( (string) filemtime( $css ), $registered->ver );
	}

	/**
	 * A component without a stylesheet enqueues nothing — no 404 in the head.
	 */
	public function test_component_css_is_not_enqueued_without_a_file() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component() ) ) );

		$this->go_to( get_permalink( $post_id ) );

		Sdc_Frontend::enqueue_component_styles();

		$this->assertFalse( wp_style_is( 'sdc-component-hero', 'enqueued' ) );
	}

	/**
	 * Outside a singular view there is no page to style.
	 */
	public function test_component_css_is_not_enqueued_outside_a_singular_view() {
		$this->content_fixture( 'hero', '.sdc-hero{color:red}' );

		Sdc_Frontend::enqueue_component_styles();

		$this->assertFalse( wp_style_is( 'sdc-component-hero', 'enqueued' ) );
	}

	/**
	 * A path outside every public root has no URL, and is skipped rather than
	 * guessed. The filter is the documented way out for such a site.
	 */
	public function test_component_css_needs_a_public_url() {
		$this->component_fixture( 'hero', "<?php sdc_text( 'heading' ); ?>", '.sdc-hero{color:red}' );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component() ) ) );

		$this->go_to( get_permalink( $post_id ) );

		Sdc_Frontend::enqueue_component_styles();

		// The fixture lives in the temp dir: no root, no URL, no enqueue.
		$this->assertFalse( wp_style_is( 'sdc-component-hero', 'enqueued' ) );

		$seen = array();

		add_filter(
			'sdc_pb_component_style_src',
			function ( $src, $file, $type ) use ( &$seen ) {
				$seen = array( $src, $file, $type );

				return 'https://cdn.example.org/' . $type . '.css';
			},
			10,
			3
		);

		Sdc_Frontend::enqueue_component_styles();

		$this->assertTrue( wp_style_is( 'sdc-component-hero', 'enqueued' ) );
		$this->assertSame( 'https://cdn.example.org/hero.css', wp_styles()->registered['sdc-component-hero']->src );
		$this->assertSame( '', $seen[0] );
		$this->assertSame( $this->components . '/hero/component.css', $seen[1] );
		$this->assertSame( 'hero', $seen[2] );
	}

	/**
	 * The hook is registered where WordPress fires it.
	 */
	public function test_the_stylesheet_hook_is_registered() {
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( 'Sdc_Frontend', 'enqueue_component_styles' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Run a helper and return what it echoed.
	 *
	 * @param string $function Helper name.
	 * @param mixed  ...$args  Arguments.
	 * @return string
	 */
	private function capture( $function, ...$args ) {
		ob_start();
		call_user_func_array( $function, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Point the component lookup at this test's scratch directory.
	 */
	private function use_components() {
		$dir = $this->components;

		add_filter(
			'sdc_pb_component_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);
	}

	/**
	 * An attachment with a real file behind it, removed in tear_down().
	 *
	 * A bare post ID is not enough: the URLs under test come from the upload
	 * directory, so the file has to exist on disk.
	 *
	 * @param string $mime  MIME type.
	 * @param string $name  File name.
	 * @param string $title Attachment title.
	 * @return int Attachment ID.
	 */
	private function attachment_fixture( $mime, $name, $title ) {
		$upload = wp_upload_dir();
		$file   = trailingslashit( $upload['path'] ) . wp_generate_password( 8, false ) . '-' . $name;

		file_put_contents( $file, 'probe' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$file
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$this->attachments[] = array( (int) $id, $file );

		return (int) $id;
	}

	/**
	 * A component directory in the scratch root, with the files a component has.
	 *
	 * @param string      $type   Component type.
	 * @param string|null $render View body; null leaves the generic output.
	 * @param string|null $css    Stylesheet contents; null leaves it out.
	 */
	private function component_fixture( $type, $render = null, $css = null ) {
		$this->use_components();

		$dir = $this->components . '/' . $type;

		$this->assertTrue( wp_mkdir_p( $dir ) );

		file_put_contents( $dir . '/definition.json', wp_json_encode( array( 'key' => Sdc_Schema::KEY_PREFIX . $type ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		if ( null !== $render ) {
			file_put_contents( $dir . '/render.php', $render . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		}

		if ( null !== $css ) {
			file_put_contents( $dir . '/component.css', $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		}
	}

	/**
	 * A component directory under `wp-content/`, which is the one location a
	 * stylesheet can be enqueued from without a filter.
	 *
	 * @param string $type Component type.
	 * @param string $css  Stylesheet contents.
	 * @return string Absolute path of the stylesheet.
	 */
	private function content_fixture( $type, $css ) {
		$base = untrailingslashit( WP_CONTENT_DIR ) . '/' . Sdc_Schema::USER_DIR;

		$this->assertTrue( wp_mkdir_p( $base . '/' . $type ) );

		// Remembered so tear_down() removes exactly what this test created.
		$this->content_dirs[] = $base . '/' . $type;

		file_put_contents( $base . '/' . $type . '/component.css', $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $base . '/' . $type . '/component.css';
	}

	/**
	 * Field definitions covering every branch of the generator.
	 *
	 * @return array[]
	 */
	private function scaffold_fields() {
		return array(
			array(
				'name' => 'heading',
				'type' => 'text',
			),
			array(
				'name' => 'body',
				'type' => 'wysiwyg',
			),
			array(
				'name' => 'photo',
				'type' => 'image',
			),
			array(
				'name' => 'doc',
				'type' => 'file',
			),
			array(
				'name' => 'cta',
				'type' => 'link',
			),
			array(
				'name' => 'flag',
				'type' => 'true_false',
			),
			array(
				'name'       => 'group1',
				'type'       => 'group',
				'sub_fields' => array(
					array(
						'name' => 'title',
						'type' => 'text',
					),
				),
			),
			array(
				'name'       => 'items',
				'type'       => 'repeater',
				'sub_fields' => array(
					array(
						'name' => 'q',
						'type' => 'text',
					),
					array(
						'name' => 'img',
						'type' => 'image',
					),
					array(
						'name' => 'doc',
						'type' => 'file',
					),
					array(
						'name' => 'cta',
						'type' => 'link',
					),
					array(
						'name' => 'flag',
						'type' => 'true_false',
					),
					array(
						'name' => 'txt',
						'type' => 'wysiwyg',
					),
				),
			),
		);
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
		}

		rmdir( $dir );
	}
}

<?php
/**
 * The admin panel in front of the view file.
 *
 * @package SdcPageBuilder
 */

/**
 * Covers `Sdc_View_Editor`: the markup a user sees, and the three answers it
 * gives the save path — is this body valid, should anything be written at all,
 * and what exactly. The distinction that matters most is between "the form did
 * not carry a view" and "the form carried an empty view": the first must leave
 * an existing `render.php` alone, the second removes it.
 */
class ViewEditorTest extends Sdc_TestCase {

	/**
	 * Scratch root for the current test.
	 *
	 * @var string
	 */
	private $root = '';

	/**
	 * Writable component directory, redirected into the scratch root.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * A scratch directory, an administrator, and no posted state.
	 */
	public function set_up() {
		parent::set_up();

		$this->root = untrailingslashit( get_temp_dir() ) . '/sdc-ve-' . wp_generate_password( 8, false );
		$this->dir  = $this->root . '/' . Sdc_Schema::USER_DIR;

		$this->assertTrue( wp_mkdir_p( $this->dir ) );

		$dir = $this->dir;

		add_filter(
			'sdc_pb_write_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		add_filter(
			'sdc_pb_component_dirs',
			static function () use ( $dir ) {
				return array( $dir );
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array();
	}

	/**
	 * Undo the filters and the posted state.
	 */
	public function tear_down() {
		$_POST = array();

		remove_all_filters( 'sdc_pb_write_dir' );
		remove_all_filters( 'sdc_pb_component_dirs' );
		remove_all_filters( 'sdc_pb_can_edit_view' );

		$this->remove_tree( $this->root );

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * The panel is a labelled textarea, not a bare box.
	 */
	public function test_the_panel_shows_a_textarea() {
		$html = $this->panel( 'hero', array() );

		$this->assertStringContainsString( 'name="sdc_view_body"', $html );
		$this->assertStringContainsString( 'id="sdc_view_body"', $html );
		$this->assertStringContainsString( 'Ansicht', $html );
	}

	/**
	 * The existing body is what the textarea starts with — otherwise opening
	 * the panel and saving would blank a view nobody touched.
	 */
	public function test_the_panel_shows_the_stored_body() {
		$this->write_view( 'hero', '<p><?php sdc_text( \'heading\' ); ?></p>' );

		$html = $this->panel( 'hero', array() );

		$this->assertStringContainsString( 'sdc_text( &#039;heading&#039; )', $html );
	}

	/**
	 * A view author needs the reference in the panel; a separate manual would
	 * drift away from the API.
	 */
	public function test_the_panel_lists_the_helpers() {
		$html = $this->panel( 'hero', array() );

		foreach ( Sdc_View::helpers() as $helper ) {
			$this->assertStringContainsString( esc_html( $helper['signature'] ), $html );
		}
	}

	/**
	 * The generator needs a field list, so it appears only where there is one.
	 */
	public function test_the_generate_button_needs_fields() {
		$without = $this->panel( 'hero', array() );
		$with    = $this->panel( 'hero', $this->text_fields() );

		$this->assertStringNotContainsString( 'sdc_generate_view', $without );
		$this->assertStringContainsString( 'sdc_generate_view', $with );
	}

	/**
	 * A view is PHP on the server. Without the capability there is no panel —
	 * not a disabled one, so nobody copies a field name out of a form they
	 * cannot use.
	 */
	public function test_the_panel_is_absent_without_the_capability() {
		add_filter( 'sdc_pb_can_edit_view', '__return_false' );

		$this->assertSame( '', $this->panel( 'hero', $this->text_fields() ) );
	}

	/* ---------------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------------ */

	/**
	 * What the user typed is what lands in the file.
	 */
	public function test_save_writes_the_posted_body() {
		$_POST = array( Sdc_View_Editor::FIELD => '<b><?php sdc_text( \'heading\' ); ?></b>' );

		$this->assertTrue( Sdc_View_Editor::save( $this->dir, 'hero', $this->text_fields() ) );

		$file = Sdc_View::file( $this->dir, 'hero' );

		$this->assertFileExists( $file );
		$this->assertStringContainsString( 'sdc_text( \'heading\' );', $this->body_of( $file ) );
	}

	/**
	 * Empty means "no view": the generic output comes back.
	 */
	public function test_an_empty_body_removes_the_view() {
		$this->write_view( 'hero', '<p>alt</p>' );

		$_POST = array( Sdc_View_Editor::FIELD => '' );

		$this->assertTrue( Sdc_View_Editor::save( $this->dir, 'hero', $this->text_fields() ) );
		$this->assertFileDoesNotExist( Sdc_View::file( $this->dir, 'hero' ) );
	}

	/**
	 * A form without the panel says nothing about the view.
	 *
	 * Without this distinction, saving a component whose panel was not
	 * rendered (capability removed, or an older form) would delete a view the
	 * user never saw.
	 */
	public function test_a_missing_field_leaves_the_view_alone() {
		$this->write_view( 'hero', '<p>bleibt</p>' );

		$_POST = array( 'sdc_label' => 'Held' );

		$this->assertTrue( Sdc_View_Editor::save( $this->dir, 'hero', $this->text_fields() ) );
		$this->assertStringContainsString( '<p>bleibt</p>', $this->body_of( Sdc_View::file( $this->dir, 'hero' ) ) );
	}

	/**
	 * A syntax error is refused and nothing is written.
	 */
	public function test_a_syntax_error_is_reported() {
		$_POST = array( Sdc_View_Editor::FIELD => '<?php if ( ?>' );

		$result = Sdc_View_Editor::validate( 'hero', $this->text_fields() );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_view_syntax', $result->get_error_code() );
	}

	/**
	 * The generator replaces the body with one built from the fields.
	 */
	public function test_the_generate_button_builds_the_body_from_fields() {
		$_POST = array(
			Sdc_View_Editor::FIELD    => '<p>wird ersetzt</p>',
			Sdc_View_Editor::GENERATE => '1',
		);

		$this->assertTrue( Sdc_View_Editor::save( $this->dir, 'hero', $this->text_fields() ) );

		$body = $this->body_of( Sdc_View::file( $this->dir, 'hero' ) );

		$this->assertStringNotContainsString( 'wird ersetzt', $body );
		$this->assertStringContainsString( 'sdc_text( \'heading\' )', $body );
	}

	/**
	 * Generate wins over the textarea: the point is a body matching the fields
	 * that are being saved, not the one that was there before.
	 */
	public function test_generate_ignores_the_textarea() {
		$_POST = array(
			Sdc_View_Editor::FIELD    => 'nicht verwenden',
			Sdc_View_Editor::GENERATE => '1',
		);

		$body = Sdc_View_Editor::posted_body( 'hero', $this->text_fields() );

		$this->assertStringNotContainsString( 'nicht verwenden', $body );
	}

	/* ---------------------------------------------------------------------
	 * The admin save path
	 * ------------------------------------------------------------------ */

	/**
	 * Creating a component writes its view in the same request.
	 *
	 * This is the seam the panel exists for: the form posts a body, and the
	 * file is on disk afterwards without a second step.
	 */
	public function test_create_writes_the_view_next_to_the_definition() {
		$type = 've-' . strtolower( wp_generate_password( 6, false ) );

		$_POST = array(
			'sdc_type'      => $type,
			'sdc_label'     => 'Panel',
			'sdc_fields'    => wp_json_encode( $this->text_fields() ),
			'sdc_view_body' => '<article><?php sdc_text( \'heading\' ); ?></article>',
		);

		$this->assertTrue( $this->invoke( 'create' ) );

		$this->assertFileExists( $this->dir . '/' . $type . '/definition.json' );
		$this->assertFileExists( $this->dir . '/' . $type . '/render.php' );
	}

	/**
	 * A bad view stops the save before the definition is written.
	 *
	 * Half a save is worse than none: the component would exist with markup
	 * that fatals on the next page load, and the error would surface on the
	 * frontend instead of the form.
	 */
	public function test_a_broken_view_keeps_the_definition_from_being_written() {
		$type = 've-' . strtolower( wp_generate_password( 6, false ) );

		$_POST = array(
			'sdc_type'      => $type,
			'sdc_label'     => 'Panel',
			'sdc_fields'    => wp_json_encode( $this->text_fields() ),
			'sdc_view_body' => '<?php if ( ?>',
		);

		$result = $this->invoke( 'create' );

		$this->assertWPError( $result );
		$this->assertFileDoesNotExist( $this->dir . '/' . $type . '/definition.json' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Render the panel for a type.
	 *
	 * @param string  $type   Component type.
	 * @param array[] $fields Field definitions.
	 * @return string HTML.
	 */
	private function panel( $type, array $fields ) {
		ob_start();
		Sdc_View_Editor::render( $type, $fields );
		return (string) ob_get_clean();
	}

	/**
	 * One text field, enough for the generator to produce markup.
	 *
	 * @return array[]
	 */
	private function text_fields() {
		return array(
			array(
				'name'  => 'heading',
				'label' => 'Überschrift',
				'type'  => 'text',
			),
		);
	}

	/**
	 * Put a view on disk, the way an earlier save would have.
	 *
	 * @param string $type Component type.
	 * @param string $body Body.
	 */
	private function write_view( $type, $body ) {
		$file = Sdc_View::file( $this->dir, $type );

		$this->assertTrue( wp_mkdir_p( dirname( $file ) ) );
		$this->assertNotFalse( file_put_contents( $file, Sdc_View::wrap( $type, $body ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}

	/**
	 * The body of a written view file.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function body_of( $file ) {
		return Sdc_View::read( $file );
	}

	/**
	 * Call a private static method of Sdc_Admin.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke( $method, ...$args ) {
		$reflection = new ReflectionMethod( 'Sdc_Admin', $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Directory.
	 */
	private function remove_tree( $path ) {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Test cleanup.

		foreach ( (array) $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$target = $path . '/' . $entry;

			if ( is_dir( $target ) ) {
				$this->remove_tree( $target );
			} elseif ( is_file( $target ) ) {
				unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}

		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
	}
}

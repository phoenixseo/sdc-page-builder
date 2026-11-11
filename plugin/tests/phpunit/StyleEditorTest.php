<?php
/**
 * The stylesheet of a component: the panel, and the file behind it.
 *
 * @package SdcPageBuilder
 */

/**
 * Covers `Sdc_Style_Editor` and the stylesheet methods it stands on: what the
 * panel shows, what a save writes, and what it refuses.
 *
 * The failure this suite guards against is the quiet one. A stylesheet is
 * delivered inline in a shared `<style>` element, so a stray `</style` or an
 * unclosed comment does not break this component — it breaks the page, and
 * the page is where nobody looks for the cause.
 */
class StyleEditorTest extends Sdc_TestCase {

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

		$this->root = untrailingslashit( get_temp_dir() ) . '/sdc-se-' . wp_generate_password( 8, false );
		$this->dir  = $this->root . '/' . Sdc_Schema::USER_DIR;

		$this->assertTrue( wp_mkdir_p( $this->dir ) );

		$this->direct( array( $this->dir ) );

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
		remove_all_filters( 'sdc_pb_can_edit_style' );

		$this->remove_tree( $this->root );

		parent::tear_down();
	}

	/*
	---------------------------------------------------------------------
	 * The panel
	 * ------------------------------------------------------------------
	 */

	/**
	 * The panel is a labelled box the browser can submit.
	 */
	public function test_the_panel_shows_a_textarea() {
		$html = $this->panel( 'hero', '.sdc-component--hero { color: red; }' );

		$this->assertStringContainsString( 'name="sdc_css_body"', $html );
		$this->assertStringContainsString( 'id="sdc_css_body"', $html );
		$this->assertStringContainsString( 'sdc-component--hero', $html );
	}

	/**
	 * The stored stylesheet is what the box starts with.
	 */
	public function test_the_panel_shows_the_stored_stylesheet() {
		$this->write_style_file( 'hero', '.sdc-component--hero { color: red; }' );

		$html = $this->panel( 'hero' );

		$this->assertStringContainsString( 'color: red;', $html );
	}

	/**
	 * A stylesheet the theme ships is shown, but not offered for editing.
	 *
	 * The theme comes first in the lookup order, so a save would write a file
	 * the page never reads — success reported, nothing changed. That is the
	 * one outcome an editor must not have.
	 */
	public function test_a_theme_stylesheet_is_read_only() {
		$theme = $this->root . '/theme/sdc-components';
		$this->assertTrue( wp_mkdir_p( $theme . '/hero' ) );
		$this->assertNotFalse( file_put_contents( $theme . '/hero/component.css', '.from-theme { color: red; }' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->direct( array( $theme, $this->dir ), $this->dir );

		$html = $this->panel( 'hero' );

		$this->assertStringContainsString( '.from-theme', $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringNotContainsString( 'name="sdc_css_body"', $html );
	}

	/*
	---------------------------------------------------------------------
	 * Reading and writing
	 * ------------------------------------------------------------------
	 */

	/**
	 * A posted stylesheet ends up in the component's own directory.
	 */
	public function test_save_writes_the_file() {
		$_POST = array( 'sdc_css_body' => '.sdc-component--hero { color: red; }' );

		$this->assertTrue( Sdc_Style_Editor::save( $this->dir, 'hero' ) );

		$file = $this->file( 'hero' );

		$this->assertFileExists( $file );
		$this->assertStringContainsString( 'color: red;', (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
	}

	/**
	 * An empty box removes the file: back to inheriting the theme's styles.
	 */
	public function test_an_empty_stylesheet_removes_the_file() {
		$this->write_style_file( 'hero', '.sdc-component--hero { color: red; }' );

		$_POST = array( 'sdc_css_body' => '   ' );

		$this->assertTrue( Sdc_Style_Editor::save( $this->dir, 'hero' ) );
		$this->assertFileDoesNotExist( $this->file( 'hero' ) );
	}

	/**
	 * A form that carries no stylesheet leaves the file alone.
	 *
	 * Without this, any form that does not render the panel — the create form,
	 * a request from an older version — would delete the styles as a side
	 * effect of being submitted.
	 */
	public function test_a_form_without_the_field_leaves_the_file_alone() {
		$this->write_style_file( 'hero', '.sdc-component--hero { color: red; }' );

		$_POST = array( 'sdc_label' => 'Held' );

		$this->assertTrue( Sdc_Style_Editor::save( $this->dir, 'hero' ) );
		$this->assertFileExists( $this->file( 'hero' ) );
	}

	/**
	 * A rejected stylesheet is not written.
	 */
	public function test_a_broken_stylesheet_is_refused() {
		$_POST = array( 'sdc_css_body' => '.sdc-component--hero { color: red;' );

		$result = Sdc_Style_Editor::save( $this->dir, 'hero' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_style_braces', $result->get_error_code() );
		$this->assertFileDoesNotExist( $this->file( 'hero' ) );
	}

	/**
	 * A stylesheet written on Windows reads back without its BOM, with one
	 * kind of line ending, and with a closing newline.
	 *
	 * Without this every round trip through the box would look like an edit —
	 * and a diff a day long is how a person stops trusting the screen.
	 */
	public function test_the_stylesheet_is_normalized() {
		$_POST = array( 'sdc_css_body' => "\xEF\xBB\xBF.a {\r\n\tcolor: red;\r\n}" );

		$this->assertTrue( Sdc_Style_Editor::save( $this->dir, 'hero' ) );

		$stored = (string) file_get_contents( $this->file( 'hero' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.

		$this->assertStringNotContainsString( "\r", $stored );
		$this->assertStringNotContainsString( "\xEF\xBB\xBF", $stored );
		$this->assertStringEndsWith( "}\n", $stored );
	}

	/*
	---------------------------------------------------------------------
	 * The scaffold
	 * ------------------------------------------------------------------
	 */

	/**
	 * The scaffold button fills the box with the component's own selector.
	 */
	public function test_the_scaffold_starts_with_the_component_selector() {
		$_POST = array( 'sdc_css_scaffold' => '1' );

		$this->assertTrue( Sdc_Style_Editor::save( $this->dir, 'hero' ) );

		$stored = (string) file_get_contents( $this->file( 'hero' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.

		$this->assertStringContainsString( '.sdc-component--hero {', $stored );

		// The scaffold is a comment plus an empty rule: it has to survive its
		// own lint, or the button produces a file the form then refuses.
		$this->assertTrue( Sdc_View::lint_style( $stored ) );
	}

	/*
	---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------
	 */

	/**
	 * A site can forbid styles while allowing views.
	 */
	public function test_the_filter_can_forbid_editing() {
		add_filter( 'sdc_pb_can_edit_style', '__return_false' );

		$this->assertFalse( Sdc_View::can_edit_style( 'hero' ) );
		$this->assertSame( '', $this->panel( 'hero' ) );
	}

	/**
	 * A rejected stylesheet does not stop the rest of the save from being
	 * validated first — but a forbidden one is reported as such, not as a
	 * file-system failure.
	 */
	public function test_a_forbidden_edit_is_not_written() {
		add_filter( 'sdc_pb_can_edit_style', '__return_false' );

		$_POST = array( 'sdc_css_body' => '.a { color: red; }' );

		$result = Sdc_Style_Editor::save( $this->dir, 'hero' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_style_forbidden', $result->get_error_code() );
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/*
	---------------------------------------------------------------------
	 * The seam to the real form
	 * ------------------------------------------------------------------
	 */

	/**
	 * The component screen shows three tabs, and all three boxes exist.
	 *
	 * The browser only shows one panel, but every panel is submitted — that
	 * is the whole reason a tab switch cannot lose a change. A form that
	 * renders only the active panel would silently drop the other two.
	 */
	public function test_the_edit_form_has_three_tabs_and_three_panels() {
		$type = $this->fresh_type( 'tab' );

		$this->write_definition( $type );
		$this->reload_schema();

		$html = $this->edit_form( $type );

		foreach ( array( 'content', 'view', 'styles' ) as $id ) {
			$this->assertStringContainsString( 'data-sdc-tab="' . $id . '"', $html );
			$this->assertStringContainsString( 'data-sdc-panel="' . $id . '"', $html );
		}

		$this->assertStringContainsString( 'name="sdc_fields"', $html );
		$this->assertStringContainsString( 'name="sdc_view_body"', $html );
		$this->assertStringContainsString( 'name="sdc_css_body"', $html );

		// All three before the end of the form: the save button posts them.
		$this->assertLessThan( strpos( $html, '</form>' ), strpos( $html, 'sdc_css_body' ) );
	}

	/**
	 * The styles box sits in the styles panel, not somewhere else.
	 */
	public function test_the_stylesheet_box_belongs_to_the_styles_tab() {
		$type = $this->fresh_type( 'zuord' );

		$this->write_definition( $type );
		$this->reload_schema();

		$html = $this->edit_form( $type );

		$panel = strpos( $html, 'data-sdc-panel="styles"' );

		$this->assertNotFalse( $panel );
		$this->assertGreaterThan( $panel, strpos( $html, 'name="sdc_css_body"' ) );
	}

	/**
	 * A rejected stylesheet sends the form back to the styles tab.
	 *
	 * The error message alone lands above a screen of tabs; without the tab
	 * hint the author has to search for the box that caused it.
	 */
	public function test_a_rejected_stylesheet_names_its_tab() {
		$type = $this->fresh_type( 'taberr' );

		$this->write_definition( $type );
		$this->reload_schema();

		$_POST = array(
			'sdc_type'     => $type,
			'sdc_label'    => 'Held',
			// The stored field, unchanged: an empty list here would stop the
			// save at the "fields removed" guard before the stylesheet is
			// ever looked at, and the test would prove nothing.
			'sdc_fields'   => (string) wp_json_encode(
				array(
					array(
						'name'  => 'heading',
						'type'  => 'text',
						'label' => 'Überschrift',
					),
				)
			),
			'sdc_css_body' => '.a { color: red;',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'styles', $result->get_error_data()['sdc_tab'] );
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * The component screen's edit form, as HTML.
	 *
	 * @param string $type Component type.
	 * @return string HTML.
	 */
	private function edit_form( $type ) {
		$method = new ReflectionMethod( 'Sdc_Admin', 'render_edit_form' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null, $type );

		return (string) ob_get_clean();
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
	 * A component type this test owns alone.
	 *
	 * @param string $base Name prefix.
	 * @return string
	 */
	private function fresh_type( $base ) {
		return $base . '-' . strtolower( wp_generate_password( 6, false ) );
	}

	/**
	 * Put a `definition.json` on disk, the way an earlier save would have.
	 *
	 * @param string $type Component type.
	 */
	private function write_definition( $type ) {
		$dir = $this->dir . '/' . $type;

		$this->assertTrue( wp_mkdir_p( $dir ) );

		$this->assertNotFalse( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents(
				$dir . '/definition.json',
				(string) wp_json_encode(
					array(
						'key'    => Sdc_Schema::KEY_PREFIX . $type,
						'title'  => 'Held',
						'fields' => array(
							array(
								'name'  => 'heading',
								'label' => 'Überschrift',
								'type'  => 'text',
								'key'   => 'field_sdc_' . str_replace( '-', '_', $type ) . '_heading',
							),
						),
					)
				)
			)
		);
	}

	/**
	 * Make the schema loader see what is on disk now.
	 */
	private function reload_schema() {
		Sdc_Schema::flush();

		if ( function_exists( 'acf_get_store' ) ) {
			foreach ( array( 'field-groups', 'fields' ) as $store ) {
				$instance = acf_get_store( $store );
				if ( $instance && method_exists( $instance, 'reset' ) ) {
					$instance->reset();
				}
			}
		}

		// The hook name comes from the host and cannot be renamed.
		do_action( 'acf/include_fields' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Hook defined by ACF/SCF.

		Sdc_Schema::flush();
	}

	/**
	 * Point the write directory and the lookup order at what a test needs.
	 *
	 * Two separate hooks, not one list: a test that models a theme-shipped
	 * stylesheet puts the theme first in the lookup order while the writable
	 * slot stays where it is. Hooking `sdc_pb_write_dir` to the first entry
	 * would make the panel believe it owns the theme's file.
	 *
	 * @param string[] $dirs  Directories, in lookup order.
	 * @param string   $write Directory a save targets; defaults to the last entry.
	 */
	private function direct( array $dirs, $write = '' ) {
		$write = '' === $write ? (string) end( $dirs ) : $write;

		add_filter(
			'sdc_pb_write_dir',
			static function () use ( $write ) {
				return $write;
			}
		);

		add_filter(
			'sdc_pb_component_dirs',
			static function () use ( $dirs ) {
				return $dirs;
			}
		);
	}

	/**
	 * Render the panel for a type.
	 *
	 * @param string      $type Component type.
	 * @param string|null $css  Stylesheet to show; null reads it from disk.
	 * @return string HTML.
	 */
	private function panel( $type, $css = null ) {
		ob_start();
		Sdc_Style_Editor::render( $type, $css );
		return (string) ob_get_clean();
	}

	/**
	 * Absolute path of a component's stylesheet.
	 *
	 * @param string $type Component type.
	 * @return string
	 */
	private function file( $type ) {
		return $this->dir . '/' . $type . '/' . Sdc_View::STYLE;
	}

	/**
	 * Put a stylesheet on disk, the way an earlier save would have.
	 *
	 * @param string $type Component type.
	 * @param string $css  Stylesheet.
	 */
	private function write_style_file( $type, $css ) {
		$this->assertTrue( wp_mkdir_p( dirname( $this->file( $type ) ) ) );
		$this->assertNotFalse( file_put_contents( $this->file( $type ), Sdc_View::normalize_style( $css ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
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

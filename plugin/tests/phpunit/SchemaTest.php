<?php
/**
 * Component types, as declared by the JSON schemas only.
 *
 * @package SdcPageBuilder
 */

/**
 * The universality claim: a component type is a JSON file, nothing else. The
 * second type (`hero`) exists to prove it — if any test here needs code
 * outside `schemas/`, the claim is false.
 */
class SchemaTest extends Sdc_TestCase {

	/**
	 * Both shipped types are discovered from the schema directory.
	 */
	public function test_shipped_types_are_discovered() {
		$components = Sdc_Schema::components();

		$this->assertArrayHasKey( 'faq', $components );
		$this->assertArrayHasKey( 'hero', $components );
	}

	/**
	 * A descriptor carries what the editor needs to render a component card.
	 */
	public function test_descriptor_carries_label_icon_and_fields() {
		$faq = Sdc_Schema::get( 'faq' );

		$this->assertSame( 'faq', $faq['type'] );
		$this->assertSame( 'group_sdc_component_faq', $faq['key'] );
		$this->assertSame( 'FAQ', $faq['label'] );
		$this->assertNotEmpty( $faq['icon'] );
		$this->assertNotEmpty( $faq['fields'] );
	}

	/**
	 * Fields are normalized to the flat shape the editor's JS consumes.
	 *
	 * Nesting is not in the descriptor: `normalize_field()` returns one row per
	 * top-level field, and the row structure comes from the rendered markup
	 * (see RendererTest).
	 */
	public function test_fields_are_normalized_to_a_flat_shape() {
		$fields = Sdc_Schema::fields( 'faq' );
		$names  = array_column( $fields, 'name' );

		$this->assertSame( array( 'heading', 'items' ), $names );

		foreach ( $fields as $field ) {
			$this->assertSame(
				array( 'key', 'name', 'label', 'type', 'required' ),
				array_keys( $field ),
				'Unexpected descriptor shape for field ' . $field['name']
			);
		}

		$this->assertSame( 'repeater', $fields[1]['type'] );
		$this->assertSame( 'field_sdc_faq_items', $fields[1]['key'] );
	}

	/**
	 * `version_string()` is what gets stored per component instance.
	 */
	public function test_version_string_is_type_at_version() {
		$this->assertSame( 'faq@1', Sdc_Schema::version_string( 'faq' ) );
		$this->assertSame( 'hero@1', Sdc_Schema::version_string( 'hero' ) );
	}

	/**
	 * An unknown type is null, not an error — the renderer relies on that.
	 */
	public function test_unknown_type_returns_null() {
		$this->assertNull( Sdc_Schema::get( 'gibt-es-nicht' ) );
		$this->assertSame( array(), Sdc_Schema::fields( 'gibt-es-nicht' ) );
		$this->assertSame( 'gibt-es-nicht@1', Sdc_Schema::version_string( 'gibt-es-nicht' ) );
	}

	/**
	 * The hero type is link + textarea + text — three field types the FAQ type
	 * does not use, which is what makes it a real second case.
	 */
	public function test_hero_uses_field_types_the_faq_type_does_not() {
		$hero = array_column( Sdc_Schema::fields( 'hero' ), 'type', 'name' );
		$faq  = array_column( Sdc_Schema::fields( 'faq' ), 'type', 'name' );

		$this->assertSame( 'link', $hero['cta'] );
		$this->assertSame( 'textarea', $hero['subline'] );
		$this->assertNotContains( 'link', $faq );
	}

	/**
	 * One directory per component: the host's loader reads one level only.
	 *
	 * ACF's `scan_files()` skips sub directories, so a component in
	 * `sdc-components/hero/` is invisible unless that sub directory is
	 * registered as its own path. This asserts the expansion.
	 */
	public function test_json_paths_include_each_component_subdirectory() {
		$base = untrailingslashit( get_temp_dir() ) . '/sdc-paths-' . wp_generate_password( 8, false );
		$sub  = $base . '/' . Sdc_Schema::USER_DIR . '/hero';

		$this->assertTrue( mkdir( $sub, 0777, true ) );
		file_put_contents( $sub . '/definition.json', '{"key":"group_sdc_component_hero"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		add_filter(
			'sdc_pb_component_dirs',
			function () use ( $base ) {
				return array( $base . '/' . Sdc_Schema::USER_DIR );
			}
		);

		$paths = Sdc_Schema::json_paths();

		remove_all_filters( 'sdc_pb_component_dirs' );

		$this->assertContains( $base . '/' . Sdc_Schema::USER_DIR, $paths );
		$this->assertContains( $sub, $paths );
		$this->assertContains( Sdc_Schema::dir(), $paths );

		unlink( $sub . '/definition.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		rmdir( $sub );
		rmdir( $base . '/' . Sdc_Schema::USER_DIR );
		rmdir( $base );
	}

	/**
	 * A directory that does not exist is not offered to the host.
	 */
	public function test_missing_component_dirs_are_ignored() {
		add_filter(
			'sdc_pb_component_dirs',
			function () {
				return array( '/nonexistent/sdc-components' );
			}
		);

		$this->assertSame( array(), Sdc_Schema::user_dirs() );

		remove_all_filters( 'sdc_pb_component_dirs' );
	}

	/**
	 * A component keeps the file name it was loaded from.
	 *
	 * The host names JSON files after the group key. Left alone, saving
	 * `definition.json` from the admin would rename it to
	 * `group_sdc_component_hero.json` and leave a stale copy behind.
	 */
	public function test_saving_a_component_keeps_its_file_name() {
		$file = Sdc_Schema::dir() . 'hero/definition.json';
		$post = array( 'key' => Sdc_Schema::KEY_PREFIX . 'hero' );

		$this->assertSame( 'definition.json', Sdc_Schema::save_file_name( 'group_sdc_component_hero.json', $post, $file ) );
	}

	/**
	 * The file a component lives in is found by its key, not its name.
	 *
	 * Every component directory holds a `definition.json`, so the name alone
	 * cannot say which component a file belongs to.
	 */
	public function test_file_for_key_finds_the_defining_file() {
		$this->assertSame( Sdc_Schema::dir() . 'hero/definition.json', Sdc_Schema::file_for_key( 'group_sdc_component_hero' ) );
		$this->assertSame( Sdc_Schema::dir() . 'faq/definition.json', Sdc_Schema::file_for_key( 'group_sdc_component_faq' ) );
		$this->assertSame( '', Sdc_Schema::file_for_key( 'group_sdc_component_does_not_exist' ) );
		$this->assertSame( '', Sdc_Schema::file_for_key( 'group_other_plugin' ) );
	}

	/**
	 * A component's edit lands in its own directory, not in `acf-json/`.
	 *
	 * Leaving the host's save path in place would create a second file
	 * defining the same key — the loader would then see the component twice.
	 */
	public function test_save_paths_point_at_the_component_directory() {
		$paths = Sdc_Schema::save_paths( array( '/tmp/acf-json' ), array( 'key' => 'group_sdc_component_hero' ) );

		$this->assertSame( array( Sdc_Schema::dir() . 'hero' ), $paths );

		$untouched = Sdc_Schema::save_paths( array( '/tmp/acf-json' ), array( 'key' => 'group_other_plugin' ) );
		$this->assertSame( array( '/tmp/acf-json' ), $untouched );
	}

	/**
	 * Only files in the plugin's own component directories are claimed.
	 *
	 * A field group from another plugin's `acf-json/` belongs to the host's
	 * default naming; renaming it would break that plugin's own layout.
	 */
	public function test_save_file_name_leaves_foreign_groups_alone() {
		$proposed = 'group_sdc_component_hero.json';

		$this->assertSame(
			$proposed,
			Sdc_Schema::save_file_name( $proposed, array( 'key' => Sdc_Schema::KEY_PREFIX . 'hero' ), '/some/other/plugin/acf-json/' . $proposed )
		);
		$this->assertSame(
			'group_sdc_other.json',
			Sdc_Schema::save_file_name( 'group_sdc_other.json', array( 'key' => 'group_sdc_other' ), Sdc_Schema::dir() . 'el.json' )
		);
		$this->assertFalse( Sdc_Schema::owns_file( '' ) );
	}
}

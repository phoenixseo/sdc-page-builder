<?php
/**
 * What the inspector learns about a component's fields.
 *
 * `Sdc_Block::schema()` is the only path the editor has to a field's shape: it
 * gets a type, a name and whatever attributes the control needs. A container
 * that arrives without its sub-shape is not "less detailed" — the editor picks
 * a control by type, falls back to a text input, and writes a string where the
 * renderer reads a row. So the schema is where the data loss starts.
 *
 * @package SdcPageBuilder
 */

/**
 * Component fields describe their containers completely.
 */
class InspectorSchemaTest extends Sdc_TestCase {

	/**
	 * Directories added by a test, removed again in tear_down().
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Every test here starts from a flushed cache.
	 */
	public function set_up() {
		parent::set_up();

		Sdc_Schema::flush();
	}

	/**
	 * Remove the fixture directories and leave no filter or cache behind.
	 */
	public function tear_down() {
		remove_all_filters( 'sdc_pb_component_dirs' );

		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_tree( $dir );
		}

		$this->temp_dirs = array();
		Sdc_Schema::flush();

		parent::tear_down();
	}

	/**
	 * Register a component backed by a field group of this test's own.
	 *
	 * The shipped components are faq and hero; neither has a container beyond a
	 * repeater, so a container test needs its own definition. Registering the
	 * field group directly is what a site does too — the component JSON only
	 * points at the group key.
	 *
	 * @param string $type   Component type.
	 * @param array  $fields Field definitions.
	 * @return string The directory to filter `sdc_pb_component_dirs` with.
	 */
	private function make_component( $type, array $fields ) {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF is not loaded.' );
		}

		$key = Sdc_Schema::KEY_PREFIX . $type;

		acf_add_local_field_group(
			array(
				'key'      => $key,
				'title'    => 'SDC: ' . $type,
				'fields'   => $fields,
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'page',
						),
					),
				),
			)
		);

		$base = untrailingslashit( get_temp_dir() ) . '/sdc-inspector-' . wp_generate_password( 8, false );
		$dir  = $base . '/' . Sdc_Schema::USER_DIR . '/' . $type;

		$this->assertTrue( mkdir( $dir, 0777, true ) );

		file_put_contents( $dir . '/definition.json', wp_json_encode( array( 'key' => $key ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->temp_dirs[] = $base;

		return $base . '/' . Sdc_Schema::USER_DIR;
	}

	/**
	 * Delete a directory tree.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );

		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
			}
		}

		rmdir( $dir );
	}

	/**
	 * A flexible field hands its layouts to the editor, sub-fields included.
	 *
	 * Without them the control cannot offer a layout to pick and cannot render
	 * the chosen layout's fields — the only thing left is a text input.
	 */
	public function test_flexible_field_hands_its_layouts_to_the_editor() {
		$dirs = $this->make_component(
			'fcprobe',
			array(
				array(
					'key'     => 'field_fcprobe_panels',
					'name'    => 'panels',
					'label'   => 'Panels',
					'type'    => 'flexible_content',
					'layouts' => array(
						'layout_fcprobe_title' => array(
							'key'        => 'layout_fcprobe_title',
							'name'       => 'title_panel',
							'label'      => 'Titel',
							'sub_fields' => array(
								array(
									'key'   => 'field_fcprobe_title',
									'name'  => 'panel_title',
									'label' => 'Titel',
									'type'  => 'text',
								),
							),
						),
					),
				),
			)
		);

		add_filter(
			'sdc_pb_component_dirs',
			static function () use ( $dirs ) {
				return array( $dirs );
			}
		);

		Sdc_Schema::flush();

		$schema = Sdc_Block::schema();

		$this->assertArrayHasKey( 'fcprobe', $schema, 'The fixture component was not discovered' );
		$this->assertArrayHasKey( 'fields', $schema['fcprobe'] );
		$this->assertArrayHasKey( 'layouts', $schema['fcprobe']['fields'][0], 'The field reached the editor without its layouts' );

		$layouts = $schema['fcprobe']['fields'][0]['layouts'];

		$this->assertSame( 'title_panel', $layouts[0]['name'] );
		$this->assertSame( 'Titel', $layouts[0]['label'] );
		$this->assertSame( 'panel_title', $layouts[0]['sub_fields'][0]['name'] );
		$this->assertSame( 'text', $layouts[0]['sub_fields'][0]['type'] );
	}

	/**
	 * A repeater and a group arrive with their sub-fields, a leaf without.
	 */
	public function test_group_hands_its_sub_fields_to_the_editor() {
		$dirs = $this->make_component(
			'groupprobe',
			array(
				array(
					'key'        => 'field_groupprobe_contact',
					'name'       => 'contact',
					'label'      => 'Kontakt',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'   => 'field_groupprobe_name',
							'name'  => 'contact_name',
							'label' => 'Name',
							'type'  => 'text',
						),
					),
				),
				array(
					'key'   => 'field_groupprobe_title',
					'name'  => 'title',
					'label' => 'Titel',
					'type'  => 'text',
				),
			)
		);

		add_filter(
			'sdc_pb_component_dirs',
			static function () use ( $dirs ) {
				return array( $dirs );
			}
		);

		Sdc_Schema::flush();

		$fields = Sdc_Block::schema()['groupprobe']['fields'];

		$this->assertSame( 'contact_name', $fields[0]['sub_fields'][0]['name'] );
		$this->assertArrayNotHasKey( 'sub_fields', $fields[1], 'A leaf field must not claim sub-fields' );
		$this->assertArrayNotHasKey( 'layouts', $fields[1] );
	}
}

<?php
/**
 * The admin screen's logic: validation and the files it writes.
 *
 * @package SdcPageBuilder
 */

/**
 * Everything the component manager decides before it touches the disk, plus
 * the disk work itself — redirected into a temporary directory so the suite
 * never writes into `wp-content/` or the plugin's own `schemas/`.
 */
class AdminTest extends Sdc_TestCase {

	/**
	 * Temporary write directory for the current test.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * A scratch root, removed in tear_down().
	 *
	 * @var string
	 */
	private $root = '';

	/**
	 * Redirect writes into a scratch directory and start from a clean cache.
	 */
	public function set_up() {
		parent::set_up();

		$this->root = untrailingslashit( get_temp_dir() ) . '/sdc-admin-' . wp_generate_password( 8, false );
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

		$_POST = array();

		$this->reload_host();
	}

	/**
	 * Drop the filters, the scratch directory and any posted state.
	 */
	public function tear_down() {
		remove_all_filters( 'sdc_pb_write_dir' );
		remove_all_filters( 'sdc_pb_component_dirs' );

		delete_transient( Sdc_Admin::INSTANCE_TRANSIENT );
		$_POST = array();

		$this->remove_tree( $this->root );

		$this->reload_host();

		parent::tear_down();
	}

	// Type validation: what a component type may look like.

	/**
	 * A type that would escape the component directory is refused.
	 *
	 * @dataProvider bad_types
	 *
	 * @param string $type Submitted type.
	 */
	public function test_validate_type_rejects_bad_types( $type ) {
		$result = Sdc_Admin::validate_type( $type );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_bad_type', $result->get_error_code() );
	}

	/**
	 * Types that would become a path or a broken field key.
	 *
	 * @return array[]
	 */
	public function bad_types() {
		return array(
			'uppercase'    => array( 'Hero' ),
			'parent path'  => array( '../x' ),
			'slash'        => array( 'a/b' ),
			'space'        => array( 'hero 2' ),
			'leading dash' => array( '-hero' ),
			'too long'     => array( str_repeat( 'a', 41 ) ),
		);
	}

	/**
	 * An empty type is its own error, not a format error.
	 */
	public function test_validate_type_rejects_empty() {
		$result = Sdc_Admin::validate_type( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_no_type', $result->get_error_code() );
	}

	/**
	 * Valid types come back unchanged.
	 *
	 * @dataProvider good_types
	 *
	 * @param string $type Valid type.
	 */
	public function test_validate_type_keeps_valid_types( $type ) {
		$this->assertSame( $type, Sdc_Admin::validate_type( $type ) );
	}

	/**
	 * The shapes the screen promises to accept.
	 *
	 * @return array[]
	 */
	public function good_types() {
		return array(
			'simple'     => array( 'hero' ),
			'dash'       => array( 'hero-2' ),
			'underscore' => array( 'hero_2' ),
			'digit'      => array( 'h2' ),
			'max'        => array( str_repeat( 'a', 40 ) ),
		);
	}

	// Field parsing: pasted JSON becomes field definitions.

	/**
	 * No fields is a valid, empty component.
	 */
	public function test_parse_fields_accepts_empty_input() {
		$this->assertSame( array(), Sdc_Admin::parse_fields( '', 'teaser' ) );
		$this->assertSame( array(), Sdc_Admin::parse_fields( "  \n ", 'teaser' ) );
	}

	/**
	 * Broken JSON is reported as such.
	 */
	public function test_parse_fields_rejects_broken_json() {
		$result = Sdc_Admin::parse_fields( '[{"name":"head",', 'teaser' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_json', $result->get_error_code() );
	}

	/**
	 * A JSON object is not a field list.
	 */
	public function test_parse_fields_rejects_object_instead_of_list() {
		$result = Sdc_Admin::parse_fields( '{"name":"head","type":"text"}', 'teaser' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_shape', $result->get_error_code() );
	}

	/**
	 * Every field needs a name.
	 */
	public function test_parse_fields_rejects_field_without_name() {
		$result = Sdc_Admin::parse_fields( '[{"type":"text"}]', 'teaser' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_name', $result->get_error_code() );
	}

	/**
	 * Two fields on one level must not share a name.
	 */
	public function test_parse_fields_rejects_duplicate_names() {
		$result = Sdc_Admin::parse_fields(
			'[{"name":"head","type":"text"},{"name":"head","type":"textarea"}]',
			'teaser'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_duplicate', $result->get_error_code() );
	}

	/**
	 * The same name on different levels is fine.
	 */
	public function test_parse_fields_allows_same_sub_name_on_another_level() {
		$fields = Sdc_Admin::parse_fields(
			'[{"name":"head","type":"text"},{"name":"list","type":"group","sub_fields":[{"name":"head","type":"text"}]}]',
			'teaser'
		);

		$this->assertIsArray( $fields );
		$this->assertSame( 'field_sdc_teaser_head', $fields[1]['sub_fields'][0]['key'] );
	}

	/**
	 * Every field needs a type.
	 */
	public function test_parse_fields_rejects_field_without_type() {
		$result = Sdc_Admin::parse_fields( '[{"name":"head"}]', 'teaser' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_type', $result->get_error_code() );
	}

	/**
	 * A type the host does not know is refused — asked of the host, not of a
	 * hard-coded list, so the test holds on ACF Free and SCF alike.
	 */
	public function test_parse_fields_rejects_unknown_field_type() {
		if ( ! function_exists( 'acf_get_field_type' ) ) {
			$this->fail( 'The host field API is missing; the suite must run with ACF or SCF.' );
		}

		$this->assertEmpty( acf_get_field_type( 'sdc_no_such_type' ) );

		$result = Sdc_Admin::parse_fields( '[{"name":"head","type":"sdc_no_such_type"}]', 'teaser' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_type', $result->get_error_code() );
	}

	/**
	 * A valid field is normalized: prefixed key, label fallback, whitelist.
	 */
	public function test_parse_fields_normalizes_fields() {
		$fields = Sdc_Admin::parse_fields(
			wp_json_encode(
				array(
					array(
						'name'     => 'head',
						'type'     => 'text',
						'required' => 1,
						'rows'     => 3,
						'choices'  => array( 'a' => 'A' ),
						'bogus'    => 'drop me',
						'key'      => 'field_someone_else',
					),
				)
			),
			'teaser'
		);

		$this->assertIsArray( $fields );
		$this->assertCount( 1, $fields );

		$field = $fields[0];

		$this->assertSame( 'field_sdc_teaser_head', $field['key'] );
		$this->assertSame( 'head', $field['name'] );
		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( 'head', $field['label'], 'Ohne Label fällt es auf den Namen zurück.' );
		$this->assertSame( 1, $field['required'] );
		$this->assertSame( 3, $field['rows'] );
		$this->assertSame( array( 'a' => 'A' ), $field['choices'] );
		$this->assertArrayNotHasKey( 'bogus', $field );
	}

	/**
	 * A given label wins over the fallback.
	 */
	public function test_parse_fields_keeps_given_label() {
		$fields = Sdc_Admin::parse_fields( '[{"name":"head","type":"text","label":"Überschrift"}]', 'teaser' );

		$this->assertSame( 'Überschrift', $fields[0]['label'] );
	}

	/**
	 * Sub fields are validated and rekeyed like top level fields.
	 */
	public function test_parse_fields_rekeys_sub_fields() {
		$fields = Sdc_Admin::parse_fields(
			'[{"name":"list","type":"group","sub_fields":[{"name":"item","type":"text","bogus":1}]}]',
			'teaser'
		);

		$this->assertIsArray( $fields );
		$this->assertSame( 'field_sdc_teaser_list', $fields[0]['key'] );
		$this->assertSame( 'field_sdc_teaser_item', $fields[0]['sub_fields'][0]['key'] );
		$this->assertArrayNotHasKey( 'bogus', $fields[0]['sub_fields'][0] );
	}

	/**
	 * Names are reduced to what the host accepts: a–z, 0–9, underscore.
	 */
	public function test_parse_fields_cleans_names() {
		$fields = Sdc_Admin::parse_fields( '[{"name":"Head Line 2","type":"text"}]', 'teaser' );

		$this->assertIsArray( $fields );
		$this->assertSame( 'headline2', $fields[0]['name'] );
		$this->assertSame( 'field_sdc_teaser_headline2', $fields[0]['key'] );
	}

	// Imported definition: an uploaded file becomes a component.

	/**
	 * An import that is not JSON is refused.
	 */
	public function test_parse_definition_rejects_broken_json() {
		$result = Sdc_Admin::parse_definition( 'not json' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_import_json', $result->get_error_code() );
	}

	/**
	 * The key has to carry the component prefix.
	 */
	public function test_parse_definition_rejects_foreign_key() {
		$result = Sdc_Admin::parse_definition( '{"key":"group_other_thing","title":"X"}' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_import_key', $result->get_error_code() );
	}

	/**
	 * A definition without a key is refused.
	 */
	public function test_parse_definition_rejects_missing_key() {
		$result = Sdc_Admin::parse_definition( '{"title":"X"}' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_import_key', $result->get_error_code() );
	}

	/**
	 * A definition without a title is refused.
	 */
	public function test_parse_definition_rejects_missing_title() {
		$result = Sdc_Admin::parse_definition( '{"key":"group_sdc_component_teaser","title":"   "}' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_import_title', $result->get_error_code() );
	}

	/**
	 * The type inside the key is validated like a posted type.
	 */
	public function test_parse_definition_rejects_invalid_type_in_key() {
		$result = Sdc_Admin::parse_definition( '{"key":"group_sdc_component_Teaser","title":"X"}' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_bad_type', $result->get_error_code() );
	}

	/**
	 * A valid definition is rebuilt, not trusted: type derived from the key,
	 * keys re-prefixed, the builder's location rule installed.
	 */
	public function test_parse_definition_rebuilds_a_valid_definition() {
		$raw = wp_json_encode(
			array(
				'key'         => 'group_sdc_component_teaser',
				'title'       => '  Teaser  ',
				'sdc_version' => 0,
				'fields'      => array(
					array(
						'key'  => 'field_foreign',
						'name' => 'head',
						'type' => 'text',
					),
				),
			)
		);

		$parsed = Sdc_Admin::parse_definition( $raw );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'teaser', $parsed['type'] );

		$definition = $parsed['definition'];

		$this->assertSame( 'group_sdc_component_teaser', $definition['key'] );
		$this->assertSame( 'Teaser', $definition['title'] );
		$this->assertSame( 1, $definition['sdc_version'] );
		$this->assertTrue( $definition['active'] );
		$this->assertSame( 'field_sdc_teaser_head', $definition['fields'][0]['key'] );
		$this->assertSame(
			array(
				array(
					array(
						'param'    => 'sdc_component',
						'operator' => '==',
						'value'    => '1',
					),
				),
			),
			$definition['location']
		);
	}

	// Files on disk: create, duplicate and delete.

	/**
	 * Creating a component writes `<type>/definition.json` with valid JSON.
	 */
	public function test_create_writes_a_definition_file() {
		$type = $this->fresh_type( 'callout' );

		$_POST = array(
			'sdc_type'        => $type,
			'sdc_label'       => 'Callout',
			'sdc_description' => 'Ein Hinweis.',
			'sdc_icon'        => 'info',
			'sdc_fields'      => '[{"name":"text","type":"text","required":1}]',
		);

		$this->assertTrue( $this->invoke( 'create' ) );

		$file = $this->dir . '/' . $type . '/definition.json';

		$this->assertFileExists( $file );

		$definition = json_decode( (string) file_get_contents( $file ), true );

		$this->assertIsArray( $definition, 'Die geschriebene Datei ist gültiges JSON.' );
		$this->assertSame( 'group_sdc_component_' . $type, $definition['key'] );
		$this->assertSame( 'Callout', $definition['title'] );
		$this->assertSame( 'Ein Hinweis.', $definition['sdc_description'] );
		$this->assertSame( 'info', $definition['sdc_icon'] );
		$this->assertSame( 'field_sdc_' . $type . '_text', $definition['fields'][0]['key'] );
		$this->assertSame( '1', (string) $definition['location'][0][0]['value'] );
	}

	/**
	 * The plugin's own `schemas/` and `wp-content/` stay untouched.
	 */
	public function test_create_writes_only_into_the_filtered_directory() {
		$type = $this->fresh_type( 'callout' );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '',
		);

		$this->assertTrue( $this->invoke( 'create' ) );

		$this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/' . Sdc_Schema::USER_DIR . '/' . $type );
		$this->assertFileDoesNotExist( Sdc_Schema::dir() . $type . '/definition.json' );
	}

	/**
	 * A type that already exists is refused — the file is not overwritten.
	 */
	public function test_create_refuses_an_existing_type() {
		$type = $this->fresh_type( 'callout' );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '',
		);

		$this->assertTrue( $this->invoke( 'create' ) );
		$this->reload();

		$result = $this->invoke( 'create' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_type_exists', $result->get_error_code() );
	}

	/**
	 * A shipped component cannot be duplicated onto its own type.
	 */
	public function test_create_refuses_a_shipped_type() {
		$_POST = array(
			'sdc_type'   => 'hero',
			'sdc_label'  => 'Hero',
			'sdc_fields' => '',
		);

		$result = $this->invoke( 'create' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_type_exists', $result->get_error_code() );
	}

	/**
	 * A leftover folder from a write that died between mkdir and the file
	 * does not block the next attempt.
	 *
	 * Only a folder that already holds a definition is a duplicate; refusing
	 * on any folder would leave the type unusable with no way out but FTP.
	 */
	public function test_create_fills_a_leftover_folder() {
		$type = $this->fresh_type( 'callout' );

		$this->assertTrue( wp_mkdir_p( $this->dir . '/' . $type ) );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '',
		);

		$this->assertTrue( $this->invoke( 'create' ) );
		$this->assertFileExists( $this->dir . '/' . $type . '/definition.json' );
	}

	/**
	 * A failed write never takes an existing `render.php` down with it.
	 *
	 * The folder is only removed when the failed attempt created it, and
	 * even then `rmdir` leaves anything inside alone.
	 */
	public function test_failed_write_cleanup_spares_an_existing_folder() {
		$type   = $this->fresh_type( 'callout' );
		$folder = $this->dir . '/' . $type;

		$this->assertTrue( wp_mkdir_p( $folder ) );
		$this->assertTrue( (bool) file_put_contents( $folder . '/render.php', '<?php' ) );

		$this->invoke( 'discard_folder', $folder, false );

		$this->assertDirectoryExists( $folder );
		$this->assertFileExists( $folder . '/render.php' );

		$empty = $this->dir . '/' . $this->fresh_type( 'empty' );

		$this->assertTrue( wp_mkdir_p( $empty ) );
		$this->invoke( 'discard_folder', $empty, false );

		$this->assertDirectoryDoesNotExist( $empty );
	}

	/**
	 * A component that stands on a page is counted, and refusing to delete
	 * it without the checkbox comes from that count.
	 *
	 * The document is stored the way Sdc_Store stores it — a PHP array in
	 * one meta row. A counter that only understood JSON reported 0 and the
	 * in-use guard never fired.
	 */
	public function test_instance_counts_reads_stored_documents() {
		$type    = $this->fresh_type( 'callout' );
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		update_post_meta(
			$post_id,
			Sdc_Store::META_KEY,
			array(
				'v'          => 1,
				'components' => array(
					array(
						'_id'    => 'c_one',
						'type'   => $type,
						'schema' => '',
						'data'   => array(),
					),
					array(
						'_id'    => 'c_two',
						'type'   => $type,
						'schema' => '',
						'data'   => array(),
					),
				),
			)
		);

		delete_transient( Sdc_Admin::INSTANCE_TRANSIENT );

		$counts = $this->invoke( 'instance_counts' );

		$this->assertSame( 1, $counts[ $type ]['pages'] );
		$this->assertSame( 2, $counts[ $type ]['components'] );
	}

	/**
	 * The nonce a form carries is one the handler accepts.
	 *
	 * Rendering and verifying happen in two places, so they can drift apart
	 * silently — and did: the form wrote `sdc_pb_nonce`, the handler asked
	 * for `_wpnonce`, and every submit answered "the link you followed has
	 * expired". This ties the two ends to the same constant.
	 */
	public function test_form_nonce_is_the_one_the_handler_verifies() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->invoke( 'form_hidden', 'create' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . Sdc_Admin::NONCE_FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="_wpnonce"', $html );

		$this->assertSame( 1, preg_match( '/name="' . Sdc_Admin::NONCE_FIELD . '" value="([^"]+)"/', $html, $m ) );
		$this->assertSame( 1, wp_verify_nonce( $m[1], Sdc_Admin::NONCE_ACTION ) );
	}

	/**
	 * A component without a label is refused.
	 */
	public function test_create_requires_a_label() {
		$type = $this->fresh_type( 'callout' );

		$_POST = array(
			'sdc_type'  => $type,
			'sdc_label' => '   ',
		);

		$result = $this->invoke( 'create' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_no_label', $result->get_error_code() );
		$this->assertDirectoryDoesNotExist( $this->dir . '/' . $type );
	}

	/**
	 * Duplicating copies the field structure under fresh keys.
	 */
	public function test_duplicate_copies_a_component_with_new_keys() {
		$type = $this->fresh_type( 'hero-copy' );

		$_POST = array(
			'sdc_source' => 'hero',
			'sdc_type'   => $type,
		);

		$this->assertTrue( $this->invoke( 'duplicate' ) );

		$file = $this->dir . '/' . $type . '/definition.json';

		$this->assertFileExists( $file );

		$definition = json_decode( (string) file_get_contents( $file ), true );

		$this->assertIsArray( $definition );
		$this->assertSame( 'group_sdc_component_' . $type, $definition['key'] );
		$this->assertStringContainsString( '(Kopie)', $definition['title'] );
		$this->assertNotEmpty( $definition['fields'] );

		$source_fields = Sdc_Schema::fields( 'hero' );
		$this->assertCount( count( $source_fields ), $definition['fields'] );

		foreach ( $definition['fields'] as $index => $field ) {
			$this->assertSame( 'field_sdc_' . $type . '_' . $field['name'], $field['key'] );
			$this->assertNotSame( $source_fields[ $index ]['key'], $field['key'] );
		}
	}

	/**
	 * Duplicating onto a type that already exists is refused.
	 */
	public function test_duplicate_refuses_an_existing_type() {
		$type = $this->fresh_type( 'hero-copy' );

		$_POST = array(
			'sdc_source' => 'hero',
			'sdc_type'   => $type,
		);

		$this->assertTrue( $this->invoke( 'duplicate' ) );
		$this->reload();

		$result = $this->invoke( 'duplicate' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_type_exists', $result->get_error_code() );
	}

	/**
	 * Duplicating an unknown source is refused.
	 */
	public function test_duplicate_refuses_an_unknown_source() {
		$_POST = array(
			'sdc_source' => 'nope',
			'sdc_type'   => $this->fresh_type( 'nope-copy' ),
		);

		$result = $this->invoke( 'duplicate' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_unknown_source', $result->get_error_code() );
	}

	/**
	 * A component shipped with the plugin is not the screen's to delete.
	 */
	public function test_delete_refuses_a_component_outside_the_write_dir() {
		$_POST = array( 'sdc_type' => 'hero' );

		$result = $this->invoke( 'delete' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_not_owned', $result->get_error_code() );
		$this->assertFileExists( Sdc_Schema::dir() . 'hero/definition.json' );
	}

	/**
	 * Deleting an own component removes the folder and its definition.
	 */
	public function test_delete_removes_an_own_component() {
		$type = $this->fresh_type( 'callout' );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '',
		);

		$this->assertTrue( $this->invoke( 'create' ) );
		$this->reload();

		$this->assertFileExists( $this->dir . '/' . $type . '/definition.json' );

		// A write that died before its rename leaves this behind.
		$this->assertTrue( (bool) file_put_contents( $this->dir . '/' . $type . '/definition.json.tmp', '{' ) );

		$_POST = array( 'sdc_type' => $type );

		$this->assertTrue( $this->invoke( 'delete' ) );

		$this->assertFileDoesNotExist( $this->dir . '/' . $type . '/definition.json' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/' . $type );
	}

	/**
	 * An unknown component cannot be deleted.
	 */
	public function test_delete_refuses_an_unknown_component() {
		$_POST = array( 'sdc_type' => 'nope' );

		$result = $this->invoke( 'delete' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_unknown_component', $result->get_error_code() );
	}

	// Editing an existing component.

	/**
	 * Saving an edit writes back into the same file, under the same key.
	 *
	 * The type is the identity of a component: it is the folder name, the
	 * group key and the page's reference to it. An edit therefore changes
	 * the label, the description, the icon and the fields — never the type.
	 */
	public function test_update_writes_back_into_the_same_file() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component( $type );

		$_POST = array(
			'sdc_type'        => $type,
			'sdc_label'       => 'Hinweis neu',
			'sdc_description' => 'Neue Beschreibung.',
			'sdc_icon'        => 'info',
			'sdc_fields'      => '[{"name":"text","type":"textarea","label":"Text"},{"name":"source","type":"text","label":"Quelle"}]',
		);

		$this->assertTrue( $this->invoke( 'update' ) );

		$definition = json_decode( (string) file_get_contents( $file ), true );

		$this->assertIsArray( $definition, 'Die Datei ist nach dem Speichern gültiges JSON.' );
		$this->assertSame( 'group_sdc_component_' . $type, $definition['key'], 'Der Gruppen-Key bleibt.' );
		$this->assertSame( 'Hinweis neu', $definition['title'] );
		$this->assertSame( 'Neue Beschreibung.', $definition['sdc_description'] );
		$this->assertSame( 'info', $definition['sdc_icon'] );
		$this->assertSame( 'field_sdc_' . $type . '_source', $definition['fields'][1]['key'] );
		$this->assertCount( 1, glob( $this->dir . '/' . $type . '/definition.json*' ), 'Nur eine Datei im Ordner.' );
		$this->assertFileDoesNotExist( $file . '.tmp', 'Kein .tmp-Rest nach dem Speichern.' );
	}

	/**
	 * Properties the form does not show survive an edit.
	 *
	 * The file is the truth and may carry settings an earlier version or a
	 * hand edit added. Rebuilding the definition from the form would reset
	 * them, so the stored array is the base and only four keys change.
	 */
	public function test_update_keeps_properties_the_form_does_not_know() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component( $type );

		$base                    = json_decode( (string) file_get_contents( $file ), true );
		$base['menu_order']      = 7;
		$base['position']        = 'side';
		$base['sdc_version']     = 3;
		$base['custom_property'] = 'bleibt';
		$this->assertNotFalse( file_put_contents( $file, (string) wp_json_encode( $base ) ) );
		$this->reload();

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Anders',
			'sdc_fields' => '',
		);

		$this->assertTrue( $this->invoke( 'update' ) );

		$definition = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 7, $definition['menu_order'] );
		$this->assertSame( 'side', $definition['position'] );
		$this->assertSame( 3, $definition['sdc_version'] );
		$this->assertSame( 'bleibt', (string) $definition['custom_property'] );
		$this->assertSame( 'Anders', $definition['title'] );
	}

	/**
	 * A field whose name did not change keeps its key.
	 *
	 * This is the promise behind editing at all: the key is what the page's
	 * stored values are looked up by, so an unchanged name must produce an
	 * unchanged key — otherwise every saved page would go blank after an
	 * edit that only renamed the label.
	 */
	public function test_update_keeps_the_key_of_an_unchanged_field() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component(
			$type,
			'[{"name":"text","type":"text","label":"Text"}]'
		);

		$before = json_decode( (string) file_get_contents( $file ), true );
		$key    = $before['fields'][0]['key'];

		// Same name, different label.
		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '[{"name":"text","type":"text","label":"Andere Beschriftung"}]',
		);

		$this->assertTrue( $this->invoke( 'update' ) );

		$after = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( $key, $after['fields'][0]['key'], 'Der Feld-Key bleibt bei unverändertem Namen.' );
		$this->assertSame( 'field_sdc_' . $type . '_text', $after['fields'][0]['key'] );
		$this->assertSame( 'Andere Beschriftung', $after['fields'][0]['label'] );
	}

	/**
	 * A key the file brought itself survives an edit.
	 *
	 * Deriving the key from the type and the field name is only correct for
	 * components this plugin wrote. A hand written or imported definition
	 * may use any key, and the page looks its values up by exactly that
	 * key — replacing it would leave every saved value on every page
	 * unreadable after a save that only changed a label.
	 */
	public function test_update_keeps_a_key_the_file_brought_itself() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component(
			$type,
			'[{"name":"text","type":"text","label":"Text"},{"name":"list","type":"group","sub_fields":[{"name":"item","type":"text"}]}]'
		);

		$base = json_decode( (string) file_get_contents( $file ), true );

		$base['fields'][0]['key']                  = 'field_abc123';
		$base['fields'][1]['key']                  = 'field_group_999';
		$base['fields'][1]['sub_fields'][0]['key'] = 'field_sub_777';
		$this->assertNotFalse( file_put_contents( $file, (string) wp_json_encode( $base ) ) );
		$this->reload();

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '[{"name":"text","type":"text","label":"Neue Beschriftung"},{"name":"list","type":"group","sub_fields":[{"name":"item","type":"text"}]}]',
		);

		$this->assertTrue( $this->invoke( 'update' ) );

		$after = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 'field_abc123', $after['fields'][0]['key'], 'Der fremde Feld-Key bleibt.' );
		$this->assertSame( 'Neue Beschriftung', $after['fields'][0]['label'] );
		$this->assertSame( 'field_group_999', $after['fields'][1]['key'], 'Der fremde Gruppen-Key bleibt.' );
		$this->assertSame( 'field_sub_777', $after['fields'][1]['sub_fields'][0]['key'], 'Der fremde Unterfeld-Key bleibt.' );
	}

	/**
	 * A dropped field is refused until the loss is confirmed, and the file
	 * is left byte for byte as it was.
	 *
	 * Silently dropping a field is not a visible mistake: the values stay in
	 * the page document, the field is gone from the editor, and nobody can
	 * tell afterwards that content was lost.
	 */
	public function test_update_refuses_a_removed_field() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component(
			$type,
			'[{"name":"text","type":"text","label":"Text"},{"name":"source","type":"text","label":"Quelle"}]'
		);

		$before = (string) file_get_contents( $file );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '[{"name":"text","type":"text","label":"Text"}]',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_removed', $result->get_error_code() );
		$this->assertStringContainsString( 'source', $result->get_error_message() );
		$this->assertSame( $before, (string) file_get_contents( $file ), 'Die Datei bleibt unverändert.' );

		// With the checkbox the same edit goes through.
		$_POST['sdc_confirm_fields'] = '1';

		$this->assertTrue( $this->invoke( 'update' ) );

		$after = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 1, count( $after['fields'] ) );
		$this->assertSame( 'text', $after['fields'][0]['name'] );
	}

	/**
	 * A nested field counts as removed too.
	 *
	 * `sub_fields` are where a repeater's content lives, so a guard that only
	 * looked at the top level would drop them silently.
	 */
	public function test_update_notices_a_removed_sub_field() {
		$type = $this->fresh_type( 'callout' );
		$this->create_component(
			$type,
			'[{"name":"items","type":"group","label":"Einträge","sub_fields":[{"name":"q","type":"text","label":"Frage"},{"name":"a","type":"textarea","label":"Antwort"}]}]'
		);

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '[{"name":"items","type":"group","label":"Einträge","sub_fields":[{"name":"q","type":"text","label":"Frage"}]}]',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_removed', $result->get_error_code() );
		$this->assertStringContainsString( 'a', $result->get_error_message() );
	}

	/**
	 * Adding a field is not a loss and needs no confirmation.
	 */
	public function test_update_adds_a_field_without_confirmation() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component( $type, '[{"name":"text","type":"text","label":"Text"}]' );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => '[{"name":"text","type":"text","label":"Text"},{"name":"extra","type":"text","label":"Zusatz"}]',
		);

		$this->assertTrue( $this->invoke( 'update' ) );

		$definition = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( 2, count( $definition['fields'] ) );
	}

	/**
	 * A component outside the write directory cannot be edited in place.
	 *
	 * The shipped and theme components are deploy code: the next deploy would
	 * overwrite the edit, so those are duplicable, not editable.
	 */
	public function test_update_refuses_a_component_outside_the_write_dir() {
		$before = (string) file_get_contents( Sdc_Schema::dir() . 'hero/definition.json' );

		$_POST = array(
			'sdc_type'   => 'hero',
			'sdc_label'  => 'Umgebaut',
			'sdc_fields' => '',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_outside_write_dir', $result->get_error_code() );
		$this->assertSame( $before, (string) file_get_contents( Sdc_Schema::dir() . 'hero/definition.json' ), 'Die Plugin-Datei bleibt unverändert.' );
	}

	/**
	 * An unknown type cannot be edited.
	 */
	public function test_update_refuses_an_unknown_type() {
		$_POST = array(
			'sdc_type'   => $this->fresh_type( 'nope' ),
			'sdc_label'  => 'Nichts',
			'sdc_fields' => '',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_unknown_type', $result->get_error_code() );
	}

	/**
	 * A missing label or broken JSON is refused and the file keeps its bytes.
	 */
	public function test_update_refuses_a_missing_label_and_broken_json() {
		$type   = $this->fresh_type( 'callout' );
		$file   = $this->create_component( $type, '[{"name":"text","type":"text","label":"Text"}]' );
		$before = (string) file_get_contents( $file );

		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => '',
			'sdc_fields' => '',
		);

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_no_label', $result->get_error_code() );
		$this->assertSame( $before, (string) file_get_contents( $file ) );

		$_POST['sdc_label']  = 'Callout';
		$_POST['sdc_fields'] = '[{"name":';

		$result = $this->invoke( 'update' );

		$this->assertWPError( $result );
		$this->assertSame( 'sdc_fields_json', $result->get_error_code() );
		$this->assertSame( $before, (string) file_get_contents( $file ) );
	}

	/**
	 * A rejected save keeps what was typed, so nothing has to be entered
	 * twice.
	 *
	 * Two halves, tested without the redirect (which would exit): what is
	 * captured from the form, and what the form reads back on the next
	 * request. A mismatch between them is exactly how the fields would be
	 * lost.
	 */
	public function test_a_rejected_save_keeps_the_typed_values() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component(
			$type,
			'[{"name":"text","type":"text","label":"Text"},{"name":"source","type":"text","label":"Quelle"}]'
		);

		// A removal without the checkbox: refused, and the typed list is the
		// only copy of what the author just entered.
		$typed = '[{"name":"text","type":"textarea","label":"Neuer Text"}]';

		$_POST = array(
			'sdc_type'        => $type,
			'sdc_label'       => 'Getippter Name',
			'sdc_description' => 'Getippte Beschreibung',
			'sdc_icon'        => 'info',
			'sdc_fields'      => $typed,
		);

		$this->assertWPError( $this->invoke( 'update' ) );

		// What the redirect stores.
		$draft = $this->invoke( 'posted_draft' );

		$this->assertSame( 'Getippter Name', $draft['label'] );
		$this->assertSame( 'Getippte Beschreibung', $draft['description'] );
		$this->assertSame( 'info', $draft['icon'] );
		$this->assertSame( $typed, $draft['fields'], 'Die Feldliste kommt unverändert aus dem Formular.' );

		// What the form reads on the next request — under its own token.
		$token = 'testtoken123';
		set_transient( 'sdc_pb_draft_' . $token, $draft, 60 );
		$_GET['sdc_detail'] = $token;

		$read = $this->invoke( 'take_draft', $type );

		unset( $_GET['sdc_detail'] );
		delete_transient( 'sdc_pb_draft_' . $token );

		$this->assertIsArray( $read, 'Der Entwurf liegt bereit.' );
		$this->assertSame( $typed, $read['fields'] );
		$this->assertSame( 'Getippter Name', $read['label'] );

		// A draft of another component is not applied to this one.
		set_transient( 'sdc_pb_draft_' . $token, $draft, 60 );
		$_GET['sdc_detail'] = $token;

		$this->assertNull( $this->invoke( 'take_draft', $type . '-anders' ) );

		unset( $_GET['sdc_detail'] );
		delete_transient( 'sdc_pb_draft_' . $token );
	}

	/**
	 * The edit screen offers the stored fields without their generated keys.
	 *
	 * A key is derived from type and name, so showing it suggests it could be
	 * edited. Everything else in the file is shown.
	 */
	public function test_edit_form_shows_the_fields_without_generated_keys() {
		$type = $this->fresh_type( 'callout' );
		$file = $this->create_component(
			$type,
			'[{"name":"text","type":"text","label":"Text","required":1}]'
		);

		$this->assertFileExists( $file );

		$fields = $this->invoke( 'printable_fields', json_decode( (string) file_get_contents( $file ), true )['fields'] );

		$this->assertStringNotContainsString( 'field_sdc_' . $type, $fields, 'Der generierte Key steht nicht im Formular.' );
		$this->assertStringContainsString( '"required": 1', $fields, 'Eigene Feld-Einstellungen bleiben sichtbar.' );
		$this->assertStringContainsString( '"label": "Text"', $fields );
	}

	/**
	 * A saved edit lands back on its own form, not on the catalog.
	 *
	 * This is the difference between the generate button being useful or
	 * useless: the view it produces is the response to the click. Sending
	 * the author to the overview hides that response and makes the click
	 * look like it did nothing, even though the file was written.
	 */
	public function test_a_saved_edit_returns_to_its_form() {
		$type = $this->fresh_type( 'teaser' );

		$_POST = array( 'sdc_type' => $type );

		$this->assertSame(
			array( 'sdc_edit' => $type ),
			$this->invoke( 'success_args', 'update' ),
			'Nach dem Speichern bleibt man in der Komponente.'
		);
	}

	/**
	 * Every other task returns to the catalog.
	 *
	 * They put their result where the list shows it — a new, copied or
	 * deleted component — so a form target would be a detour.
	 */
	public function test_other_tasks_return_to_the_catalog() {
		$_POST = array( 'sdc_type' => 'teaser' );

		foreach ( array( 'create', 'duplicate', 'delete', 'import' ) as $task ) {
			$this->assertSame( array(), $this->invoke( 'success_args', $task ), $task );
		}
	}

	/**
	 * Without a posted type there is no form to return to.
	 *
	 * `edit_args()` is the single place that decides this, so the fallback
	 * is checked here rather than discovered on a redirect that points at
	 * an edit screen refusing to render.
	 */
	public function test_a_saved_edit_without_a_type_returns_to_the_catalog() {
		$_POST = array( 'sdc_type' => '' );

		$this->assertSame( array(), $this->invoke( 'success_args', 'update' ) );
	}

	// Helpers.

	/**
	 * Create one component in the scratch directory and return its file.
	 *
	 * @param string $type    Component type.
	 * @param string $fields  JSON field list.
	 * @return string Absolute path of the written definition.
	 */
	private function create_component( $type, $fields = '' ) {
		$_POST = array(
			'sdc_type'   => $type,
			'sdc_label'  => 'Callout',
			'sdc_fields' => $fields,
		);

		$this->assertTrue( $this->invoke( 'create' ) );
		$this->reload();

		return $this->dir . '/' . $type . '/definition.json';
	}

	/**
	 * A component type this test owns alone.
	 *
	 * The host keeps loaded field groups for the whole PHP process and does
	 * not reliably forget a group whose file was just deleted, so two tests
	 * must never share a type: the second one would see the first one's
	 * leftover and be refused as a duplicate. Unique types per test keep the
	 * suite independent of that cache without weakening an assertion.
	 *
	 * @param string $base Readable prefix.
	 * @return string Valid component type.
	 */
	private function fresh_type( $base ) {
		return $base . '-' . strtolower( wp_generate_password( 6, false ) );
	}

	/**
	 * Call a private static method of Sdc_Admin.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments for the method.
	 * @return mixed
	 */
	private function invoke( $method, ...$args ) {
		$reflection = new ReflectionMethod( 'Sdc_Admin', $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Make the host read the component files again.
	 *
	 * ACF/SCF load Local JSON once per request, so a file written by the code
	 * under test is invisible until the loader runs again. The stores are
	 * reset first: a group whose file was deleted must not linger.
	 */
	private function reload() {
		$this->reload_host();
	}

	/**
	 * Drop the host's cached groups and let it read the files again.
	 *
	 * The host cache lives for the whole PHP process, so a group whose file
	 * this suite just deleted would otherwise leak into the next test.
	 */
	private function reload_host() {
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
		delete_transient( Sdc_Admin::INSTANCE_TRANSIENT );
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

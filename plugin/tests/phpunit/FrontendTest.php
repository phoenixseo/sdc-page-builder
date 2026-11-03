<?php
/**
 * Frontend output: the stored document is what the page shows.
 *
 * @package SdcPageBuilder
 */

/**
 * Covers the seam that turns a document into page HTML. The live rendering of
 * a real permalink is covered separately in the container by
 * `tests/m3-verify.php`; this class covers the function that produces it.
 */
class FrontendTest extends Sdc_TestCase {

	/**
	 * A page with a document renders both components.
	 */
	public function test_render_post_emits_every_component() {
		$post_id = $this->make_page();
		Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					$this->hero_component( 'Kopfzeile' ),
					$this->faq_component( 'Fragen', 2 ),
				),
			)
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( '<div class="sdc-page sdc-page--' . $post_id . '">', $html );
		$this->assertStringContainsString( 'sdc-component--hero', $html );
		$this->assertStringContainsString( 'sdc-component--faq', $html );
		$this->assertStringContainsString( 'Kopfzeile', $html );
		$this->assertStringContainsString( 'Fragen', $html );
	}

	/**
	 * The rendered values come from the stored document, not from defaults.
	 */
	public function test_rendered_values_come_from_the_document() {
		$post_id = $this->make_page();
		Sdc_Store::save_data(
			$post_id,
			array( 'components' => array( $this->faq_component( 'Gespeicherte Überschrift', 3 ) ) )
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( 'Gespeicherte Überschrift', $html );
		$this->assertStringContainsString( 'Frage 3', $html );
		$this->assertStringContainsString( 'Antwort 3 mit Umlauten: äöüß', $html );
	}

	/**
	 * A page without a document renders nothing at all — no wrapper, no empty div.
	 */
	public function test_page_without_document_renders_nothing() {
		$post_id = $this->make_page();

		$this->assertSame( '', Sdc_Frontend::render_post( $post_id ) );
	}

	/**
	 * An unknown type is skipped, the rest of the page still renders.
	 */
	public function test_unknown_component_type_is_skipped_not_fatal() {
		$post_id = $this->make_page();
		update_post_meta(
			$post_id,
			Sdc_Store::META_KEY,
			wp_slash(
				array(
					'v'          => Sdc_Store::FORMAT_VERSION,
					'components' => array(
						array(
							'_id'  => 'c_broken01',
							'type' => 'verschwunden',
							'data' => array(),
						),
						array(
							'_id'  => 'c_faq00001',
							'type' => 'faq',
							'data' => $this->faq_component( 'Bleibt' )['data'],
						),
					),
				)
			)
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringContainsString( 'Bleibt', $html );
		$this->assertStringNotContainsString( 'verschwunden', $html );
	}

	/**
	 * The frontend does not emit the editor's data attributes.
	 */
	public function test_frontend_markup_carries_no_editor_attributes() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component() ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringNotContainsString( 'data-sdc-type', $html );
		$this->assertStringNotContainsString( 'data-sdc-context', $html );
		$this->assertStringNotContainsString( 'data-sdc-instance', $html );
	}

	/**
	 * Stored values are escaped on output.
	 */
	public function test_frontend_escapes_stored_values() {
		$post_id = $this->make_page();
		Sdc_Store::save_data(
			$post_id,
			array(
				'components' => array(
					array(
						'type' => 'faq',
						'data' => array(
							'heading' => '<script>alert(1)</script>',
							'items'   => array(),
						),
					),
				),
			)
		);

		$html = Sdc_Frontend::render_post( $post_id );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/**
	 * `the_content` is only appended to on a singular view of an editable type.
	 */
	public function test_filter_content_leaves_non_singular_requests_alone() {
		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component() ) ) );

		// No main query / loop in a unit test: the guard must return the input.
		$this->assertSame( 'Inhalt', Sdc_Frontend::filter_content( 'Inhalt' ) );
	}

	/**
	 * A component directory in the theme owns both the schema and the markup.
	 *
	 * The contract a theme relies on: drop the JSON and a `render.php` into
	 * one directory, and the page renders through that file instead of the
	 * generic field markup.
	 */
	public function test_theme_template_renders_instead_of_generic_markup() {
		$dir = $this->component_dir(
			'hero',
			'<?php echo \'<article class="theme-hero">\' . esc_html( $data[\'heading\'] ) . \'</article>\';'
		);

		add_filter(
			'sdc_pb_component_dirs',
			function () use ( $dir ) {
				return array( $dir );
			}
		);

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Aus dem Theme' ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		remove_all_filters( 'sdc_pb_component_dirs' );
		$this->remove_dir( $dir );

		$this->assertStringContainsString( '<article class="theme-hero">Aus dem Theme</article>', $html );
		$this->assertStringNotContainsString( 'sdc-component--hero', $html );
		$this->assertStringNotContainsString( 'sdc-field', $html );
	}

	/**
	 * The template finds the default field markup without copying it.
	 */
	public function test_component_fields_html_is_usable_inside_a_template() {
		$dir = $this->component_dir(
			'faq',
			'<?php echo \'<div class="theme-faq">\' . Sdc_Frontend::component_fields_html( $type, $data ) . \'</div>\';'
		);

		add_filter(
			'sdc_pb_component_dirs',
			function () use ( $dir ) {
				return array( $dir );
			}
		);

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->faq_component( 'Themen-FAQ', 1 ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		remove_all_filters( 'sdc_pb_component_dirs' );
		$this->remove_dir( $dir );

		$this->assertStringContainsString( '<div class="theme-faq">', $html );
		$this->assertStringContainsString( 'Themen-FAQ', $html );
		$this->assertStringContainsString( 'Antwort 1', $html );
	}

	/**
	 * The filter is the last word: it can replace the markup entirely.
	 */
	public function test_component_html_filter_replaces_the_markup() {
		$seen = array();

		add_filter(
			'sdc_pb_component_html',
			function ( $html, $type, $data, $component ) use ( &$seen ) {
				$seen = array(
					'type'     => $type,
					'heading'  => $data['heading'],
					'has_id'   => ! empty( $component['_id'] ),
					'replaced' => false !== strpos( $html, 'sdc-component--' . $type ),
				);

				return '<section class="filtered-' . esc_attr( $type ) . '">' . esc_html( $data['heading'] ) . '</section>';
			},
			10,
			4
		);

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Gefiltert' ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		remove_all_filters( 'sdc_pb_component_html' );

		$this->assertStringContainsString( '<section class="filtered-hero">Gefiltert</section>', $html );
		$this->assertStringNotContainsString( 'sdc-component--hero', $html );
		$this->assertSame( 'hero', $seen['type'] );
		$this->assertSame( 'Gefiltert', $seen['heading'] );
		$this->assertTrue( $seen['has_id'] );
		$this->assertTrue( $seen['replaced'] );
	}

	/**
	 * A filter returning nothing removes the component.
	 */
	public function test_component_html_filter_can_drop_a_component() {
		add_filter( 'sdc_pb_component_html', '__return_empty_string' );

		$post_id = $this->make_page();
		Sdc_Store::save_data( $post_id, array( 'components' => array( $this->hero_component( 'Weg' ) ) ) );

		$html = Sdc_Frontend::render_post( $post_id );

		remove_all_filters( 'sdc_pb_component_html' );

		$this->assertSame( '', $html );
	}

	/**
	 * A component type is never a path: traversal attempts find no template.
	 */
	public function test_template_lookup_rejects_a_type_with_path_parts() {
		$this->assertSame( '', Sdc_Schema::template( '../../etc/passwd' ) );
		$this->assertSame( '', Sdc_Schema::template( '' ) );
	}

	/**
	 * Create `<temp>/sdc-components/<type>/render.php` and return the base dir.
	 *
	 * @param string $type Component type.
	 * @param string $code Full template body, starting with `<?php`.
	 * @return string Absolute base directory.
	 */
	private function component_dir( $type, $code ) {
		$base = untrailingslashit( get_temp_dir() ) . '/sdc-test-' . wp_generate_password( 8, false );
		$dir  = $base . '/' . Sdc_Schema::USER_DIR . '/' . $type;

		$this->assertTrue( mkdir( $dir, 0777, true ) );

		file_put_contents( $dir . '/render.php', $code . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/definition.json', wp_json_encode( array( 'key' => Sdc_Schema::KEY_PREFIX . $type ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $base . '/' . Sdc_Schema::USER_DIR;
	}

	/**
	 * Remove a fixture directory tree.
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

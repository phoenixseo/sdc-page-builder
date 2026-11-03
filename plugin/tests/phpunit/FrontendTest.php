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
}

<?php
/**
 * Editor markup: the component cards the builder renders into the metabox.
 *
 * @package SdcPageBuilder
 */

/**
 * The renderer is shared by editor and frontend. This class covers the editor
 * contract: instance-prefixed input names, one card per component, no
 * collisions between two components of the same type.
 */
class RendererTest extends Sdc_TestCase {

	/**
	 * A component card declares its type, context and instance.
	 */
	public function test_component_card_exposes_type_context_and_instance() {
		$html = Sdc_Renderer::render_component( 'faq', $this->faq_component()['data'], 'editor', 'c_one' );

		$this->assertStringContainsString( 'class="sdc-component sdc-component--faq"', $html );
		$this->assertStringContainsString( 'data-sdc-type="faq"', $html );
		$this->assertStringContainsString( 'data-sdc-context="editor"', $html );
		$this->assertStringContainsString( 'data-sdc-instance="c_one"', $html );
	}

	/**
	 * Input names carry the instance, so two FAQ components do not collide.
	 *
	 * The host names inputs by field *key*, not by name — measured in the
	 * markup: `acf[c_one][field_sdc_faq_heading]`.
	 */
	public function test_two_instances_of_one_type_do_not_share_input_names() {
		$data = $this->faq_component()['data'];

		$one = Sdc_Renderer::render_component( 'faq', $data, 'editor', 'c_one' );
		$two = Sdc_Renderer::render_component( 'faq', $data, 'editor', 'c_two' );

		$this->assertStringContainsString( 'name="acf[c_one][field_sdc_faq_heading]"', $one );
		$this->assertStringContainsString( 'name="acf[c_two][field_sdc_faq_heading]"', $two );

		preg_match_all( '/name="(acf\[[^\]]+\]\[[^"]+)"/', $one, $names_one );
		preg_match_all( '/name="(acf\[[^\]]+\]\[[^"]+)"/', $two, $names_two );

		$this->assertNotEmpty( $names_one[1] );
		$this->assertSame( array(), array_intersect( $names_one[1], $names_two[1] ), 'Both instances emit the same input names' );
	}

	/**
	 * Every field of the schema appears in the markup.
	 */
	public function test_all_schema_fields_are_rendered() {
		$html = Sdc_Renderer::render_component( 'faq', $this->faq_component()['data'], 'editor', 'c_one' );

		$this->assertStringContainsString( 'data-sdc-name="heading"', $html );
		$this->assertStringContainsString( 'data-sdc-name="items"', $html );
	}

	/**
	 * Row values from the document are present in the rendered inputs.
	 */
	public function test_saved_values_reach_the_inputs() {
		$html = Sdc_Renderer::render_component( 'faq', $this->faq_component( 'Überschrift gespeichert', 2 )['data'], 'editor', 'c_one' );

		$this->assertStringContainsString( 'Überschrift gespeichert', $html );
		$this->assertStringContainsString( 'Frage 1', $html );
		$this->assertStringContainsString( 'Antwort 2 mit Umlauten', $html );
	}

	/**
	 * Values are escaped — a document is not a trusted HTML source.
	 */
	public function test_values_are_escaped() {
		$data = array(
			'heading' => '<script>alert("xss")</script>',
			'items'   => array(),
		);

		$html = Sdc_Renderer::render_component( 'faq', $data, 'editor', 'c_one' );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * An unknown type renders nothing rather than throwing.
	 */
	public function test_unknown_type_renders_nothing() {
		$this->assertSame( '', Sdc_Renderer::render_component( 'gibt-es-nicht', array(), 'editor', 'c_x' ) );
	}

	/**
	 * An instance id cannot break out of the prefix or the attribute.
	 */
	public function test_instance_id_is_sanitized() {
		$html = Sdc_Renderer::render_component( 'faq', $this->faq_component()['data'], 'editor', 'c_"onmouseover="alert(1)' );

		$this->assertStringNotContainsString( 'onmouseover="alert', $html );
	}

	/**
	 * Both shipped types render through the same seam — no per-type code path.
	 */
	public function test_second_type_renders_without_type_specific_code() {
		$html = Sdc_Renderer::render_component( 'hero', $this->hero_component()['data'], 'editor', 'c_hero' );

		$this->assertStringContainsString( 'sdc-component--hero', $html );
		$this->assertStringContainsString( 'data-sdc-name="heading"', $html );
		$this->assertStringContainsString( 'data-sdc-name="cta"', $html );
	}

	/**
	 * An empty list renders an empty container, not a broken one.
	 */
	public function test_empty_repeater_renders_empty_container() {
		$data = array(
			'heading' => 'Ohne Einträge',
			'items'   => array(),
		);

		$html = Sdc_Renderer::render_component( 'faq', $data, 'editor', 'c_one' );

		$this->assertStringContainsString( 'data-sdc-name="items"', $html );
		$this->assertStringContainsString( 'Ohne Einträge', $html );
	}
}

<?php
/**
 * Containers: a repeating list whose rows name their own layout, and a group.
 *
 * Both share one failure mode: a container that hands its `sub_fields` down to
 * every row loses the rows whose fields live elsewhere. For `group` those are
 * its own sub-fields and the values arrive as an object; for
 * `flexible_content` the sub-fields sit in a layout named by `acf_fc_layout`
 * and the field itself declares none. In both cases the row rendered empty and
 * was dropped — silently, which is the part that matters.
 *
 * @package SdcPageBuilder
 */

/**
 * One list layout with a title, one with a quote, and a group of two texts.
 */
class ContainerTest extends Sdc_TestCase {

	/**
	 * A flexible field: two layouts, each with one field of its own.
	 *
	 * @return array
	 */
	private function flexible_field() {
		return array(
			'key'        => 'field_sdc_test_fc',
			'name'       => 'panels',
			'type'       => 'flexible_content',
			'label'      => 'Panels',
			'layouts'    => array(
				'layout_title' => array(
					'key'        => 'layout_title',
					'name'       => 'title_panel',
					'label'      => 'Titel',
					'sub_fields' => array(
						array(
							'key'   => 'field_sdc_test_panel_title',
							'name'  => 'panel_title',
							'type'  => 'text',
							'label' => 'Titel',
						),
					),
				),
				'layout_quote' => array(
					'key'        => 'layout_quote',
					'name'       => 'quote_panel',
					'label'      => 'Zitat',
					'sub_fields' => array(
						array(
							'key'   => 'field_sdc_test_panel_quote',
							'name'  => 'panel_quote',
							'type'  => 'text',
							'label' => 'Zitat',
						),
					),
				),
			),
			'sub_fields' => array(),
		);
	}

	/**
	 * A group holding two texts.
	 *
	 * @return array
	 */
	private function group_field() {
		return array(
			'key'        => 'field_sdc_test_group',
			'name'       => 'contact',
			'type'       => 'group',
			'label'      => 'Kontakt',
			'sub_fields' => array(
				array(
					'key'   => 'field_sdc_test_group_name',
					'name'  => 'contact_name',
					'type'  => 'text',
					'label' => 'Name',
				),
				array(
					'key'   => 'field_sdc_test_group_mail',
					'name'  => 'contact_mail',
					'type'  => 'text',
					'label' => 'E-Mail',
				),
			),
		);
	}

	/**
	 * Rows of two layouts, one of each.
	 *
	 * @return array
	 */
	private function two_layouts() {
		return array(
			array(
				'acf_fc_layout' => 'title_panel',
				'panel_title'   => 'Erstes Panel',
				'panel_quote'   => 'Zitat aus dem Titel-Layout',
			),
			array(
				'acf_fc_layout' => 'quote_panel',
				'panel_quote'   => 'Zweites Panel',
				'panel_title'   => 'Titel aus dem Zitat-Layout',
			),
		);
	}

	/**
	 * The rendered rows without the row template.
	 *
	 * The template is present in every list: it is what the editor clones for
	 * "add row", and for a flexible field it carries the first layout's fields
	 * on purpose. Any assertion about what a *stored* row renders has to look
	 * past it, otherwise the template answers the question.
	 *
	 * @param string $html Rendered list.
	 * @return string
	 */
	private function rows_only( $html ) {
		return (string) preg_replace( '/<template[^>]*>.*?<\/template>/s', '', $html );
	}

	/**
	 * Each row renders the fields of its own layout, and only those.
	 *
	 * The two layouts carry the names that belong to the *other* layout on
	 * purpose: a renderer that ignores `acf_fc_layout` and walks all keys it
	 * finds would show four inputs and look correct at a glance.
	 */
	public function test_each_row_renders_only_the_fields_of_its_layout() {
		$html = $this->rows_only(
			Sdc_Renderer::render_fields( array( $this->flexible_field() ), array( 'panels' => $this->two_layouts() ) )
		);

		$this->assertStringContainsString( 'Erstes Panel', $html );
		$this->assertStringContainsString( 'Zweites Panel', $html );

		$this->assertStringContainsString( 'data-sdc-name="panel_title"', $html );
		$this->assertStringContainsString( 'data-sdc-name="panel_quote"', $html );

		$this->assertSame(
			1,
			substr_count( $html, 'data-sdc-name="panel_title"' ),
			'The title field is declared by one layout, so exactly one row may carry it'
		);
		$this->assertSame(
			1,
			substr_count( $html, 'data-sdc-name="panel_quote"' ),
			'The quote field is declared by one layout, so exactly one row may carry it'
		);
	}

	/**
	 * The row template names the first layout.
	 *
	 * The template is what the editor clones for "add row". Without a layout it
	 * has no fields, so the new row would be empty until the author picks one —
	 * and if they never do, the server drops the row.
	 */
	public function test_row_template_carries_the_first_layout() {
		$html = Sdc_Renderer::render_fields( array( $this->flexible_field() ), array( 'panels' => array() ) );

		$this->assertMatchesRegularExpression( '/<template data-sdc-row-template>.*<\/template>/s', $html );

		preg_match( '/<template data-sdc-row-template>(.*)<\/template>/s', $html, $match );

		$this->assertStringContainsString( 'panel_title', $match[1] );
		$this->assertStringContainsString( '__i__', $match[1] );
	}

	/**
	 * A row without a layout contributes nothing instead of guessing.
	 *
	 * The template is excluded here: it always names the first layout, so
	 * "panel_title appears" would say nothing about the stored row.
	 */
	public function test_row_without_a_layout_renders_no_fields() {
		$rows = array( array( 'panel_title' => 'Verwaist' ) );

		$html = $this->rows_only(
			Sdc_Renderer::render_fields( array( $this->flexible_field() ), array( 'panels' => $rows ) )
		);

		$this->assertStringNotContainsString( 'Verwaist', $html );
		$this->assertStringNotContainsString( 'data-sdc-name="panel_title"', $html );
	}

	/**
	 * A group renders its sub-fields with their own names.
	 */
	public function test_group_renders_its_sub_fields() {
		$html = Sdc_Renderer::render_fields(
			array( $this->group_field() ),
			array(
				'contact' => array(
					'contact_name' => 'Erika',
					'contact_mail' => 'erika@example.test',
				),
			)
		);

		$this->assertStringContainsString( 'data-sdc-name="contact_name"', $html );
		$this->assertStringContainsString( 'data-sdc-name="contact_mail"', $html );
		$this->assertStringContainsString( 'Erika', $html );
		$this->assertStringContainsString( 'erika@example.test', $html );
	}

	/**
	 * The frontend prints the values of each row's own layout.
	 */
	public function test_frontend_prints_values_of_the_rows_layout() {
		$html = Sdc_Frontend::render_fields_probe( array( $this->flexible_field() ), array( 'panels' => $this->two_layouts() ) );

		$this->assertStringContainsString( 'Erstes Panel', $html );
		$this->assertStringContainsString( 'Zweites Panel', $html );

		// The value the row carries for the *other* layout must not leak in.
		$this->assertStringNotContainsString( 'Zitat aus dem Titel-Layout', $html );
		$this->assertStringNotContainsString( 'Titel aus dem Zitat-Layout', $html );
	}

	/**
	 * The frontend prints a group's sub-values.
	 */
	public function test_frontend_prints_group_values() {
		$html = Sdc_Frontend::render_fields_probe(
			array( $this->group_field() ),
			array(
				'contact' => array(
					'contact_name' => 'Erika',
					'contact_mail' => '',
				),
			)
		);

		$this->assertStringContainsString( 'Erika', $html );

		// An empty sub-field prints nothing rather than an empty wrapper.
		$this->assertStringNotContainsString( 'contact_mail', $html );
	}

	/**
	 * A group given a scalar — the shape the old text input wrote — renders
	 * nothing instead of throwing.
	 *
	 * Sites that stored a group before the control existed still hold such a
	 * string. It must not take the page down.
	 */
	public function test_group_fed_a_string_does_not_break_the_page() {
		$html = Sdc_Frontend::render_fields_probe( array( $this->group_field() ), array( 'contact' => 'irgendwas' ) );

		$this->assertIsString( $html );
		$this->assertStringNotContainsString( 'irgendwas', $html );
	}
}

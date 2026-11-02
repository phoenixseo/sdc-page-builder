<?php
/**
 * Wie baut ACF den name-Pfad eines Feldes? Muss ich wissen, bevor ich
 * eigene Zeilen-Container rendere und die Blätter vom Wirt rendern lasse.
 */

function probe_render( $label, $field ) {
	ob_start();
	try {
		acf_render_field( $field );
		$html = ob_get_clean();
	} catch ( Throwable $e ) {
		ob_end_clean();
		printf( "%-34s AUSNAHME: %s\n", $label, $e->getMessage() );
		return;
	}
	preg_match_all( '/name="([^"]*)"/', $html, $m );
	printf( "%-34s len=%-4d names=%s\n", $label, strlen( $html ), implode( ' | ', array_unique( $m[1] ) ) );
}

$base = array(
	'key'   => 'field_sdc_faq_heading',
	'label' => 'Überschrift',
	'type'  => 'text',
	'value' => 'Wert',
);

probe_render( 'name=heading', array_merge( $base, array( 'name' => 'heading' ) ) );
probe_render( 'name=acf[field_sdc_faq_heading]', array_merge( $base, array( 'name' => 'acf[field_sdc_faq_heading]' ) ) );
probe_render( 'name=heading + prefix=acf', array_merge( $base, array( 'name' => 'heading', 'prefix' => 'acf' ) ) );
probe_render(
	'name=items[0][q]',
	array_merge(
		$base,
		array(
			'key'   => 'field_sdc_faq_item_q',
			'name'  => 'items[0][q]',
			'value' => 'Frage',
		)
	)
);
probe_render(
	'name=acf[..][0][..] explizit',
	array_merge(
		$base,
		array(
			'key'   => 'field_sdc_faq_item_q',
			'name'  => 'acf[field_sdc_faq_items][0][field_sdc_faq_item_q]',
			'value' => 'Frage',
		)
	)
);

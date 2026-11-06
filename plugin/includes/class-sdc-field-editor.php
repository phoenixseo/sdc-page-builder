<?php
/**
 * The field editor on the component screen.
 *
 * A component's fields are describable in one JSON list, but asking an editor
 * to write that JSON by hand is asking them to know the host's field schema by
 * heart. This class supplies the part that cannot be guessed: which field
 * types exist, what each of them can be configured with, and in what range the
 * values have to be.
 *
 * The split is deliberate:
 *
 *   - PHP owns the catalog. One list of types and options, translated, and it
 *     is what the browser receives. Two lists would drift.
 *   - The browser owns the rows. Adding, reordering, removing and the
 *     type-specific option controls are DOM work; nothing about them needs a
 *     request.
 *   - The submitted value stays a single `sdc_fields` textarea. The server
 *     path (`Sdc_Admin::parse_fields()`) does not change, and a site with
 *     JavaScript switched off still has a usable form.
 *
 * The textarea is therefore both the transport and the fallback, not the
 * interface.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field catalog and markup for the component form.
 */
class Sdc_Field_Editor {

	/**
	 * Option kinds the browser knows how to render.
	 *
	 * Kept as strings rather than as a class constant list, because the value
	 * travels to the browser and an unknown kind must degrade to a text input
	 * instead of throwing there.
	 */
	const KIND_TEXT     = 'text';
	const KIND_TEXTAREA = 'textarea';
	const KIND_NUMBER   = 'number';
	const KIND_BOOL     = 'bool';
	const KIND_SELECT   = 'select';
	const KIND_PAIRS    = 'pairs';

	/**
	 * Field types offered in the picker.
	 *
	 * Only types whose properties this editor can show are listed. Anything
	 * else is still *allowed* in the file — the JSON panel takes it — it just
	 * has no clickable row, because a row that silently drops half of its
	 * options is worse than no row at all.
	 *
	 * Property names are the host's own (`maxlength`, `return_format`,
	 * `button_label` …): the list is written into `definition.json` verbatim.
	 *
	 * @var array<string,array>
	 */
	const TYPES = array(
		'text'         => array(
			'label'   => 'Textzeile',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'placeholder', 'label' => 'Platzhalter', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'maxlength', 'label' => 'Maximale Zeichen', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
				array( 'key' => 'prepend', 'label' => 'Davor', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'append', 'label' => 'Danach', 'kind' => self::KIND_TEXT ),
			),
		),
		'textarea'     => array(
			'label'   => 'Mehrzeiliger Text',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'rows', 'label' => 'Zeilen im Eingabefeld', 'kind' => self::KIND_NUMBER, 'min' => 1, 'max' => 40 ),
				array( 'key' => 'placeholder', 'label' => 'Platzhalter', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXTAREA ),
				array( 'key' => 'maxlength', 'label' => 'Maximale Zeichen', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
			),
		),
		'wysiwyg'      => array(
			'label'   => 'Formatierter Text',
			'group'   => 'Text',
			'options' => array(),
		),
		'number'       => array(
			'label'   => 'Zahl',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'min', 'label' => 'Kleinster Wert', 'kind' => self::KIND_NUMBER ),
				array( 'key' => 'max', 'label' => 'Größter Wert', 'kind' => self::KIND_NUMBER ),
				array( 'key' => 'step', 'label' => 'Schrittweite', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'placeholder', 'label' => 'Platzhalter', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'prepend', 'label' => 'Davor', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'append', 'label' => 'Danach', 'kind' => self::KIND_TEXT ),
			),
		),
		'range'        => array(
			'label'   => 'Schieberegler',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'min', 'label' => 'Kleinster Wert', 'kind' => self::KIND_NUMBER ),
				array( 'key' => 'max', 'label' => 'Größter Wert', 'kind' => self::KIND_NUMBER ),
				array( 'key' => 'step', 'label' => 'Schrittweite', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'prepend', 'label' => 'Davor', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'append', 'label' => 'Danach', 'kind' => self::KIND_TEXT ),
			),
		),
		'email'        => array(
			'label'   => 'E-Mail-Adresse',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'placeholder', 'label' => 'Platzhalter', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
			),
		),
		'url'          => array(
			'label'   => 'Link-Adresse',
			'group'   => 'Text',
			'options' => array(
				array( 'key' => 'placeholder', 'label' => 'Platzhalter', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
			),
		),
		'select'       => array(
			'label'   => 'Auswahlliste',
			'group'   => 'Auswahl',
			'options' => array(
				array( 'key' => 'choices', 'label' => 'Auswahlmöglichkeiten', 'kind' => self::KIND_PAIRS ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
				array( 'key' => 'multiple', 'label' => 'Mehrfachauswahl erlauben', 'kind' => self::KIND_BOOL ),
				array(
					'key'     => 'return_format',
					'label'   => 'Rückgabewert',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'value' => 'Wert',
						'label' => 'Beschriftung',
						'array' => 'Wert und Beschriftung',
					),
				),
			),
		),
		'checkbox'     => array(
			'label'   => 'Mehrfaches Ankreuzen',
			'group'   => 'Auswahl',
			'options' => array(
				array( 'key' => 'choices', 'label' => 'Auswahlmöglichkeiten', 'kind' => self::KIND_PAIRS ),
				array(
					'key'     => 'layout',
					'label'   => 'Anordnung',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'vertical'   => 'Untereinander',
						'horizontal' => 'Nebeneinander',
					),
				),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung (Werte, mit Komma getrennt)', 'kind' => self::KIND_TEXT ),
			),
		),
		'radio'        => array(
			'label'   => 'Einfache Auswahl',
			'group'   => 'Auswahl',
			'options' => array(
				array( 'key' => 'choices', 'label' => 'Auswahlmöglichkeiten', 'kind' => self::KIND_PAIRS ),
				array(
					'key'     => 'layout',
					'label'   => 'Anordnung',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'vertical'   => 'Untereinander',
						'horizontal' => 'Nebeneinander',
					),
				),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
			),
		),
		'button_group' => array(
			'label'   => 'Knopfleiste',
			'group'   => 'Auswahl',
			'options' => array(
				array( 'key' => 'choices', 'label' => 'Auswahlmöglichkeiten', 'kind' => self::KIND_PAIRS ),
				array( 'key' => 'default_value', 'label' => 'Vorbelegung', 'kind' => self::KIND_TEXT ),
			),
		),
		'true_false'   => array(
			'label'   => 'Ja/Nein',
			'group'   => 'Auswahl',
			'options' => array(
				array(
					'key'     => 'default_value',
					'label'   => 'Vorbelegung',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'0' => 'Nein',
						'1' => 'Ja',
					),
				),
				array( 'key' => 'ui', 'label' => 'Als Schalter anzeigen', 'kind' => self::KIND_BOOL ),
			),
		),
		'image'        => array(
			'label'   => 'Bild',
			'group'   => 'Medien',
			'options' => array(
				array(
					'key'     => 'return_format',
					'label'   => 'Rückgabewert',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'array' => 'Datenfeld',
						'url'   => 'Adresse',
						'id'    => 'Beitrags-Nummer',
					),
				),
			),
		),
		'file'         => array(
			'label'   => 'Datei',
			'group'   => 'Medien',
			'options' => array(
				array(
					'key'     => 'return_format',
					'label'   => 'Rückgabewert',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'array' => 'Datenfeld',
						'url'   => 'Adresse',
						'id'    => 'Beitrags-Nummer',
					),
				),
			),
		),
		'link'         => array(
			'label'   => 'Verweis',
			'group'   => 'Medien',
			'options' => array(
				array(
					'key'     => 'return_format',
					'label'   => 'Rückgabewert',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'url'   => 'Adresse',
						'array' => 'Datenfeld',
					),
				),
			),
		),
		'repeater'     => array(
			'label'      => 'Wiederholung',
			'group'      => 'Aufbau',
			'container'  => true,
			'options'    => array(
				array( 'key' => 'button_label', 'label' => 'Beschriftung des Hinzufügen-Knopfs', 'kind' => self::KIND_TEXT ),
				array(
					'key'     => 'layout',
					'label'   => 'Darstellung im Editor',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'table' => 'Tabelle',
						'block' => 'Blöcke',
						'row'   => 'Zeilen',
					),
				),
				array( 'key' => 'min', 'label' => 'Mindestanzahl Zeilen', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
				array( 'key' => 'max', 'label' => 'Höchstanzahl Zeilen', 'kind' => self::KIND_NUMBER, 'min' => 0 ),
			),
		),
		'group'        => array(
			'label'     => 'Gruppe',
			'group'     => 'Aufbau',
			'container' => true,
			'options'   => array(
				array(
					'key'     => 'layout',
					'label'   => 'Darstellung im Editor',
					'kind'    => self::KIND_SELECT,
					'choices' => array(
						'block' => 'Blöcke',
						'table' => 'Tabelle',
						'row'   => 'Zeilen',
					),
				),
			),
		),
	);

	/**
	 * Hook the assets.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the editor on the component screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . Sdc_Admin::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sdc-pb-field-editor',
			SDC_PB_URL . 'assets/field-editor.css',
			array(),
			self::asset_version( 'assets/field-editor.css' )
		);

		wp_enqueue_script(
			'sdc-pb-field-editor',
			SDC_PB_URL . 'assets/field-editor.js',
			array(),
			self::asset_version( 'assets/field-editor.js' ),
			true
		);

		/*
		 * No `wp_set_script_translations()`: every string this script shows
		 * arrives through `SDC_FIELD_EDITOR.labels` below, translated by PHP
		 * at localize time. Script translations would register metadata that
		 * nothing reads — the JS has no `wp.i18n` calls.
		 */
		wp_localize_script(
			'sdc-pb-field-editor',
			'SDC_FIELD_EDITOR',
			array(
				// A list, not a map: the picker shows the types in this order.
				'types'  => self::types(),
				'labels' => array(
					'field'          => __( 'Feld', 'sdc-page-builder' ),
					'subField'       => __( 'Unterfeld', 'sdc-page-builder' ),
					'addField'       => __( 'Feld hinzufügen', 'sdc-page-builder' ),
					'addSubField'    => __( 'Unterfeld hinzufügen', 'sdc-page-builder' ),
					'name'           => __( 'Feldname', 'sdc-page-builder' ),
					'label'          => __( 'Beschriftung', 'sdc-page-builder' ),
					'type'           => __( 'Feldtyp', 'sdc-page-builder' ),
					'instructions'   => __( 'Hinweis im Editor', 'sdc-page-builder' ),
					'required'       => __( 'Pflichtfeld', 'sdc-page-builder' ),
					'options'        => __( 'Einstellungen', 'sdc-page-builder' ),
					'subFields'      => __( 'Unterfelder', 'sdc-page-builder' ),
					'remove'         => __( 'Entfernen', 'sdc-page-builder' ),
					'up'             => __( 'Nach oben', 'sdc-page-builder' ),
					'down'           => __( 'Nach unten', 'sdc-page-builder' ),
					'collapse'       => __( 'Einklappen', 'sdc-page-builder' ),
					'expand'         => __( 'Ausklappen', 'sdc-page-builder' ),
					'empty'          => __( 'Noch keine Felder. Unten einen Feldtyp wählen und hinzufügen.', 'sdc-page-builder' ),
					'removeField'    => __( 'Dieses Feld samt Einstellungen entfernen?', 'sdc-page-builder' ),
					'removeChildren' => __( 'Der neue Typ ist keine Sammlung. Die Unterfelder dieses Feldes werden entfernt. Fortfahren?', 'sdc-page-builder' ),
					'noName'         => __( 'Jedes Feld braucht einen Namen. Fehlt er bei: %s', 'sdc-page-builder' ),
					'duplicateName'  => __( 'Dieser Feldname kommt doppelt vor: %s', 'sdc-page-builder' ),
					'pairValue'      => __( 'Wert', 'sdc-page-builder' ),
					'pairLabel'      => __( 'Beschriftung', 'sdc-page-builder' ),
					'pairAdd'        => __( 'Möglichkeit hinzufügen', 'sdc-page-builder' ),
					'nameHint'       => __( 'a–z, 0–9 und _ . Wird aus der Beschriftung gebildet, solange du ihn nicht selbst änderst.', 'sdc-page-builder' ),
					'jsonUpdated'    => __( 'Das JSON wurde aus den Feldern neu geschrieben.', 'sdc-page-builder' ),
					'jsonBroken'     => __( 'Das JSON im Textfeld ist fehlerhaft und wurde nicht überschrieben: %s', 'sdc-page-builder' ),
					'unknownType'    => __( 'Dieser Feldtyp hat hier keine eigenen Einstellungen. Alles, was im JSON steht, bleibt beim Speichern erhalten — ändern lässt er sich nur dort.', 'sdc-page-builder' ),
				),
			)
		);
	}

	/**
	 * Cache-busting version for an asset.
	 *
	 * The plugin version moves in releases, not in development, so a stale
	 * `?ver=0.1.0` would keep serving yesterday's script while the file on disk
	 * already has today's. The file's mtime changes whenever the file does.
	 *
	 * @param string $relative Path inside the plugin directory.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		$file = SDC_PB_DIR . $relative;

		if ( is_readable( $file ) ) {
			$stamp = filemtime( $file );

			if ( false !== $stamp ) {
				return (string) $stamp;
			}
		}

		return SDC_PB_VERSION;
	}

	/**
	 * The catalog, as a list the browser can iterate.
	 *
	 * @return array[]
	 */
	public static function types() {
		$out = array();

		foreach ( self::TYPES as $type => $definition ) {
			$options = array();

			foreach ( (array) $definition['options'] as $option ) {
				$options[] = $option;
			}

			$out[] = array(
				'type'      => (string) $type,
				'label'     => (string) $definition['label'],
				'group'     => (string) $definition['group'],
				'container' => ! empty( $definition['container'] ),
				'options'   => $options,
			);
		}

		return $out;
	}

	/**
	 * Does the catalog know this type?
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public static function is_known( $type ) {
		return isset( self::TYPES[ (string) $type ] );
	}

	/**
	 * Markup: the row list, the picker, and the JSON fallback.
	 *
	 * @param string $json Current fields as JSON, without `key` entries. Empty
	 *                     for a component without fields.
	 */
	public static function render( $json ) {
		echo '<div class="sdc-fe" data-sdc-fe>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Feldtyp wählen, hinzufügen, Einstellungen ausfüllen. Das JSON für die definition.json entsteht daraus.', 'sdc-page-builder' )
		);

		echo '<div class="sdc-fe__list" data-sdc-fe-list></div>';
		echo '<p class="sdc-fe__empty" data-sdc-fe-empty>' . esc_html__( 'Noch keine Felder. Unten einen Feldtyp wählen und hinzufügen.', 'sdc-page-builder' ) . '</p>';

		echo '<div class="sdc-fe__add">';
		self::picker( __( 'Feld hinzufügen', 'sdc-page-builder' ) );
		echo '</div>';

		echo '<p class="sdc-fe__error" data-sdc-fe-error role="alert" hidden></p>';

		echo '<details class="sdc-fe__json">';
		echo '<summary>' . esc_html__( 'JSON direkt bearbeiten', 'sdc-page-builder' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Wird aus den Feldern oben gefüllt und beim Absenden mitgeschickt. Wer hier von Hand ändert, klickt danach „JSON übernehmen“.', 'sdc-page-builder' ) . '</p>';
		printf(
			'<textarea id="sdc_fields" name="sdc_fields" rows="16" class="large-text code" spellcheck="false">%s</textarea>',
			esc_textarea( (string) $json )
		);
		echo '<p><button type="button" class="button" data-sdc-fe-apply>' . esc_html__( 'JSON übernehmen', 'sdc-page-builder' ) . '</button></p>';
		echo '</details>';

		echo '</div>';
	}

	/**
	 * A type picker: grouped select plus its button.
	 *
	 * Rendered here and cloned by the browser for nested containers, so both
	 * levels offer exactly the same types.
	 *
	 * @param string $button_label Button text.
	 */
	private static function picker( $button_label ) {
		$groups = array();

		foreach ( self::TYPES as $type => $definition ) {
			$groups[ $definition['group'] ][ $type ] = $definition['label'];
		}

		printf(
			'<select class="sdc-fe__select" data-sdc-fe-add-type aria-label="%s">',
			esc_attr__( 'Feldtyp für das neue Feld', 'sdc-page-builder' )
		);

		foreach ( $groups as $group => $types ) {
			printf( '<optgroup label="%s">', esc_attr( $group ) );

			foreach ( $types as $type => $label ) {
				printf( '<option value="%s">%s</option>', esc_attr( $type ), esc_html( $label ) );
			}

			echo '</optgroup>';
		}

		echo '</select>';

		printf(
			'<button type="button" class="button" data-sdc-fe-add>%s</button>',
			esc_html( $button_label )
		);
	}

	/**
	 * Properties each field row carries besides its type options.
	 *
	 * Used by the tests and by `Sdc_Admin::FIELD_PROPS` as documentation of
	 * what the editor can set; the writing side stays the whitelist there.
	 *
	 * @return string[]
	 */
	public static function base_props() {
		return array( 'name', 'label', 'type', 'instructions', 'required', 'sub_fields' );
	}
}

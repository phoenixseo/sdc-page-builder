<?php
/**
 * Server-side validation and history for the SDC page document.
 *
 * Scope: this class never writes. It answers two questions — "is this
 * document acceptable?" and "what did this page look like earlier?" — and
 * leaves the writing to Sdc_Rest, which can then guarantee all-or-nothing.
 *
 * @package SdcPageBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator, exporter and revision reader for the page document.
 */
class Sdc_Document {

	/**
	 * Document format version emitted by export.
	 */
	const EXPORT_FORMAT = 1;

	/**
	 * Highest number of revisions offered for restore.
	 */
	const HISTORY_LIMIT = 50;

	/**
	 * Highest number of components accepted in one document.
	 *
	 * A guard against an import that would make the page unopenable, not a
	 * design limit: the document is one meta row, so a 5000-component page
	 * would also blow the PHP memory limit while rendering.
	 */
	const MAX_COMPONENTS = 200;

	/**
	 * Labels for the document-level error bag.
	 */
	const ERROR_LABELS = array(
		'nicht_json'        => 'Die Datei ist kein gültiges JSON: %s',
		'format_unbekannt'  => 'Unbekanntes Exportformat "%s" (erwartet: %d).',
		'nicht_objekt'      => 'Das Dokument hat keine Struktur aus Komponenten.',
		'komponenten_typ'   => 'Das Feld "components" muss eine Liste sein.',
		'zu_viele'          => 'Das Dokument enthält %d Komponenten; erlaubt sind höchstens %d.',
		'komponente_typ'    => 'Eintrag %1$d ist keine Struktur.',
		'unbekannter_typ'   => 'Eintrag %1$d hat den unbekannten Komponententyp "%2$s".',
		'fehlende_id'       => 'Eintrag %1$d hat keine Komponenten-Id.',
		'doppelte_id'       => 'Die Komponenten-Id "%2$s" kommt mehrfach vor.',
		'schema_unbekannt'  => 'Für "%2$s" gibt es im Katalog kein Schema "%3$s".',
		'feld_unbekannt'    => 'Unbekanntes Feld "%2$s" in Eintrag %1$d (%3$s).',
		'pflichtfeld'       => 'Pflichtfeld "%2$s" fehlt in Eintrag %1$d (%3$s).',
		'typ_falsch'        => 'Feld "%2$s" in Eintrag %1$d (%3$s) hat den falschen Datentyp.',
		'zeile_typ'         => 'Zeile %2$d in "%3$s" (Eintrag %1$d) ist keine Struktur.',
		'zeile_pflichtfeld' => 'Pflichtfeld "%2$s" fehlt in Zeile %3$d von "%4$s" (Eintrag %1$d).',
	);

	/**
	 * Components the catalogue knows, keyed by type.
	 *
	 * @return array<string,array>
	 */
	public static function catalogue() {
		return Sdc_Schema::components();
	}

	/**
	 * Validate a document against the live component catalogue.
	 *
	 * Runs against the host field definitions, not against the trimmed-down
	 * REST catalogue: normalize_field() drops `sub_fields`, so the REST view
	 * cannot see required repeater columns.
	 *
	 * @param mixed $document Decoded document.
	 * @return array{ok:bool,errors:string[],document:array}
	 */
	public static function validate( $document ) {
		$errors = array();

		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( self::error( 'nicht_objekt', array() ) ),
				'document' => array(),
			);
		}

		if ( ! isset( $document['components'] ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( self::error( 'nicht_objekt', array() ) ),
				'document' => array(),
			);
		}

		if ( ! is_array( $document['components'] ) || ! array_is_list( $document['components'] ) ) {
			return array(
				'ok'       => false,
				'errors'   => array( self::error( 'komponenten_typ', array() ) ),
				'document' => array(),
			);
		}

		$max = self::MAX_COMPONENTS;
		if ( count( $document['components'] ) > $max ) {
			$errors[] = self::error( 'zu_viele', array( count( $document['components'] ), $max ) );
		}

		$catalogue = self::catalogue();
		$seen      = array();

		foreach ( $document['components'] as $index => $component ) {
			$position = $index + 1;

			if ( ! is_array( $component ) || array_is_list( $component ) ) {
				$errors[] = self::error( 'komponente_typ', array( $position ) );
				continue;
			}

			$type = isset( $component['type'] ) ? (string) $component['type'] : '';
			if ( ! isset( $catalogue[ $type ] ) ) {
				$errors[] = self::error( 'unbekannter_typ', array( $position, $type ) );
				continue;
			}

			$id = isset( $component['_id'] ) ? (string) $component['_id'] : '';
			if ( '' === trim( $id ) ) {
				$errors[] = self::error( 'fehlende_id', array( $position ) );
			} elseif ( isset( $seen[ $id ] ) ) {
				$errors[] = self::error( 'doppelte_id', array( $position, $id ) );
			} else {
				$seen[ $id ] = true;
			}

			$schema  = isset( $component['schema'] ) ? (string) $component['schema'] : '';
			$current = Sdc_Schema::version_string( $type );
			if ( $schema !== $current ) {
				$errors[] = self::error(
					'schema_unbekannt',
					array( $position, $type, '' === $schema ? '(leer)' : $schema )
				);
				continue;
			}

			$fields = Sdc_Field_Adapter::get_fields( $catalogue[ $type ]['key'] );
			$data   = isset( $component['data'] ) ? $component['data'] : array();

			if ( ! is_array( $data ) || array_is_list( $data ) ) {
				$errors[] = self::error( 'typ_falsch', array( $position, 'data', $type ) );
				continue;
			}

			$allowed = array();
			foreach ( $fields as $field ) {
				$allowed[ (string) $field['name'] ] = $field;
			}

			foreach ( array_keys( $data ) as $name ) {
				if ( ! isset( $allowed[ (string) $name ] ) ) {
					$errors[] = self::error( 'feld_unbekannt', array( $position, (string) $name, $type ) );
				}
			}

			foreach ( $fields as $field ) {
				$errors = array_merge(
					$errors,
					self::validate_field( $field, $data[ $field['name'] ] ?? null, $position, $type )
				);
			}
		}

		return array(
			'ok'       => array() === $errors,
			'errors'   => $errors,
			'document' => $document,
		);
	}

	/**
	 * Validate one top-level field of a component against its definition.
	 *
	 * @param array  $field    Host field definition.
	 * @param mixed  $value    Submitted value.
	 * @param int    $position 1-based component position.
	 * @param string $type     Component type, for messages.
	 * @return string[] Errors.
	 */
	private static function validate_field( array $field, $value, $position, $type ) {
		$name  = (string) $field['name'];
		$fname = (string) ( $field['type'] ?? '' );

		if ( 'repeater' === $fname || 'flexible_content' === $fname ) {
			return self::validate_rows( $field, $value, $position, $type );
		}

		if ( 'group' === $fname ) {
			if ( ! is_array( $value ) ) {
				return self::is_blank( $value ) ? array() : array( self::error( 'typ_falsch', array( $position, $name, $type ) ) );
			}
			$errors = array();
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
				$errors = array_merge(
					$errors,
					self::validate_field( $sub, $value[ $sub['name'] ] ?? null, $position, $type )
				);
			}
			return $errors;
		}

		if ( self::is_blank( $value ) ) {
			return ! empty( $field['required'] )
				? array( self::error( 'pflichtfeld', array( $position, $name, $type ) ) )
				: array();
		}

		return self::matches_type( $fname, $value )
			? array()
			: array( self::error( 'typ_falsch', array( $position, $name, $type ) ) );
	}

	/**
	 * Validate each row of a repeater, including required sub-fields.
	 *
	 * @param array  $field    Repeater definition.
	 * @param mixed  $value    Rows.
	 * @param int    $position Component position.
	 * @param string $type     Component type.
	 * @return string[] Errors.
	 */
	private static function validate_rows( array $field, $value, $position, $type ) {
		$name = (string) $field['name'];

		if ( null === $value || array() === $value ) {
			return ! empty( $field['required'] )
				? array( self::error( 'pflichtfeld', array( $position, $name, $type ) ) )
				: array();
		}

		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array( self::error( 'typ_falsch', array( $position, $name, $type ) ) );
		}

		$subs   = (array) ( $field['sub_fields'] ?? array() );
		$errors = array();

		foreach ( $value as $row_index => $row ) {
			$row_no = $row_index + 1;

			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				$errors[] = self::error( 'zeile_typ', array( $position, $row_no, $name ) );
				continue;
			}

			foreach ( $subs as $sub ) {
				$sub_name  = (string) $sub['name'];
				$sub_value = $row[ $sub_name ] ?? null;

				if ( self::is_blank( $sub_value ) ) {
					if ( ! empty( $sub['required'] ) ) {
						$errors[] = self::error( 'zeile_pflichtfeld', array( $position, $sub_name, $row_no, $name ) );
					}
					continue;
				}

				if ( ! self::matches_type( (string) ( $sub['type'] ?? '' ), $sub_value ) ) {
					$errors[] = self::error( 'typ_falsch', array( $position, $name, $type ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Whether a value counts as "not provided".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_blank( $value ) {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return false;
	}

	/**
	 * Loose type check per host field type.
	 *
	 * Deliberately permissive: the host accepts more than any strict schema
	 * would, and a false rejection is worse than a permissive accept.
	 *
	 * @param string $field_type Host field type.
	 * @param mixed  $value      Value.
	 * @return bool
	 */
	private static function matches_type( $field_type, $value ) {
		switch ( $field_type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'email':
			case 'url':
			case 'password':
				return is_string( $value ) || is_numeric( $value );

			case 'number':
			case 'range':
				return is_numeric( $value );

			case 'true_false':
				return is_bool( $value ) || is_numeric( $value ) || is_string( $value );

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
			case 'image':
			case 'file':
			case 'gallery':
			case 'post_object':
			case 'relationship':
			case 'taxonomy':
			case 'user':
			case 'link':
			case 'date_picker':
			case 'color_picker':
				return is_scalar( $value ) || is_array( $value );

			default:
				return true;
		}
	}

	/**
	 * Fetch the stored document of a page.
	 *
	 * @param int $post_id Page id.
	 * @return array Normalised document.
	 */
	public static function read( $post_id ) {
		return Sdc_Store::get_data( $post_id );
	}

	/**
	 * Revisions of a page, newest first, each decoded.
	 *
	 * A revision whose meta is not a document is skipped: it cannot be
	 * restored and must not be offered as if it could.
	 *
	 * @param int $post_id Page id.
	 * @return array[] Each: id, date, author, components, document.
	 */
	public static function history( $post_id ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => self::HISTORY_LIMIT,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
			)
		);

		$history = array();

		foreach ( $revisions as $revision ) {
			$raw = get_post_meta( $revision->ID, Sdc_Store::META_KEY, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				$raw = $decoded;
			}
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$document = Sdc_Store::sanitize( $raw );

			$history[] = array(
				'id'         => (int) $revision->ID,
				'date'       => (string) $revision->post_date,
				'author'     => (int) $revision->post_author,
				'components' => count( $document['components'] ),
				'document'   => $document,
			);
		}

		return $history;
	}

	/**
	 * Load the document stored in one revision.
	 *
	 * @param int $post_id  Page id.
	 * @param int $revision Revision id.
	 * @return array|WP_Error Document, or an error if it does not belong here.
	 */
	public static function revision_document( $post_id, $revision ) {
		$revision_id = self::resolve_revision( $post_id, $revision );
		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		foreach ( self::history( $post_id ) as $entry ) {
			if ( $entry['id'] === $revision_id ) {
				return $entry['document'];
			}
		}

		return new WP_Error(
			'sdc_revision_leer',
			'Diese Revision enthält kein SDC-Dokument und kann nicht wiederhergestellt werden.',
			array( 'status' => 409 )
		);
	}

	/**
	 * Turn a caller-supplied revision reference into a validated revision id.
	 *
	 * @param int        $post_id  Page id.
	 * @param int|string $revision Revision id, or a relative operand such as `-1`.
	 * @return int|WP_Error
	 */
	private static function resolve_revision( $post_id, $revision ) {
		$raw = trim( (string) $revision );

		if ( '' === $raw ) {
			return new WP_Error( 'sdc_revision_fehlt', 'Es wurde keine Revision angegeben.', array( 'status' => 400 ) );
		}

		if ( 0 === strpos( $raw, '-' ) || '-' === $raw[0] ) {
			$offset = (int) ltrim( $raw, '-' );
			if ( $offset < 1 ) {
				return new WP_Error( 'sdc_revision_ungueltig', 'Die relative Revision muss mindestens -1 sein.', array( 'status' => 400 ) );
			}
			$history = self::history( $post_id );
			if ( ! isset( $history[ $offset - 1 ] ) ) {
				return new WP_Error(
					'sdc_revision_unbekannt',
					sprintf( 'Es gibt keine Revision %s — die Seite hat %d wiederherstellbare Revisionen.', $raw, count( $history ) ),
					array( 'status' => 404 )
				);
			}
			return $history[ $offset - 1 ]['id'];
		}

		$revision_id = (int) $raw;
		if ( $revision_id < 1 ) {
			return new WP_Error( 'sdc_revision_ungueltig', 'Die Revisions-Id muss eine positive Zahl sein.', array( 'status' => 400 ) );
		}

		$revision_post = get_post( $revision_id );
		if ( ! $revision_post || 'revision' !== $revision_post->post_type ) {
			return new WP_Error( 'sdc_revision_unbekannt', 'Diese Revision existiert nicht.', array( 'status' => 404 ) );
		}

		if ( (int) $revision_post->post_parent !== (int) $post_id ) {
			return new WP_Error(
				'sdc_revision_fremd',
				'Diese Revision gehört zu einer anderen Seite und wird nicht eingespielt.',
				array( 'status' => 409 )
			);
		}

		return $revision_id;
	}

	/**
	 * Build the export payload.
	 *
	 * @param int $post_id Page id.
	 * @return array
	 */
	public static function export( $post_id ) {
		$post = get_post( $post_id );

		return array(
			'format'   => self::EXPORT_FORMAT,
			'plugin'   => 'sdc-page-builder',
			'version'  => defined( 'SDC_VERSION' ) ? SDC_VERSION : '',
			'exported' => gmdate( 'c' ),
			'title'    => $post ? (string) $post->post_title : '',
			'document' => self::read( $post_id ),
		);
	}

	/**
	 * File name for an export download.
	 *
	 * @param int    $post_id Page id.
	 * @param string $format  One of json|php.
	 * @return string
	 */
	public static function export_filename( $post_id, $format = 'json' ) {
		$post  = get_post( $post_id );
		$title = $post ? (string) $post->post_title : '';
		$slug  = sanitize_title( $title );
		if ( '' === $slug ) {
			$slug = 'sdc-page-' . (int) $post_id;
		}
		$ext = 'php' === $format ? 'php' : 'json';
		return $slug . '.' . $ext;
	}

	/**
	 * Order-independent hash of a document's content.
	 *
	 * Compares structure, not serialisation: key order in a JSON file is not
	 * content, and a roundtrip must not fail because PHP sorted differently.
	 *
	 * @param array $document Document.
	 * @return string
	 */
	public static function fingerprint( array $document ) {
		return md5( (string) wp_json_encode( self::canonical( $document ) ) );
	}

	/**
	 * Recursively sort associative arrays by key, preserving list order.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonical( $item );
		}

		return $value;
	}

	/**
	 * Message for an error code, filled with its arguments.
	 *
	 * @param string $code Error code.
	 * @param array  $args Arguments.
	 * @return string
	 */
	private static function error( $code, array $args ) {
		$template = self::ERROR_LABELS[ $code ] ?? $code;
		return array() === $args ? $template : vsprintf( $template, $args );
	}
}

<?php
/**
 * REST route for reading and writing a page's builder document.
 *
 * Saving over REST instead of a form post is a design requirement, not a
 * convenience: the flat field model needs one input per subfield (1373 inputs
 * for 30 components in the reference test), which PHP truncates at
 * max_input_vars = 1000. One JSON body has no such limit.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers `sdc-pb/v1`.
 */
class Sdc_Rest {

	const NAMESPACE_V1 = 'sdc-pb/v1';

	/**
	 * Hook the routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_page' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_page' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
					'args'                => array_merge(
						self::id_arg(),
						array(
							'components' => array(
								'required' => true,
								'type'     => 'array',
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/components',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_components' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => self::id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_page' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array_merge(
					self::id_arg(),
					array(
						'revision' => array(
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_page' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array_merge(
					self::id_arg(),
					array(
						'payload' => array(
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/pages/(?P<id>\d+)/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_page' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array_merge(
					self::id_arg(),
					array(
						'download' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
	}

	/**
	 * Shared `id` argument definition.
	 *
	 * @return array
	 */
	private static function id_arg() {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	/**
	 * Permission check for a single page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_edit( $request ) {
		$post_id = (int) $request['id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'sdc_not_found', __( 'Seite nicht gefunden.', 'sdc-page-builder' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'sdc_forbidden', __( 'Keine Berechtigung für diese Seite.', 'sdc-page-builder' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET a page's document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_page( $request ) {
		$post_id  = (int) $request['id'];
		$document = Sdc_Store::get_data( $post_id );

		return new WP_REST_Response(
			array(
				'post_id'    => $post_id,
				'document'   => $document,
				'components' => self::describe_document( $document ),
			),
			200
		);
	}

	/**
	 * POST a page's document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_page( $request ) {
		$post_id = (int) $request['id'];
		$input   = $request->get_param( 'components' );

		if ( ! is_array( $input ) ) {
			return new WP_Error( 'sdc_invalid', __( 'components muss ein Array sein.', 'sdc-page-builder' ), array( 'status' => 400 ) );
		}

		// Unknown component types are rejected: silently dropping them would
		// look like a successful save while destroying content.
		$known = array_keys( Sdc_Schema::components() );
		foreach ( $input as $component ) {
			$type = is_array( $component ) && isset( $component['type'] ) ? (string) $component['type'] : '';
			if ( '' === $type ) {
				return new WP_Error( 'sdc_invalid_component', __( 'Komponente ohne Typ.', 'sdc-page-builder' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $type, $known, true ) ) {
				return new WP_Error(
					'sdc_unknown_component',
					/* translators: %s: component type */
					sprintf( __( 'Unbekannter Komponententyp: %s', 'sdc-page-builder' ), $type ),
					array( 'status' => 400 )
				);
			}
		}

		// The editor posts components before they are stored, so they have no
		// id yet and `Sdc_Document::validate()` — which checks stored documents
		// — would reject them for the wrong reason. Only the field content is
		// checked here. `required` is part of the schema and was previously
		// enforced on import but not on save.
		$content_errors = array();
		foreach ( $input as $index => $component ) {
			$type   = (string) $component['type'];
			$fields = Sdc_Schema::fields( $type );
			$data   = isset( $component['data'] ) && is_array( $component['data'] ) ? $component['data'] : array();

			foreach ( $fields as $field ) {
				if ( empty( $field['required'] ) ) {
					continue;
				}
				$value = $data[ $field['name'] ] ?? null;
				if ( is_string( $value ) ? '' === trim( $value ) : empty( $value ) ) {
					$content_errors[] = sprintf(
						/* translators: 1: component position, 2: component type, 3: field label */
						__( 'Komponente %1$d (%2$s): Pflichtfeld "%3$s" ist leer.', 'sdc-page-builder' ),
						(int) $index + 1,
						$type,
						(string) $field['label']
					);
				}
			}
		}

		if ( $content_errors ) {
			return new WP_Error(
				'sdc_invalid_document',
				__( 'Das Dokument wurde abgelehnt. Gespeichert wurde nichts.', 'sdc-page-builder' ),
				array(
					'status' => 422,
					'errors' => $content_errors,
				)
			);
		}

		$saved = Sdc_Store::save_data( $post_id, array( 'components' => $input ) );

		return new WP_REST_Response(
			array(
				'post_id'    => $post_id,
				'saved'      => true,
				'document'   => $saved,
				'components' => self::describe_document( $saved ),
			),
			200
		);
	}

	/**
	 * GET the component catalogue.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_components() {
		$out = array();
		foreach ( Sdc_Schema::components() as $type => $component ) {
			$out[] = array(
				'type'        => $type,
				'label'       => $component['label'],
				'description' => $component['description'],
				'icon'        => $component['icon'],
				'schema'      => Sdc_Schema::version_string( $type ),
				'fields'      => $component['fields'],
			);
		}

		return new WP_REST_Response(
			array(
				'host'       => Sdc_Field_Adapter::host(),
				'version'    => SDC_PB_VERSION,
				'components' => $out,
			),
			200
		);
	}

	/**
	 * GET the revision history of a page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_history( $request ) {
		$post_id = (int) $request['id'];

		return new WP_REST_Response(
			array(
				'post_id'   => $post_id,
				'revisions' => self::describe_history( Sdc_Document::history( $post_id ) ),
			),
			200
		);
	}

	/**
	 * POST a restore of one revision into the live document.
	 *
	 * The current document is snapshotted first, so a wrong pick is itself
	 * a revision away from being undone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_page( $request ) {
		$post_id  = (int) $request['id'];
		$revision = $request->get_param( 'revision' );

		$document = Sdc_Document::revision_document( $post_id, $revision );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$snapshot = self::snapshot( $post_id, 'restore' );
		$saved    = Sdc_Store::save_data( $post_id, $document );

		return new WP_REST_Response(
			array(
				'post_id'    => $post_id,
				'restored'   => true,
				'snapshot'   => $snapshot,
				'document'   => $saved,
				'components' => self::describe_document( $saved ),
			),
			200
		);
	}

	/**
	 * POST an import of an exported payload.
	 *
	 * Validates the whole document before touching storage: an invalid import
	 * leaves the page byte-identical and returns the reasons.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import_page( $request ) {
		$post_id = (int) $request['id'];
		$payload = $request->get_param( 'payload' );

		// The editor sends the file content as a JSON string; the CLI and the
		// test script send the structure. Accept both, refuse anything else.
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'sdc_json_kaputt',
					sprintf(
						/* translators: %s: parser message */
						__( 'Die Datei ist kein gültiges JSON: %s', 'sdc-page-builder' ),
						json_last_error_msg()
					),
					array( 'status' => 400 )
				);
			}
			$payload = $decoded;
		}

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'sdc_import_format',
				__( 'Der Import erwartet ein JSON-Objekt.', 'sdc-page-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $payload['format'] ) && Sdc_Document::EXPORT_FORMAT !== (int) $payload['format'] ) {
			return new WP_Error(
				'sdc_import_format',
				sprintf(
					/* translators: 1: found format, 2: expected format */
					__( 'Unbekanntes Exportformat "%1$s" (erwartet: %2$d).', 'sdc-page-builder' ),
					is_scalar( $payload['format'] ) ? (string) $payload['format'] : gettype( $payload['format'] ),
					Sdc_Document::EXPORT_FORMAT
				),
				array( 'status' => 400 )
			);
		}

		$document = isset( $payload['document'] ) ? $payload['document'] : $payload;
		$result   = Sdc_Document::validate( $document );

		if ( ! $result['ok'] ) {
			return new WP_Error(
				'sdc_import_invalid',
				__( 'Der Import wurde abgelehnt. Das Dokument ist unverändert.', 'sdc-page-builder' ),
				array(
					'status' => 422,
					'errors' => $result['errors'],
				)
			);
		}

		$unsaved = Sdc_Store::sanitize( $document );
		$before  = Sdc_Store::get_data( $post_id );

		if ( self::same_document( $before, $unsaved ) ) {
			return new WP_REST_Response(
				array(
					'post_id'    => $post_id,
					'imported'   => false,
					'changed'    => false,
					'snapshot'   => null,
					'document'   => $before,
					'components' => self::describe_document( $before ),
				),
				200
			);
		}

		$snapshot = self::snapshot( $post_id, 'import' );
		$saved    = Sdc_Store::save_data( $post_id, $unsaved );

		return new WP_REST_Response(
			array(
				'post_id'    => $post_id,
				'imported'   => true,
				'changed'    => true,
				'snapshot'   => $snapshot,
				'document'   => $saved,
				'components' => self::describe_document( $saved ),
			),
			200
		);
	}

	/**
	 * GET the export payload, optionally as a download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_page( $request ) {
		$post_id  = (int) $request['id'];
		$download = (bool) $request->get_param( 'download' );
		$payload  = Sdc_Document::export( $post_id );
		$body     = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$response = new WP_REST_Response( $payload, 200 );

		if ( $download ) {
			$response->header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );
			$response->header(
				'Content-Disposition',
				'attachment; filename="' . Sdc_Document::export_filename( $post_id ) . '"'
			);
			// The client needs the exact bytes for a file, not a re-encoded body.
			$response->set_data( $body );
		}

		return $response;
	}

	/**
	 * Record the current document as a revision before it is overwritten.
	 *
	 * Two measured traps:
	 *   1. `wp_save_post_revision()` returns an int or **null**, never a
	 *      WP_Error. Treating null as success reported "snapshot rev 0".
	 *   2. It refuses to write when the post already matches the newest
	 *      revision — and a restore changes meta only, so the snapshot it
	 *      would take is exactly the state that is never different. The
	 *      forced form writes unconditionally.
	 *
	 * @param int    $post_id Page id.
	 * @param string $reason  What triggered the snapshot.
	 * @return array
	 */
	private static function snapshot( $post_id, $reason ) {
		$current = Sdc_Store::get_data( $post_id );
		$post    = get_post( $post_id );

		$id = $post ? _wp_put_post_revision( $post, true ) : 0;

		return array(
			'reason'      => $reason,
			'revision_id' => is_numeric( $id ) ? (int) $id : 0,
			'components'  => count( (array) $current['components'] ),
		);
	}

	/**
	 * Normalised comparison of two documents, ignoring key order.
	 *
	 * @param array $a Document.
	 * @param array $b Document.
	 * @return bool
	 */
	private static function same_document( array $a, array $b ) {
		return Sdc_Document::fingerprint( $a ) === Sdc_Document::fingerprint( $b );
	}

	/**
	 * History entries, without the full documents.
	 *
	 * @param array[] $history Entries from Sdc_Document::history().
	 * @return array[]
	 */
	private static function describe_history( array $history ) {
		$out = array();

		foreach ( $history as $index => $entry ) {
			$out[] = array(
				'revision'   => $entry['id'],
				'offset'     => - ( $index + 1 ),
				'date'       => $entry['date'],
				'author'     => $entry['author'],
				'components' => $entry['components'],
			);
		}

		return $out;
	}

	/**
	 * Attach the resolved schema label to each stored component.
	 *
	 * @param array $document Stored document.
	 * @return array
	 */
	private static function describe_document( array $document ) {
		$out = array();
		foreach ( (array) $document['components'] as $component ) {
			$out[] = array(
				'_id'     => $component['_id'],
				'type'    => $component['type'],
				'schema'  => $component['schema'],
				'label'   => Sdc_Schema::get( $component['type'] )['label'] ?? $component['type'],
				'current' => Sdc_Schema::version_string( $component['type'] ),
			);
		}
		return $out;
	}
}

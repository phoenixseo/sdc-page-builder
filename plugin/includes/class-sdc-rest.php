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

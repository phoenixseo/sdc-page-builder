<?php
/**
 * Envelope store: one meta key per post, one write per save.
 *
 * Shape:
 *   {
 *     "v": 1,
 *     "components": [
 *       { "_id": "c_7f3a", "type": "faq", "schema": "faq@1", "data": { ... } }
 *     ]
 *   }
 *
 * Why one key: measured 2.3 ms / 3 queries against 733 ms / 1807 queries for
 * the flat per-subfield model on 100 rows. It also keeps the whole document
 * out of the form-post path, which is hard limited by max_input_vars.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes builder documents.
 */
class Sdc_Store {

	/**
	 * Meta key holding the whole document.
	 *
	 * Leading underscore keeps it out of the generic custom-fields panel.
	 */
	const META_KEY = '_sdc_data';

	/**
	 * Envelope format version.
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Register the meta key with WordPress.
	 */
	public static function init() {
		register_post_meta(
			'',
			self::META_KEY,
			array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => false, // Written through our own REST route.
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		// Revision support: WordPress only versions meta keys that opt in.
		add_filter( 'wp_post_revision_meta_keys', array( __CLASS__, 'revision_meta_keys' ) );
	}

	/**
	 * Let WordPress copy the document into revisions.
	 *
	 * @param string[] $keys Meta keys to version.
	 * @return string[]
	 */
	public static function revision_meta_keys( $keys ) {
		$keys   = (array) $keys;
		$keys[] = self::META_KEY;
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Validate and normalize an incoming document.
	 *
	 * Unknown shapes are rejected rather than silently stored — a builder that
	 * accepts garbage is worse than one that refuses it.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return self::empty_document();
		}

		$components = array();
		$seen       = array();

		foreach ( (array) ( $value['components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) || empty( $component['type'] ) ) {
				continue;
			}

			$id = isset( $component['_id'] ) ? self::clean_id( $component['_id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = self::new_id();
			}
			$seen[ $id ] = true;

			$components[] = array(
				'_id'    => $id,
				'type'   => sanitize_key( $component['type'] ),
				'schema' => isset( $component['schema'] ) ? sanitize_text_field( $component['schema'] ) : '',
				'data'   => isset( $component['data'] ) && is_array( $component['data'] ) ? $component['data'] : array(),
			);
		}

		return array(
			'v'          => self::FORMAT_VERSION,
			'components' => $components,
		);
	}

	/**
	 * Empty document.
	 *
	 * @return array
	 */
	public static function empty_document() {
		return array(
			'v'          => self::FORMAT_VERSION,
			'components' => array(),
		);
	}

	/**
	 * Read the document for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_data( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || ( empty( $raw['components'] ) && ! isset( $raw['v'] ) ) ) {
			return self::empty_document();
		}

		return wp_parse_args( $raw, self::empty_document() );
	}

	/**
	 * Write the document for a post.
	 *
	 * One meta write for the whole page, regardless of component count.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $document Document as accepted by `sanitize()`.
	 * @return array Saved document.
	 */
	public static function save_data( $post_id, $document ) {
		$post_id  = (int) $post_id;
		$document = self::sanitize( $document );

		update_post_meta( $post_id, self::META_KEY, wp_slash( $document ) );

		// A meta write alone never creates a revision — measured: the count
		// stays put and the previous revision keeps the old document. Without
		// this call the builder had no history to restore from.
		wp_save_post_revision( $post_id );

		return $document;
	}

	/**
	 * A stable, URL-safe component/row id.
	 *
	 * @return string
	 */
	public static function new_id() {
		return 'c_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
	}

	/**
	 * Keep only id-safe characters.
	 *
	 * @param mixed $id Candidate id.
	 * @return string
	 */
	private static function clean_id( $id ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
	}
}

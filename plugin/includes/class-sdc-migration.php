<?php
/**
 * Move an old metabox document into blocks.
 *
 * Pages built before the block editor carry their components in `_sdc_data`
 * and nothing in `post_content`; `the_content` appended them. With the
 * metabox gone, such a page renders correctly in the frontend but opens empty
 * in the editor — the author sees a blank canvas for a page that is full of
 * content. That is worse than the box it replaced, so the document has to
 * become block markup once.
 *
 * The document is not deleted. It stays as the backup (and as the source for
 * the revision WordPress writes on the update), and a marker says the page has
 * been migrated — `the_content` uses the same marker to stop appending, which
 * is what keeps a migrated page from showing every component twice.
 *
 * Two paths run the migration, for the same reason: the editor fetches its
 * content over REST, and only some setups still render through `post.php`.
 * Whichever comes first wins; the marker makes the second call a no-op.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * One-way migration from the metabox document to blocks.
 */
class Sdc_Migration {

	/**
	 * Meta key marking a page as migrated. Holds the version that ran.
	 */
	const META_KEY = '_sdc_migrated';

	/**
	 * Current migration version.
	 *
	 * Bump only together with a migration that has to run again: the marker
	 * compares with `>=`, so nothing re-runs by accident.
	 */
	const VERSION = 1;

	/**
	 * Hook the migration.
	 */
	public static function init() {
		// `post.php` / `post-new.php` carry the post id in the query string and
		// run before the editor asks REST for the post.
		add_action( 'load-post.php', array( __CLASS__, 'migrate_requested_post' ) );

		// The editor's own request. Filtering the dispatch rather than shaping
		// a response: the content is in the database before WordPress reads it,
		// so the editor and every other client see the same page.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'migrate_rest_request' ), 10, 3 );
	}

	/**
	 * Migrate the post named in the admin URL.
	 */
	public static function migrate_requested_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a post id, not acting on a form.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( $post_id > 0 ) {
			self::migrate_post( $post_id );
		}
	}

	/**
	 * Migrate the post a REST request is about to read or write.
	 *
	 * Only single-post reads and writes with `context=edit`: an edit context is
	 * the editor, a `view` context is a reader who must not trigger a write.
	 *
	 * @param mixed           $result  Response to short-circuit with, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request.
	 * @return mixed Unchanged `$result` — this filter only has a side effect.
	 */
	public static function migrate_rest_request( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$method = strtoupper( (string) $request->get_method() );

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $result;
		}

		if ( 'edit' !== $request->get_param( 'context' ) ) {
			return $result;
		}

		$route = (string) $request->get_route();

		if ( ! preg_match( '#^/wp/v2/([a-z0-9_-]+)/(\d+)$#', $route, $matches ) ) {
			return $result;
		}

		self::migrate_post( (int) $matches[2] );

		return $result;
	}

	/**
	 * Turn one post's document into blocks, once.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether this call changed the post.
	 */
	public static function migrate_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
			return false;
		}

		if ( self::is_migrated( $post_id ) ) {
			return false;
		}

		$document   = Sdc_Store::get_data( $post_id );
		$components = isset( $document['components'] ) && is_array( $document['components'] )
			? $document['components']
			: array();

		/*
		 * Content that already holds components keeps them. A page can carry
		 * both — built in the box, extended in the editor — and appending the
		 * document would print the old components a second time. Marking only
		 * is the safe reading: the blocks in the content are what the author
		 * sees, and the document stays as the backup it already was.
		 */
		if ( self::has_blocks( $post->post_content ) ) {
			update_post_meta( $post_id, self::META_KEY, self::VERSION );
			return false;
		}

		if ( empty( $components ) ) {
			// Nothing to move. Marking here is what keeps this from running a
			// query per editor load for every page on the site.
			update_post_meta( $post_id, self::META_KEY, self::VERSION );
			return false;
		}

		$markup = self::markup( $components );

		if ( '' === $markup ) {
			return false;
		}

		$content = trim( (string) $post->post_content );
		$content = '' === $content ? $markup : $content . "\n\n" . $markup;

		// Block attribute JSON is not post content a reader ever writes; the
		// KSES filter would strip parts of it for a user without
		// `unfiltered_html` (an editor role, a multisite admin) and leave a
		// block whose attributes no longer parse. Removed for exactly the
		// duration of the write, then restored.
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);

		if ( $kses ) {
			kses_init_filters();
		}

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, self::VERSION );
		self::revision_note( $post_id );

		return true;
	}

	/**
	 * Whether a post has been migrated.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_migrated( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::META_KEY, true ) >= self::VERSION;
	}

	/**
	 * Whether content already carries builder blocks.
	 *
	 * Checks the serialized block comment, not a rendered class: an empty
	 * component renders nothing, and the block still exists in the content.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function has_blocks( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		return (bool) preg_match( '#<!--\s+wp:sdc/#', $content );
	}

	/**
	 * Block markup for a list of components.
	 *
	 * `serialize_block()` rather than string concatenation: it is core's own
	 * encoder, so escaping of quotes and slashes in the values matches what the
	 * editor parses back.
	 *
	 * @param array[] $components Document components.
	 * @return string Markup; empty when no component could be written.
	 */
	private static function markup( array $components ) {
		$markup = array();

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || empty( $component['type'] ) ) {
				continue;
			}

			$type = (string) $component['type'];

			// A component whose folder is gone has no block; converting it
			// would produce a name WordPress cannot render.
			if ( ! Sdc_Schema::get( $type ) ) {
				continue;
			}

			$markup[] = serialize_block(
				array(
					'blockName'    => Sdc_Block::name( $type ),
					'attrs'        => array(
						'data' => isset( $component['data'] ) && is_array( $component['data'] )
							? $component['data']
							: array(),
					),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		}

		return implode( "\n\n", $markup );
	}

	/**
	 * Say in the revision what changed, so the diff is not just "migration".
	 *
	 * Revisions are created by `wp_update_post()` itself; only the note is
	 * written here.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function revision_note( $post_id ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'numberposts' => 1,
				'orderby'     => 'date ID',
				'order'       => 'DESC',
				'check_enabled' => false,
			)
		);

		if ( empty( $revisions ) ) {
			return;
		}

		$revision = reset( $revisions );

		if ( $revision && '' === trim( (string) $revision->post_excerpt ) ) {
			wp_update_post(
				array(
					'ID'           => $revision->ID,
					'post_excerpt' => __( 'SDC: Komponenten aus der Metabox in Blöcke übernommen.', 'sdc-page-builder' ),
				)
			);
		}
	}
}

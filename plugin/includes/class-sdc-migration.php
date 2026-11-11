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
 * A page can already carry some components as blocks — built in the editor,
 * extended in the box — or a page can have been marked migrated by an earlier
 * run that only looked at the content. Those pages need the *missing*
 * components, and nothing else: marking them “migrated” while the content
 * lacks a component is how the hero of page 98 disappeared without a message.
 * So the migration compares instance by instance (n-th component of a type
 * against the n-th block of that type) and appends what has no block yet.
 *
 * The document is not deleted. It stays in the post meta as the backup; the
 * live page is the block content. The marker says the page has been migrated,
 * and `the_content` uses the same marker to stop appending — which is what
 * keeps a migrated page from showing every component twice.
 *
 * Two paths run the migration, for the same reason: the editor fetches its
 * content over REST, and only some setups still render through `post.php`.
 * Whichever comes first wins; the marker makes the second call a no-op.
 *
 * On a marked page the marker alone is not evidence: the broken run set it
 * while a component was still missing. The page is therefore still read —
 * one parse of the document, no write — and components without a block are
 * appended then. [`Sdc_Migration::missing_types()`] is that read; the repair
 * command pairs it with [`Sdc_Migration::migrate_post()`] as its dry run, so
 * report and write cannot drift apart.
 *
 * `--force` is the other direction: on a marked page the automatic run stops
 * as soon as the content holds any builder block, because `post_content` is
 * the source of truth from then on — a block the author deleted in the editor
 * must not come back on the next page load. An explicit repair call passes
 * `$force` and is read fully.
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
	 * Turn one post's document into blocks.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force    Read a marked page through, even when its content
	 *                       already carries builder blocks. The automatic
	 *                       hooks do not pass this: on a page that has been
	 *                       through the editor, `post_content` decides, and
	 *                       a block the author deleted stays deleted. Only an
	 *                       explicit repair (`wp sdc-pb repair --apply`)
	 *                       passes it, and the write is still limited to
	 *                       components without a block.
	 * @return bool Whether this call changed the post.
	 */
	public static function migrate_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
			return false;
		}

		$migrated = self::is_migrated( $post_id );
		$content  = (string) $post->post_content;

		// Marked and the automatic run: the page has been through the editor,
		// so its content is the source of truth. Reading the document again
		// here would re-append every block the author has since deleted —
		// an editor save would resurrect them. A page marked while its
		// content holds no builder block was marked by a run that never
		// wrote anything, and is still read below.
		if ( $migrated && ! $force && self::has_blocks( $content ) ) {
			return false;
		}

		$missing = self::missing_markup( $content, self::document_components( $post_id ) );

		if ( '' === $missing ) {
			// The content carries the whole document (the normal state after
			// the page has been through the editor), or there is no document
			// to move at all. Nothing to write: the run only has to remember
			// that it looked.
			if ( ! $migrated ) {
				update_post_meta( $post_id, self::META_KEY, self::VERSION );
			}
			return false;
		}

		$content = trim( $content );
		$content = '' === $content ? $missing : $content . "\n\n" . $missing;

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
	 * Components of a page that have no builder block in the content.
	 *
	 * The same read the migration does, without writing anything — the repair
	 * command's dry run. `wp sdc-pb repair` and `wp sdc-pb repair --apply`
	 * therefore report and write the same list; a second implementation of
	 * the comparison would be free to disagree with the one that writes.
	 *
	 * It ignores the marker on purpose: a page marked by the broken run is
	 * exactly the page this is asked about.
	 *
	 * Compared as a multiset, not position by position: a page whose document
	 * lists `[hero, faq]` and whose content already carries a `faq` block is
	 * one component short (the hero), not two. A positional comparison
	 * (`array_diff_assoc()`) reports the `faq` as missing as well, because its
	 * index moved — and `--apply` would then append a block for a component
	 * that is already on the page.
	 *
	 * @param int $post_id Post ID.
	 * @return string[] Component types without a block, in document order; may
	 *                  repeat. Empty when the page holds its whole document,
	 *                  has no document, or is not a builder post type.
	 */
	public static function missing_types( $post_id ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
			return array();
		}

		$types = array();

		foreach ( self::document_components( $post_id ) as $component ) {
			if ( is_array( $component ) && ! empty( $component['type'] ) ) {
				$types[] = (string) $component['type'];
			}
		}

		if ( empty( $types ) ) {
			return array();
		}

		$present = array_count_values( self::types_in_content( (string) $post->post_content ) );
		$missing = array();

		foreach ( $types as $type ) {
			if ( ! empty( $present[ $type ] ) ) {
				--$present[ $type ];
				continue;
			}

			$missing[] = $type;
		}

		return $missing;
	}

	/**
	 * Components of a post's document, in document order.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Components; empty when the page has no document.
	 */
	private static function document_components( $post_id ) {
		$document = Sdc_Store::get_data( $post_id );

		return isset( $document['components'] ) && is_array( $document['components'] )
			? $document['components']
			: array();
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
	 * Block markup for the components the content does not hold yet.
	 *
	 * Matched per instance, not per type: a page may carry two FAQ blocks
	 * and have a document with three, and only the third belongs at the end.
	 * Both the n-th component of a type in the document and the n-th block
	 * of that type in the content are counted in order, so a type that occurs
	 * twice is neither collapsed nor duplicated.
	 *
	 * Only content *outside* a component block is walked. A component can
	 * place blocks or contain a serialized copy of its own payload; a nested
	 * `sdc/faq` is rendered by its parent and is not a placement the author
	 * made, so counting it would make the migration skip a component that
	 * has no block of its own.
	 *
	 * @param string  $content    Current post content.
	 * @param array[] $components Document components, in document order.
	 * @return string Markup of the components without a block; empty when
	 *                the content already holds all of them.
	 */
	private static function missing_markup( $content, array $components ) {
		$remaining = array_count_values( self::types_in_content( $content ) );
		$markup    = array();

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || empty( $component['type'] ) ) {
				continue;
			}

			$type = (string) $component['type'];

			// A block in the content already stands for this instance of the
			// type: consume one and move on.
			if ( ! empty( $remaining[ $type ] ) ) {
				--$remaining[ $type ];
				continue;
			}

			$block = self::block_markup( $component );

			if ( '' !== $block ) {
				$markup[] = $block;
			}
		}

		return implode( "\n\n", $markup );
	}

	/**
	 * One component as block markup.
	 *
	 * `serialize_block()` rather than string concatenation: it is core's own
	 * encoder, so escaping of quotes and slashes in the values matches what the
	 * editor parses back.
	 *
	 * @param array $component Document component.
	 * @return string Markup; empty when the component has no block — an entry
	 *                without a type, or a type whose folder is gone (the name
	 *                WordPress would get cannot be rendered).
	 */
	private static function block_markup( array $component ) {
		if ( empty( $component['type'] ) ) {
			return '';
		}

		$type = (string) $component['type'];

		if ( ! Sdc_Schema::get( $type ) ) {
			return '';
		}

		return serialize_block(
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

	/**
	 * Component types placed in the content, in content order, one entry per
	 * block.
	 *
	 * Distinguishes the two block shapes the plugin has ever written: the
	 * `sdc/<type>` name, and the earlier single `sdc/component` block that
	 * keeps the type in an attribute. Blocks nested inside a component are
	 * not placements of their own and are skipped with their parent.
	 *
	 * @param string $content Post content.
	 * @return string[] Types; may repeat.
	 */
	private static function types_in_content( $content ) {
		$content = (string) $content;
		$types   = array();

		if ( false === strpos( $content, 'wp:' . Sdc_Block::PREFIX ) ) {
			return $types;
		}

		foreach ( parse_blocks( $content ) as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$name = (string) $block['blockName'];

			if ( Sdc_Block::LEGACY === $name ) {
				$legacy = isset( $block['attrs']['type'] ) ? (string) $block['attrs']['type'] : '';

				if ( '' !== $legacy ) {
					$types[] = $legacy;
				}
				continue;
			}

			if ( 0 === strpos( $name, Sdc_Block::PREFIX ) ) {
				$type = substr( $name, strlen( Sdc_Block::PREFIX ) );

				if ( '' !== $type ) {
					$types[] = $type;
				}
				continue;
			}

			// A container (a group, columns, a core block) is walked: a
			// component inside it is still on the page.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$types = array_merge( $types, self::types_in_content( serialize_blocks( $block['innerBlocks'] ) ) );
			}
		}

		return $types;
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

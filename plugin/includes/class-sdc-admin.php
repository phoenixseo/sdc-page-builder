<?php
/**
 * Component catalog: list, create, duplicate, import and delete.
 *
 * A component is a directory with a `definition.json` in it. That directory
 * is either shipped (plugin, theme) or maintained from here. Both stay files
 * on purpose: a component has to remain copyable between sites and themes,
 * and a database copy would be a second source of truth for the same group
 * key — the loader would then see the component twice.
 *
 * Writes go only into the directory the plugin owns, by default
 * `wp-content/sdc-components/`. A theme on a deploy host is read-only, and a
 * component shipped with a theme belongs to that theme: an edit here would be
 * overwritten by the next deploy. Theme components are therefore listed,
 * readable and duplicable into the writable directory — not editable in place.
 *
 * The form does not build a field editor. Fields are pasted as JSON, the way
 * they are written in a component's `definition.json`; keys, labels and the
 * location rule are derived. Rebuilding the host's field-group editor here
 * would duplicate it, and it would be the part that breaks first.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * The component management screen.
 */
class Sdc_Admin {

	/**
	 * Menu slug, also the page query arg.
	 */
	const MENU_SLUG = 'sdc-components';

	/**
	 * Capability required to change components.
	 *
	 * Components are not per-post content; whoever manages the site's field
	 * schema is an administrator.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Notice code per task — the past participle the message table uses.
	 *
	 * The task name and its success message are two vocabularies
	 * ("create" / "angelegt"). Mapping them here keeps the redirect simple
	 * and stops a successful action from landing on a page with no feedback
	 * at all, which is what an unmapped code silently does.
	 */
	const NOTICE_CODES = array(
		'create'    => 'created',
		'update'    => 'updated',
		'duplicate' => 'duplicated',
		'delete'    => 'deleted',
		'import'    => 'imported',
	);

	/**
	 * Nonce action for every form on the screen.
	 */
	const NONCE_ACTION = 'sdc_pb_components';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'sdc_pb_nonce';

	/**
	 * `admin-post.php` action that carries every task.
	 */
	const HOOK = 'sdc_pb_component';

	/**
	 * Transient holding the per-type instance counts.
	 */
	const INSTANCE_TRANSIENT = 'sdc_pb_instance_counts';

	/**
	 * Transient holding the number of pages with a component the page lost.
	 *
	 * Cached for two minutes rather than counted per admin page view: the
	 * scan walks every builder page's document and content, and an operator
	 * who reloads the list five times should not pay for it five times. The
	 * window is short because a repaired page must stop being reported
	 * without anyone clearing a cache by hand.
	 */
	const BROKEN_TRANSIENT = 'sdc_pb_broken_pages';

	/**
	 * Upper bound for an imported definition file.
	 */
	const MAX_IMPORT_BYTES = 262144;

	/**
	 * Field properties copied from pasted JSON into the definition.
	 *
	 * A whitelist, not a pass-through: a component file should contain the
	 * host's field properties, not whatever else the paste carried.
	 *
	 * @var string[]
	 */
	const FIELD_PROPS = array(
		'instructions',
		'required',
		'default_value',
		'placeholder',
		'prepend',
		'append',
		'rows',
		'return_format',
		'choices',
		'min',
		'max',
		'step',
		'multiple',
		'display_format',
		'button_label',
		'layout',
	);

	/**
	 * Hook the screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::HOOK, array( __CLASS__, 'handle' ) );

		// Instance counts are derived data. A document changes with a meta
		// write; placement changes with a post save (the block editor writes
		// `post_content` and never touches the document meta), and a deleted
		// or trashed page removes instances without a meta write at all.
		add_action( 'added_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
		add_action( 'save_post', array( __CLASS__, 'clear_counts_now' ) );
		add_action( 'deleted_post', array( __CLASS__, 'clear_counts_now' ) );
		add_action( 'trashed_post', array( __CLASS__, 'clear_counts_now' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'clear_counts_now' ) );

		/*
		 * The page list is where an operator looks first, so it is where a
		 * page that lost a component has to say so. `admin_notices` covers
		 * every screen with one hook; the renderer gates on the screen and
		 * the capability, and stays silent everywhere else.
		 */
		add_action( 'admin_notices', array( __CLASS__, 'render_broken_notice' ) );
	}

	/**
	 * Drop the cached counts, whatever changed.
	 *
	 * Both transients: the instance counts and the count of pages with a
	 * lost component. The second one used to be left out, and the notice
	 * then kept naming a page the operator had just repaired — for up to
	 * two minutes, with no way to tell a stale notice from a new fault.
	 */
	public static function clear_counts_now() {
		delete_transient( self::INSTANCE_TRANSIENT );
		delete_transient( self::BROKEN_TRANSIENT );
	}

	/**
	 * Number of pages whose document names a type with no view.
	 *
	 * A page counts once, however many of its components are broken: the
	 * notice is a pointer to a page to open, not a tally of defects, and a
	 * page with five lost components is one repair run either way.
	 *
	 * "No view" is asked the same way the renderer asks it — through
	 * `Sdc_Frontend::has_view()` — so the notice cannot report a page that
	 * renders fine, nor stay silent about one that does not.
	 *
	 * @return int Count of affected pages, 0 when none.
	 */
	public static function count_broken_pages() {
		$cached = get_transient( self::BROKEN_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = 0;
		$posts = get_posts(
			array(
				'post_type'        => Sdc_Editor::post_types(),
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_key'         => Sdc_Store::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only pages carrying a document can be affected.
			)
		);

		foreach ( (array) $posts as $post_id ) {
			if ( self::post_has_missing_view( (int) $post_id ) ) {
				++$count;
			}
		}

		set_transient( self::BROKEN_TRANSIENT, $count, 2 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Does this page's document name a component type that cannot render?
	 *
	 * Reads the document, not the rendered page: rendering a page to find
	 * out whether it renders is the expensive and misleading way round.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private static function post_has_missing_view( $post_id ) {
		$data = Sdc_Store::get_data( $post_id );
		if ( ! is_array( $data ) || empty( $data['components'] ) || ! is_array( $data['components'] ) ) {
			return false;
		}

		foreach ( $data['components'] as $component ) {
			if ( ! is_array( $component ) || empty( $component['type'] ) ) {
				continue;
			}

			if ( ! Sdc_Frontend::has_view( (string) $component['type'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell the operator on the page list that pages lost their components.
	 *
	 * Only on the page list, only for someone who can act on it, and only
	 * when there is something to report — an always-present warning trains
	 * people to ignore warnings.
	 */
	public static function render_broken_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || 'page' !== $screen->post_type ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$count = self::count_broken_pages();
		if ( $count < 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'SDC Page Builder:', 'sdc-page-builder' ),
			esc_html(
				sprintf(
					/* translators: 1: number of pages, 2: WP-CLI command. */
					_n(
						'%1$d Seite enthält eine Komponente ohne Ansicht. Sie rendert nichts, bis die Komponente zurück ist — Vorschau prüfen mit %2$s.',
						'%1$d Seiten enthalten eine Komponente ohne Ansicht. Sie rendern nichts, bis die Komponente zurück ist — Vorschau prüfen mit %2$s.',
						$count,
						'sdc-page-builder'
					),
					$count,
					'wp sdc-pb repair'
				)
			)
		);
	}

	/**
	 * Register the top-level menu entry.
	 */
	public static function menu() {
		add_menu_page(
			esc_html__( 'SDC-Komponenten', 'sdc-page-builder' ),
			esc_html__( 'SDC', 'sdc-page-builder' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-layout',
			58
		);
	}

	/**
	 * Drop the cached instance counts when a document changes.
	 *
	 * @param int    $meta_id   Meta row id (unused).
	 * @param int    $object_id Post id (unused).
	 * @param string $meta_key  Meta key that changed.
	 */
	public static function clear_counts( $meta_id, $object_id, $meta_key ) {
		if ( Sdc_Store::META_KEY === $meta_key ) {
			delete_transient( self::INSTANCE_TRANSIENT );
		}
	}

	/**
	 * Directory components are written to.
	 *
	 * The theme is deliberately not an option: it ships code, it is read-only
	 * on deploy hosts, and `wp-content/sdc-components/` survives a theme
	 * switch — which is what a component managed from the admin has to do.
	 *
	 * @return string Absolute path without trailing slash, empty when unknown.
	 */
	public static function write_dir() {
		$default = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/' . Sdc_Schema::USER_DIR : '';

		/**
		 * Filters the directory new components are written to.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = (string) apply_filters( 'sdc_pb_write_dir', $default );

		return untrailingslashit( $dir );
	}

	/**
	 * Make sure the write directory exists and is writable.
	 *
	 * @return string|WP_Error Absolute path, or an error explaining what to do.
	 */
	private static function ensure_write_dir() {
		$dir = self::write_dir();

		if ( '' === $dir ) {
			return new WP_Error(
				'sdc_no_write_dir',
				__( 'Kein Zielordner: WP_CONTENT_DIR ist nicht definiert.', 'sdc-page-builder' )
			);
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'sdc_no_write_dir',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s lässt sich nicht anlegen. Der Webserver braucht Schreibrecht auf wp-content.', 'sdc-page-builder' ),
					$dir
				)
			);
		}

		if ( ! is_writable( $dir ) ) {
			return new WP_Error(
				'sdc_no_write_dir',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s ist nicht beschreibbar. Komponenten können hier nur gelesen werden.', 'sdc-page-builder' ),
					$dir
				)
			);
		}

		return $dir;
	}

	/**
	 * Whether a file lives in the directory this screen writes to.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	private static function in_write_dir( $file ) {
		$dir = self::write_dir();

		if ( '' === $file || '' === $dir ) {
			return false;
		}

		$dir  = trailingslashit( wp_normalize_path( $dir ) );
		$file = wp_normalize_path( $file );

		return 0 === strpos( $file, $dir );
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung für diese Seite.', 'sdc-page-builder' ) );
		}

		$dir      = self::write_dir();
		$writable = '' !== $dir && ( is_dir( $dir ) ? is_writable( $dir ) : is_writable( dirname( $dir ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which form to show; no data changes.
		$edit = isset( $_GET['sdc_edit'] ) ? sanitize_key( wp_unslash( $_GET['sdc_edit'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SDC-Komponenten', 'sdc-page-builder' ) . '</h1>';

		self::render_notice();

		// One component open for editing: the catalog would only get in the way.
		if ( '' !== $edit ) {
			self::render_edit_form( $edit );
			echo '</div>';
			return;
		}

		if ( ! $writable ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: absolute directory path. */
						__( 'Der Ordner %s ist nicht beschreibbar. Komponenten werden angezeigt, aber Anlegen und Löschen ist gesperrt.', 'sdc-page-builder' ),
						'' === $dir ? __( '(unbekannt)', 'sdc-page-builder' ) : $dir
					)
				)
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Eine Komponente ist ein Ordner mit einer definition.json. Im Backend angelegte Komponenten liegen in %s und überleben einen Theme-Wechsel. Komponenten im Theme sind ausgeliefert und hier schreibgeschützt.', 'sdc-page-builder' ),
					'' === $dir ? __( '(unbekannt)', 'sdc-page-builder' ) : $dir
				)
			)
		);

		self::render_table();
		self::render_create_form( $writable );
		self::render_import_form( $writable );

		echo '</div>';
	}

	/**
	 * The catalog table.
	 */
	private static function render_table() {
		$rows = self::entries();

		echo '<h2>' . esc_html__( 'Vorhandene Komponenten', 'sdc-page-builder' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Keine Komponenten gefunden. Das Plugin liefert hero und faq mit — fehlen die, ist ACF oder SCF nicht aktiv.', 'sdc-page-builder' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach (
			array(
				esc_html__( 'Komponente', 'sdc-page-builder' ),
				esc_html__( 'Felder', 'sdc-page-builder' ),
				esc_html__( 'Vorlage', 'sdc-page-builder' ),
				esc_html__( 'Ablage', 'sdc-page-builder' ),
				esc_html__( 'Verwendung', 'sdc-page-builder' ),
				esc_html__( 'Aktionen', 'sdc-page-builder' ),
			) as $heading
		) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			self::render_row( $row );
		}

		echo '</tbody></table>';
	}

	/**
	 * Every component, with what the screen needs to show it.
	 *
	 * @return array[]
	 */
	private static function entries() {
		$counts = self::instance_counts();
		$rows   = array();

		foreach ( Sdc_Schema::components() as $type => $component ) {
			$file   = Sdc_Schema::file_for_key( (string) $component['key'] );
			$rows[] = array(
				'type'        => $type,
				'label'       => (string) $component['label'],
				'description' => (string) $component['description'],
				'version'     => (int) $component['version'],
				'fields'      => count( (array) $component['fields'] ),
				'file'        => $file,
				'template'    => Sdc_Schema::template( $type ),
				'source'      => self::source_label( $file ),
				'writable'    => self::in_write_dir( $file ),
				'pages'       => isset( $counts[ $type ]['pages'] ) ? (int) $counts[ $type ]['pages'] : 0,
				'instances'   => isset( $counts[ $type ]['components'] ) ? (int) $counts[ $type ]['components'] : 0,
			);
		}

		return $rows;
	}

	/**
	 * Where a component's definition comes from.
	 *
	 * @param string $file Absolute path, empty when unknown.
	 * @return string Translated label.
	 */
	private static function source_label( $file ) {
		if ( '' === $file ) {
			return __( 'unbekannt', 'sdc-page-builder' );
		}

		if ( self::in_write_dir( $file ) ) {
			return __( 'wp-content', 'sdc-page-builder' );
		}

		$normalized = wp_normalize_path( $file );

		if ( 0 === strpos( $normalized, trailingslashit( wp_normalize_path( Sdc_Schema::dir() ) ) ) ) {
			return __( 'Plugin', 'sdc-page-builder' );
		}

		return __( 'Theme', 'sdc-page-builder' );
	}

	/**
	 * One catalog row.
	 *
	 * @param array $row Row data from `entries()`.
	 */
	private static function render_row( array $row ) {
		$type = $row['type'];

		echo '<tr>';

		// Component: label, type, description.
		echo '<td>';
		printf( '<strong>%s</strong><br />', esc_html( $row['label'] ) );
		printf( '<code>%s</code>', esc_html( $type ) );
		if ( '' !== $row['description'] ) {
			printf( '<br /><span class="description">%s</span>', esc_html( $row['description'] ) );
		}
		echo '</td>';

		printf( '<td>%d</td>', (int) $row['fields'] );

		echo '<td>';
		if ( '' === $row['template'] ) {
			echo '<span class="description">' . esc_html__( '—', 'sdc-page-builder' ) . '</span>';
		} else {
			printf( '<code>%s</code>', esc_html( self::relative_path( $row['template'] ) ) );
		}
		echo '</td>';

		// Storage: source, path, writability.
		echo '<td>';
		printf( '<strong>%s</strong>', esc_html( $row['source'] ) );
		if ( '' !== $row['file'] ) {
			printf( '<br /><code>%s</code>', esc_html( self::relative_path( $row['file'] ) ) );
		}
		printf(
			'<br /><span class="description">%s</span>',
			esc_html( $row['writable'] ? __( 'hier verwaltbar', 'sdc-page-builder' ) : __( 'schreibgeschützt', 'sdc-page-builder' ) )
		);
		echo '</td>';

		// Usage.
		printf(
			'<td>%s</td>',
			esc_html( self::usage_label( $row ) )
		);

		echo '<td>';
		self::render_row_actions( $row );
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Export, duplicate and delete controls for one component.
	 *
	 * @param array $row Row data.
	 */
	private static function render_row_actions( array $row ) {
		$type  = $row['type'];
		$label = $row['label'];

		// Only a component in the write directory can be edited in place;
		// a theme or plugin component is duplicable, not editable.
		if ( $row['writable'] ) {
			$edit_url = add_query_arg(
				array(
					'page'     => self::MENU_SLUG,
					'sdc_edit' => $type,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a class="button button-small" href="%s">%s</a> ',
				esc_url( $edit_url ),
				esc_html__( 'Bearbeiten', 'sdc-page-builder' )
			);
		}

		$export_url = add_query_arg(
			array(
				'action'   => self::HOOK,
				'sdc_task' => 'export',
				'sdc_type' => rawurlencode( $type ),
				// Same field name handle() verifies — a `_wpnonce` here would
				// be ignored and exporting would fail like the forms did.
				self::NONCE_FIELD => wp_create_nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);

		printf(
			'<a class="button button-small" href="%s">%s</a> ',
			esc_url( $export_url ),
			esc_html__( 'Export', 'sdc-page-builder' )
		);

		echo '<details style="display:inline-block;margin-top:6px;">';
		echo '<summary class="button button-small">' . esc_html__( 'Duplizieren', 'sdc-page-builder' ) . '</summary>';

		printf( '<form method="post" action="%s" style="margin-top:6px;">', esc_url( admin_url( 'admin-post.php' ) ) );
		self::form_hidden( 'duplicate' );
		printf( '<input type="hidden" name="sdc_source" value="%s" />', esc_attr( $type ) );
		printf(
			'<label for="sdc_type_%1$s">%2$s</label><br /><input type="text" id="sdc_type_%1$s" name="sdc_type" value="" placeholder="%3$s" required /> ',
			esc_attr( $type ),
			esc_html__( 'Neuer Typ', 'sdc-page-builder' ),
			esc_attr( $type . '-kopie' )
		);
		printf( '<button type="submit" class="button button-small">%s</button>', esc_html__( 'Anlegen', 'sdc-page-builder' ) );
		echo '</form>';
		echo '</details>';

		if ( ! $row['writable'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Duplizieren legt die Kopie in wp-content/sdc-components/ an — dort ist sie hier bearbeitbar.', 'sdc-page-builder' )
			);
			return;
		}

		$confirm = sprintf(
			/* translators: %s: component label. */
			__( 'Komponente „%s" mit definition.json und render.php löschen?', 'sdc-page-builder' ),
			$label
		);
		if ( $row['pages'] > 0 ) {
			$confirm = sprintf(
				/* translators: 1: component label, 2: number of pages. */
				__( 'Komponente „%1$s" wird auf %2$d Seiten verwendet. Diese Stellen rendern danach nichts mehr. Wirklich löschen?', 'sdc-page-builder' ),
				$label,
				(int) $row['pages']
			);
		}

		printf( '<form method="post" action="%s" style="margin-top:6px;">', esc_url( admin_url( 'admin-post.php' ) ) );
		self::form_hidden( 'delete' );
		printf( '<input type="hidden" name="sdc_type" value="%s" />', esc_attr( $type ) );
		if ( $row['pages'] > 0 ) {
			printf(
				'<label><input type="checkbox" name="sdc_force" value="1" /> %s</label><br />',
				esc_html__( 'Auch bei Verwendung löschen', 'sdc-page-builder' )
			);
		}
		printf(
			'<button type="submit" class="button button-small button-link-delete" onclick="return confirm(%s);">%s</button>',
			esc_attr( wp_json_encode( $confirm ) ),
			esc_html__( 'Löschen', 'sdc-page-builder' )
		);
		echo '</form>';
	}

	/**
	 * Form that creates a component.
	 *
	 * @param bool $writable Whether writing is possible.
	 */
	private static function render_create_form( $writable ) {
		echo '<h2>' . esc_html__( 'Neue Komponente', 'sdc-page-builder' ) . '</h2>';

		if ( ! $writable ) {
			echo '<p>' . esc_html__( 'Anlegen ist gesperrt, solange der Zielordner nicht beschreibbar ist.', 'sdc-page-builder' ) . '</p>';
			return;
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		self::form_hidden( 'create' );

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="sdc_type">' . esc_html__( 'Typ', 'sdc-page-builder' ) . '</label></th><td>';
		echo '<input type="text" id="sdc_type" name="sdc_type" value="" class="regular-text" required pattern="[a-z0-9][a-z0-9_-]*" />';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Kleinbuchstaben, Ziffern, Binde- und Unterstriche. Der Typ wird zum Ordnernamen und zum Teil des Gruppen-Keys: group_sdc_component_<typ>.', 'sdc-page-builder' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_label">' . esc_html__( 'Name', 'sdc-page-builder' ) . '</label></th><td>';
		echo '<input type="text" id="sdc_label" name="sdc_label" value="" class="regular-text" required />';
		printf( '<p class="description">%s</p>', esc_html__( 'Erscheint im Komponenten-Picker, z. B. „Zitat" oder „Preistabelle".', 'sdc-page-builder' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_description">' . esc_html__( 'Beschreibung', 'sdc-page-builder' ) . '</label></th><td>';
		echo '<textarea id="sdc_description" name="sdc_description" rows="2" class="large-text"></textarea>';
		printf( '<p class="description">%s</p>', esc_html__( 'Optional. Steht als Hilfetext in der Kachel des Pickers.', 'sdc-page-builder' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_icon">' . esc_html__( 'Icon', 'sdc-page-builder' ) . '</label></th><td>';
		echo '<input type="text" id="sdc_icon" name="sdc_icon" value="layout" class="regular-text" />';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Optional. Ein Dashicon-Name, z. B. layout, cover-image, format-quote, table.', 'sdc-page-builder' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_fields">' . esc_html__( 'Felder', 'sdc-page-builder' ) . '</label></th><td>';
		Sdc_Field_Editor::render( '' );
		self::render_fields_help();
		echo '</td></tr>';

		/*
		 * No type yet: the panel shows the helper reference and takes a body,
		 * but the generator needs the saved field list and appears on the edit
		 * screen instead.
		 *
		 * A row of its own, spanning both columns: the panel prints blocks,
		 * and a paragraph directly inside a `form-table` would make the
		 * browser hoist it out of the table.
		 */
		echo '<tr><th scope="row">' . esc_html__( 'Ansicht', 'sdc-page-builder' ) . '</th><td>';
		Sdc_View_Editor::render( '' );
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Komponente anlegen', 'sdc-page-builder' ) );
		echo '</form>';
	}

	/**
	 * Form that edits a component this screen owns.
	 *
	 * The values come from the raw file, not from the loader: `Sdc_Schema::get()`
	 * hands back normalized fields (types cast, defaults filled in), and
	 * writing those back would rewrite the whole file on a save the user never
	 * asked for. The one exception is a rejected save — then the transient
	 * carries what was typed, so nothing has to be retyped.
	 *
	 * @param string $type Component type.
	 */
	private static function render_edit_form( $type ) {
		$type = self::validate_type( $type );

		if ( is_wp_error( $type ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $type->get_error_message() ) );
			return;
		}

		$component = Sdc_Schema::get( $type );

		if ( null === $component ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: component type. */
						__( 'Die Komponente %s gibt es nicht.', 'sdc-page-builder' ),
						$type
					)
				)
			);
			return;
		}

		$file = Sdc_Schema::file_for_key( (string) $component['key'] );

		if ( '' === $file || ! is_readable( $file ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Zu dieser Komponente wurde keine lesbare definition.json gefunden.', 'sdc-page-builder' )
			);
			return;
		}

		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the component directories.
		$base = false === $raw ? null : json_decode( (string) $raw, true );

		if ( ! is_array( $base ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: path relative to the WordPress root. */
						__( 'Die Datei %s ist nicht lesbar oder enthält kein gültiges JSON.', 'sdc-page-builder' ),
						self::relative_path( $file )
					)
				)
			);
			return;
		}

		$values = array(
			'label'       => isset( $base['title'] ) ? (string) $base['title'] : $type,
			'description' => isset( $base['sdc_description'] ) ? (string) $base['sdc_description'] : '',
			'icon'        => isset( $base['sdc_icon'] ) ? (string) $base['sdc_icon'] : '',
			'fields'      => self::printable_fields( isset( $base['fields'] ) && is_array( $base['fields'] ) ? $base['fields'] : array() ),
			'view'        => Sdc_View::read( Sdc_View::file( self::write_dir(), $type ) ),
			// The delivered stylesheet, not the slot in wp-content: when a
			// theme ships the file, that is the one the page sees.
			'css'         => Sdc_View::read_style( Sdc_View::style( $type ) ),
		);

		$draft = self::take_draft( $type );

		if ( null !== $draft ) {
			foreach ( array_keys( $values ) as $name ) {
				if ( isset( $draft[ $name ] ) && is_string( $draft[ $name ] ) ) {
					$values[ $name ] = $draft[ $name ];
				}
			}
		}

		printf(
			'<h2>%s</h2>',
			esc_html(
				sprintf(
					/* translators: %s: component label. */
					__( 'Komponente bearbeiten: %s', 'sdc-page-builder' ),
					$values['label']
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Gespeichert wird in dieselbe definition.json. Der Typ bleibt unverändert: er steckt im Gruppen-Key und in jedem Feld-Key, an dem die Inhalte der Seiten hängen. Ein anderer Typ entsteht über „Duplizieren".', 'sdc-page-builder' )
		);

		if ( ! self::in_write_dir( $file ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Diese Komponente liegt nicht in wp-content/sdc-components/. Theme- und Plugin-Komponenten sind schreibgeschützt, weil der nächste Deploy die Änderung überschreiben würde. Dupliziere sie, um sie hier zu bearbeiten.', 'sdc-page-builder' )
			);
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( self::catalog_url() ), esc_html__( 'Zurück zur Übersicht', 'sdc-page-builder' ) );
			return;
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		self::form_hidden( 'update' );
		printf( '<input type="hidden" name="sdc_type" value="%s" />', esc_attr( $type ) );

		/*
		 * Three tabs, because this screen edits three different layers and a
		 * normal day touches only the first. All three panels sit in the DOM
		 * and are posted together, so switching a tab never drops a change;
		 * without JavaScript the browser shows all three stacked, which is
		 * the fallback for a browser that cannot switch them.
		 *
		 * The field definitions, not the JSON: the view generator walks
		 * arrays. `$values['fields']` is a JSON string — for a draft it may
		 * even be broken, and then there is simply nothing to generate from
		 * and the panel says so.
		 */
		$view_fields = json_decode( (string) $values['fields'], true );

		Sdc_Tabs::render(
			array(
				array(
					'id'       => 'content',
					'label'    => __( 'Inhalt', 'sdc-page-builder' ),
					'hint'     => __( 'Name, Beschreibung und die Felder, die der Redaktion angeboten werden. Das ist der Teil, der sich im Alltag ändert.', 'sdc-page-builder' ),
					'callback' => function () use ( $type, $values ) {
						echo '<table class="form-table" role="presentation">';
						self::render_identity_rows( $type, $values );
						self::render_fields_row( $values );
						echo '</table>';
					},
				),
				array(
					'id'       => 'view',
					'label'    => __( 'Ansicht (PHP)', 'sdc-page-builder' ),
					'hint'     => __( 'Wie die Komponente ausgegeben wird. Optional: ohne render.php erscheinen die Felder in einer generischen Ausgabe.', 'sdc-page-builder' ),
					'callback' => function () use ( $type, $values, $view_fields ) {
						Sdc_View_Editor::render( $type, is_array( $view_fields ) ? $view_fields : array(), $values['view'] );
					},
				),
				array(
					'id'       => 'styles',
					'label'    => __( 'Styles (CSS)', 'sdc-page-builder' ),
					'hint'     => __( 'Eigene Gestaltung der Komponente. Optional: ohne component.css erbt sie die Styles des Themes.', 'sdc-page-builder' ),
					'callback' => function () use ( $type, $values ) {
						Sdc_Style_Editor::render( $type, $values['css'] );
					},
				),
			),
			self::active_tab(),
			__( 'Bereiche der Komponente', 'sdc-page-builder' )
		);

		submit_button( __( 'Komponente speichern', 'sdc-page-builder' ) );
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::catalog_url() ), esc_html__( 'Abbrechen', 'sdc-page-builder' ) );
		echo '</form>';
	}

	/**
	 * The tab that should be open on arrival.
	 *
	 * Set by the redirect after a save that failed in a specific layer, so
	 * the error and the box that caused it are on screen together.
	 *
	 * @return string Tab id, or an empty string for the first tab.
	 */
	private static function active_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only, no data changes.
		return isset( $_GET['sdc_tab'] ) ? sanitize_key( wp_unslash( $_GET['sdc_tab'] ) ) : '';
	}

	/**
	 * Type, name, description and icon, as rows of a form table.
	 *
	 * @param string $type   Component type.
	 * @param array  $values Values the form shows.
	 */
	private static function render_identity_rows( $type, array $values ) {
		// The type is shown, never posted as an editable value.
		echo '<tr><th scope="row">' . esc_html__( 'Typ', 'sdc-page-builder' ) . '</th><td>';
		printf( '<code>%s</code>', esc_html( $type ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Nicht änderbar. Ein neuer Typ würde neue Feld-Keys erzeugen, und die Inhalte der Seiten hingen danach an Feldern, die es nicht mehr gibt.', 'sdc-page-builder' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_label">' . esc_html__( 'Name', 'sdc-page-builder' ) . '</label></th><td>';
		printf( '<input type="text" id="sdc_label" name="sdc_label" value="%s" class="regular-text" required />', esc_attr( $values['label'] ) );
		printf( '<p class="description">%s</p>', esc_html__( 'Erscheint im Komponenten-Picker, z. B. „Zitat" oder „Preistabelle".', 'sdc-page-builder' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_description">' . esc_html__( 'Beschreibung', 'sdc-page-builder' ) . '</label></th><td>';
		printf( '<textarea id="sdc_description" name="sdc_description" rows="2" class="large-text">%s</textarea>', esc_textarea( $values['description'] ) );
		printf( '<p class="description">%s</p>', esc_html__( 'Optional. Steht als Hilfetext in der Kachel des Pickers.', 'sdc-page-builder' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="sdc_icon">' . esc_html__( 'Icon', 'sdc-page-builder' ) . '</label></th><td>';
		printf( '<input type="text" id="sdc_icon" name="sdc_icon" value="%s" class="regular-text" />', esc_attr( $values['icon'] ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Optional. Ein Dashicon-Name, z. B. layout, cover-image, format-quote, table.', 'sdc-page-builder' )
		);
		echo '</td></tr>';
	}

	/**
	 * The field editor, its confirmation checkbox and the help text.
	 *
	 * @param array $values Values the form shows.
	 */
	private static function render_fields_row( array $values ) {
		echo '<tr><th scope="row"><label for="sdc_fields">' . esc_html__( 'Felder', 'sdc-page-builder' ) . '</label></th><td>';
		Sdc_Field_Editor::render( $values['fields'] );
		// Outside the editor on purpose: the confirmation must stay visible even
		// when the JSON panel is collapsed, or the save fails without a reason
		// the user can see.
		printf(
			'<p><label><input type="checkbox" name="sdc_confirm_fields" value="1" /> %s</label></p>',
			esc_html__( 'Felder entfernen bestätigen', 'sdc-page-builder' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Nur nötig, wenn ein Feldname aus der Liste verschwindet. Die Inhalte bleiben in den Seiten gespeichert, sind danach aber unsichtbar.', 'sdc-page-builder' )
		);
		self::render_fields_help();
		echo '</td></tr>';
	}

	/**
	 * The field list as it can be shown for editing.
	 *
	 * `key` is left out on every level: it is derived from type and name, and
	 * a key in the textarea would suggest it could be edited — while a changed
	 * key is exactly what makes the stored content disappear.
	 *
	 * @param array[] $fields Field definitions from the file.
	 * @return string JSON, empty for a component without fields.
	 */
	private static function printable_fields( array $fields ) {
		$stripped = self::strip_keys( $fields );

		if ( empty( $stripped ) ) {
			return '';
		}

		$json = wp_json_encode( $stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '' : $json;
	}

	/**
	 * A field list without its `key` entries, on every nesting level.
	 *
	 * @param array[] $fields Field definitions.
	 * @return array[]
	 */
	private static function strip_keys( array $fields ) {
		$out = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			unset( $field['key'] );

			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::strip_keys( $field['sub_fields'] );
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Posted values of a rejected save, keyed like the form fields.
	 *
	 * Kept in a transient next to the error message: the field list is the one
	 * thing a user must never have to type twice, and a second state in the
	 * database would be a cache for a form.
	 *
	 * @param string $type Component the form belongs to.
	 * @return array|null Null when there is no draft for this form.
	 */
	private static function take_draft( $type ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the form's own draft token; no data changes.
		$token = isset( $_GET['sdc_detail'] ) ? sanitize_key( wp_unslash( $_GET['sdc_detail'] ) ) : '';

		if ( '' === $token ) {
			return null;
		}

		$key   = 'sdc_pb_draft_' . $token;
		$draft = get_transient( $key );

		if ( ! is_array( $draft ) ) {
			return null;
		}

		if ( ! isset( $draft['type'] ) || (string) $type !== (string) $draft['type'] ) {
			return null;
		}

		return $draft;
	}

	/**
	 * URL of the catalog list.
	 *
	 * @return string
	 */
	private static function catalog_url() {
		return add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Example and available field types, so the paste target is obvious.
	 */
	private static function render_fields_help() {
		echo '<details style="margin-top:8px;">';
		echo '<summary>' . esc_html__( 'Beispiel und verfügbare Feldtypen', 'sdc-page-builder' ) . '</summary>';

		echo '<p>' . esc_html__( 'Ein Eintrag pro Feld. Was ACF beim Feld kennt, darf mit hinein (rows, choices, return_format, sub_fields …).', 'sdc-page-builder' ) . '</p>';
		echo '<pre class="code">' . esc_html(
			"[\n" .
			"  {\"name\": \"heading\", \"label\": \"Überschrift\", \"type\": \"text\", \"required\": 1},\n" .
			"  {\"name\": \"body\", \"label\": \"Text\", \"type\": \"wysiwyg\"},\n" .
			"  {\"name\": \"items\", \"label\": \"Einträge\", \"type\": \"repeater\", \"sub_fields\": [\n" .
			"    {\"name\": \"q\", \"label\": \"Frage\", \"type\": \"text\"},\n" .
			"    {\"name\": \"a\", \"label\": \"Antwort\", \"type\": \"textarea\"}\n" .
			"  ]}\n" .
			']'
		) . '</pre>';

		$types = self::field_types();
		if ( ! empty( $types ) ) {
			echo '<p><strong>' . esc_html__( 'Feldtypen des Hosts', 'sdc-page-builder' ) . ':</strong> ';
			echo '<code>' . esc_html( implode( ', ', $types ) ) . '</code></p>';
		}

		echo '</details>';
	}

	/**
	 * Form that imports a definition file.
	 *
	 * @param bool $writable Whether writing is possible.
	 */
	private static function render_import_form( $writable ) {
		echo '<h2>' . esc_html__( 'Komponente importieren', 'sdc-page-builder' ) . '</h2>';

		if ( ! $writable ) {
			echo '<p>' . esc_html__( 'Importieren ist gesperrt, solange der Zielordner nicht beschreibbar ist.', 'sdc-page-builder' ) . '</p>';
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Eine definition.json aus einem anderen Projekt — der Gruppen-Key darin bestimmt den Typ. Vorhandene Typen werden nicht überschrieben.', 'sdc-page-builder' )
		);

		printf( '<form method="post" action="%s" enctype="multipart/form-data">', esc_url( admin_url( 'admin-post.php' ) ) );
		self::form_hidden( 'import' );
		echo '<input type="file" name="sdc_component_file" accept=".json,application/json" required /> ';
		submit_button( __( 'Importieren', 'sdc-page-builder' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Hidden fields every form on the screen carries.
	 *
	 * @param string $task Task name.
	 */
	private static function form_hidden( $task ) {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::HOOK ) );
		printf( '<input type="hidden" name="sdc_task" value="%s" />', esc_attr( $task ) );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * Show the result of the last action.
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a notice code, no data changes.
		$code = isset( $_GET['sdc_notice'] ) ? sanitize_key( wp_unslash( $_GET['sdc_notice'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'created'    => array( 'success', __( 'Komponente angelegt.', 'sdc-page-builder' ) ),
			'updated'    => array( 'success', __( 'Komponente gespeichert.', 'sdc-page-builder' ) ),
			'duplicated' => array( 'success', __( 'Komponente dupliziert.', 'sdc-page-builder' ) ),
			'deleted'    => array( 'success', __( 'Komponente gelöscht.', 'sdc-page-builder' ) ),
			'imported'   => array( 'success', __( 'Komponente importiert.', 'sdc-page-builder' ) ),
			'error'      => array( 'error', __( 'Die Aktion wurde nicht ausgeführt.', 'sdc-page-builder' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		list( $kind, $message ) = $messages[ $code ];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a notice token, no data changes.
		$token  = isset( $_GET['sdc_detail'] ) ? sanitize_key( wp_unslash( $_GET['sdc_detail'] ) ) : '';
		$detail = '';

		if ( '' !== $token ) {
			$stored = get_transient( 'sdc_pb_notice_' . $token );
			if ( is_string( $stored ) && '' !== $stored ) {
				$detail = $stored;
				delete_transient( 'sdc_pb_notice_' . $token );
			}
		}

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $kind ), esc_html( $message ) );

		if ( '' !== $detail ) {
			printf( '<p>%s</p>', esc_html( $detail ) );
		}

		echo '</div>';
	}

	/**
	 * Handle every task from the screen.
	 */
	public static function handle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung für diese Aktion.', 'sdc-page-builder' ) );
		}

		// The same field the forms write. check_admin_referer() looks for
		// `_wpnonce` unless it is told otherwise, so naming the field here is
		// what makes the two ends meet; without it every action died with
		// "the link you followed has expired".
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		$task = isset( $_POST['sdc_task'] ) ? sanitize_key( wp_unslash( $_POST['sdc_task'] ) ) : '';

		if ( '' === $task ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above; export links carry the task in the URL.
			$task = isset( $_GET['sdc_task'] ) ? sanitize_key( wp_unslash( $_GET['sdc_task'] ) ) : '';
		}

		$result = new WP_Error( 'sdc_unknown_task', __( 'Unbekannte Aktion.', 'sdc-page-builder' ) );

		if ( 'export' === $task ) {
			self::export();
			return;
		}

		if ( 'create' === $task ) {
			$result = self::create();
		} elseif ( 'update' === $task ) {
			$result = self::update();
		} elseif ( 'duplicate' === $task ) {
			$result = self::duplicate();
		} elseif ( 'delete' === $task ) {
			$result = self::delete();
		} elseif ( 'import' === $task ) {
			$result = self::import();
		}

		if ( is_wp_error( $result ) ) {
			/*
			 * A rejected edit goes back to the form, not to the catalog: the
			 * posted field list is the only copy of what the user just typed.
			 * The draft travels in a transient behind the same one-time token
			 * as the message, so nothing large has to fit into a URL.
			 */
			if ( 'update' === $task ) {
				$args  = self::edit_args();
				$draft = empty( $args ) ? array() : self::posted_draft();
				$data  = $result->get_error_data();

				// A validator that knows which tab owns the box says so; the
				// form then opens there instead of on the field list.
				if ( ! empty( $args ) && is_array( $data ) && ! empty( $data['sdc_tab'] ) ) {
					$args['sdc_tab'] = sanitize_key( (string) $data['sdc_tab'] );
				}

				self::redirect( 'error', $result->get_error_message(), $args, $draft );
			}

			self::redirect( 'error', $result->get_error_message() );
		}

		$notice = isset( self::NOTICE_CODES[ $task ] ) ? self::NOTICE_CODES[ $task ] : 'error';

		self::redirect( $notice, '', self::success_args( $task ) );
	}

	/**
	 * Where a finished task sends the browser.
	 *
	 * An edit goes back to its own form, not to the catalog. The reason is
	 * the generate button: it produces the view **as the result of this
	 * request**, so a redirect to the list throws away the one thing the
	 * click was for — the author would have to page back in and open the
	 * panel to find out whether the generator did anything.
	 *
	 * Create, duplicate and delete have no result of their own on the form,
	 * so they stay on the catalog where the change is visible.
	 *
	 * @param string $task Task name.
	 * @return array Extra query args; empty for the catalog.
	 */
	private static function success_args( $task ) {
		if ( 'update' !== $task ) {
			return array();
		}

		$args = self::edit_args();

		// Whatever produced the answer the author is coming back to read
		// decides which tab opens: the generator lives in the view, the
		// scaffold in the styles.
		if ( array() !== $args && Sdc_View_Editor::wants_generate() ) {
			$args['sdc_tab'] = 'view';
		}

		if ( array() !== $args && Sdc_Style_Editor::wants_scaffold() ) {
			$args['sdc_tab'] = 'styles';
		}

		return $args;
	}

	/**
	 * Query arg that sends the next request to the edit form of the posted type.
	 *
	 * @return array Empty when the posted type is not usable as a form target.
	 */
	private static function edit_args() {
		$type = self::posted( 'sdc_type' );

		return '' === $type ? array() : array( 'sdc_edit' => $type );
	}

	/**
	 * What the edit form has to show again after a rejected save.
	 *
	 * @return array
	 */
	private static function posted_draft() {
		return array(
			'type'        => self::posted( 'sdc_type' ),
			'label'       => self::posted( 'sdc_label' ),
			'description' => self::posted( 'sdc_description' ),
			'icon'        => self::posted( 'sdc_icon' ),
			'fields'      => self::posted_raw( 'sdc_fields' ),
			'view'        => self::posted_raw( Sdc_View_Editor::FIELD ),
			'css'         => self::posted_raw( Sdc_Style_Editor::FIELD ),
		);
	}

	/**
	 * Create a component from the form.
	 *
	 * @return true|WP_Error
	 */
	private static function create() {
		$dir = self::ensure_write_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$type = self::posted( 'sdc_type' );
		$type = self::validate_type( $type );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		if ( null !== Sdc_Schema::get( $type ) ) {
			return new WP_Error(
				'sdc_type_exists',
				sprintf(
					/* translators: %s: component type. */
					__( 'Eine Komponente mit dem Typ %s gibt es bereits.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		$label = self::posted( 'sdc_label' );

		if ( '' === $label ) {
			return new WP_Error( 'sdc_no_label', __( 'Die Komponente braucht einen Namen.', 'sdc-page-builder' ) );
		}

		$fields = self::parse_fields( self::posted_raw( 'sdc_fields' ), $type );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$definition = self::definition(
			$type,
			$label,
			self::posted( 'sdc_description' ),
			self::posted( 'sdc_icon' ),
			$fields
		);

		/*
		 * The view is checked before anything is written. A view with a typo
		 * would otherwise leave a component whose definition is saved and
		 * whose markup fatals on the next page load — and the error would
		 * point at the frontend instead of the form.
		 */
		$view_valid = Sdc_View_Editor::validate( $type, $fields );

		if ( is_wp_error( $view_valid ) ) {
			return $view_valid;
		}

		$written = self::write_definition( $dir, $type, $definition );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// After the definition: a view without one would be a file nothing
		// loads, and the component itself is the thing that has to exist.
		return Sdc_View_Editor::save( $dir, $type, $fields );
	}

	/**
	 * Duplicate an existing component into the writable directory.
	 *
	 * @return true|WP_Error
	 */
	private static function duplicate() {
		$dir = self::ensure_write_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$source = self::validate_type( self::posted( 'sdc_source' ) );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$component = Sdc_Schema::get( $source );

		if ( null === $component ) {
			return new WP_Error(
				'sdc_unknown_source',
				sprintf(
					/* translators: %s: component type. */
					__( 'Die Komponente %s gibt es nicht.', 'sdc-page-builder' ),
					$source
				)
			);
		}

		$file = Sdc_Schema::file_for_key( (string) $component['key'] );

		if ( '' === $file ) {
			return new WP_Error( 'sdc_no_file', __( 'Zur Quellkomponente wurde keine definition.json gefunden.', 'sdc-page-builder' ) );
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the component directories.

		if ( false === $raw ) {
			return new WP_Error(
				'sdc_unreadable',
				sprintf(
					/* translators: %s: absolute file path. */
					__( 'Die Datei %s ist nicht lesbar.', 'sdc-page-builder' ),
					$file
				)
			);
		}

		$definition = json_decode( (string) $raw, true );

		if ( ! is_array( $definition ) ) {
			return new WP_Error(
				'sdc_broken_source',
				sprintf(
					/* translators: %s: absolute file path. */
					__( 'Die Datei %s enthält kein gültiges JSON.', 'sdc-page-builder' ),
					$file
				)
			);
		}

		$type = self::validate_type( self::posted( 'sdc_type' ) );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		if ( null !== Sdc_Schema::get( $type ) ) {
			return new WP_Error(
				'sdc_type_exists',
				sprintf(
					/* translators: %s: component type. */
					__( 'Eine Komponente mit dem Typ %s gibt es bereits.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : array();

		$definition['key']         = Sdc_Schema::KEY_PREFIX . $type;
		$definition['title']       = (string) $component['label'] . ' (Kopie)';
		$definition['sdc_version'] = 1;
		$definition['fields']      = self::rekey_fields( $fields, $type );
		$definition['location']    = self::location();

		return self::write_definition( $dir, $type, $definition );
	}

	/**
	 * Write an edit back into the component's own definition file.
	 *
	 * The file is the basis, not `definition()`: `menu_order`, `position`,
	 * `location` and everything else this form does not show belong to the
	 * component and have to survive a save. Only the four values the form
	 * edits are replaced.
	 *
	 * @return true|WP_Error
	 */
	private static function update() {
		$type = self::validate_type( self::posted( 'sdc_type' ) );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$component = Sdc_Schema::get( $type );

		if ( null === $component ) {
			return new WP_Error(
				'sdc_unknown_type',
				sprintf(
					/* translators: %s: component type. */
					__( 'Die Komponente %s gibt es nicht.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		$file = Sdc_Schema::file_for_key( (string) $component['key'] );

		if ( '' === $file || ! self::in_write_dir( $file ) ) {
			return new WP_Error(
				'sdc_outside_write_dir',
				__( 'Diese Komponente liegt nicht in wp-content/sdc-components/. Theme- und Plugin-Komponenten sind schreibgeschützt, weil der nächste Deploy die Änderung überschreiben würde. Dupliziere sie, um sie hier zu bearbeiten.', 'sdc-page-builder' )
			);
		}

		$raw  = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the component directories; an unreadable file is reported below.
		$base = false === $raw ? null : json_decode( (string) $raw, true );

		if ( ! is_array( $base ) ) {
			return new WP_Error(
				'sdc_read_failed',
				sprintf(
					/* translators: %s: absolute file path. */
					__( 'Die Datei %s ist nicht lesbar oder enthält kein gültiges JSON. Ohne sie lässt sich die Komponente nicht speichern.', 'sdc-page-builder' ),
					$file
				)
			);
		}

		$base_fields = isset( $base['fields'] ) && is_array( $base['fields'] ) ? $base['fields'] : array();

		$label = self::posted( 'sdc_label' );

		if ( '' === $label ) {
			return new WP_Error( 'sdc_no_label', __( 'Die Komponente braucht einen Namen.', 'sdc-page-builder' ) );
		}

		// The stored field list is passed in so an unchanged name keeps its
		// stored key instead of getting a derived one.
		$fields = self::parse_fields( self::posted_raw( 'sdc_fields' ), $type, $base_fields );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$removed = self::removed_names( $base_fields, $fields );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle().
		$confirm = '1' === self::posted( 'sdc_confirm_fields' );

		/*
		 * A dropped field name is a silent data loss: the value stays in the
		 * page document but no longer belongs to a field, so it disappears
		 * from the editor without anyone deleting it. The save therefore
		 * stops here until the checkbox says the loss is intended.
		 */
		if ( ! empty( $removed ) && ! $confirm ) {
			return new WP_Error(
				'sdc_fields_removed',
				sprintf(
					/* translators: %s: comma separated list of field labels and names. */
					__( 'Diese Felder fehlen in der neuen Feldliste: %s. Ihre Inhalte bleiben auf den Seiten gespeichert, sind danach aber unsichtbar. Setze „Felder entfernen bestätigen", wenn das gewollt ist.', 'sdc-page-builder' ),
					implode( ', ', $removed )
				)
			);
		}

		$definition = $base;

		$definition['title']           = $label;
		$definition['sdc_description'] = self::posted( 'sdc_description' );
		$definition['sdc_icon']        = self::posted( 'sdc_icon' );
		$definition['fields']          = $fields;

		/*
		 * Both code layers are linted before the first file is written, so a
		 * rejected stylesheet cannot leave the component saved with half of
		 * the edit applied. The write lints again — this is about the order
		 * of the three files, not about trusting either answer.
		 */
		$view_valid = Sdc_View_Editor::validate( $type, $fields );

		if ( is_wp_error( $view_valid ) ) {
			$view_valid->add_data( array( 'sdc_tab' => 'view' ) );
			return $view_valid;
		}

		$style_valid = Sdc_Style_Editor::validate( $type );

		if ( is_wp_error( $style_valid ) ) {
			$style_valid->add_data( array( 'sdc_tab' => 'styles' ) );
			return $style_valid;
		}

		$written = self::write_definition( self::write_dir(), $type, $definition, true );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$viewed = Sdc_View_Editor::save( self::write_dir(), $type, $fields );

		if ( is_wp_error( $viewed ) ) {
			return $viewed;
		}

		return Sdc_Style_Editor::save( self::write_dir(), $type );
	}

	/**
	 * Names the stored fields have and a submitted list does not.
	 *
	 * Recursive, because a sub field keeps its value under its own name:
	 * dropping one loses what the editor stored in it. The entries are
	 * written the way the user sees them in the field list.
	 *
	 * @param array[] $base      Stored fields.
	 * @param array[] $submitted Submitted fields.
	 * @return string[]
	 */
	private static function removed_names( array $base, array $submitted ) {
		$before  = self::field_names( $base );
		$after   = self::field_names( $submitted );
		$removed = array();

		foreach ( $before as $name => $label ) {
			if ( isset( $after[ $name ] ) ) {
				continue;
			}

			$removed[] = $name === $label ? $name : $label . ' (' . $name . ')';
		}

		return $removed;
	}

	/**
	 * Field name => label, for a whole field tree.
	 *
	 * @param array[] $fields Field definitions.
	 * @param array   $names  Entries collected so far, for the recursive call.
	 * @return array
	 */
	private static function field_names( array $fields, array $names = array() ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}

			$name = (string) $field['name'];

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $names[ $name ] ) ) {
				$names[ $name ] = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? (string) $field['label'] : $name;
			}

			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$names = self::field_names( $field['sub_fields'], $names );
			}
		}

		return $names;
	}

	/**
	 * Delete a component that this screen owns.
	 *
	 * @return true|WP_Error
	 */
	private static function delete() {
		$type = self::validate_type( self::posted( 'sdc_type' ) );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$component = Sdc_Schema::get( $type );

		if ( null === $component ) {
			return new WP_Error(
				'sdc_unknown_component',
				sprintf(
					/* translators: %s: component type. */
					__( 'Die Komponente %s gibt es nicht.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		$file = Sdc_Schema::file_for_key( (string) $component['key'] );

		if ( ! self::in_write_dir( $file ) ) {
			return new WP_Error(
				'sdc_not_owned',
				__( 'Diese Komponente liegt nicht in wp-content/sdc-components/ — sie gehört zum Theme oder zum Plugin und wird dort gelöscht, nicht hier.', 'sdc-page-builder' )
			);
		}

		$counts = self::instance_counts();
		$pages  = isset( $counts[ $type ]['pages'] ) ? (int) $counts[ $type ]['pages'] : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle().
		$force = isset( $_POST['sdc_force'] ) && '1' === (string) wp_unslash( $_POST['sdc_force'] );

		if ( $pages > 0 && ! $force ) {
			return new WP_Error(
				'sdc_in_use',
				sprintf(
					/* translators: 1: number of pages, 2: component type. */
					__( 'Die Komponente %2$s steht auf %1$d Seiten. Setze „Auch bei Verwendung löschen", wenn das gewollt ist.', 'sdc-page-builder' ),
					$pages,
					$type
				)
			);
		}

		$folder = dirname( $file );

		// The .tmp is the leftover of a write that died before its rename;
		// leaving it behind would keep the folder from ever being removed.
		foreach ( array( $file . '.tmp', $file, $folder . '/render.php' ) as $target ) {
			if ( is_file( $target ) && ! unlink( $target ) ) {
				return new WP_Error(
					'sdc_unlink_failed',
					sprintf(
						/* translators: %s: absolute file path. */
						__( 'Die Datei %s ließ sich nicht löschen.', 'sdc-page-builder' ),
						$target
					)
				);
			}
		}

		// Only removes the directory when nothing else lives in it.
		@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A non-empty component folder is not an error.

		Sdc_Schema::flush();
		delete_transient( self::INSTANCE_TRANSIENT );

		return true;
	}

	/**
	 * Send one component's definition as a download.
	 */
	private static function export() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() in handle().
		$type = self::validate_type( isset( $_GET['sdc_type'] ) ? sanitize_key( wp_unslash( $_GET['sdc_type'] ) ) : '' );

		if ( is_wp_error( $type ) ) {
			self::redirect( 'error', $type->get_error_message() );
		}

		$component = Sdc_Schema::get( $type );
		$file      = null === $component ? '' : Sdc_Schema::file_for_key( (string) $component['key'] );

		if ( '' === $file || ! is_readable( $file ) ) {
			self::redirect( 'error', __( 'Für diese Komponente wurde keine lesbare definition.json gefunden.', 'sdc-page-builder' ) );
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the component directories.

		if ( false === $raw ) {
			self::redirect( 'error', __( 'Die Definitionsdatei ist nicht lesbar.', 'sdc-page-builder' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $type . '-definition.json"' );
		echo $raw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The file is the component definition verbatim; it is not HTML.
		exit;
	}

	/**
	 * Import a definition file from the browser.
	 *
	 * @return true|WP_Error
	 */
	private static function import() {
		$dir = self::ensure_write_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! isset( $_FILES['sdc_component_file'] ) ) {
			return new WP_Error( 'sdc_no_file', __( 'Es wurde keine Datei ausgewählt.', 'sdc-page-builder' ) );
		}

		$upload = (array) $_FILES['sdc_component_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below: upload error code, size and is_uploaded_file().

		if ( isset( $upload['error'] ) && UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			return new WP_Error( 'sdc_upload_failed', __( 'Der Upload ist fehlgeschlagen.', 'sdc-page-builder' ) );
		}

		if ( empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'sdc_upload_failed', __( 'Die hochgeladene Datei ist nicht angekommen.', 'sdc-page-builder' ) );
		}

		if ( isset( $upload['size'] ) && (int) $upload['size'] > self::MAX_IMPORT_BYTES ) {
			return new WP_Error( 'sdc_upload_too_large', __( 'Die Datei ist zu groß für eine Komponente.', 'sdc-page-builder' ) );
		}

		$raw      = file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the upload's temporary file.
		$imported = self::parse_definition( (string) $raw );

		if ( is_wp_error( $imported ) ) {
			return $imported;
		}

		$type = $imported['type'];

		if ( null !== Sdc_Schema::get( $type ) ) {
			return new WP_Error(
				'sdc_type_exists',
				sprintf(
					/* translators: %s: component type. */
					__( 'Eine Komponente mit dem Typ %s gibt es bereits. Import überschreibt nichts.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		return self::write_definition( $dir, $type, $imported['definition'] );
	}

	/**
	 * Validate an uploaded definition.
	 *
	 * @param string $raw File contents.
	 * @return array|WP_Error Type and definition.
	 */
	public static function parse_definition( $raw ) {
		$definition = json_decode( (string) $raw, true );

		if ( ! is_array( $definition ) ) {
			return new WP_Error(
				'sdc_import_json',
				sprintf(
					/* translators: %s: JSON parser message. */
					__( 'Die Datei ist kein gültiges JSON: %s', 'sdc-page-builder' ),
					json_last_error_msg()
				)
			);
		}

		$key = isset( $definition['key'] ) ? (string) $definition['key'] : '';

		if ( 0 !== strpos( $key, Sdc_Schema::KEY_PREFIX ) ) {
			return new WP_Error(
				'sdc_import_key',
				sprintf(
					/* translators: %s: key prefix. */
					__( 'Der Gruppen-Key fehlt oder beginnt nicht mit %s.', 'sdc-page-builder' ),
					Sdc_Schema::KEY_PREFIX
				)
			);
		}

		$type = self::validate_type( substr( $key, strlen( Sdc_Schema::KEY_PREFIX ) ) );

		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$title = isset( $definition['title'] ) ? trim( (string) $definition['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'sdc_import_title', __( 'Die Definition hat keinen Titel.', 'sdc-page-builder' ) );
		}

		$fields = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : array();
		$fields = self::rekey_fields( $fields, $type );

		$definition['key']         = Sdc_Schema::KEY_PREFIX . $type;
		$definition['title']       = $title;
		$definition['fields']      = $fields;
		$definition['location']    = self::location();
		$definition['sdc_version'] = isset( $definition['sdc_version'] ) ? max( 1, (int) $definition['sdc_version'] ) : 1;
		$definition['active']      = true;

		return array(
			'type'       => $type,
			'definition' => $definition,
		);
	}

	/**
	 * Validate a component type.
	 *
	 * Public because the tests exercise it directly; it has no side effects
	 * and no redirect path, so exposing it adds no attack surface.
	 *
	 * @param string $type Raw type.
	 * @return string|WP_Error
	 */
	public static function validate_type( $type ) {
		$type = (string) $type;

		if ( '' === $type ) {
			return new WP_Error( 'sdc_no_type', __( 'Es fehlt der Typ der Komponente.', 'sdc-page-builder' ) );
		}

		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $type ) ) {
			return new WP_Error(
				'sdc_bad_type',
				sprintf(
					/* translators: %s: submitted type. */
					__( 'Der Typ „%s" ist nicht erlaubt: nur Kleinbuchstaben, Ziffern, Binde- und Unterstriche, beginnend mit Buchstabe oder Ziffer.', 'sdc-page-builder' ),
					$type
				)
			);
		}

		// The type becomes a directory name and part of a field key.
		if ( strlen( $type ) > 40 ) {
			return new WP_Error( 'sdc_bad_type', __( 'Der Typ ist zu lang (maximal 40 Zeichen).', 'sdc-page-builder' ) );
		}

		return $type;
	}

	/**
	 * Build a complete field group definition.
	 *
	 * @param string  $type        Component type.
	 * @param string  $label       Title shown in the picker.
	 * @param string  $description Optional description.
	 * @param string  $icon        Optional dashicon name.
	 * @param array[] $fields      Field definitions.
	 * @return array
	 */
	private static function definition( $type, $label, $description, $icon, array $fields ) {
		return array(
			'key'                  => Sdc_Schema::KEY_PREFIX . $type,
			'title'                => $label,
			'sdc_description'      => $description,
			'sdc_icon'             => '' === $icon ? 'layout' : $icon,
			'sdc_version'          => 1,
			'fields'               => $fields,
			'location'             => self::location(),
			'menu_order'           => count( Sdc_Schema::components() ),
			'position'             => 'normal',
			'style'                => 'default',
			'label_placement'      => 'top',
			'instruction_placement' => 'label',
			'active'               => true,
			'description'          => '',
		);
	}

	/**
	 * The location rule that keeps the host from rendering the group on its
	 * own: no admin screen matches `sdc_component == 1`, the builder renders
	 * the fields itself.
	 *
	 * @return array[]
	 */
	private static function location() {
		return array(
			array(
				array(
					'param'    => 'sdc_component',
					'operator' => '==',
					'value'    => '1',
				),
			),
		);
	}

	/**
	 * Prefix every field key in a component with the component's type.
	 *
	 * Two components must never share a field key: the host keeps field
	 * objects in a key-indexed store, so a duplicate key makes one component
	 * resolve the other's field.
	 *
	 * @param array[] $fields Field definitions.
	 * @param string  $type   Component type.
	 * @return array[]
	 */
	private static function rekey_fields( array $fields, $type ) {
		$out = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? self::clean_name( $field['name'] ) : '';

			if ( '' !== $name ) {
				$field['key'] = 'field_sdc_' . $type . '_' . $name;
			}

			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::rekey_fields( $field['sub_fields'], $type );
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Turn pasted JSON into field definitions.
	 *
	 * @param string  $raw   Posted JSON.
	 * @param string  $type  Component type.
	 * @param array[] $base  Fields already stored in the file, for key reuse.
	 * @return array[]|WP_Error
	 */
	public static function parse_fields( $raw, $type, array $base = array() ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'sdc_fields_json',
				sprintf(
					/* translators: %s: JSON parser message. */
					__( 'Die Felder sind kein gültiges JSON: %s', 'sdc-page-builder' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $decoded ) || array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			return new WP_Error(
				'sdc_fields_shape',
				__( 'Die Felder müssen eine Liste sein: [ {…}, {…} ].', 'sdc-page-builder' )
			);
		}

		$names  = array();
		$fields = self::normalize_fields( $decoded, (string) $type, 1, $names, $base );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return $fields;
	}

	/**
	 * Validate one level of pasted fields.
	 *
	 * @param array[] $fields       Raw field entries.
	 * @param string  $type         Component type.
	 * @param int     $level        Nesting level, for error messages.
	 * @param array   $names        Names already used on this level.
	 * @param array[] $base         Stored fields on this level, for key reuse.
	 * @return array[]|WP_Error
	 */
	private static function normalize_fields( array $fields, $type, $level, array &$names, array $base = array() ) {
		$out = array();

		foreach ( $fields as $index => $field ) {
			$position = (int) $index + 1;

			if ( ! is_array( $field ) ) {
				return new WP_Error(
					'sdc_fields_entry',
					sprintf(
						/* translators: 1: nesting level, 2: entry number. */
						__( 'Feld %2$d auf Ebene %1$d ist kein Objekt.', 'sdc-page-builder' ),
						$level,
						$position
					)
				);
			}

			$name = isset( $field['name'] ) ? self::clean_name( $field['name'] ) : '';

			if ( '' === $name ) {
				return new WP_Error(
					'sdc_fields_name',
					sprintf(
						/* translators: 1: entry number, 2: nesting level. */
						__( 'Feld %1$d auf Ebene %2$d hat keinen gültigen Namen (a–z, 0–9, _).', 'sdc-page-builder' ),
						$position,
						$level
					)
				);
			}

			if ( isset( $names[ $name ] ) ) {
				return new WP_Error(
					'sdc_fields_duplicate',
					sprintf(
						/* translators: %s: field name. */
						__( 'Der Feldname %s kommt doppelt vor. Zwei Felder einer Ebene brauchen verschiedene Namen.', 'sdc-page-builder' ),
						$name
					)
				);
			}
			$names[ $name ] = true;

			$field_type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';

			if ( '' === $field_type ) {
				return new WP_Error(
					'sdc_fields_type',
					sprintf(
						/* translators: %s: field name. */
						__( 'Feld %s hat keinen Typ.', 'sdc-page-builder' ),
						$name
					)
				);
			}

			if ( ! self::type_exists( $field_type ) ) {
				return new WP_Error(
					'sdc_fields_type',
					sprintf(
						/* translators: 1: field type, 2: field name. */
						__( 'Den Feldtyp „%1$s" (Feld %2$s) kennt der Host nicht.', 'sdc-page-builder' ),
						$field_type,
						$name
					)
				);
			}

			$stored = self::stored_field( $base, $name );

			/*
			 * The key is what the page's saved values are looked up
			 * by, so it follows the stored file whenever there is one:
			 * a component written by hand may use any key, and
			 * replacing it with the derived one would hide every value
			 * already saved on a page. Only a field the file does not
			 * know gets a fresh key.
			 */
			$normalized = array(
				'key'   => isset( $stored['key'] ) && '' !== (string) $stored['key'] ? (string) $stored['key'] : 'field_sdc_' . $type . '_' . $name,
				'label' => isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? (string) $field['label'] : $name,
				'name'  => $name,
				'type'  => $field_type,
			);

			foreach ( self::FIELD_PROPS as $prop ) {
				if ( isset( $field[ $prop ] ) ) {
					$normalized[ $prop ] = $field[ $prop ];
				}
			}

			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$sub_names = array();
				$sub_base  = isset( $stored['sub_fields'] ) && is_array( $stored['sub_fields'] ) ? $stored['sub_fields'] : array();
				$sub       = self::normalize_fields( $field['sub_fields'], $type, $level + 1, $sub_names, $sub_base );

				if ( is_wp_error( $sub ) ) {
					return $sub;
				}

				$normalized['sub_fields'] = $sub;
			}

			$out[] = $normalized;
		}

		return $out;
	}

	/**
	 * The stored entry of a field, matched by name.
	 *
	 * @param array[] $base Stored fields on one level.
	 * @param string  $name Field name.
	 * @return array Empty when the file does not know the name.
	 */
	private static function stored_field( array $base, $name ) {
		foreach ( $base as $entry ) {
			if ( is_array( $entry ) && isset( $entry['name'] ) && (string) $entry['name'] === (string) $name ) {
				return $entry;
			}
		}

		return array();
	}

	/**
	 * Field names as the host accepts them.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_name( $name ) {
		$name = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) );
		return (string) $name;
	}

	/**
	 * Does the host know this field type?
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	private static function type_exists( $type ) {
		if ( ! function_exists( 'acf_get_field_type' ) ) {
			return true;
		}

		return (bool) acf_get_field_type( $type );
	}

	/**
	 * Field types the host offers, for the help panel.
	 *
	 * @return string[]
	 */
	private static function field_types() {
		if ( ! function_exists( 'acf_get_field_types' ) ) {
			return array();
		}

		$types = acf_get_field_types();
		$names = is_array( $types ) ? array_keys( $types ) : array();

		sort( $names );

		return array_map( 'strval', $names );
	}

	/**
	 * Write a component directory with its definition file.
	 *
	 * `$overwrite` is off for every task that creates a component: an
	 * existing definition is another component's definition, and replacing
	 * it would silently take its content away. The edit task is the one case
	 * where replacing is the point.
	 *
	 * @param string $dir        Write directory.
	 * @param string $type       Component type.
	 * @param array  $definition Field group definition.
	 * @param bool   $overwrite  Whether an existing definition may be replaced.
	 * @return true|WP_Error
	 */
	private static function write_definition( $dir, $type, array $definition, $overwrite = false ) {
		$folder  = trailingslashit( $dir ) . $type;
		$file    = $folder . '/definition.json';
		$existed = is_dir( $folder );

		// A folder without a definition is the leftover of an attempt that
		// died between mkdir and the rename. Writing into it finishes that
		// attempt; refusing would block every retry with "already exists"
		// while the folder stays behind forever.
		if ( is_file( $file ) && ! $overwrite ) {
			return new WP_Error(
				'sdc_folder_exists',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s existiert bereits.', 'sdc-page-builder' ),
					$folder
				)
			);
		}

		if ( ! $existed && ! wp_mkdir_p( $folder ) ) {
			return new WP_Error(
				'sdc_mkdir_failed',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s ließ sich nicht anlegen.', 'sdc-page-builder' ),
					$folder
				)
			);
		}

		$json = wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			self::discard_folder( $folder, $existed );

			return new WP_Error( 'sdc_encode_failed', __( 'Die Definition ließ sich nicht als JSON schreiben.', 'sdc-page-builder' ) );
		}

		/*
		 * Write next to the target and rename. A reader must never see half a
		 * definition: the loader would read a truncated file, fail to decode
		 * it and drop the component, so a crash mid-write would silently cost
		 * the component instead of leaving it untouched. The rename is atomic
		 * within one filesystem.
		 */
		$tmp  = $file . '.tmp';
		$done = file_put_contents( $tmp, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a component file; WP_Filesystem would ask for credentials in the admin.

		if ( false === $done || ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename; a failure is reported on the next lines.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temporary file of a failed write.
			self::discard_folder( $folder, $existed );

			return new WP_Error(
				'sdc_write_failed',
				sprintf(
					/* translators: %s: absolute file path. */
					__( 'Die Datei %s ließ sich nicht schreiben.', 'sdc-page-builder' ),
					$file
				)
			);
		}

		Sdc_Schema::flush();
		delete_transient( self::INSTANCE_TRANSIENT );

		return true;
	}

	/**
	 * Remove a component folder a failed write created.
	 *
	 * A folder that was already there is left alone: it may hold a
	 * `render.php` the failure has nothing to do with.
	 *
	 * @param string $folder  Absolute path.
	 * @param bool   $existed Whether the folder was there before the write.
	 */
	private static function discard_folder( $folder, $existed ) {
		if ( $existed || ! is_dir( $folder ) ) {
			return;
		}

		// Only removes the directory when the failed write left nothing in it.
		@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A folder that is not empty is not an error.
	}

	/**
	 * Where and how often each component is actually placed.
	 *
	 * Placement is the content of a page — blocks now, the stored document
	 * only for a page the migration has not reached. Reading `_sdc_data` for
	 * both had two consequences this fixes:
	 *
	 * - Revisions carry a copy of the same meta row. One page saved sixteen
	 *   times reported sixteen pages with sixteen instances; the catalog said
	 *   "18 Seiten" for a site with two pages carrying the component.
	 * - On a migrated page the document is a backup. Counting it kept a
	 *   component "in use" after the author had deleted every block using it,
	 *   so the delete guard never let go of a component nothing placed.
	 *
	 * One query for the post ids, one meta read per page (answered by the
	 * object cache), and the result sits in a transient until a post or a
	 * document changes.
	 *
	 * @return array[] Type => array( 'pages' => int, 'components' => int ).
	 */
	private static function instance_counts() {
		$cached = get_transient( self::INSTANCE_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post_ids = get_posts(
			array(
				'post_type'   => Sdc_Editor::post_types(),
				// No revisions, no autosaves, no trash: all three either copy
				// a page or no longer render, and each would count as one.
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => 'none',
			)
		);

		$counts = array();
		$pages  = array();

		foreach ( (array) $post_ids as $post_id ) {
			foreach ( self::types_in_post( (int) $post_id ) as $type ) {
				$counts[ $type ]['components']    = isset( $counts[ $type ]['components'] ) ? $counts[ $type ]['components'] + 1 : 1;
				$pages[ $type ][ (int) $post_id ] = true;
			}
		}

		foreach ( $pages as $type => $ids ) {
			$counts[ $type ]['pages'] = count( $ids );
		}

		foreach ( $counts as $type => $unused ) {
			$counts[ $type ]['pages'] = isset( $counts[ $type ]['pages'] ) ? $counts[ $type ]['pages'] : 0;
		}

		set_transient( self::INSTANCE_TRANSIENT, $counts, 5 * MINUTE_IN_SECONDS );

		return $counts;
	}

	/**
	 * Component types one post shows, one entry per instance.
	 *
	 * Public because it is the answer to "may this component be deleted" and
	 * the same question a test asks.
	 *
	 * @param int $post_id Post ID.
	 * @return string[] Types in placement order.
	 */
	public static function types_in_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$types = self::types_in_content( (string) $post->post_content );

		/*
		 * The document is the fallback only while the page has not been
		 * migrated; after that it is a backup. A component the author deleted
		 * from the canvas has to stop counting, or nothing is ever deletable.
		 */
		if ( empty( $types ) && ! Sdc_Migration::is_migrated( $post_id ) ) {
			$types = self::types_in_document( $post_id );
		}

		return $types;
	}

	/**
	 * Component types in block content.
	 *
	 * Walks `innerBlocks` as well: a component inside a column or a group is
	 * placed just the same. The old `sdc/component` block names its type in an
	 * attribute instead of the block name.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	private static function types_in_content( $content ) {
		if ( ! Sdc_Migration::has_blocks( (string) $content ) ) {
			return array();
		}

		return self::walk_blocks( parse_blocks( (string) $content ) );
	}

	/**
	 * Collect component types from parsed blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function walk_blocks( array $blocks ) {
		$types = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( Sdc_Block::LEGACY === $name ) {
				$legacy = isset( $block['attrs']['type'] ) ? (string) $block['attrs']['type'] : '';

				if ( '' !== $legacy ) {
					$types[] = $legacy;
				}
			} elseif ( 0 === strpos( $name, Sdc_Block::PREFIX ) ) {
				$types[] = substr( $name, strlen( Sdc_Block::PREFIX ) );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$types = array_merge( $types, self::walk_blocks( $block['innerBlocks'] ) );
			}
		}

		return $types;
	}

	/**
	 * Component types in a post's stored document.
	 *
	 * Both shapes are read: a PHP array in one meta row (how `Sdc_Store`
	 * writes) and an older JSON string. A counter that understood only one of
	 * them reported zero and silently disabled the in-use guard.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function types_in_document( $post_id ) {
		$document = Sdc_Store::get_data( (int) $post_id );

		if ( empty( $document['components'] ) || ! is_array( $document['components'] ) ) {
			return array();
		}

		$types = array();

		foreach ( $document['components'] as $component ) {
			if ( is_array( $component ) && ! empty( $component['type'] ) ) {
				$types[] = (string) $component['type'];
			}
		}

		return $types;
	}

	/**
	 * How a usage count reads to a human.
	 *
	 * "3 Seiten, 4 Instanzen" is a claim about placement, so the wording has
	 * to match what is counted — a component placed twice on one page is one
	 * page and two instances.
	 *
	 * @param array $row Row with 'pages' and 'instances'.
	 * @return string
	 */
	private static function usage_label( array $row ) {
		$pages     = isset( $row['pages'] ) ? (int) $row['pages'] : 0;
		$instances = isset( $row['instances'] ) ? (int) $row['instances'] : 0;

		if ( $pages < 1 || $instances < 1 ) {
			return esc_html__( 'nicht platziert', 'sdc-page-builder' );
		}

		return sprintf(
			/* translators: 1: number of pages, 2: number of placements on those pages. */
			_n( '%1$d Seite, %2$d Instanz', '%1$d Seiten, %2$d Instanzen', $instances, 'sdc-page-builder' ),
			$pages,
			$instances
		);
	}

	/**
	 * A path relative to the WordPress root, for display.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $path, $root ) ) {
			return substr( $path, strlen( $root ) );
		}

		return $path;
	}

	/**
	 * Read a posted text field.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in handle(); all callers run after that.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by check_admin_referer(); sanitized on the next line.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Read a posted field without sanitizing: JSON has to survive intact.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted_raw( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by check_admin_referer(); the value is parsed as JSON, which rejects everything else.
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * Errors are stored in a transient behind a one-time token rather than
	 * passed in the URL: a URL is echoed into the page, and the detail text
	 * comes from user input.
	 *
	 * @param string $code   Notice code.
	 * @param string $detail Optional detail text.
	 * @param array  $extra  Extra query args, e.g. the edit form to return to.
	 * @param array  $draft  Optional posted values the form shows again.
	 */
	private static function redirect( $code, $detail = '', array $extra = array(), array $draft = array() ) {
		$args = array(
			'page'       => self::MENU_SLUG,
			'sdc_notice' => $code,
		);

		if ( '' !== $detail ) {
			/*
			 * Lowercase on purpose: the reading side runs the token through
			 * sanitize_key(), which lowercases. A mixed-case token would be
			 * stored under a different key than it is looked up with, and
			 * every error message would silently lose its reason.
			 */
			$token = strtolower( wp_generate_password( 12, false, false ) );

			set_transient( 'sdc_pb_notice_' . $token, $detail, 5 * MINUTE_IN_SECONDS );

			$args['sdc_detail'] = $token;

			// Same one-time token as the message, so the draft cannot be
			// read by guessing a second one.
			if ( ! empty( $draft ) ) {
				set_transient( 'sdc_pb_draft_' . $token, $draft, 5 * MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

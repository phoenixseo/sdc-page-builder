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

		// Instance counts are derived data: one meta write invalidates them.
		add_action( 'added_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'clear_counts' ), 10, 3 );
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SDC-Komponenten', 'sdc-page-builder' ) . '</h1>';

		self::render_notice();

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
				esc_html__( 'Instanzen', 'sdc-page-builder' ),
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
			esc_html(
				sprintf(
					/* translators: 1: number of pages, 2: number of instances. */
					__( '%1$d Seiten, %2$d Instanzen', 'sdc-page-builder' ),
					(int) $row['pages'],
					(int) $row['instances']
				)
			)
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

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::HOOK,
					'sdc_task' => 'export',
					'sdc_type' => rawurlencode( $type ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
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
		echo '<textarea id="sdc_fields" name="sdc_fields" rows="10" class="large-text code" spellcheck="false"></textarea>';
		echo '<p class="description">' . esc_html__( 'JSON-Liste. Keys und Labels werden ergänzt, wenn sie fehlen. Leer lassen für eine Komponente ohne Felder.', 'sdc-page-builder' ) . '</p>';
		self::render_fields_help();
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Komponente anlegen', 'sdc-page-builder' ) );
		echo '</form>';
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

		check_admin_referer( self::NONCE_ACTION );

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
		} elseif ( 'duplicate' === $task ) {
			$result = self::duplicate();
		} elseif ( 'delete' === $task ) {
			$result = self::delete();
		} elseif ( 'import' === $task ) {
			$result = self::import();
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( 'error', $result->get_error_message() );
		}

		self::redirect( $task );
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

		return self::write_definition( $dir, $type, $definition );
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

		foreach ( array( $file, $folder . '/render.php' ) as $target ) {
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
	 * @param string $raw  JSON list.
	 * @param string $type Component type.
	 * @return array[]|WP_Error
	 */
	public static function parse_fields( $raw, $type ) {
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
		$fields = self::normalize_fields( $decoded, (string) $type, 1, $names );

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
	 * @return array[]|WP_Error
	 */
	private static function normalize_fields( array $fields, $type, $level, array &$names ) {
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

			$normalized = array(
				'key'   => 'field_sdc_' . $type . '_' . $name,
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
				$sub       = self::normalize_fields( $field['sub_fields'], $type, $level + 1, $sub_names );

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
	 * @param string $dir        Write directory.
	 * @param string $type       Component type.
	 * @param array  $definition Field group definition.
	 * @return true|WP_Error
	 */
	private static function write_definition( $dir, $type, array $definition ) {
		$folder = trailingslashit( $dir ) . $type;

		if ( is_dir( $folder ) ) {
			return new WP_Error(
				'sdc_folder_exists',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s existiert bereits.', 'sdc-page-builder' ),
					$folder
				)
			);
		}

		if ( ! wp_mkdir_p( $folder ) ) {
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
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleaning up a folder that was just created.

			return new WP_Error( 'sdc_encode_failed', __( 'Die Definition ließ sich nicht als JSON schreiben.', 'sdc-page-builder' ) );
		}

		$file = $folder . '/definition.json';
		$done = file_put_contents( $file, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a component file; WP_Filesystem would ask for credentials in the admin.

		if ( false === $done ) {
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleaning up a folder that was just created.

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
	 * Per-type usage counts across all stored documents.
	 *
	 * One query, not one per page: the document is a JSON string in a single
	 * meta row, so counting means decoding, and decoding everything on every
	 * page load would grow with the site. Cached until a document changes.
	 *
	 * @return array[] Type => array( 'pages' => int, 'components' => int ).
	 */
	private static function instance_counts() {
		$cached = get_transient( self::INSTANCE_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One aggregate read over the meta table; the result is cached in a transient.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Sdc_Store::META_KEY
			)
		);

		$counts = array();
		$pages  = array();

		foreach ( (array) $rows as $row ) {
			$document = json_decode( (string) $row->meta_value, true );

			if ( ! is_array( $document ) || empty( $document['components'] ) ) {
				continue;
			}

			foreach ( (array) $document['components'] as $component ) {
				if ( ! is_array( $component ) || empty( $component['type'] ) ) {
					continue;
				}

				$type = (string) $component['type'];

				$counts[ $type ]['components']         = isset( $counts[ $type ]['components'] ) ? $counts[ $type ]['components'] + 1 : 1;
				$pages[ $type ][ (int) $row->post_id ] = true;
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
	 */
	private static function redirect( $code, $detail = '' ) {
		$args = array(
			'page'       => self::MENU_SLUG,
			'sdc_notice' => $code,
		);

		if ( '' !== $detail ) {
			$token = wp_generate_password( 12, false, false );

			set_transient( 'sdc_pb_notice_' . $token, $detail, 5 * MINUTE_IN_SECONDS );

			$args['sdc_detail'] = $token;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

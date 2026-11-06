<?php
/**
 * Component views: the `render.php` that sits next to a component's JSON.
 *
 * The builder can render a component from its field definitions alone, but
 * that output is generic by design — it knows a field is a textarea, not that
 * this textarea is a quotation. A view is where the markup of one component
 * type lives. `Sdc_Frontend` already looks for `render.php` before it falls
 * back to the generic output; this class supplies everything around that
 * lookup:
 *
 *   - the runtime helpers a view calls (`sdc_text()`, `sdc_each()` …), so a
 *     view writes escaped output by default instead of remembering to;
 *   - the read/write path, so the view is editable in the admin without FTP;
 *   - `lint()`, which rejects a syntax error *before* the file lands on disk;
 *   - `scaffold()`, which turns the field list into a working view, so the
 *     first version of a new component is something to adjust rather than
 *     something to write from an empty box.
 *
 * Two rules hold the design together:
 *
 *   1. A view is code. Whoever may edit it may run anything on the server, so
 *      editing is gated on `manage_options` and can be switched off entirely
 *      with `SDC_PB_ALLOW_VIEW_EDIT` (false) or the `sdc_pb_can_edit_view`
 *      filter.
 *   2. An empty view means "use the generic output". Deleting the file is
 *      therefore the way back, and never leaves a component without markup.
 *
 * The helpers are global functions with the plugin's `sdc_` prefix: they are
 * called from view files, where a class name would be noise.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read, write, lint, generate and support component views.
 */
class Sdc_View {

	/**
	 * File name of a component's view, inside its component directory.
	 */
	const FILE = 'render.php';

	/**
	 * File name of a component's stylesheet, inside its component directory.
	 */
	const STYLE = 'component.css';

	/**
	 * Line that separates the plugin's file header from the editable body.
	 *
	 * Everything above it is written by the plugin; everything below it is
	 * what the admin screen shows and saves. Without a marker the round trip
	 * would have to guess which part of a hand-written file is "the body".
	 */
	const BODY_MARKER = '/* sdc-view:body */';

	/**
	 * Maximum container nesting the generator produces.
	 *
	 * Mirrors `Sdc_Renderer::MAX_DEPTH`: deeper structures cannot be edited
	 * in the builder, so generating a view for them would advertise a depth
	 * the rest of the plugin refuses.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Component contexts on the include stack.
	 *
	 * A view reads `$data` as a local variable, but the helpers take no
	 * arguments, so they need to know which component is being rendered. A
	 * stack rather than a single value: a view may render another component
	 * through `Sdc_Frontend::component_fields_html()`.
	 *
	 * @var array[] Each entry: array( 'type' => string, 'data' => array ).
	 */
	private static $stack = array();

	/**
	 * Push a component context for the duration of one render.
	 *
	 * @param string $type Component type.
	 * @param array  $data Stored values of this instance.
	 */
	public static function push( $type, array $data ) {
		self::$stack[] = array(
			'type' => (string) $type,
			'data' => $data,
		);
	}

	/**
	 * Drop the context pushed by the matching `push()`.
	 */
	public static function pop() {
		array_pop( self::$stack );
	}

	/**
	 * The innermost context.
	 *
	 * @return array|null
	 */
	public static function context() {
		$count = count( self::$stack );

		return $count > 0 ? self::$stack[ $count - 1 ] : null;
	}

	/**
	 * Data of the innermost context.
	 *
	 * @return array|null
	 */
	public static function data() {
		$context = self::context();

		return null === $context ? null : $context['data'];
	}

	/**
	 * Type of the innermost context.
	 *
	 * @return string
	 */
	public static function type() {
		$context = self::context();

		return null === $context ? '' : $context['type'];
	}

	/**
	 * Absolute path of a component's view file.
	 *
	 * @param string $dir  Component root directory.
	 * @param string $type Component type.
	 * @return string Empty string for an unusable type.
	 */
	public static function file( $dir, $type ) {
		$type = (string) $type;

		// The type becomes a path segment. Anything but a plain slug would
		// let a stored document or a form post write outside the component
		// directory.
		if ( ! self::valid_type( $type ) ) {
			return '';
		}

		$dir = untrailingslashit( (string) $dir );

		if ( '' === $dir ) {
			return '';
		}

		return $dir . '/' . $type . '/' . self::FILE;
	}

	/**
	 * Whether a component type may be used as a path segment.
	 *
	 * @param string $type Component type.
	 * @return bool
	 */
	private static function valid_type( $type ) {
		$type = (string) $type;

		return '' !== $type && (bool) preg_match( '/^[A-Za-z0-9_\-]+$/', $type );
	}

	/**
	 * Absolute path of a component's stylesheet, whether or not it exists.
	 *
	 * @param string $dir  Component root directory.
	 * @param string $type Component type.
	 * @return string Empty string for an unusable type.
	 */
	public static function style_file( $dir, $type ) {
		$file = self::file( $dir, $type );

		if ( '' === $file ) {
			return '';
		}

		return substr( $file, 0, -strlen( self::FILE ) ) . self::STYLE;
	}

	/**
	 * The stylesheet of a component, wherever the site keeps it.
	 *
	 * Same lookup order as `Sdc_Schema::template()`: a child theme overrides
	 * the parent theme, and both override `wp-content/sdc-components/`. The
	 * first directory that has a `component.css` for this type wins, so the
	 * markup and its styles come from the same component directory.
	 *
	 * @param string $type Component type.
	 * @return string Absolute path, or an empty string when there is none.
	 */
	public static function style( $type ) {
		// Validates the type once; a path separator in it would otherwise
		// turn the lookup into a read outside the component directories.
		if ( ! self::valid_type( $type ) ) {
			return '';
		}

		foreach ( Sdc_Schema::user_dirs() as $dir ) {
			$file = self::style_file( $dir, $type );

			if ( '' !== $file && is_readable( $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * The editable body of a view file.
	 *
	 * @param string $file Absolute path.
	 * @return string Body, or an empty string when the file is missing.
	 */
	public static function read( $file ) {
		$file = (string) $file;

		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}

		$code = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A view file inside the component directories; a failure is reported as an empty body.

		if ( false === $code ) {
			return '';
		}

		return self::body_of( (string) $code );
	}

	/**
	 * Everything below the body marker, or the file without its opening tag.
	 *
	 * A hand-written file has no marker. Taking everything after `<?php` keeps
	 * such a file editable instead of showing an empty box that would overwrite
	 * it on the next save.
	 *
	 * @param string $code Full file contents.
	 * @return string
	 */
	public static function body_of( $code ) {
		$code = (string) $code;
		$pos  = strpos( $code, self::BODY_MARKER );

		if ( false !== $pos ) {
			/*
			 * The marker sits in front of the header's closing tag, so the
			 * body starts after that tag. Both are part of the file, not the
			 * body. Written as an escape so this comment cannot close PHP.
			 */
			$code = ltrim( substr( $code, $pos + strlen( self::BODY_MARKER ) ), " 	" );

			if ( 0 === strpos( $code, '?>' ) ) {
				$code = substr( $code, 2 );
			}
		} else {
			$open = strpos( $code, '<?php' );

			if ( false !== $open ) {
				$code = substr( $code, $open + 5 );
			}
		}

		$code = trim( (string) $code, "\r\n" );

		return '' === $code ? '' : $code . "\n";
	}

	/**
	 * Strip what a body must not carry, so it survives the round trip.
	 *
	 * A body is a template: markup with `<?php … ?>` islands, exactly like a
	 * theme file. The file wrapper opens PHP for its header and closes it
	 * again, so the body does not have to start with any tag — and a body
	 * pasted together with its own header comment still ends up usable.
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	public static function normalize( $body ) {
		$body = (string) $body;

		// A BOM from a copy-paste through a text editor is invisible and
		// would be printed as output before any markup.
		if ( 0 === strpos( $body, "\xEF\xBB\xBF" ) ) {
			$body = substr( $body, 3 );
		}

		$body = ltrim( $body, " 	\r\n" );

		// A pasted file may still begin with the plugin's own header. Dropping
		// it keeps the marker the only one in the file, so reading the file
		// back cannot pick the wrong comment.
		$body = preg_replace( '#^<\?php\s*/\*\*(?:(?!\*/).)*\*/\s*#s', '', $body );
		$body = preg_replace( '#^' . preg_quote( self::BODY_MARKER, '#' ) . '\s*#', '', (string) $body );

		$body = rtrim( (string) $body );

		return '' === $body ? '' : $body . "\n";
	}

	/**
	 * Full file contents for a body.
	 *
	 * The wrapper owns the opening tag and closes it again, so the body is a
	 * template and never has to think about PHP's entry point.
	 *
	 * @param string $type Component type, for the header comment.
	 * @param string $body Normalised body.
	 * @return string
	 */
	public static function wrap( $type, $body ) {
		$header = "<?php\n"
			. "/**\n"
			. " * Ansicht der Komponente \"%s\".\n"
			. " *\n"
			. " * Verwaltet über den Komponenten-Screen. Verfügbar sind \$type, \$data,\n"
			. " * \$component sowie die sdc_*-Helfer (siehe Sdc_View::helpers()).\n"
			. " */\n"
			. self::BODY_MARKER . " ?>\n";

		return sprintf( $header, str_replace( '*/', '', (string) $type ) ) . self::normalize( $body );
	}

	/**
	 * Does this body parse as PHP?
	 *
	 * `token_get_all()` with `TOKEN_PARSE` runs the parser without executing
	 * anything: a view with a typo is refused at save time and the page keeps
	 * working, instead of a fatal error appearing the next time somebody opens
	 * it.
	 *
	 * @param string $body View body.
	 * @return true|WP_Error
	 */
	public static function lint( $body ) {
		$code = self::normalize( $body );

		if ( '' === $code ) {
			return true;
		}

		try {
			/*
			 * The body is a template, so it is parsed in HTML mode: a leading
			 * `?>` would otherwise make the whole body an expression. The
			 * prefix stays on one line, so a reported line number matches the
			 * line the author sees.
			 */
			token_get_all( '<?php ?>' . $code, TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error(
				'sdc_view_syntax',
				sprintf(
					/* translators: 1: line number, 2: parser message. */
					__( 'Die Ansicht hat einen PHP-Syntaxfehler in Zeile %1$d: %2$s', 'sdc-page-builder' ),
					max( 1, (int) $error->getLine() ),
					$error->getMessage()
				)
			);
		} catch ( Throwable $error ) {
			return new WP_Error(
				'sdc_view_syntax',
				sprintf(
					/* translators: %s: parser message. */
					__( 'Die Ansicht ließ sich nicht prüfen: %s', 'sdc-page-builder' ),
					$error->getMessage()
				)
			);
		}

		return true;
	}

	/**
	 * Write a component's view.
	 *
	 * An empty body removes the file: "no view" is the generic output, and a
	 * file that only holds a header would suppress it for no reason.
	 *
	 * @param string $dir  Component root directory.
	 * @param string $type Component type.
	 * @param string $body View body.
	 * @return true|WP_Error
	 */
	public static function write( $dir, $type, $body ) {
		$file = self::file( $dir, $type );

		if ( '' === $file ) {
			return new WP_Error( 'sdc_view_type', __( 'Dieser Komponententyp ist kein gültiger Dateiname.', 'sdc-page-builder' ) );
		}

		$code  = self::normalize( $body );
		$valid = self::lint( $code );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( '' === $code ) {
			if ( is_file( $file ) && ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a view file; a failure is reported on the next line.
				return new WP_Error(
					'sdc_view_delete_failed',
					sprintf(
						/* translators: %s: absolute file path. */
						__( 'Die Datei %s ließ sich nicht entfernen.', 'sdc-page-builder' ),
						$file
					)
				);
			}

			return true;
		}

		$folder = dirname( $file );

		if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
			return new WP_Error(
				'sdc_view_mkdir_failed',
				sprintf(
					/* translators: %s: absolute directory path. */
					__( 'Der Ordner %s ließ sich nicht anlegen.', 'sdc-page-builder' ),
					$folder
				)
			);
		}

		$contents = self::wrap( $type, $code );

		/*
		 * Same write discipline as `definition.json`: write next to the target
		 * and rename, so a crash mid-write cannot leave half a view that the
		 * next page load would fatal on.
		 */
		$tmp  = $file . '.tmp';
		$done = file_put_contents( $tmp, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a component file; WP_Filesystem would ask for credentials in the admin.

		if ( false === $done || ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename; a failure is reported here.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temporary file of a failed write.
			return new WP_Error(
				'sdc_view_write_failed',
				sprintf(
					/* translators: %s: absolute file path. */
					__( 'Die Datei %s ließ sich nicht schreiben.', 'sdc-page-builder' ),
					$file
				)
			);
		}

		return true;
	}

	/**
	 * May the current user edit views?
	 *
	 * A view is PHP that the server executes on every page load. That is the
	 * same trust level as the theme editor, so the gate is the same one:
	 * `manage_options`. `SDC_PB_ALLOW_VIEW_EDIT` switched to false removes the
	 * capability from the code base; the filter is the per-site answer.
	 *
	 * @param string $type Component type, for a type-specific decision.
	 * @return bool
	 */
	public static function can_edit( $type = '' ) {
		$allowed = ! defined( 'SDC_PB_ALLOW_VIEW_EDIT' ) || (bool) SDC_PB_ALLOW_VIEW_EDIT;
		$can     = $allowed && current_user_can( Sdc_Admin::CAPABILITY );

		/**
		 * Filters whether views may be edited in the admin.
		 *
		 * @param bool   $can  Whether editing is allowed.
		 * @param string $type Component type.
		 */
		return (bool) apply_filters( 'sdc_pb_can_edit_view', $can, (string) $type );
	}

	/**
	 * Resolve a field value, by dotted path, from a context.
	 *
	 * Dotted paths are how a group's sub-field is reachable without the view
	 * having to write `$data['cta']['title']` by hand: `sdc_text( 'cta.title' )`.
	 * Field names are `[a-z0-9_]` (`Sdc_Admin::clean_name()`), so a dot is
	 * always a path separator and never part of a name.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 * @return mixed|null Null when the path does not exist.
	 */
	public static function resolve( $key, $context = null ) {
		if ( null === $context ) {
			$context = self::data();
		}

		$key = (string) $key;

		if ( '' === $key || ! is_array( $context ) ) {
			return null;
		}

		$value = $context;

		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}

			$value = $value[ $part ];
		}

		return $value;
	}

	/**
	 * A value as plain text, without escaping.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function to_text( $value ) {
		if ( is_array( $value ) ) {
			return self::choices_text( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Flatten a choice, relation or taxonomy value into text.
	 *
	 * Hosts return labels, values or objects depending on `return_format`;
	 * all three shapes are read, so a view does not depend on a schema
	 * setting that a component author may change later.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function choices_text( $value ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? (string) $value : '';
		}

		$out = array();

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				foreach ( array( 'label', 'name', 'title', 'post_title', 'display_name' ) as $prop ) {
					if ( isset( $item[ $prop ] ) && is_scalar( $item[ $prop ] ) ) {
						$out[] = (string) $item[ $prop ];
						continue 2;
					}
				}

				if ( isset( $item['url'] ) && is_scalar( $item['url'] ) ) {
					$out[] = (string) $item['url'];
				}

				continue;
			}

			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}

		return implode( ', ', $out );
	}

	/**
	 * Normalise a link value: array, URL string, or nothing.
	 *
	 * @param mixed $value Raw link value.
	 * @return array{url:string,title:string,target:string}|null Null when there is no URL.
	 */
	public static function link_parts( $value ) {
		$url    = '';
		$title  = '';
		$target = '';

		if ( is_array( $value ) ) {
			$url    = isset( $value['url'] ) ? (string) $value['url'] : '';
			$title  = isset( $value['title'] ) ? (string) $value['title'] : '';
			$target = isset( $value['target'] ) ? (string) $value['target'] : '';
		} elseif ( is_scalar( $value ) ) {
			$url = (string) $value;
		}

		if ( '' === $url ) {
			return null;
		}

		return array(
			'url'    => $url,
			'title'  => $title,
			'target' => $target,
		);
	}

	/**
	 * Normalise an image or file value to what a tag needs.
	 *
	 * @param mixed  $value Raw value: attachment array, ID, or URL.
	 * @param string $size  Image size for an ID without a URL.
	 * @return array{url:string,alt:string,title:string,id:int,width:int,height:int}|null
	 */
	public static function attachment_parts( $value, $size = 'large' ) {
		$id    = 0;
		$url   = '';
		$alt   = '';
		$title = '';
		$w     = 0;
		$h     = 0;

		if ( is_array( $value ) ) {
			$id    = isset( $value['ID'] ) ? (int) $value['ID'] : ( isset( $value['id'] ) ? (int) $value['id'] : 0 );
			$url   = isset( $value['url'] ) ? (string) $value['url'] : '';
			$alt   = isset( $value['alt'] ) ? (string) $value['alt'] : '';
			$title = isset( $value['title'] ) ? (string) $value['title'] : '';
		} elseif ( is_numeric( $value ) ) {
			$id = (int) $value;
		} elseif ( is_scalar( $value ) ) {
			$url = (string) $value;
		}

		if ( '' === $url && $id > 0 ) {
			$src = wp_get_attachment_image_src( $id, (string) $size );

			if ( $src ) {
				$url = (string) $src[0];
				$w   = (int) $src[1];
				$h   = (int) $src[2];
			}
		}

		if ( '' === $url ) {
			return null;
		}

		// An attachment array rarely carries alt text; the media library does.
		if ( '' === $alt && $id > 0 ) {
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
		if ( '' === $title && $id > 0 ) {
			$title = (string) get_the_title( $id );
		}

		return array(
			'url'    => $url,
			'alt'    => $alt,
			'title'  => $title,
			'id'     => $id,
			'width'  => $w,
			'height' => $h,
		);
	}

	/**
	 * The helper reference shown in the admin panel.
	 *
	 * Generated from the same list the panel prints, so documentation and
	 * API cannot drift into two versions.
	 *
	 * @return array[] Each entry: array( 'signature' => string, 'description' => string ).
	 */
	public static function helpers() {
		return array(
			array(
				'signature'   => "sdc_text( 'name' )",
				'description' => __( 'Feldwert als Text, HTML escapt. Mehrfachauswahl wird mit Komma verbunden.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_html( 'name' )",
				'description' => __( 'Feldwert als HTML, durch wp_kses_post. Für formatierbaren Text und Textareas mit Absätzen.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_attr( 'name' )",
				'description' => __( 'Feldwert für ein Attribut, escapt. Für eigene Tags statt der Helfer unten.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_url( 'name' )",
				'description' => __( 'Feldwert als Adresse, escapt und auf http(s)/relative Ziele geprüft.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_image( 'name', 'large' )",
				'description' => __( 'Bild-Tag mit Adresse, Alt-Text und Maßen. Nimmt Anhang-Array, ID oder Adresse.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_link( 'name' )",
				'description' => __( 'Verweis-Tag mit Beschriftung aus dem Feld. Drittes Argument setzt die Beschriftung.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_file( 'name' )",
				'description' => __( 'Verweis auf eine Datei, mit deren Titel als Beschriftung.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_has( 'name' )",
				'description' => __( 'Wahr, wenn das Feld einen Wert hat. Für bedingte Blöcke.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_value( 'name' )",
				'description' => __( 'Rohwert ohne Ausgabe, für eigene Logik. Punkte sprechen Unterfelder an: cta.title.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => "sdc_each( 'name', function ( \$row ) { … } )",
				'description' => __( 'Schleife über die Zeilen einer Wiederholung. Hilferufe darin bekommen $row als letztes Argument: sdc_text( \'q\', $row ), sdc_image( \'bild\', \'large\', array(), $row ).', 'sdc-page-builder' ),
			),
			array(
				'signature'   => 'sdc_fields()',
				'description' => __( 'Die Standardausgabe aller Felder dieser Komponente, wenn nur das Drumherum eigen sein soll.', 'sdc-page-builder' ),
			),
			array(
				'signature'   => 'sdc_class( … )',
				'description' => __( 'Baut eine Klassenliste aus nicht-leeren Teilen, escapt für ein class-Attribut.', 'sdc-page-builder' ),
			),
		);
	}

	/**
	 * Generate a working view from a component's fields.
	 *
	 * The point is not a beautiful default but a running starting point: every
	 * field appears once, already escaped and already wrapped in a class named
	 * after the component, so the author edits markup instead of writing it
	 * from nothing.
	 *
	 * @param string  $type   Component type.
	 * @param array[] $fields Field definitions, as stored in `definition.json`.
	 * @return string The body, ready for the textarea.
	 */
	public static function scaffold( $type, array $fields ) {
		$slug = preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( (string) $type ) );
		$slug = '' === $slug ? 'component' : $slug;

		$head = "/*\n";
		$head .= " * Ansicht von \"{$type}\", erzeugt aus den Feldern.\n";
		$head .= " * Frei umbaubar: Klassen, Reihenfolge und Tags gehören dir.\n";
		$head .= " */\n";

		$body = self::scaffold_fields( $fields, $slug, '', '' );

		return $head . ( '' === $body ? "<?php sdc_fields(); ?>\n" : $body );
	}

	/**
	 * Fill one field. Not directly callable from outside; the caller passes the
	 * component type context, which is read from the plugin's own definition
	 * by `scaffold()`.
	 *
	 * @param string $type Component type, used for nothing but the label.
	 * @return string HTML, for the panel's hint.
	 */
	public static function scaffold_hint( $type ) {
		return sprintf(
			/* translators: %s: component type. */
			__( 'Aus den Feldern von „%s“ erzeugt. Ersetzt, was jetzt im Feld steht.', 'sdc-page-builder' ),
			(string) $type
		);
	}

	/**
	 * Field list to body text.
	 *
	 * @param array[] $fields    Field definitions.
	 * @param string  $slug      Component slug, for class names.
	 * @param string  $prefix    Dotted path prefix for group sub-fields.
	 * @param string  $context   PHP expression of the data source; empty for
	 *                           the component's own data, `$row` inside a loop.
	 * @param int     $depth     Current container depth.
	 * @return string
	 */
	private static function scaffold_fields( array $fields, $slug, $prefix = '', $context = '', $depth = 1 ) {
		$out = '';

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$out .= self::scaffold_field( $field, $slug, $prefix, $context, (int) $depth );
		}

		return $out;
	}

	/**
	 * One field to body text.
	 *
	 * @param array  $field   Field definition.
	 * @param string $slug    Component slug.
	 * @param string $prefix  Dotted path prefix.
	 * @param string $context Data-source expression.
	 * @param int    $depth   Container depth.
	 * @return string
	 */
	private static function scaffold_field( array $field, $slug, $prefix, $context, $depth ) {
		$name = (string) $field['name'];
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$key  = var_export( $prefix . $name, true );          // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Building source code, not logging.
		$sub  = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		$css  = $slug . '__' . preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( $name ) );

		/*
		 * Where the row goes depends on the helper's signature: the data
		 * source is the *last* parameter, so a helper that takes options in
		 * between needs those passed explicitly. Writing `, $row` after the
		 * existing arguments put the row into `$attr` of `sdc_image()` and
		 * into `$text` of `sdc_link()` — the field then resolved against the
		 * component instead of the row and rendered nothing.
		 */
		$state = '' === $context ? '' : $context;
		$tail  = '' === $state ? '' : ', ' . $state;        // For sdc_text/… : second parameter.
		$image = '' === $state ? '' : ', array(), ' . $state;
		$file  = '' === $state ? '' : ", '', " . $state;
		$link  = '' === $state ? '' : ", '', " . $state;

		if ( 'repeater' === $type ) {
			if ( $depth > self::MAX_DEPTH || empty( $sub ) ) {
				return '';
			}

			$inner = self::scaffold_fields( $sub, $slug, '', '$row', $depth + 1 );

			/*
			 * Single-quoted concatenation: a double-quoted string would read
			 * `$row` as the outer variable and print its (undefined) value
			 * instead of the loop parameter the generated code needs.
			 */
			return '<?php sdc_each( ' . $key . ', function ( $row ) { ?>' . "\n"
				. '<div class="' . $css . '">' . "\n"
				. $inner
				. '</div>' . "\n"
				. '<?php }' . $tail . ' ); ?>' . "\n";
		}

		if ( 'group' === $type ) {
			if ( $depth > self::MAX_DEPTH || empty( $sub ) ) {
				return '';
			}

			$inner = self::scaffold_fields( $sub, $slug, $prefix . $name . '.', $context, $depth + 1 );

			return '<div class="' . $css . '">' . "\n" . $inner . '</div>' . "\n";
		}

		if ( 'true_false' === $type ) {
			return "<?php if ( sdc_has( {$key}{$tail} ) ) : ?>\n"
				. '<span class="' . $css . '">' . esc_html__( 'Ja', 'sdc-page-builder' ) . '</span>' . "\n"
				. "<?php endif; ?>\n";
		}

		if ( 'image' === $type ) {
			return '<figure class="' . $css . '">' . "\n"
				. "	<?php sdc_image( {$key}, 'large'{$image} ); ?>\n"
				. "</figure>\n";
		}

		if ( 'file' === $type ) {
			return '<p class="' . $css . '">' . "\n"
				. "	<?php sdc_file( {$key}{$file} ); ?>\n"
				. "</p>\n";
		}

		if ( 'link' === $type ) {
			return '<p class="' . $css . '">' . "\n"
				. "	<?php sdc_link( {$key}, 'button'{$link} ); ?>\n"
				. "</p>\n";
		}

		if ( 'wysiwyg' === $type || 'textarea' === $type ) {
			return '<div class="' . $css . '">' . "\n"
				. "	<?php sdc_html( {$key}{$tail} ); ?>\n"
				. "</div>\n";
		}

		return '<p class="' . $css . '">' . "\n"
			. "	<?php sdc_text( {$key}{$tail} ); ?>\n"
			. "</p>\n";
	}
}

/*
 * The helpers a view calls.
 *
 * All of them echo and all of them escape: a view that forgets to escape is
 * the normal outcome when escaping is opt-in, so the shortest way to write a
 * field is also the safe one. `sdc_value()` is the way out when a view needs
 * the raw value.
 *
 * Every helper takes the data source as its last argument, defaulting to the
 * component being rendered. Inside `sdc_each()` that default would be the
 * component, not the row — hence the generated views pass `$row` explicitly.
 */

if ( ! function_exists( 'sdc_text' ) ) {
	/**
	 * Echo a field value as escaped text.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_text( $key, $context = null ) {
		echo esc_html( Sdc_View::to_text( Sdc_View::resolve( $key, $context ) ) );
	}
}

if ( ! function_exists( 'sdc_attr' ) ) {
	/**
	 * Echo a field value as an escaped attribute value.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_attr( $key, $context = null ) {
		echo esc_attr( Sdc_View::to_text( Sdc_View::resolve( $key, $context ) ) );
	}
}

if ( ! function_exists( 'sdc_url' ) ) {
	/**
	 * Echo a field value as an escaped URL.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_url( $key, $context = null ) {
		$value = Sdc_View::resolve( $key, $context );

		if ( is_array( $value ) ) {
			$parts = Sdc_View::link_parts( $value );
			$value = null === $parts ? '' : $parts['url'];
		}

		echo esc_url( Sdc_View::to_text( $value ) );
	}
}

if ( ! function_exists( 'sdc_html' ) ) {
	/**
	 * Echo a field value as HTML that survives `wp_kses_post`.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_html( $key, $context = null ) {
		echo wp_kses_post( Sdc_View::to_text( Sdc_View::resolve( $key, $context ) ) );
	}
}

if ( ! function_exists( 'sdc_has' ) ) {
	/**
	 * Whether a field has a value worth rendering.
	 *
	 * `'0'` counts as a value: a select with the option key 0 and a false
	 * `true_false` are different things, and only the view knows which it
	 * means — so the test is "not empty", never "truthy".
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param array|null $context Data array; null means the current component.
	 * @return bool
	 */
	function sdc_has( $key, $context = null ) {
		$value = Sdc_View::resolve( $key, $context );

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		if ( null === $value || false === $value ) {
			return false;
		}

		return '' !== trim( Sdc_View::to_text( $value ) );
	}
}

if ( ! function_exists( 'sdc_value' ) ) {
	/**
	 * A raw field value, not printed.
	 *
	 * @param string     $key      Field name or dotted path.
	 * @param array|null $context  Data array; null means the current component.
	 * @param mixed      $fallback Returned when the field does not exist.
	 * @return mixed
	 */
	function sdc_value( $key, $context = null, $fallback = null ) {
		$value = Sdc_View::resolve( $key, $context );

		return null === $value ? $fallback : $value;
	}
}

if ( ! function_exists( 'sdc_image' ) ) {
	/**
	 * Echo an `<img>` for an image field.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param string     $size    Image size used when the value is an ID.
	 * @param array      $attr    Extra attributes, e.g. `array( 'class' => 'hero__img' )`.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_image( $key, $size = 'large', array $attr = array(), $context = null ) {
		$parts = Sdc_View::attachment_parts( Sdc_View::resolve( $key, $context ), $size );

		if ( null === $parts ) {
			return;
		}

		$attrs = '';

		foreach ( $attr as $name => $value ) {
			$name = preg_replace( '/[^A-Za-z0-9_\-:]/', '', (string) $name );

			if ( '' === $name || 'src' === strtolower( $name ) ) {
				continue;
			}

			$attrs .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		printf(
			'<img src="%1$s" alt="%2$s"%3$s%4$s%5$s%6$s loading="lazy" decoding="async" />',
			esc_url( $parts['url'] ),
			esc_attr( $parts['alt'] ),
			'' !== $parts['title'] ? ' title="' . esc_attr( $parts['title'] ) . '"' : '',
			$parts['width'] > 0 ? ' width="' . (int) $parts['width'] . '"' : '',
			$parts['height'] > 0 ? ' height="' . (int) $parts['height'] . '"' : '',
			$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped name/value pairs.
		);
	}
}

if ( ! function_exists( 'sdc_link' ) ) {
	/**
	 * Echo an `<a>` for a link field.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param string     $class   Optional class attribute.
	 * @param string     $text    Optional label; the field's title is the default.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_link( $key, $class = '', $text = '', $context = null ) {
		$parts = Sdc_View::link_parts( Sdc_View::resolve( $key, $context ) );

		if ( null === $parts ) {
			return;
		}

		$label = '' !== $text ? $text : $parts['title'];

		if ( '' === $label ) {
			$label = $parts['url'];
		}

		printf(
			'<a href="%1$s"%2$s%3$s>%4$s</a>',
			esc_url( $parts['url'] ),
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
			'_blank' === $parts['target'] ? ' target="_blank" rel="noopener noreferrer"' : '',
			esc_html( $label )
		);
	}
}

if ( ! function_exists( 'sdc_file' ) ) {
	/**
	 * Echo an `<a>` for a file field.
	 *
	 * @param string     $key     Field name or dotted path.
	 * @param string     $class   Optional class attribute.
	 * @param array|null $context Data array; null means the current component.
	 */
	function sdc_file( $key, $class = '', $context = null ) {
		$parts = Sdc_View::attachment_parts( Sdc_View::resolve( $key, $context ), 'full' );

		if ( null === $parts ) {
			return;
		}

		$label = '' !== $parts['title'] ? $parts['title'] : basename( $parts['url'] );

		printf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( $parts['url'] ),
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
			esc_html( $label )
		);
	}
}

if ( ! function_exists( 'sdc_each' ) ) {
	/**
	 * Loop over the rows of a repeater field.
	 *
	 * The callback receives the row array and its index. Helpers inside it need
	 * the row as their second argument — `sdc_text( 'q', $row )` — because the
	 * default context is the component, not the row.
	 *
	 * @param string     $key      Field name or dotted path.
	 * @param callable   $callback Receives ( array $row, int $index ).
	 * @param array|null $context  Data array; null means the current component.
	 */
	function sdc_each( $key, $callback, $context = null ) {
		$rows = Sdc_View::resolve( $key, $context );

		if ( ! is_array( $rows ) || ! $rows || ! is_callable( $callback ) ) {
			return;
		}

		$index = 0;

		foreach ( $rows as $row ) {
			call_user_func( $callback, is_array( $row ) ? $row : array(), $index );
			++$index;
		}
	}
}

if ( ! function_exists( 'sdc_fields' ) ) {
	/**
	 * Echo the generic markup of the current component's fields.
	 */
	function sdc_fields() {
		$type = Sdc_View::type();
		$data = Sdc_View::data();

		if ( '' === $type || null === $data ) {
			return;
		}

		echo Sdc_Frontend::component_fields_html( $type, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The frontend builder escapes every leaf it prints.
	}
}

if ( ! function_exists( 'sdc_class' ) ) {
	/**
	 * Echo a class attribute from the parts that are not empty.
	 *
	 * @param string ...$classes Class names; empty ones are dropped.
	 */
	function sdc_class( ...$classes ) {
		$out = array();

		foreach ( $classes as $class ) {
			$class = trim( (string) $class );

			if ( '' !== $class ) {
				$out[] = $class;
			}
		}

		if ( $out ) {
			echo 'class="' . esc_attr( implode( ' ', $out ) ) . '"';
		}
	}
}

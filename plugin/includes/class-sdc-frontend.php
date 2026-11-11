<?php
/**
 * Frontend output: turns a stored document into the page body.
 *
 * The renderer knows how to walk a component's fields, but it was built for the
 * metabox — its leaves are form inputs named `acf[...]`. A page that ships
 * those inputs has no content, it has a form the reader cannot use. So the
 * frontend needs its own leaf builder: values in, escaped text out.
 *
 * Measured before building it, because the plan flagged this as a risk:
 * `acf_render_field()` does render outside the admin (143 bytes for a text
 * field, full markup for a textarea), and it renders an *input with the value
 * in an attribute*. That is a control, not content. It is also why the
 * frontend path cannot reuse `Sdc_Renderer::render_component()`: that method
 * emits `data-sdc-*` control attributes and row templates for the browser.
 *
 * So this class renders the containers (list, group) just like the renderer —
 * by field type, never by component type — and formats leaves as HTML text.
 *
 * Output path: a `the_content` filter, gated to the builder's post types.
 * `the_content` rather than a shortcode because an author never has to
 * remember one: a page with components simply shows its components.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders builder documents in the frontend.
 */
class Sdc_Frontend {

	/**
	 * Maximum nesting depth. Mirrors `Sdc_Renderer::MAX_DEPTH`.
	 *
	 * Beyond this, containers are dropped instead of rendered — and the drop is
	 * logged, so a content author's silent loss is never silent to us.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Hook the frontend side.
	 */
	public static function init() {
		// Priority 20: after `do_blocks` (9) and `wptexturize` (10), so the
		// stored values are not run through the block parser or texturizer.
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_component_styles' ) );
	}

	/**
	 * Append the rendered components to a post's content.
	 *
	 * Only for the post types the builder edits, only on the main query, and
	 * only for a real post — never in feeds, REST responses or the admin list.
	 *
	 * @param string $content Post content.
	 * @return string Content followed by the rendered components.
	 */
	public static function filter_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
			return $content;
		}

		/*
		 * A migrated page carries its components as blocks, and the block
		 * renderer already prints them from `the_content`. Appending the
		 * document on top of that would show every component twice.
		 */
		if ( Sdc_Migration::is_migrated( $post->ID ) ) {
			return $content;
		}

		return $content . self::render_post( $post->ID );
	}

	/**
	 * Render a post's stored document.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML; empty string when there is nothing to show.
	 */
	public static function render_post( $post_id ) {
		$components = self::document_components( $post_id );

		$html = '';
		foreach ( $components as $component ) {
			$html .= self::render_component( $component, (int) $post_id );
		}

		if ( '' === $html ) {
			return '';
		}

		return '<div class="sdc-page sdc-page--' . esc_attr( (string) $post_id ) . '">' . $html . '</div>';
	}

	/**
	 * The component list of a post's stored document.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Component entries; empty when the post has no document.
	 */
	private static function document_components( $post_id ) {
		$document = Sdc_Store::get_data( (int) $post_id );

		return isset( $document['components'] ) && is_array( $document['components'] )
			? $document['components']
			: array();
	}

	/**
	 * Enqueue the stylesheet a component directory ships.
	 *
	 * A component directory owns three files with fixed names: the JSON, the
	 * `render.php`, and a `component.css` for whatever the view needs beyond
	 * the site's own styles. Enqueueing it here rather than leaving it to the
	 * theme is what makes a component portable: drop the folder in, and the
	 * page looks right without a second place to register the file. A
	 * component without CSS simply has none.
	 *
	 * The version is the file's modification time, so editing the stylesheet
	 * reaches readers without a plugin release.
	 */
	public static function enqueue_component_styles() {
		/*
		 * Component styles are inlined into the head by
		 * `Sdc_Block::print_component_styles()`, which reads the same document
		 * and also covers blocks. Running both would ship every stylesheet
		 * twice — once inline, once as a file.
		 */
		if ( apply_filters( 'sdc_pb_inline_component_styles', true ) && class_exists( 'Sdc_Block' ) ) {
			return;
		}

		// Only a singular view of a builder post prints components; enqueueing
		// anywhere else would load files nobody sees.
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! in_array( $post->post_type, Sdc_Editor::post_types(), true ) ) {
			return;
		}

		$seen = array();

		foreach ( self::document_components( (int) $post->ID ) as $component ) {
			$type = isset( $component['type'] ) ? (string) $component['type'] : '';

			// A document may repeat a type; one stylesheet is enough.
			if ( '' === $type || isset( $seen[ $type ] ) ) {
				continue;
			}

			$seen[ $type ] = true;

			$file = Sdc_View::style( $type );

			if ( '' === $file ) {
				continue;
			}

			$src = self::asset_src( $file );

			/**
			 * Filters the URL of a component's stylesheet.
			 *
			 * For a site that serves component directories from a CDN, or
			 * keeps them somewhere `asset_src()` has no root for.
			 *
			 * @param string $src  URL, empty when the path has no public root.
			 * @param string $file Absolute path of the stylesheet.
			 * @param string $type Component type.
			 */
			$src = (string) apply_filters( 'sdc_pb_component_style_src', $src, $file, $type );

			if ( '' === $src ) {
				continue;
			}

			wp_enqueue_style(
				'sdc-component-' . sanitize_key( $type ),
				$src,
				array(),
				(string) filemtime( $file )
			);
		}
	}

	/**
	 * URL of a file that lives in a component directory.
	 *
	 * Component directories are not all under one root — a child theme, the
	 * parent theme and `wp-content/` are each a possibility — so the path is
	 * matched against the roots WordPress knows a URL for. A path outside all
	 * of them has no URL and is skipped instead of guessed.
	 *
	 * @param string $file Absolute path.
	 * @return string URL, or an empty string when the path has no public root.
	 */
	private static function asset_src( $file ) {
		$file = wp_normalize_path( (string) $file );

		if ( '' === $file ) {
			return '';
		}

		$roots = array();

		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$roots[ get_stylesheet_directory() ] = get_stylesheet_directory_uri();
			$roots[ get_template_directory() ]   = get_template_directory_uri();
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ WP_CONTENT_DIR ] = content_url();
		}

		if ( defined( 'SDC_PB_DIR' ) && defined( 'SDC_PB_URL' ) ) {
			$roots[ SDC_PB_DIR ] = SDC_PB_URL;
		}

		foreach ( $roots as $root => $url ) {
			$root = untrailingslashit( wp_normalize_path( (string) $root ) );

			// An empty root would normalise to "/" and match every path.
			if ( '' === $root || '/' === $root ) {
				continue;
			}

			if ( 0 === strpos( $file, $root . '/' ) ) {
				return untrailingslashit( (string) $url ) . substr( $file, strlen( $root ) );
			}
		}

		return '';
	}

	/**
	 * Render one component from an explicit type and data array.
	 *
	 * The metabox path reads a stored document; a block carries its own
	 * attributes. Both must end in `render_component()`, or the canvas and the
	 * page would disagree about what a component looks like.
	 *
	 * A block knows which post it sits in only when the caller passes it. The
	 * type and the values are enough to render; the post ID decides whether a
	 * missing view is reported in the page source, so a caller that has it
	 * should say so.
	 *
	 * @param string $type    Component type.
	 * @param array  $data    Field values keyed by field name.
	 * @param int    $post_id Post the component belongs to; 0 when unknown.
	 * @return string HTML.
	 */
	public static function render_instance( $type, array $data, $post_id = 0 ) {
		return self::render_component(
			array(
				'type' => (string) $type,
				'data' => $data,
			),
			(int) $post_id
		);
	}

	/**
	 * Public URL of a component's stylesheet, empty when it has none.
	 *
	 * @param string $type Component type.
	 * @return string
	 */
	public static function component_style_url( $type ) {
		$file = Sdc_View::style( $type );

		return '' === $file ? '' : self::asset_src( $file );
	}

	/**
	 * Render one component from the document.
	 *
	 * Three layers, in this order: a theme template (`render.php` next to the
	 * component's JSON), the generic markup built from the field definitions,
	 * then the `sdc_pb_component_html` filter over whichever of the two won.
	 * A theme can therefore restyle, replace, or drop a component without
	 * touching the plugin.
	 *
	 * An unknown type is skipped and logged: a document can outlive the plugin
	 * version that wrote it, and a page must not go white for that reason.
	 *
	 * A page must not go white *silently* either. Two different losses reach
	 * this method, and both used to end in an empty string with nothing in
	 * the log:
	 *
	 * - the schema no longer knows the type (component deleted or renamed),
	 * - the schema still knows it, but there is no `render.php` anywhere in
	 *   the component directories and the definition declares no fields
	 *   either, so the generic output can never produce anything.
	 *
	 * "The component rendered an empty string" is **not** on that list: a
	 * healthy component prints nothing when its values are blank, and a page
	 * whose hero happens to be unfilled is not a lost component. The decision
	 * therefore comes from `has_view()`, which asks the definitions, not from
	 * comparing this render's output to `''`.
	 *
	 * Both cases are reported by `report_missing()`. Deduplication happens
	 * there, so a page with three copies of the same broken component logs
	 * once.
	 *
	 * @param mixed $component Component entry from the document.
	 * @param int   $post_id   Post the document belongs to; 0 when unknown.
	 * @return string HTML.
	 */
	private static function render_component( $component, $post_id = 0 ) {
		if ( ! is_array( $component ) || empty( $component['type'] ) ) {
			return '';
		}

		$type = (string) $component['type'];

		if ( ! self::has_view( $type ) ) {
			return (string) self::report_missing( $type, (int) $post_id );
		}

		$data = isset( $component['data'] ) && is_array( $component['data'] ) ? $component['data'] : array();

		$html = self::render_template( $type, $data, $component );

		if ( null === $html ) {
			$body = self::component_fields_html( $type, $data );
			if ( '' === trim( $body ) ) {
				return '';
			}

			$html = sprintf(
				'<div class="sdc-component sdc-component--%1$s">%2$s</div>',
				esc_attr( $type ),
				$body
			);
		}

		/**
		 * Filters the HTML of one rendered component.
		 *
		 * Runs after the theme template, if there was one, so a filter is the
		 * last word. Returning an empty string removes the component.
		 *
		 * @param string $html      Component HTML.
		 * @param string $type      Component type.
		 * @param array  $data      Stored values of this instance.
		 * @param array  $component Full document entry (`_id`, `type`, `schema`, `data`).
		 */
		return (string) apply_filters( 'sdc_pb_component_html', $html, $type, $data, $component );
	}

	/**
	 * Can this component type render something at all?
	 *
	 * The question the renderer answers implicitly and the admin notice has
	 * to ask up front, so it lives in one place. "No view" means: the schema
	 * does not know the type, *or* it knows it and there is neither a
	 * render.php nor a single field the generic output could print.
	 *
	 * Deliberately decided from the field *definitions*, not from a render
	 * with empty values: a healthy component prints nothing when its data is
	 * empty, so asking it that way would report every page whose values
	 * happen to be blank. Measured on the dev instance — `faq` has no
	 * render.php and no definition in `sdc-components/`; it still renders
	 * through the schema definition's fields, and a value-based check called
	 * that "no view" on the two pages that display it correctly.
	 *
	 * @param string $type Component type.
	 * @return bool
	 */
	public static function has_view( $type ) {
		$type = (string) $type;
		$meta = '' === $type ? null : Sdc_Schema::get( $type );
		if ( ! $meta ) {
			return false;
		}

		if ( '' !== (string) Sdc_Schema::template( $type ) ) {
			return true;
		}

		// No view file: the generic field output is the view, and it exists
		// exactly when the definition declares fields. `components()` has
		// already normalized them, so this reads the same list the renderer
		// would iterate.
		return isset( $meta['fields'] ) && is_array( $meta['fields'] ) && array() !== $meta['fields'];
	}

	/**
	 * Say that a component type has no view, instead of returning nothing.
	 *
	 * Two audiences, two channels, one call:
	 *
	 * - **The log**, once per request and type (`static` set). A page that
	 *   places the same broken component three times is one fault to fix,
	 *   not three, and a log that repeats it per instance is noise a reader
	 *   stops scanning. Not `_doing_it_wrong()`: that is reserved for API
	 *   misuse, and this is a data state, not a wrong call.
	 * - **The source text of the page**, as an HTML comment, and only for
	 *   someone who may edit this post. Visitors get nothing — an empty
	 *   spot and a comment describing the empty spot look equally broken to
	 *   a reader, and the second one tells the internet what the site runs.
	 *
	 * The comment is not wrapped in the component's own container: the point
	 * is to survive where the component itself rendered nothing.
	 *
	 * @param string $type    Component type that could not be rendered.
	 * @param int    $post_id Post the document belongs to; 0 when unknown.
	 * @return string HTML comment for an editor, empty string for everyone else.
	 */
	private static function report_missing( $type, $post_id = 0 ) {
		static $seen = array();

		$type    = (string) $type;
		$post_id = (int) $post_id;

		if ( ! isset( $seen[ $type ] ) ) {
			$seen[ $type ] = true;

			/*
			 * Translated, not a plain string: the operator who reads the log
			 * is as likely to be German as the author who reads the source
			 * text. The type is quoted but not escaped — a log is plain
			 * text, and escaping would print an HTML entity into it.
			 */
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A missing view is a content loss and has to be traceable outside the page.
				sprintf(
					/* translators: 1: component type, 2: post ID. */
					__( 'SDC Page Builder: Komponententyp "%1$s" hat keine Ansicht (Seite %2$d)', 'sdc-page-builder' ),
					$type,
					$post_id
				)
			);
		}

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return sprintf(
			'<!-- sdc: %1$s -->',
			esc_html(
				sprintf(
					/* translators: %s: component type. */
					__( 'Komponententyp "%s" hat keine Ansicht', 'sdc-page-builder' ),
					$type
				)
			)
		);
	}

	/**
	 * Render through a theme template, when the site ships one.
	 *
	 * The template is theme code, so its output is not escaped — same trust
	 * boundary as a template part. It receives `$type`, `$data` (raw stored
	 * values) and `$component` (the document entry) and echoes its markup.
	 *
	 * @param string $type      Component type.
	 * @param array  $data      Stored values; read by the template, not here.
	 * @param array  $component Document entry; read by the template, not here.
	 * @return string|null HTML, or null when there is no template.
	 */
	private static function render_template( $type, array $data, array $component ) {
		$file = Sdc_Schema::template( $type );

		if ( '' === $file ) {
			return null;
		}

		// The template reads $type, $data and $component as plain local
		// variables — no extraction, no globals. Documented in the README so a
		// theme author knows exactly which names exist.
		$render = static function () use ( $file, $type, $data, $component ) {
			ob_start();

			/*
			 * The `sdc_*` helpers take no data argument, so the component
			 * being rendered has to be known somewhere else: this is that
			 * place. Pushed around the include and dropped in `finally`,
			 * because a template that dies must not leave the next
			 * component reading this one's values.
			 */
			Sdc_View::push( $type, $data );

			try {
				include $file;
			} finally {
				Sdc_View::pop();
				$html = (string) ob_get_clean();
			}

			return $html;
		};

		return $render();
	}

	/**
	 * Default markup for a component's fields.
	 *
	 * Public because a theme template usually wants the generic field output
	 * and only the surrounding element of its own: calling this keeps the
	 * container rules and the escaping in one place instead of copying them
	 * into every `render.php`.
	 *
	 * @param string $type Component type.
	 * @param array  $data Stored values.
	 * @return string HTML; empty string for an unknown type.
	 */
	public static function component_fields_html( $type, $data ) {
		$meta = Sdc_Schema::get( (string) $type );
		if ( ! $meta ) {
			return '';
		}

		$fields = Sdc_Field_Adapter::get_fields( Sdc_Field_Adapter::get_field_group( $meta['key'] ) );

		return self::render_fields( $fields, is_array( $data ) ? $data : array(), 1 );
	}

	/**
	 * Render arbitrary field definitions, for verification.
	 *
	 * Lets a test reach the private recursion without writing a page — the
	 * depth guard and the leaf formatter are only observable from outside
	 * through a public seam.
	 *
	 * @param array[] $fields Field definitions.
	 * @param array   $data   Values.
	 * @param int     $depth  Starting depth.
	 * @return string HTML.
	 */
	public static function render_fields_probe( array $fields, array $data, $depth = 1 ) {
		return self::render_fields( $fields, $data, (int) $depth );
	}

	/**
	 * A set of field definitions against a data array.
	 *
	 * Container or leaf is decided by field type, exactly as in the renderer —
	 * a new component type needs no change here.
	 *
	 * @param array[] $fields Field definitions from the host.
	 * @param array   $data   Values keyed by field name.
	 * @param int     $depth  Current nesting depth.
	 * @return string HTML.
	 */
	private static function render_fields( array $fields, array $data, $depth ) {
		$html = '';

		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$value = $data[ $name ] ?? ( $field['default_value'] ?? '' );

			if ( 'repeater' === $field['type'] || 'flexible_content' === $field['type'] ) {
				$html .= self::render_list( $field, $value, $depth );
				continue;
			}
			if ( 'group' === $field['type'] ) {
				$html .= self::render_group( $field, $value, $depth );
				continue;
			}

			$html .= self::render_leaf( $field, $value );
		}

		return $html;
	}

	/**
	 * A list container: one block per row.
	 *
	 * Empty rows are dropped — the editor allows a row with no values, and an
	 * empty block is markup noise, not content.
	 *
	 * @param array $field Container definition (has `sub_fields`).
	 * @param mixed $value Array of rows.
	 * @param int   $depth Nesting depth.
	 * @return string HTML.
	 */
	private static function render_list( array $field, $value, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			error_log( sprintf( 'SDC Page Builder: Verschachtelungstiefe %d überschreitet das Maximum %d (Feld "%s").', (int) $depth, self::MAX_DEPTH, (string) $field['name'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$rows = is_array( $value ) ? array_values( $value ) : array();

		$html = '';
		foreach ( $rows as $row ) {
			$row  = is_array( $row ) ? $row : array();
			$sub  = self::row_fields( $field, $row );
			$body = self::render_fields( $sub, $row, $depth + 1 );

			if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
				continue;
			}

			$html .= '<div class="sdc-row">' . $body . '</div>';
		}

		if ( '' === $html ) {
			return '';
		}

		return '<div class="sdc-list sdc-list--' . esc_attr( self::slug( $field['name'] ) ) . '">' . $html . '</div>';
	}

	/**
	 * The sub-fields one row is rendered with.
	 *
	 * A repeater has one set for every row. `flexible_content` has one set per
	 * layout, and the row names its layout in `acf_fc_layout` — reading the
	 * field's own `sub_fields` there yields nothing, so every row rendered
	 * empty and was dropped as an empty row.
	 *
	 * @param array $field Container definition.
	 * @param array $row   Row values.
	 * @return array[] Sub-fields; empty when the layout is unknown.
	 */
	private static function row_fields( array $field, array $row ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';

		if ( 'flexible_content' !== $type ) {
			return isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		}

		$layout_name = '';

		foreach ( array( 'acf_fc_layout', '_sdc_layout' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
				$layout_name = (string) $row[ $key ];
				break;
			}
		}

		if ( '' === $layout_name ) {
			return array();
		}

		$layouts = isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array();

		foreach ( $layouts as $layout ) {
			if ( is_array( $layout ) && isset( $layout['name'] ) && $layout_name === (string) $layout['name'] ) {
				return isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
			}
		}

		return array();
	}

	/**
	 * A group container.
	 *
	 * @param array $field Group definition.
	 * @param mixed $value Values.
	 * @param int   $depth Nesting depth.
	 * @return string HTML.
	 */
	private static function render_group( array $field, $value, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			error_log( sprintf( 'SDC Page Builder: Verschachtelungstiefe %d überschreitet das Maximum %d (Feld "%s").', (int) $depth, self::MAX_DEPTH, (string) $field['name'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return '';
		}

		$sub  = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		$body = self::render_fields( $sub, is_array( $value ) ? $value : array(), $depth + 1 );

		if ( '' === trim( $body ) ) {
			return '';
		}

		return '<div class="sdc-group sdc-group--' . esc_attr( self::slug( $field['name'] ) ) . '">' . $body . '</div>';
	}

	/**
	 * A single leaf field, formatted for reading.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Current value.
	 * @return string HTML.
	 */
	private static function render_leaf( array $field, $value ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$name = (string) $field['name'];
		$html = self::format_value( $type, $value );

		if ( '' === trim( $html ) ) {
			return '';
		}

		return '<div class="sdc-field sdc-field--' . esc_attr( $type ) . ' sdc-field--' . esc_attr( self::slug( $name ) ) . '"'
			. ' data-sdc-name="' . esc_attr( $name ) . '">'
			. $html
			. '</div>';
	}

	/**
	 * Turn one raw value into escaped HTML for its field type.
	 *
	 * Every branch escapes. Values come from a text field, so they contain
	 * whatever an author typed — including markup. Only `wysiwyg` keeps
	 * markup, and that is the one type where the host sanitizes it on save and
	 * where HTML *is* the content; even there the value passes `wp_kses_post`.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return string HTML, escaped.
	 */
	private static function format_value( $type, $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'wysiwyg':
				return wp_kses_post( (string) $value );

			case 'textarea':
			case 'text':
			case 'number':
			case 'range':
			case 'email':
			case 'url':
			case 'password':
				return '<p>' . nl2br( esc_html( (string) $value ) ) . '</p>';

			case 'true_false':
				$on = ! empty( $value );
				return '<p>' . esc_html( $on ? __( 'Ja', 'sdc-page-builder' ) : __( 'Nein', 'sdc-page-builder' ) ) . '</p>';

			case 'link':
				return self::format_link( $value );

			case 'image':
			case 'file':
				return self::format_attachment( $value, 'image' === $type );

			case 'gallery':
				return self::format_gallery( $value );

			case 'select':
			case 'radio':
			case 'checkbox':
			case 'button_group':
				return '<p>' . esc_html( self::join_values( $value ) ) . '</p>';

			case 'taxonomy':
			case 'post_object':
			case 'relationship':
			case 'user':
			case 'page_link':
				return '<p>' . esc_html( self::join_values( $value ) ) . '</p>';

			case 'oembed':
				$embed = wp_oembed_get( (string) $value );
				return false !== $embed ? $embed : '<p>' . esc_html( (string) $value ) . '</p>';

			case 'date_picker':
			case 'date_time_picker':
			case 'time_picker':
				return '<p><time datetime="' . esc_attr( (string) $value ) . '">' . esc_html( (string) $value ) . '</time></p>';

			default:
				// Unknown leaf type: the value is shown as text, never dropped
				// and never trusted. A host upgrade that adds a type therefore
				// degrades to plain text instead of disappearing.
				if ( is_scalar( $value ) ) {
					return '<p>' . nl2br( esc_html( (string) $value ) ) . '</p>';
				}
				return '';
		}
	}

	/**
	 * A link field, whether the host returned an array or a URL string.
	 *
	 * @param mixed $value Link value.
	 * @return string HTML.
	 */
	private static function format_link( $value ) {
		$url   = '';
		$title = '';

		if ( is_array( $value ) ) {
			$url   = isset( $value['url'] ) ? (string) $value['url'] : '';
			$title = isset( $value['title'] ) ? (string) $value['title'] : '';
		} elseif ( is_scalar( $value ) ) {
			$url = (string) $value;
		}

		$url = esc_url( $url );
		if ( '' === $url ) {
			return '';
		}

		return '<p><a href="' . $url . '">' . esc_html( '' !== $title ? $title : $url ) . '</a></p>';
	}

	/**
	 * An image or file field. A bare ID is accepted as well as a full array.
	 *
	 * @param mixed $value   Attachment array or ID.
	 * @param bool  $is_image Whether to render an `<img>`.
	 * @return string HTML.
	 */
	private static function format_attachment( $value, $is_image ) {
		$id  = 0;
		$url = '';
		$alt = '';

		if ( is_array( $value ) ) {
			$id  = isset( $value['ID'] ) ? (int) $value['ID'] : ( isset( $value['id'] ) ? (int) $value['id'] : 0 );
			$url = isset( $value['url'] ) ? (string) $value['url'] : '';
			$alt = isset( $value['alt'] ) ? (string) $value['alt'] : '';
		} elseif ( is_numeric( $value ) ) {
			$id = (int) $value;
		} elseif ( is_scalar( $value ) ) {
			$url = (string) $value;
		}

		$url = esc_url( $url );
		if ( '' === $url && $id > 0 ) {
			$src = wp_get_attachment_image_src( $id, $is_image ? 'large' : 'full' );
			$url = $src ? esc_url( (string) $src[0] ) : '';
		}

		if ( '' === $url ) {
			return '';
		}

		if ( ! $is_image ) {
			$label = $alt;
			if ( '' === $label && $id > 0 ) {
				$label = (string) get_the_title( $id );
			}
			return '<p><a href="' . $url . '">' . esc_html( '' !== $label ? $label : $url ) . '</a></p>';
		}

		return '<figure class="sdc-image"><img src="' . $url . '" alt="' . esc_attr( $alt ) . '" loading="lazy" /></figure>';
	}

	/**
	 * A gallery field.
	 *
	 * @param mixed $value Array of attachments or IDs.
	 * @return string HTML.
	 */
	private static function format_gallery( $value ) {
		if ( ! is_array( $value ) ) {
			return '';
		}

		$items = '';
		foreach ( $value as $item ) {
			$items .= self::format_attachment( $item, true );
		}

		return '' === $items ? '' : '<div class="sdc-gallery">' . $items . '</div>';
	}

	/**
	 * Flatten a value from a choice, relation or taxonomy field into text.
	 *
	 * Hosts return either labels or objects depending on `return_format`; both
	 * shapes are read here so the output does not depend on schema settings.
	 *
	 * @param mixed $value Field value.
	 * @return string Plain text, unescaped.
	 */
	private static function join_values( $value ) {
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
	 * A CSS-class safe slug from a field name or type.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function slug( $name ) {
		$slug = preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( (string) $name ) );
		return '' === $slug ? 'field' : $slug;
	}
}

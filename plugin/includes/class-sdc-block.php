<?php
/**
 * Components as blocks: placement inside the block editor.
 *
 * Until now a page's components lived in one postmeta document (`_sdc_data`)
 * and were appended in the frontend by `the_content`. That made the builder a
 * separate screen next to the editor, and the editor itself showed nothing.
 *
 * A block closes that gap: it is a placement, not a second data model. Each
 * block carries its component's values as `data` and renders through the very
 * same `Sdc_Frontend` path the metabox uses — so the canvas shows the real
 * frontend markup, and there is no second renderer that could drift.
 *
 * One block per component type, not one block for all of them. A single
 * `sdc/component` was cheaper to register, but every instance carried the same
 * name: three FAQ blocks on a page were three times "SDC-Komponente" in the
 * list view and the breadcrumb, and the author had to open them to find the
 * right one. The type in the block name puts the component's own label where
 * the author looks.
 *
 * The registration is a loop over the schema, and the editor builds its
 * inspector from the same schema, so a new component needs no code here.
 *
 * What this class does NOT do: let the author type into the canvas. Editing
 * stays in the inspector, exactly like ACF Blocks. Inline editing would need
 * a second renderer in JavaScript, and a hand-written `render.php` can put its
 * values anywhere — no client-side editor could know where.
 *
 * @package SdcPageBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders one block per component.
 */
class Sdc_Block {

	/**
	 * Block name prefix. The component type is appended: `sdc/faq`.
	 */
	const PREFIX = 'sdc/';

	/**
	 * The single block name earlier builds used for every type.
	 *
	 * Kept as a migration source only: content written with it still yields
	 * its component types when styles are collected.
	 */
	const LEGACY = 'sdc/component';

	/**
	 * Editor script handle.
	 */
	const SCRIPT = 'sdc-pb-block';

	/**
	 * Editor stylesheet handle.
	 */
	const STYLE = 'sdc-pb-block-editor';

	/**
	 * Handle of the aggregated component stylesheet.
	 */
	const STYLES = 'sdc-components';

	/**
	 * Block category slug the components appear under.
	 *
	 * A category of their own, not `design` among the theme's blocks: the
	 * components are what this editor is for, and the author should not have
	 * to scroll past paragraphs, columns and embeds to reach them.
	 */
	const CATEGORY = 'sdc-components';

	/**
	 * Hook the block side.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );

		// The plugin requires WordPress 6.4, so `block_categories_all` is the
		// only filter to register. The pre-5.8 `block_categories` would still
		// fire — deprecated — and `WP_DEBUG` turns that into a notice on every
		// admin page.
		add_filter( 'block_categories_all', array( __CLASS__, 'categories' ) );

		// Two hooks, one job: `enqueue_block_assets` fires in the frontend and
		// inside the editor iframe, `wp_enqueue_scripts` covers a theme that
		// does not call the block-assets hook.
		add_action( 'enqueue_block_assets', array( __CLASS__, 'print_component_styles' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'print_component_styles' ), 20 );
	}

	/**
	 * Put the component category first in the inserter.
	 *
	 * First, not merely present: the inserter is where a page starts, and a
	 * category below the theme's own is a category the author scrolls past.
	 * The filter also fires on the frontend block list, where position is
	 * irrelevant — `array_unshift` is harmless there.
	 *
	 * Idempotent, because some setups apply both filters and `init` can run
	 * twice: an existing entry is left alone rather than duplicated.
	 *
	 * @param array $categories Registered categories.
	 * @return array Categories with ours first.
	 */
	public static function categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && self::CATEGORY === $category['slug'] ) {
				return $categories;
			}
		}

		array_unshift(
			$categories,
			array(
				'slug'  => self::CATEGORY,
				'title' => __( 'SDC-Komponenten', 'sdc-page-builder' ),
				'icon'  => 'layout',
			)
		);

		return $categories;
	}

	/**
	 * Register one block per component type, plus the legacy block for old
	 * content.
	 *
	 * `render_callback` in PHP rather than `"render": "file:…"` in a block.json:
	 * the PHP form works from WordPress 5.0, and the callback is a loop body
	 * here rather than one near-identical file per component.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		/*
		 * `init` can fire more than once — the test suite does it, and so does
		 * any code that re-runs the hook. A second `register_block_type()` for
		 * the same name raises `_doing_it_wrong()`, which is a real diagnostic
		 * worth keeping intact; the guard is what keeps the call idempotent.
		 */
		$registry = WP_Block_Type_Registry::get_instance();

		// `editor_style` refers to a handle, so the handle has to exist by
		// the time the block renders. An unregistered handle is not an error
		// WordPress reports — the canvas simply loses its styling.
		$css = SDC_PB_DIR . 'assets/block-editor.css';

		if ( file_exists( $css ) && ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE,
				SDC_PB_URL . 'assets/block-editor.css',
				array(),
				(string) filemtime( $css )
			);
		}

		foreach ( array_keys( self::schema() ) as $type ) {
			if ( $registry->is_registered( self::name( $type ) ) ) {
				continue;
			}

			$component = Sdc_Schema::get( $type );

			if ( ! $component ) {
				continue;
			}

			register_block_type(
				self::name( $type ),
				array(
					'api_version'     => 3,
					'title'           => (string) $component['label'],
					'description'     => (string) $component['description'],
					'category'        => self::CATEGORY,
					'icon'            => self::icon( $component ),
					'keywords'        => array( 'sdc', (string) $type, (string) $component['label'] ),
					'attributes'      => array(
						'data' => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
					'supports'        => array(
						'html'            => false,
						'className'       => false,
						'customClassName' => false,
						'anchor'          => false,
						'align'           => false,
						'spacing'         => false,
					),
					'render_callback' => function ( $attributes ) use ( $type ) {
						return self::render( $type, $attributes );
					},
					'editor_script'   => self::SCRIPT,
					'editor_style'    => self::STYLE,
				)
			);
		}

		self::register_legacy();
	}

	/**
	 * Keep the first build's block name working.
	 *
	 * Content written before this build carries `wp:sdc/component` with the
	 * type in an attribute. Unregistered, WordPress falls back to the block's
	 * stored inner HTML — which a dynamic block never has — so an old page
	 * would silently lose its components. Verified the hard way: page 128
	 * rendered empty after the switch to one block per type.
	 *
	 * The block is hidden from the inserter: it exists for content that is
	 * already written, not as a choice for new pages. Editing old content
	 * still works — the inspector is built from the same schema, using the
	 * `type` attribute to pick the component.
	 */
	private static function register_legacy() {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( self::LEGACY ) ) {
			return;
		}

		register_block_type(
			self::LEGACY,
			array(
				'api_version'     => 3,
				'title'           => __( 'SDC-Komponente (alt)', 'sdc-page-builder' ),
				'description'     => __( 'Komponente aus einer früheren Fassung. Neue Seiten nutzen den Block der jeweiligen Komponente.', 'sdc-page-builder' ),
				'category'        => self::CATEGORY,
				'icon'            => 'layout',
				'keywords'        => array( 'sdc', 'legacy' ),
				'attributes'      => array(
					'type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'data' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
				'supports'        => array(
					'inserter'        => false,
					'html'            => false,
					'className'       => false,
					'customClassName' => false,
					'anchor'          => false,
					'align'           => false,
					'spacing'         => false,
				),
				'render_callback' => function ( $attributes ) {
					$type = isset( $attributes['type'] ) ? (string) $attributes['type'] : '';

					return '' === $type ? '' : self::render( $type, $attributes );
				},
				'editor_script'   => self::SCRIPT,
				'editor_style'    => self::STYLE,
			)
		);
	}

	/**
	 * The block name of a component type.
	 *
	 * @param string $type Component type.
	 * @return string Block name.
	 */
	public static function name( $type ) {
		return self::PREFIX . (string) $type;
	}

	/**
	 * Component types that can be placed as a block.
	 *
	 * A block name allows a narrow alphabet, so a type outside it is skipped
	 * rather than registered into a name WordPress would reject.
	 *
	 * @return string[]
	 */
	public static function types() {
		$types = array();

		foreach ( array_keys( Sdc_Schema::components() ) as $type ) {
			if ( is_string( $type ) && preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $type ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * A dashicon for the block, from the component definition.
	 *
	 * @param array $component Component definition.
	 * @return string Icon name.
	 */
	private static function icon( $component ) {
		$icon = isset( $component['icon'] ) ? (string) $component['icon'] : '';

		return preg_match( '/^[a-z0-9-]+$/', $icon ) ? $icon : 'layout';
	}

	/**
	 * Render a block instance.
	 *
	 * @param string $type       Component type.
	 * @param array  $attributes Block attributes.
	 * @return string HTML.
	 */
	public static function render( $type, $attributes ) {
		$type = (string) $type;

		if ( ! Sdc_Schema::get( $type ) ) {
			return '';
		}

		$data = isset( $attributes['data'] ) && is_array( $attributes['data'] )
			? $attributes['data']
			: array();

		// The page's own types were collected in print_component_styles() and
		// live inline in the head. A block outside that list — a query loop on
		// an archive, a template part, a pattern — still needs its stylesheet,
		// and gets it as a file even though that means a later request.
		if ( ! isset( self::$inlined[ $type ] ) ) {
			self::enqueue_style( $type );
		}

		$html = Sdc_Frontend::render_instance( $type, $data );

		/*
		 * A component without values prints nothing — right for a page, wrong
		 * in the canvas: the block would look broken while it is simply empty.
		 * The block-renderer REST call carries `context=edit`, so a hint can be
		 * added there and nowhere else.
		 */
		if ( '' === $html && self::in_editor_context() ) {
			return sprintf(
				'<div class="sdc-block__empty"><p>%1$s</p><p class="sdc-block__help">%2$s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: component label. */
						__( '%s: noch keine Inhalte.', 'sdc-page-builder' ),
						(string) Sdc_Schema::get( $type )['label']
					)
				),
				esc_html__( 'Werte rechts in der Seitenleiste eintragen.', 'sdc-page-builder' )
			);
		}

		return $html;
	}

	/**
	 * Whether this render is the editor asking for a preview.
	 *
	 * `ServerSideRender` requests `/wp/v2/block-renderer/…` with
	 * `context=edit`. Reading that keeps the hint out of every frontend
	 * response without inventing a second code path for the canvas.
	 *
	 * @return bool
	 */
	private static function in_editor_context() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch, same value WordPress itself passes.
		return isset( $_GET['context'] ) && 'edit' === $_GET['context'];
	}

	/**
	 * Editor script and the schema the inspector is built from.
	 */
	public static function enqueue_editor_assets() {
		$file = SDC_PB_DIR . 'assets/block.js';

		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			SDC_PB_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-data', 'wp-compose' ),
			(string) filemtime( $file ),
			true
		);

		wp_localize_script(
			self::SCRIPT,
			'SDC_BLOCK',
			array(
				'prefix'     => self::PREFIX,
				'category'   => self::CATEGORY,
				'components' => self::schema(),
			)
		);
	}

	/**
	 * The field definitions the inspector renders.
	 *
	 * Shape per component: `label`, `description`, `icon`, `fields[]`. A field
	 * carries the attributes that decide its control: `type`, `label`, `name`,
	 * `required`, `instructions`, plus `choices` for `select`, `min`/`max`/
	 * `step` for `range`, `sub_fields` for `repeater` and `group`, and
	 * `layouts` for `flexible_content`.
	 *
	 * Built from the raw host definitions (`acf_get_fields()`), not from
	 * `Sdc_Schema::fields()`: the normalized form drops `sub_fields`, and a
	 * repeater without its rows is not a repeater.
	 *
	 * @return array[]
	 */
	public static function schema() {
		$schema = array();

		foreach ( self::types() as $type ) {
			$component = Sdc_Schema::get( $type );

			if ( ! $component ) {
				continue;
			}

			$schema[ $type ] = array(
				'type'        => (string) $type,
				'label'       => (string) $component['label'],
				'description' => (string) $component['description'],
				'icon'        => self::icon( $component ),
				'fields'      => self::fields_for_js( Sdc_Field_Adapter::get_fields( $component['key'] ) ),
			);
		}

		return $schema;
	}

	/**
	 * Reduce host field definitions to what a control needs.
	 *
	 * @param array[] $fields Raw field definitions.
	 * @return array[]
	 */
	private static function fields_for_js( array $fields ) {
		$out = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$entry = array(
				'name'         => (string) $field['name'],
				'label'        => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : (string) $field['name'],
				'type'         => isset( $field['type'] ) ? (string) $field['type'] : 'text',
				'required'     => ! empty( $field['required'] ),
				'instructions' => isset( $field['instructions'] ) ? wp_strip_all_tags( (string) $field['instructions'] ) : '',
			);

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$entry['sub_fields'] = self::fields_for_js( $field['sub_fields'] );
			}

			/*
			 * `flexible_content` has no `sub_fields` of its own: its fields sit
			 * inside each layout. Without the layouts the inspector knew a
			 * field name and nothing else — it fell through to a text input
			 * that wrote a string where the renderer expects rows.
			 */
			if ( 'flexible_content' === $entry['type'] && ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$entry['layouts'] = self::layouts_for_js( $field['layouts'] );
			}

			if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
				$entry['choices'] = array();
				foreach ( $field['choices'] as $value => $label ) {
					$entry['choices'][] = array(
						'value' => (string) $value,
						'label' => wp_strip_all_tags( (string) $label ),
					);
				}
			}

			foreach ( array( 'min', 'max', 'step', 'button_label' ) as $extra ) {
				if ( isset( $field[ $extra ] ) && '' !== $field[ $extra ] ) {
					$entry[ $extra ] = is_numeric( $field[ $extra ] ) ? $field[ $extra ] + 0 : (string) $field[ $extra ];
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Reduce a `flexible_content` field's layouts to what a control needs.
	 *
	 * A layout is a small component: a name to store, a label to pick it by,
	 * and its own fields. Hosts key the list by layout name; the inspector
	 * wants a list, so the key is dropped after the name is read.
	 *
	 * @param array $layouts Raw layout definitions.
	 * @return array[]
	 */
	private static function layouts_for_js( array $layouts ) {
		$out = array();

		foreach ( $layouts as $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['name'] ) ) {
				continue;
			}

			$out[] = array(
				'name'       => (string) $layout['name'],
				'label'      => isset( $layout['label'] ) ? wp_strip_all_tags( (string) $layout['label'] ) : (string) $layout['name'],
				'sub_fields' => self::fields_for_js(
					isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ? $layout['sub_fields'] : array()
				),
			);
		}

		return $out;
	}

	/**
	 * Deliver the component styles of the page being rendered.
	 *
	 * Inline by default: one `<style>` for the whole page, versioned by a hash
	 * of its contents. That is a deliberate answer to caching plugins.
	 * Autoptimize, WP Rocket and friends combine, defer and *remove unused*
	 * external stylesheets, and a component's CSS arriving as a separate late
	 * request is exactly what such a pipeline drops. Inline CSS in the head
	 * cannot be deferred away, costs no second request, and cannot flash
	 * unstyled content.
	 *
	 * The cost is real and worth naming: the CSS no longer caches as a file,
	 * so every HTML response carries the component styles of that page. For
	 * small component stylesheets that is cheaper than a request per page; for
	 * a set with hundreds of kilobytes it is not. The filter switches back to
	 * per-file enqueueing.
	 *
	 * @return void
	 */
	public static function print_component_styles() {
		if ( wp_style_is( self::STYLES, 'enqueued' ) || wp_style_is( self::STYLES, 'registered' ) ) {
			return;
		}

		$types = self::types_here();
		$css   = self::styles( $types );

		if ( '' !== $css ) {
			// Remembered so a block rendered later — a query loop on an archive,
			// a template part — can tell whether its styles are already on the
			// page or still have to be fetched.
			self::$inlined = array_flip( $types );

			wp_register_style( self::STYLES, false, array(), self::version( $css ) );
			wp_add_inline_style( self::STYLES, $css );
			wp_enqueue_style( self::STYLES );

			return;
		}

		// Inlining switched off, or no component CSS at all: fall back to one
		// file per component. The loop is harmless when there is nothing.
		foreach ( $types as $type ) {
			self::enqueue_style( $type );
		}
	}

	/**
	 * Component types whose styles are already inlined on this page.
	 *
	 * @var array<string,int>
	 */
	private static $inlined = array();


	/**
	 * The component stylesheets of the page as one string.
	 *
	 * Empty when there is nothing to deliver, or when a site asked for
	 * separate files instead.
	 *
	 * @param string[] $types Component types to collect; null means "the
	 *                        types placed on this page".
	 * @return string CSS.
	 */
	public static function styles( $types = null ) {
		/**
		 * Filters whether component styles are inlined into the page head.
		 *
		 * Return false to enqueue one stylesheet per component instead — for
		 * a site that serves component CSS through a bundler, or that holds
		 * the stylesheets of a large component set.
		 *
		 * @param bool $inline Default true.
		 */
		if ( ! apply_filters( 'sdc_pb_inline_component_styles', true ) ) {
			return '';
		}

		$css = '';

		foreach ( null === $types ? self::types_here() : $types as $type ) {
			$file = Sdc_View::style( $type );

			if ( '' === $file || ! is_readable( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local stylesheet, no HTTP involved.
			$body = file_get_contents( $file );

			if ( false === $body || '' === trim( $body ) ) {
				continue;
			}

			$css .= '/* ' . $type . " */\n" . trim( $body ) . "\n";
		}

		return $css;
	}

	/**
	 * A version for the inline stylesheet.
	 *
	 * A hash, not a timestamp: the same styles must produce the same version,
	 * otherwise every page load looks like a change to a caching layer.
	 *
	 * @param string $css Stylesheet contents.
	 * @return string
	 */
	private static function version( $css ) {
		return substr( md5( (string) $css ), 0, 12 );
	}

	/**
	 * Component types placed on the current post.
	 *
	 * Two sources, because a page can be built two ways: blocks in
	 * `post_content`, and the metabox document in `_sdc_data`. A page that
	 * mixes them needs the styles of both.
	 *
	 * @return string[]
	 */
	public static function types_here() {
		$post = get_post();

		if ( ! $post ) {
			return array();
		}

		$types = self::types_in( (string) $post->post_content );
		$data  = Sdc_Store::get_data( (int) $post->ID );

		if ( isset( $data['components'] ) && is_array( $data['components'] ) ) {
			foreach ( $data['components'] as $component ) {
				if ( is_array( $component ) && ! empty( $component['type'] ) ) {
					$types[] = (string) $component['type'];
				}
			}
		}

		return array_values( array_unique( array_filter( $types, 'is_string' ) ) );
	}

	/**
	 * Component types placed in a piece of block content.
	 *
	 * Recurses into inner blocks: a component inside a columns block is still
	 * on the page.
	 *
	 * @param string $content Post content.
	 * @return string[] Unique types.
	 */
	public static function types_in( $content ) {
		$content = (string) $content;

		if ( false === strpos( $content, 'wp:' . self::PREFIX ) ) {
			return array();
		}

		$types = array();

		foreach ( self::walk( parse_blocks( $content ) ) as $type ) {
			$types[ $type ] = true;
		}

		return array_keys( $types );
	}

	/**
	 * Collect component types while walking a parsed tree.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function walk( array $blocks ) {
		$types = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			// The legacy block's own name is not a component type: it carries
			// its type in an attribute instead, just below.
			if ( 0 === strpos( $name, self::PREFIX ) && self::LEGACY !== $name ) {
				$types[] = substr( $name, strlen( self::PREFIX ) );
			}

			if ( self::LEGACY === $name && ! empty( $block['attrs']['type'] ) ) {
				$types[] = (string) $block['attrs']['type'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$types = array_merge( $types, self::walk( $block['innerBlocks'] ) );
			}
		}

		return $types;
	}

	/**
	 * Enqueue one component's stylesheet as its own file.
	 *
	 * The fallback path: used when a site turned inlining off, and by a block
	 * rendered outside a page.
	 *
	 * @param string $type Component type.
	 */
	private static function enqueue_style( $type ) {
		$handle = 'sdc-component-' . sanitize_key( $type );

		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			return;
		}

		$src = Sdc_Frontend::component_style_url( $type );

		if ( '' === $src ) {
			return;
		}

		$file = Sdc_View::style( $type );

		wp_enqueue_style( $handle, $src, array(), (string) filemtime( $file ) );
	}
}

/**
 * SDC components as blocks — one block type per component.
 *
 * The server registers `sdc/faq`, `sdc/hero`, … and hands this file the field
 * schema. The inspector is built from it, and the canvas shows the component's
 * real frontend markup via `ServerSideRender`. No build step: plain
 * `wp.element.createElement`, so the file ships as it is.
 *
 * Three decisions are deliberate:
 *
 * 1. One block per type. A single generic block put "SDC-Komponente" on every
 *    instance, and three FAQ blocks on a page became indistinguishable in the
 *    list view. The type is in the block name now, and `__experimentalLabel`
 *    adds the first value, so "FAQ: Häufige Fragen" tells them apart.
 *
 * 2. No typing in the canvas. The preview is the component's own markup, and a
 *    hand-written `render.php` can place its values anywhere — a client-side
 *    editor could not know where. Clicking a value in the canvas opens and
 *    focuses its control in the sidebar instead.
 *
 * 3. The preview renders when the block is on screen or selected, not before.
 *    `ServerSideRender` is a REST round trip per block; a page with twelve
 *    components would otherwise fire twelve requests on load. Edits are
 *    debounced on top of that: one request when typing pauses, not one per
 *    keystroke.
 *
 * The row mutations — add, layout switch, move, remove, field value — live in
 * `assets/inspector-rows.js`, enqueued before this file. They are pure
 * functions there, so node can run them without a browser; a row that loses
 * `acf_fc_layout` renders empty on the server and is dropped, which is too
 * quiet a failure to leave untested.
 */
( function () {
	'use strict';

	var config = window.SDC_BLOCK || {};
	var schema = config.components || {};
	var prefix = config.prefix || 'sdc/';

	// The slug the server registered the category under, so both sides cannot
	// drift apart on a typo the inserter would answer with an empty category.
	var category = config.category || 'sdc-components';

	/*
	 * Row edits live in `assets/inspector-rows.js`: pure functions, so they can
	 * be run in node without a browser. Missing means the enqueue order broke,
	 * and a second copy of the logic here would be the silent fallback that
	 * drifts — so this is a hard error, not a catch.
	 */
	var rowsApi = window.SDCBlockRows;

	if ( ! rowsApi || typeof rowsApi.setLayout !== 'function' ) {
		throw new Error( 'SDC-Block: assets/inspector-rows.js fehlt (window.SDCBlockRows).' );
	}

	/*
	 * `assets/media-field.js` reads a media value — ID, URL or attachment
	 * array. Same rule as above: without it the picker cannot tell a stored
	 * URL from an ID, and an ID read as a URL renders `<img src="42">`.
	 */
	var mediaApi = window.SDCMediaField;

	if ( ! mediaApi || typeof mediaApi.idOf !== 'function' ) {
		throw new Error( 'SDC-Block: assets/media-field.js fehlt (window.SDCMediaField).' );
	}

	if ( ! window.wp || ! wp.blocks || ! wp.blocks.registerBlockType || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;

	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useSelect = wp.data.useSelect;

	var PanelBody = wp.components.PanelBody;
	var PanelRow = wp.components.PanelRow;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var Button = wp.components.Button;

	var ServerSideRender = wp.serverSideRender;

	/**
	 * Set one key of the data attribute.
	 *
	 * @param {Object}   data   Current data.
	 * @param {Function} commit Setter receiving the new data.
	 * @param {string}   name   Field name.
	 * @param {*}        value  New value.
	 */
	function withValue( data, commit, name, value ) {
		var next = Object.assign( {}, data );
		next[ name ] = value;
		commit( next );
	}

	/**
	 * Open the block sidebar and focus the control of one field.
	 *
	 * The canvas only knows names, not controls: the preview is server markup
	 * carrying `data-sdc-name`. So the click describes a field, and this
	 * finds the input that writes it — in the DOM, because the inspector is
	 * not addressable from outside React.
	 *
	 * @param {string} name Field name.
	 */
	function focusField( name ) {
		if ( ! name ) {
			return;
		}

		var editPost = wp.data.dispatch( 'core/edit-post' );

		if ( editPost && editPost.openGeneralSidebar ) {
			editPost.openGeneralSidebar( 'edit-post/block' );
		}

		// The panel may have been closed, so it has to mount before it can be
		// searched. Two frames plus a short wait is enough in practice, and a
		// miss only means "sidebar opened, control not focused".
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				window.setTimeout( function () {
					var holder = document.querySelector(
						'.sdc-block__field[data-sdc-field="' + name.replace( /"/g, '' ) + '"]'
					);

					if ( ! holder ) {
						return;
					}

					var input = holder.querySelector( 'input, select, textarea, button' );

					if ( input ) {
						input.focus();
						input.scrollIntoView( { block: 'center', behavior: 'smooth' } );
					}
				}, 120 );
			} );
		} );
	}

	/**
	 * The field name under a click in the canvas.
	 *
	 * Markup carries `data-sdc-name` on leaf fields; the generic renderer puts
	 * `sdc-field--<name>` in the class list instead, so both are read.
	 *
	 * @param {Element} node Clicked node.
	 * @return {string} Field name, empty when the click was not on a field.
	 */
	function fieldAt( node ) {
		var origin = node && node.nodeType === 1 ? node : node && node.parentElement;

		if ( ! origin || ! origin.closest ) {
			return '';
		}

		var holder = origin.closest( '[data-sdc-name]' );

		if ( holder ) {
			return holder.getAttribute( 'data-sdc-name' ) || '';
		}

		var leaf = origin.closest( '[class*="sdc-field--"]' );

		if ( ! leaf ) {
			return '';
		}

		var match = /sdc-field--([a-z0-9_-]+)/i.exec( leaf.className );

		return match ? match[ 1 ] : '';
	}

	/**
	 * Wrap a control so a canvas click can find it by field name.
	 *
	 * The list element carries the key, not the control inside it: React
	 * reconciles the position in the `map` array, and a control without a
	 * keyed wrapper makes every field list silently positional.
	 *
	 * @param {Object} field Field definition.
	 * @param {Object} child Control element.
	 * @param {string} key   React key for the wrapper.
	 * @return {Object} Element.
	 */
	function holder( field, child, key ) {
		return el(
			'div',
			{ key: key, className: 'sdc-block__field', 'data-sdc-field': field.name },
			child
		);
	}

	/**
	 * One field's control.
	 *
	 * @param {Object}   field  Field definition from the schema.
	 * @param {*}        value  Current value.
	 * @param {Function} commit Setter for this field.
	 * @param {string}   key    React key.
	 * @return {Object} Element.
	 */
	function control( field, value, commit, key ) {
		var label = field.label + ( field.required ? ' *' : '' );
		var help = field.instructions || undefined;
		var common = { key: key, label: label, help: help };

		switch ( field.type ) {
			case 'textarea':
			case 'wysiwyg':
				return holder(
					field,
					el(
						TextareaControl,
						Object.assign( {}, common, {
							value: typeof value === 'string' ? value : '',
							rows: 4,
							onChange: commit,
						} )
					),
					key
				);

			case 'true_false':
				return holder(
					field,
					el(
						ToggleControl,
						Object.assign( {}, common, {
							checked: !! value,
							onChange: commit,
						} )
					),
					key
				);

			case 'select':
			case 'radio':
			case 'button_group':
				return holder(
					field,
					el(
						SelectControl,
						Object.assign( {}, common, {
							value: typeof value === 'string' ? value : '',
							options: [ { value: '', label: __( '— bitte wählen —', 'sdc-page-builder' ) } ].concat(
								( field.choices || [] ).map( function ( choice ) {
									return { value: choice.value, label: choice.label };
								} )
							),
							onChange: commit,
						} )
					),
					key
				);

			case 'number':
			case 'range':
				return holder(
					field,
					el(
						TextControl,
						Object.assign( {}, common, {
							type: 'number',
							min: field.min,
							max: field.max,
							step: field.step,
							value: value === undefined || value === null ? '' : String( value ),
							onChange: function ( next ) {
								commit( '' === next ? '' : Number( next ) );
							},
						} )
					),
					key
				);

			case 'link':
				return linkControl( field, value, commit, key );

			/*
			 * Media is a value chosen from the library, not typed. Without a
			 * own branch these fell through to the text input below and the
			 * author had to know an attachment ID by heart.
			 */
			case 'image':
			case 'file':
			case 'gallery':
				return holder(
					field,
					el( MediaField, { key: key, field: field, value: value, commit: commit } ),
					key
				);

			case 'repeater':
				return repeaterControl( field, value, commit, key );

			case 'group':
				return groupControl( field, value, commit, key );

			case 'flexible_content':
				return flexibleContentControl( field, value, commit, key );

			default:
				// text, email, url and every type without a dedicated
				// control: a plain text input beats a missing field.
				return holder(
					field,
					el(
						TextControl,
						Object.assign( {}, common, {
							value: value === undefined || value === null ? '' : String( value ),
							onChange: commit,
						} )
					),
					key
				);
		}
	}

	/**
	 * The attachment IDs in a media value.
	 *
	 * @param {*} value Gallery array, single value or nothing.
	 * @return {number[]} IDs, empty when the value carries none.
	 */
	function mediaIds( value ) {
		if ( ! Array.isArray( value ) ) {
			var one = mediaApi.idOf( value );

			return one > 0 ? [ one ] : [];
		}

		return value.map( mediaApi.idOf ).filter( function ( id ) {
			return id > 0;
		} );
	}

	/**
	 * What the inspector needs to draw an attachment.
	 *
	 * A non-image attachment gets no thumbnail: WordPress lists a PDF's
	 * filename, not a picture of it, and an `<img>` pointing at a PDF is a
	 * broken icon in the sidebar.
	 *
	 * @param {Object} attachment Backbone model or plain object.
	 * @return {Object} `{ id, url, thumb, name }`.
	 */
	function describeAttachment( attachment ) {
		var json = attachment && attachment.toJSON ? attachment.toJSON() : ( attachment || {} );
		var sizes = json.sizes || {};
		var thumb = sizes.thumbnail || sizes.medium || null;
		var isImage = 'image' === json.type || ( ! json.type && !! thumb );

		return {
			id: mediaApi.idOf( json ),
			url: json.url || '',
			thumb: isImage ? ( thumb && thumb.url ? thumb.url : ( json.url || '' ) ) : '',
			name: json.filename || json.title || '',
		};
	}

	/**
	 * A media field: choose from the library, see what is chosen, remove it.
	 *
	 * Stored value is the attachment ID. A value that is already a URL keeps
	 * showing as a picture and turns into an ID on the next pick — the server
	 * reads both, and rewriting data the author did not touch is not this
	 * control's business.
	 *
	 * A component, not a function returning elements: the preview state needs
	 * hooks, and hooks called from the field list would sit in the parent's
	 * render order — one field appearing or disappearing would then break the
	 * order of every control after it.
	 *
	 * @param {Object} props `{ field, value, commit }`.
	 * @return {Object} Element.
	 */
	function MediaField( props ) {
		var field = props.field;
		var multiple = 'gallery' === field.type;
		var isImage = 'file' !== field.type;

		var ids = mediaIds( props.value );
		var url = multiple ? '' : mediaApi.urlOf( props.value );

		if ( ! multiple ) {
			ids = ids.slice( 0, 1 );
		}

		var signature = ids.join( ',' );

		var preview = useState( {} );
		var known = preview[ 0 ];
		var setKnown = preview[ 1 ];

		/*
		 * A restored block carries IDs only. Fetching what is missing gives
		 * the tiles a picture instead of "Anhang 42" — and a request per
		 * attachment is why this stops at twelve.
		 */
		useEffect( function () {
			if ( ! window.wp || ! wp.media || ! wp.media.attachment ) {
				return undefined;
			}

			var missing = ids.filter( function ( id ) {
				return ! known[ id ];
			} ).slice( 0, 12 );

			if ( ! missing.length ) {
				return undefined;
			}

			var live = true;

			missing.forEach( function ( id ) {
				wp.media.attachment( id ).fetch().then( function () {
					if ( ! live ) {
						return;
					}

					setKnown( function ( previous ) {
						var next = Object.assign( {}, previous );
						next[ id ] = describeAttachment( wp.media.attachment( id ) );

						return next;
					} );
				} );
			} );

			return function () {
				live = false;
			};
		}, [ signature ] );

		function open() {
			if ( ! wp.media ) {
				return;
			}

			var title = multiple
				? __( 'Dateien auswählen', 'sdc-page-builder' )
				: ( isImage ? __( 'Bild auswählen', 'sdc-page-builder' ) : __( 'Datei auswählen', 'sdc-page-builder' ) );

			var frame = wp.media( {
				title: title,
				button: { text: __( 'Übernehmen', 'sdc-page-builder' ) },
				library: isImage ? { type: 'image' } : {},
				multiple: multiple ? 'add' : false,
			} );

			var state = frame.state();
			var selection = state && state.get ? state.get( 'selection' ) : null;

			// Preselect what is stored, so reopening the frame shows the
			// current choice instead of an untouched library.
			if ( selection && selection.add ) {
				ids.forEach( function ( id ) {
					selection.add( wp.media.attachment( id ) );
				} );
			}

			frame.on( 'select', function () {
				var chosen = frame.state().get( 'selection' ).toJSON();
				var picked = {};

				chosen.forEach( function ( attachment ) {
					picked[ mediaApi.idOf( attachment ) ] = describeAttachment( attachment );
				} );

				setKnown( function ( previous ) {
					return Object.assign( {}, previous, picked );
				} );

				if ( multiple ) {
					props.commit( chosen.map( mediaApi.valueFor ).filter( function ( id ) {
						return '' !== id;
					} ) );

					return;
				}

				props.commit( chosen.length ? mediaApi.valueFor( chosen[ 0 ] ) : '' );
			} );

			frame.open();
		}

		function remove() {
			props.commit( multiple ? [] : '' );
		}

		var tiles = ids.map( function ( id ) {
			var info = known[ id ] || {};
			var thumb = isImage ? ( info.thumb || '' ) : '';

			return el(
				'div',
				{ key: 'tile-' + id, className: 'sdc-block__media-item' },
				thumb ? el( 'img', { className: 'sdc-block__media-thumb', src: thumb, alt: '' } ) : null,
				el(
					'span',
					{ className: 'sdc-block__media-name' },
					info.name || ( __( 'Anhang', 'sdc-page-builder' ) + ' ' + id )
				)
			);
		} );

		return el(
			'div',
			{ className: 'sdc-block__media' },
			el( 'p', { className: 'sdc-block__media-label' }, field.label + ( field.required ? ' *' : '' ) ),
			field.instructions ? el( 'p', { className: 'sdc-block__help' }, field.instructions ) : null,
			url && ! ids.length
				? el( 'img', { className: 'sdc-block__media-thumb', src: url, alt: '' } )
				: null,
			tiles.length ? el( 'div', { className: 'sdc-block__media-list' }, tiles ) : null,
			el(
				'div',
				{ className: 'sdc-block__media-actions' },
				el(
					Button,
					{
						variant: 'secondary',
						onClick: open,
						disabled: ! window.wp || ! wp.media,
					},
					ids.length || url
						? __( 'Ersetzen', 'sdc-page-builder' )
						: __( 'Auswählen', 'sdc-page-builder' )
				),
				ids.length || url
					? el(
						Button,
						{ variant: 'link', isDestructive: true, onClick: remove },
						__( 'Entfernen', 'sdc-page-builder' )
					)
					: null
			)
		);
	}

	/**
	 * ACF's `link` field stores `{ title, url, target }`.
	 *
	 * @param {Object}   field  Field definition.
	 * @param {*}        value  Current value.
	 * @param {Function} commit Setter.
	 * @param {string}   key    React key.
	 * @return {Object} Element.
	 */
	function linkControl( field, value, commit, key ) {
		var current = value && typeof value === 'object' ? value : {};

		function set( part, next ) {
			var merged = Object.assign( {}, current );
			merged[ part ] = next;
			commit( merged );
		}

		return el(
			'div',
			{ key: key, className: 'sdc-block__field sdc-block__link', 'data-sdc-field': field.name },
			el( 'p', { className: 'sdc-block__link-label' }, field.label ),
			field.instructions
				? el( 'p', { className: 'sdc-block__help' }, field.instructions )
				: null,
			el( TextControl, {
				label: __( 'Linktext', 'sdc-page-builder' ),
				value: current.title || '',
				onChange: function ( next ) {
					set( 'title', next );
				},
			} ),
			el( TextControl, {
				label: __( 'Ziel (URL)', 'sdc-page-builder' ),
				type: 'url',
				value: current.url || '',
				onChange: function ( next ) {
					set( 'url', next );
				},
			} )
		);
	}

	/**
	 * A repeater: rows of sub-fields, add / remove / move.
	 *
	 * Order is content, so moving rows is part of the control, not a comfort
	 * feature — a repeater that cannot reorder is half a function.
	 *
	 * @param {Object}   field  Field definition.
	 * @param {*}        value  Current rows.
	 * @param {Function} commit Setter.
	 * @param {string}   key    React key.
	 * @return {Object} Element.
	 */
	function repeaterControl( field, value, commit, key ) {
		var rows = Array.isArray( value ) ? value : [];
		var subFields = field.sub_fields || [];

		function add() {
			commit( rows.concat( [ rowsApi.blankFields( subFields ) ] ) );
		}

		function remove( index ) {
			commit( rowsApi.removeRow( rows, index ) );
		}

		function move( index, offset ) {
			commit( rowsApi.moveRow( rows, index, offset ) );
		}

		var items = rows.map( function ( row, index ) {
			var current = row && typeof row === 'object' ? row : {};

			return el(
				'div',
				{ key: 'row-' + index, className: 'sdc-block__row' },
				el(
					'div',
					{ className: 'sdc-block__row-head' },
					el( 'span', null, field.label + ' ' + ( index + 1 ) ),
					el( Button, {
						isSmall: true,
						variant: 'tertiary',
						disabled: index === 0,
						onClick: function () {
							move( index, -1 );
						},
					}, '↑' ),
					el( Button, {
						isSmall: true,
						variant: 'tertiary',
						disabled: index === rows.length - 1,
						onClick: function () {
							move( index, 1 );
						},
					}, '↓' ),
					el( Button, {
						isSmall: true,
						isDestructive: true,
						variant: 'tertiary',
						onClick: function () {
							remove( index );
						},
					}, __( 'Entfernen', 'sdc-page-builder' ) )
				),
				subFields.map( function ( sub ) {
					return control(
						sub,
						current[ sub.name ],
						function ( next ) {
							commit( rowsApi.setField( rows, index, sub.name, next ) );
						},
						sub.name
					);
				} )
			);
		} );

		return el(
			'div',
			{ key: key, className: 'sdc-block__repeater' },
			el( 'p', { className: 'sdc-block__link-label' }, field.label ),
			field.instructions ? el( 'p', { className: 'sdc-block__help' }, field.instructions ) : null,
			items,
			el(
				Button,
				{ variant: 'secondary', onClick: add },
				field.button_label || __( 'Zeile hinzufügen', 'sdc-page-builder' )
			)
		);
	}

	/**
	 * A group: a fixed set of sub-fields stored under one key.
	 *
	 * ACF keeps a group as an object keyed by sub-field name. Without a
	 * control of its own the group fell through to a text input, which wrote a
	 * string where the renderer reads a row — the stored values were replaced,
	 * not edited. So this writes an object, always.
	 *
	 * @param {Object}   field  Field definition.
	 * @param {*}        value  Current values.
	 * @param {Function} commit Setter.
	 * @param {string}   key    React key.
	 * @return {Object} Element.
	 */
	function groupControl( field, value, commit, key ) {
		var current = value && typeof value === 'object' && ! Array.isArray( value ) ? value : {};
		var subFields = field.sub_fields || [];

		function set( name, next ) {
			var merged = Object.assign( {}, current );
			merged[ name ] = next;
			commit( merged );
		}

		return el(
			'div',
			{ key: key, className: 'sdc-block__group', 'data-sdc-field': field.name },
			el( 'p', { className: 'sdc-block__link-label' }, field.label ),
			field.instructions ? el( 'p', { className: 'sdc-block__help' }, field.instructions ) : null,
			subFields.map( function ( sub ) {
				return control(
					sub,
					current[ sub.name ],
					function ( next ) {
						set( sub.name, next );
					},
					sub.name
				);
			} )
		);
	}

	/**
	 * A flexible content field: rows whose fields depend on their layout.
	 *
	 * The layout is part of the row's value, not of the field: ACF stores it
	 * as `acf_fc_layout`, and the renderer picks the sub-fields by that key. A
	 * row that does not carry it renders empty on the server, so the control
	 * always writes it — on creation, and again whenever the author switches
	 * the layout.
	 *
	 * Rows keep their values when the layout changes: switching back must not
	 * cost the work already typed, and the renderer simply ignores keys the
	 * new layout does not declare.
	 *
	 * @param {Object}   field  Field definition.
	 * @param {*}        value  Current rows.
	 * @param {Function} commit Setter.
	 * @param {string}   key    React key.
	 * @return {Object} Element.
	 */
	function flexibleContentControl( field, value, commit, key ) {
		var rows = Array.isArray( value ) ? value : [];
		var layouts = field.layouts || [];

		function add() {
			// A blank row still names its layout, otherwise the server has
			// no fields to render for it and silently drops the row.
			commit( rowsApi.addRow( rows, layouts ) );
		}

		function remove( index ) {
			commit( rowsApi.removeRow( rows, index ) );
		}

		function move( index, offset ) {
			commit( rowsApi.moveRow( rows, index, offset ) );
		}

		var items = rows.map( function ( row, index ) {
			var current = row && typeof row === 'object' ? row : {};
			var layout = rowsApi.layoutForRow( layouts, current );
			var options = layouts.map( function ( entry ) {
				return { value: entry.name, label: entry.label || entry.name };
			} );

			if ( ! layout ) {
				return el(
					'div',
					{ key: 'row-' + index, className: 'sdc-block__row' },
					el( 'div', { className: 'sdc-block__row-head' }, el( 'span', null, field.label + ' ' + ( index + 1 ) ) ),
					el( 'p', { className: 'sdc-block__help' }, __( 'Diese Zeile hat kein Layout. Es wird nichts angezeigt.', 'sdc-page-builder' ) ),
					el( SelectControl, {
						label: __( 'Layout', 'sdc-page-builder' ),
						value: '',
						options: [ { value: '', label: __( '— bitte wählen —', 'sdc-page-builder' ) } ].concat( options ),
						onChange: function ( next ) {
							if ( ! next ) {
								return;
							}

							commit( rowsApi.setLayout( rows, index, next ) );
						},
					} )
				);
			}

			return el(
				'div',
				{ key: 'row-' + index, className: 'sdc-block__row' },
				el(
					'div',
					{ className: 'sdc-block__row-head' },
					el( 'span', null, field.label + ' ' + ( index + 1 ) ),
					el( Button, {
						isSmall: true,
						variant: 'tertiary',
						disabled: index === 0,
						onClick: function () {
							move( index, -1 );
						},
					}, '↑' ),
					el( Button, {
						isSmall: true,
						variant: 'tertiary',
						disabled: index === rows.length - 1,
						onClick: function () {
							move( index, 1 );
						},
					}, '↓' ),
					el( Button, {
						isSmall: true,
						isDestructive: true,
						variant: 'tertiary',
						onClick: function () {
							remove( index );
						},
					}, __( 'Entfernen', 'sdc-page-builder' ) )
				),
				el( SelectControl, {
					label: __( 'Layout', 'sdc-page-builder' ),
					value: layout.name,
					options: options,
					onChange: function ( next ) {
						commit( rowsApi.setLayout( rows, index, next ) );
					},
				} ),
				( layout.sub_fields || [] ).map( function ( sub ) {
					return control(
						sub,
						current[ sub.name ],
						function ( next ) {
							commit( rowsApi.setField( rows, index, sub.name, next ) );
						},
						sub.name
					);
				} )
			);
		} );

		return el(
			'div',
			{ key: key, className: 'sdc-block__flexible', 'data-sdc-field': field.name },
			el( 'p', { className: 'sdc-block__link-label' }, field.label ),
			field.instructions ? el( 'p', { className: 'sdc-block__help' }, field.instructions ) : null,
			layouts.length ? items : el( 'p', { className: 'sdc-block__help' }, __( 'Dieses Feld hat keine Layouts.', 'sdc-page-builder' ) ),
			layouts.length
				? el(
					Button,
					{ variant: 'secondary', onClick: add },
					field.button_label || __( 'Zeile hinzufügen', 'sdc-page-builder' )
				)
				: null
		);
	}

	/**
	 * Field controls for a component definition.
	 *
	 * @param {Object}   definition Component definition.
	 * @param {Object}   data       Current data.
	 * @param {Function} commit     Setter for the whole data object.
	 * @return {Array} Elements.
	 */
	function controls( definition, data, commit ) {
		return ( definition.fields || [] ).map( function ( field ) {
			return control(
				field,
				data[ field.name ],
				function ( next ) {
					withValue( data, commit, field.name, next );
				},
				field.name
			);
		} );
	}

	/**
	 * The first value in the data that reads as a title.
	 *
	 * Used for the list view and the breadcrumb: "FAQ: Häufige Fragen" tells
	 * three blocks apart, "FAQ" three times does not.
	 *
	 * @param {Object} definition Component definition.
	 * @param {Object} data       Component data.
	 * @return {string} Text, empty when the component is still empty.
	 */
	function firstText( definition, data ) {
		var fields = definition.fields || [];
		var i;

		for ( i = 0; i < fields.length; i++ ) {
			var field = fields[ i ];

			if ( [ 'text', 'textarea', 'wysiwyg', 'email', 'url' ].indexOf( field.type ) === -1 ) {
				continue;
			}

			var value = data[ field.name ];

			if ( typeof value === 'string' && value.trim() ) {
				var text = value.replace( /\s+/g, ' ' ).trim();

				return text.length > 42 ? text.slice( 0, 41 ) + '…' : text;
			}
		}

		return '';
	}

	/**
	 * The canvas preview.
	 *
	 * Mounts the server render once the block is on screen or selected, and
	 * re-requests it only after the attributes have stopped changing. Before
	 * mounting it reserves a line of height, so the block is reachable and the
	 * observer fires.
	 *
	 * @param {Object} props      Block props.
	 * @param {Object} definition Component definition.
	 * @param {string} name       Block name.
	 * @return {Object} Element.
	 */
	function Preview( props, definition, name ) {
		var mounted = useState( false );
		var isMounted = mounted[ 0 ];
		var setMounted = mounted[ 1 ];
		var ref = useRef( null );

		/*
		 * The preview is a REST round trip and `ServerSideRender` fires one per
		 * attribute change: measured, twelve fast keystrokes produced five
		 * requests, and each of them renders the whole component server-side.
		 * Holding the attributes that go into the request until typing pauses
		 * turns a typed word into one round trip instead of one per character.
		 * The canvas keeps the previous markup until then, so nothing blinks.
		 */
		var snapshot = useState( {
			signature: JSON.stringify( props.attributes ),
			attributes: props.attributes,
		} );
		var shown = snapshot[ 0 ];
		var setShown = snapshot[ 1 ];
		var signature = JSON.stringify( props.attributes );
		var deferred = signature !== shown.signature;

		useEffect( function () {
			if ( ! deferred ) {
				return undefined;
			}

			var timer = window.setTimeout( function () {
				setShown( { signature: signature, attributes: props.attributes } );
			}, 300 );

			return function () {
				window.clearTimeout( timer );
			};
		}, [ deferred, signature ] );

		var selected = useSelect( function ( select ) {
			return select( 'core/block-editor' ).isBlockSelected( props.clientId );
		}, [ props.clientId ] );

		useEffect( function () {
			if ( isMounted ) {
				return undefined;
			}

			var node = ref.current;

			if ( ! node || ! window.IntersectionObserver ) {
				setMounted( true );
				return undefined;
			}

			var observer = new window.IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						setMounted( true );
						observer.disconnect();
					}
				} );
			}, { rootMargin: '200px' } );

			observer.observe( node );

			return function () {
				observer.disconnect();
			};
		}, [ isMounted ] );

		if ( ! ServerSideRender ) {
			return el( 'p', null, __( 'ServerSideRender fehlt — Vorschau nicht verfügbar.', 'sdc-page-builder' ) );
		}

		if ( ! isMounted && ! selected ) {
			return el(
				'div',
				{ ref: ref, className: 'sdc-block__pending' },
				el( 'span', null, definition.label )
			);
		}

		return el(
			'div',
			{ ref: ref },
			el( ServerSideRender, {
				block: name,
				attributes: shown.attributes,
				EmptyResponsePlaceholder: function () {
					return el(
						'div',
						{ className: 'sdc-block__empty' },
						el( 'p', null, definition.label + ': ' + __( 'noch keine Inhalte.', 'sdc-page-builder' ) ),
						el( 'p', { className: 'sdc-block__help' }, __( 'Werte rechts in der Seitenleiste eintragen.', 'sdc-page-builder' ) )
					);
				},
			} )
		);
	}

	/**
	 * Register one block.
	 *
	 * Shared by the per-type blocks and by the legacy block, because they
	 * differ in one thing only: where the component definition comes from. A
	 * typed block knows it from its closure; the legacy block reads it from the
	 * `type` attribute, so old content keeps its inspector.
	 *
	 * @param {Object} options Block settings.
	 */
	function registerBlock( options ) {
		var definitionFor = options.definitionFor;

		wp.blocks.registerBlockType( options.name, {
			apiVersion: 3,
			title: options.title,
			description: options.description,
			icon: options.icon || 'layout',
			category: category,
			keywords: options.keywords || [ 'sdc' ],
			attributes: options.attributes || {
				data: { type: 'object', default: {} },
			},
			supports: options.supports,

			/*
			 * The name WordPress shows in the list view, the breadcrumb and the
			 * toolbar. Experimental on purpose: core reads it, and if the API
			 * disappears the block title remains — no loss of function.
			 */
			__experimentalLabel: function ( attributes ) {
				var definition = definitionFor( attributes );

				if ( ! definition ) {
					return options.title;
				}

				var text = firstText( definition, attributes.data || {} );

				return text ? definition.label + ': ' + text : definition.label;
			},

			edit: function ( props ) {
				var definition = definitionFor( props.attributes );
				var data = props.attributes.data || {};
				var blockProps = useBlockProps
					? useBlockProps( {
						className: 'sdc-block',
						onClickCapture: function ( event ) {
							var field = fieldAt( event.target );

							// A click into the preview means "edit this value",
							// not "follow a link inside the component".
							event.preventDefault();

							if ( field ) {
								focusField( field );
							}
						},
					} )
					: { className: 'sdc-block' };

				// Content can name a component this site no longer has. Saying
				// so beats an empty block with an empty sidebar.
				if ( ! definition ) {
					return el(
						'div',
						blockProps,
						el(
							'div',
							{ className: 'sdc-block__empty' },
							el( 'p', null, __( 'Diese Komponente gibt es auf dieser Seite nicht (mehr).', 'sdc-page-builder' ) ),
							el( 'p', { className: 'sdc-block__help' }, props.attributes.type || '' )
						)
					);
				}

				return el(
					Fragment,
					null,
					el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: definition.label, initialOpen: true },
							definition.description
								? el( PanelRow, null, el( 'p', { className: 'sdc-block__help' }, definition.description ) )
								: null,
							controls( definition, data, function ( next ) {
								props.setAttributes( { data: next } );
							} )
						)
					),
					el( 'div', blockProps, Preview( props, definition, options.name ) )
				);
			},

			// Dynamic block: the markup comes from PHP, never from the post.
			save: function () {
				return null;
			},
		} );
	}

	var noSupports = {
		html: false,
		className: false,
		customClassName: false,
		anchor: false,
		align: false,
		spacing: false,
	};

	/**
	 * One block per component, named after the component.
	 *
	 * `__experimentalLabel` gets its own definition from the closure, so the
	 * list view shows "FAQ: Häufige Fragen" and three FAQ blocks on a page are
	 * tellable apart.
	 */
	function registerComponent( type, definition ) {
		registerBlock( {
			name: prefix + type,
			title: definition.label,
			description: definition.description || __( 'Komponente aus dem SDC-Baukasten.', 'sdc-page-builder' ),
			icon: definition.icon,
			keywords: [ 'sdc', type ],
			supports: noSupports,
			definitionFor: function () {
				return definition;
			},
		} );
	}

	/**
	 * The first build's block, kept for content that is already written.
	 *
	 * It is not in the inserter — the type is fixed once written, so offering
	 * it would ask the author to pick a component twice. Old pages still open,
	 * show their component and keep their inspector; the definition comes from
	 * the stored `type` attribute.
	 */
	function registerLegacy() {
		registerBlock( {
			name: 'sdc/component',
			title: __( 'SDC-Komponente (alt)', 'sdc-page-builder' ),
			description: __( 'Komponente aus einer früheren Fassung.', 'sdc-page-builder' ),
			icon: 'layout',
			keywords: [ 'sdc', 'legacy' ],
			attributes: {
				type: { type: 'string', default: '' },
				data: { type: 'object', default: {} },
			},
			supports: Object.assign( {}, noSupports, { inserter: false } ),
			definitionFor: function ( attributes ) {
				return schema[ attributes.type ] || null;
			},
		} );
	}

	Object.keys( schema ).forEach( function ( type ) {
		registerComponent( type, schema[ type ] );
	} );

	registerLegacy();
}() );

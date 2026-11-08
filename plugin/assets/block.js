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
 *    components would otherwise fire twelve requests on load.
 */
( function () {
	'use strict';

	var config = window.SDC_BLOCK || {};
	var schema = config.components || {};
	var prefix = config.prefix || 'sdc/';

	// The slug the server registered the category under, so both sides cannot
	// drift apart on a typo the inserter would answer with an empty category.
	var category = config.category || 'sdc-components';

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
	 * Replace one row of a repeater.
	 *
	 * @param {Array}    rows   Current rows.
	 * @param {Function} commit Setter.
	 * @param {number}   index  Row index.
	 * @param {Object}   row    New row.
	 */
	function replaceRow( rows, commit, index, row ) {
		var next = rows.slice();
		next[ index ] = row;
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
	 * @param {Object} field Field definition.
	 * @param {Object} child Control element.
	 * @return {Object} Element.
	 */
	function holder( field, child ) {
		return el(
			'div',
			{ className: 'sdc-block__field', 'data-sdc-field': field.name },
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
					)
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
					)
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
					)
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
					)
				);

			case 'link':
				return linkControl( field, value, commit, key );

			case 'repeater':
				return repeaterControl( field, value, commit, key );

			default:
				// text, email, url, image (as attachment ID), and every type
				// without a dedicated control: a plain text input beats a
				// missing field.
				return holder(
					field,
					el(
						TextControl,
						Object.assign( {}, common, {
							value: value === undefined || value === null ? '' : String( value ),
							onChange: commit,
						} )
					)
				);
		}
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
			var blank = {};
			subFields.forEach( function ( sub ) {
				blank[ sub.name ] = '';
			} );
			commit( rows.concat( [ blank ] ) );
		}

		function remove( index ) {
			commit( rows.filter( function ( row, position ) {
				return position !== index;
			} ) );
		}

		function move( index, offset ) {
			var target = index + offset;
			if ( target < 0 || target >= rows.length ) {
				return;
			}
			var next = rows.slice();
			var moved = next.splice( index, 1 )[ 0 ];
			next.splice( target, 0, moved );
			commit( next );
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
							var merged = Object.assign( {}, current );
							merged[ sub.name ] = next;
							replaceRow( rows, commit, index, merged );
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
	 * Mounts the server render once the block is on screen or selected. Before
	 * that it reserves a line of height, so the block is reachable and the
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
				attributes: props.attributes,
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

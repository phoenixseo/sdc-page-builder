/**
 * Field editor for the component screen.
 *
 * A field is a row you can see, not a brace you have to balance. The row list
 * is built here from the JSON already in the textarea; every change writes that
 * JSON back, so the submitted value never depends on the browser having had
 * JavaScript at the moment of the click — and a hand-edited textarea can be
 * taken back over with one button.
 *
 * The type catalog (which types exist, which options each has) is not in this
 * file. It comes from `SDC_FIELD_EDITOR.types`, filled by `Sdc_Field_Editor`:
 * one list in PHP, so the picker and the accepted properties cannot drift.
 *
 * @package SdcPageBuilder
 */
( function () {
	'use strict';

	var config = window.SDC_FIELD_EDITOR;

	if ( ! config || ! config.types ) {
		return;
	}

	var L = config.labels || {};
	var TYPES = {};
	var CONTAINERS = {};

	config.types.forEach( function ( type ) {
		TYPES[ type.type ] = type;
		if ( type.container ) {
			CONTAINERS[ type.type ] = true;
		}
	} );

	/**
	 * Translated label with a fallback, so a missing string is never `undefined`.
	 *
	 * @param {string} key      Label key.
	 * @param {string} fallback Text to use when the key is missing.
	 * @return {string} Label.
	 */
	function text( key, fallback ) {
		return L[ key ] || fallback || key;
	}

	/**
	 * A field name the host will accept: a–z, 0–9, underscore.
	 *
	 * Umlauts are transliterated first, because the server's own `clean_name()`
	 * would delete them: "Überschrift" would arrive as "berschrift".
	 *
	 * @param {string} value Raw text.
	 * @return {string} Clean name.
	 */
	function slug( value ) {
		return String( value || '' )
			.toLowerCase()
			.replace( /ä/g, 'ae' )
			.replace( /ö/g, 'oe' )
			.replace( /ü/g, 'ue' )
			.replace( /ß/g, 'ss' )
			.replace( /[^a-z0-9_]+/g, '' );
	}

	function str( value ) {
		return typeof value === 'string' ? value : ( value === null || value === undefined ? '' : String( value ) );
	}

	function has( object, key ) {
		return Object.prototype.hasOwnProperty.call( object, key );
	}

	function isObject( value ) {
		return !! value && typeof value === 'object' && ! Array.isArray( value );
	}

	/**
	 * One stored field entry as the editor's model node.
	 *
	 * Properties the editor does not own end up in `extras` and are written back
	 * untouched. Without that, opening and saving a component would quietly
	 * strip everything this form does not know — `display_format`, a `toolbar`,
	 * a nested structure of an unknown type.
	 *
	 * @param {Object} entry Field object from the definition file.
	 * @return {Object} Model node.
	 */
	function nodeFromEntry( entry ) {
		entry = isObject( entry ) ? entry : {};

		var type = str( entry.type ) || 'text';
		var definition = TYPES[ type ];
		var owned = { name: 1, label: 1, type: 1, instructions: 1, required: 1, sub_fields: 1 };
		var node = {
			name: str( entry.name ),
			label: str( entry.label ),
			type: type,
			instructions: str( entry.instructions ),
			required: !! entry.required,
			options: {},
			sub_fields: [],
			extras: {},
			open: true
		};

		node.autoName = '' === node.name || node.name === slug( node.label );

		( definition ? definition.options : [] ).forEach( function ( option ) {
			owned[ option.key ] = 1;

			if ( ! has( entry, option.key ) ) {
				return;
			}

			var value = entry[ option.key ];

			if ( option.kind === 'pairs' ) {
				if ( isObject( value ) ) {
					node.options[ option.key ] = value;
				} else if ( value !== null && value !== undefined && value !== '' ) {
					node.extras[ option.key ] = value;
				}
				return;
			}

			// Arrays and objects cannot be shown in a single input; keep them
			// verbatim instead of flattening them into a lossy string.
			if ( value === null || typeof value === 'object' ) {
				node.extras[ option.key ] = value;
				return;
			}

			node.options[ option.key ] = value;
		} );

		Object.keys( entry ).forEach( function ( key ) {
			if ( ! owned[ key ] ) {
				node.extras[ key ] = entry[ key ];
			}
		} );

		if ( Array.isArray( entry.sub_fields ) ) {
			if ( CONTAINERS[ type ] ) {
				node.sub_fields = entry.sub_fields.map( nodeFromEntry );
			} else {
				node.extras.sub_fields = entry.sub_fields;
			}
		}

		return node;
	}

	/**
	 * Model node back to a field object for `definition.json`.
	 *
	 * Order matters for reading the file: name, label, type, then the settings.
	 *
	 * @param {Object} node Model node.
	 * @return {Object} Field object.
	 */
	function entryFromNode( node ) {
		var definition = TYPES[ node.type ];
		var entry = {
			name: node.name,
			label: node.label,
			type: node.type
		};

		if ( node.instructions ) {
			entry.instructions = node.instructions;
		}

		if ( node.required ) {
			entry.required = 1;
		}

		if ( definition ) {
			definition.options.forEach( function ( option ) {
				if ( ! has( node.options, option.key ) ) {
					return;
				}

				var value = node.options[ option.key ];

				if ( option.kind === 'pairs' ) {
					if ( isObject( value ) && Object.keys( value ).length ) {
						entry[ option.key ] = value;
					}
					return;
				}

				if ( option.kind === 'bool' ) {
					if ( value ) {
						entry[ option.key ] = 1;
					}
					return;
				}

				if ( option.kind === 'number' ) {
					if ( value === '' || value === null || value === undefined || isNaN( Number( value ) ) ) {
						return;
					}
					entry[ option.key ] = Number( value );
					return;
				}

				if ( typeof value === 'string' && '' === value.trim() ) {
					return;
				}

				if ( value !== null && value !== undefined ) {
					entry[ option.key ] = value;
				}
			} );

			if ( definition.container && node.sub_fields.length ) {
				entry.sub_fields = node.sub_fields.map( entryFromNode );
			}
		}

		Object.keys( node.extras ).forEach( function ( key ) {
			if ( ! has( entry, key ) ) {
				entry[ key ] = node.extras[ key ];
			}
		} );

		return entry;
	}

	/**
	 * Parse the textarea into model nodes.
	 *
	 * Empty input is an empty list, not an error: a component without fields
	 * is a legal component.
	 *
	 * @param {string} raw JSON.
	 * @return {{list: Array, error: string}} Result.
	 */
	function modelFromJson( raw ) {
		raw = String( raw || '' ).trim();

		if ( '' === raw ) {
			return { list: [], error: '' };
		}

		var decoded;

		try {
			decoded = JSON.parse( raw );
		} catch ( error ) {
			return { list: [], error: error.message };
		}

		if ( ! Array.isArray( decoded ) ) {
			return { list: [], error: 'JSON-Liste erwartet.' };
		}

		return { list: decoded.map( nodeFromEntry ), error: '' };
	}

	/**
	 * Model nodes as the textarea's JSON.
	 *
	 * @param {Array} list Model nodes.
	 * @return {string} JSON, empty string for no fields.
	 */
	function jsonFromModel( list ) {
		if ( ! list.length ) {
			return '';
		}

		return JSON.stringify( list.map( entryFromNode ), null, 2 );
	}

	/**
	 * A fresh node for a type the user just picked.
	 *
	 * @param {string} type Field type.
	 * @return {Object} Model node.
	 */
	function newNode( type ) {
		return {
			name: '',
			label: '',
			type: TYPES[ type ] ? type : 'text',
			instructions: '',
			required: false,
			options: {},
			sub_fields: [],
			extras: {},
			open: true,
			autoName: true
		};
	}

	/* ------------------------------------------------------------- markup */

	function el( tag, className, attributes ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		Object.keys( attributes || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attributes.text;
			} else {
				node.setAttribute( key, attributes[ key ] );
			}
		} );

		return node;
	}

	function button( label, action, className ) {
		return el( 'button', className || 'button button-small', {
			type: 'button',
			'data-act': action,
			'aria-label': label,
			title: label,
			text: label
		} );
	}

	function labelled( label, control, hint ) {
		var wrapper = el( 'p', 'sdc-fe-field' );
		var box = el( 'label', 'sdc-fe-field__label' );

		box.appendChild( el( 'span', 'sdc-fe-field__caption', { text: label } ) );
		box.appendChild( control );
		wrapper.appendChild( box );

		if ( hint ) {
			wrapper.appendChild( el( 'span', 'sdc-fe-field__hint', { text: hint } ) );
		}

		return wrapper;
	}

	/* ------------------------------------------------------------- editor */

	function Editor( root ) {
		this.root = root;
		this.list = root.querySelector( '[data-sdc-fe-list]' );
		this.emptyHint = root.querySelector( '[data-sdc-fe-empty]' );
		this.errorBox = root.querySelector( '[data-sdc-fe-error]' );
		this.textarea = root.querySelector( '#sdc_fields' );
		this.applyButton = root.querySelector( '[data-sdc-fe-apply]' );

		var picker = root.querySelector( '.sdc-fe__add' );

		this.picker = picker ? picker.cloneNode( true ) : null;

		if ( picker && picker.parentNode ) {
			picker.parentNode.removeChild( picker );
		}

		this.form = root.closest( 'form' );
		this.drag = null;

		var parsed = modelFromJson( this.textarea ? this.textarea.value : '' );

		this.model = parsed.list;
		this.jsonError = parsed.error;
		this.broken = '' !== parsed.error;

		this.bind();
		this.render();

		if ( this.broken ) {
			this.showError( text( 'jsonBroken' ).replace( '%s', this.jsonError ) );
		}
	}

	/**
	 * Wire one delegated handler set for the whole editor.
	 *
	 * Rows are re-rendered often; per-row listeners would have to be rebuilt
	 * with them and would be the first thing to leak.
	 */
	Editor.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'input', function ( event ) {
			self.onInput( event );
		} );

		this.root.addEventListener( 'change', function ( event ) {
			self.onChange( event );
		} );

		this.root.addEventListener( 'click', function ( event ) {
			self.onClick( event );
		} );

		this.root.addEventListener( 'dragstart', function ( event ) {
			self.onDragStart( event );
		} );

		this.root.addEventListener( 'dragover', function ( event ) {
			self.onDragOver( event );
		} );

		this.root.addEventListener( 'dragleave', function ( event ) {
			self.onDragLeave( event );
		} );

		this.root.addEventListener( 'drop', function ( event ) {
			self.onDrop( event );
		} );

		if ( this.applyButton ) {
			this.applyButton.addEventListener( 'click', function () {
				self.applyJson();
			} );
		}

		if ( this.textarea ) {
			this.textarea.addEventListener( 'input', function () {
				// A hand-edited textarea is no longer what the rows say; the
				// rows stay put until the user asks for them to be rebuilt.
				self.dirtyJson = true;
			} );
		}

		if ( this.form ) {
			this.form.addEventListener( 'submit', function ( event ) {
				self.onSubmit( event );
			} );
		}
	};

	/**
	 * Row element for a model node.
	 *
	 * @param {Object} node   Model node.
	 * @param {Array}  parent Array the node lives in.
	 * @param {number} index  Position in `parent`.
	 * @param {number} level  Nesting level, 0 for the top list.
	 * @return {HTMLElement} Row.
	 */
	Editor.prototype.row = function ( node, parent, index, level ) {
		var self = this;
		var definition = TYPES[ node.type ];
		var row = el( 'div', 'sdc-fe-row' + ( node.open ? '' : ' is-collapsed' ), { 'data-sdc-row': '' } );
		var head = el( 'div', 'sdc-fe-row__head' );
		var handle = button( 'Ziehen', 'drag', 'sdc-fe-row__handle' );
		var title = el( 'span', 'sdc-fe-row__label' );
		var nameBadge = el( 'code', 'sdc-fe-row__name' );
		var typeBadge = el( 'span', 'sdc-fe-row__type' );
		var actions = el( 'span', 'sdc-fe-row__actions' );
		var body = el( 'div', 'sdc-fe-row__body' );

		row.sdcModel = parent;
		row.sdcIndex = index;
		row.sdcNode = node;

		handle.setAttribute( 'draggable', 'true' );
		handle.textContent = '⠿';
		title.textContent = node.label || text( 'field' );
		nameBadge.textContent = node.name || '—';
		typeBadge.textContent = definition ? definition.label : node.type;

		actions.appendChild( button( text( 'up' ), 'up' ) );
		actions.appendChild( button( text( 'down' ), 'down' ) );
		actions.appendChild( button( node.open ? text( 'collapse' ) : text( 'expand' ), 'toggle' ) );
		actions.appendChild( button( text( 'remove' ), 'remove', 'button button-small sdc-fe-row__remove' ) );

		head.appendChild( handle );
		head.appendChild( el( 'span', 'sdc-fe-row__index', { text: String( index + 1 ) } ) );
		head.appendChild( title );
		head.appendChild( nameBadge );
		head.appendChild( typeBadge );
		head.appendChild( actions );
		row.appendChild( head );

		body.appendChild( this.basics( node ) );
		body.appendChild( this.options( node ) );

		if ( definition && definition.container ) {
			body.appendChild( this.children( node, level + 1 ) );
		}

		row.appendChild( body );

		return row;
	};

	/**
	 * The four inputs every field has, plus its hint and required flag.
	 *
	 * @param {Object} node Model node.
	 * @return {HTMLElement} Block.
	 */
	Editor.prototype.basics = function ( node ) {
		var grid = el( 'div', 'sdc-fe-grid' );
		var self = this;

		var label = el( 'input', '', { type: 'text', 'data-prop': 'label' } );
		label.value = node.label;

		var name = el( 'input', '', { type: 'text', 'data-prop': 'name', pattern: '[a-z0-9_]+' } );
		name.value = node.name;
		name.className = 'code';

		var type = el( 'select', '', { 'data-prop': 'type' } );
		this.fillTypes( type, node.type );

		var instructions = el( 'textarea', '', { 'data-prop': 'instructions', rows: '2' } );
		instructions.value = node.instructions;

		var required = el( 'input', '', { type: 'checkbox', 'data-prop': 'required' } );
		required.checked = !! node.required;

		grid.appendChild( labelled( text( 'label' ), label ) );
		grid.appendChild( labelled( text( 'name' ), name, text( 'nameHint' ) ) );
		grid.appendChild( labelled( text( 'type' ), type ) );
		grid.appendChild( labelled( text( 'instructions' ), instructions ) );

		var flag = el( 'label', 'sdc-fe-flag' );
		flag.appendChild( required );
		flag.appendChild( document.createTextNode( ' ' + text( 'required' ) ) );
		grid.appendChild( flag );

		return grid;
	};

	/**
	 * Fill a type select from the catalog, grouped like the picker.
	 *
	 * @param {HTMLSelectElement} select Select element.
	 * @param {string}            current Current type.
	 */
	Editor.prototype.fillTypes = function ( select, current ) {
		select.textContent = '';

		var groups = {};
		var order = [];

		config.types.forEach( function ( type ) {
			if ( ! has( groups, type.group ) ) {
				groups[ type.group ] = [];
				order.push( type.group );
			}
			groups[ type.group ].push( type );
		} );

		order.forEach( function ( group ) {
			var box = el( 'optgroup', '', { label: group } );

			groups[ group ].forEach( function ( type ) {
				var option = el( 'option', '', { value: type.type, text: type.label } );

				if ( type.type === current ) {
					option.selected = true;
				}

				box.appendChild( option );
			} );

			select.appendChild( box );
		} );

		// A stored type the catalog does not know must stay selected, or the
		// next save would silently turn it into a text field.
		if ( ! TYPES[ current ] ) {
			var unknown = el( 'option', '', { value: current, text: current } );

			unknown.selected = true;
			select.insertBefore( unknown, select.firstChild );
		}

		select.value = current;
	};

	/**
	 * Type-specific settings.
	 *
	 * @param {Object} node Model node.
	 * @return {HTMLElement} Block.
	 */
	Editor.prototype.options = function ( node ) {
		var definition = TYPES[ node.type ];
		var box = el( 'div', 'sdc-fe-options' );

		if ( ! definition || ! definition.options.length ) {
			if ( ! definition ) {
				box.appendChild( el( 'p', 'sdc-fe-note', { text: text( 'unknownType' ) } ) );
			}
			return box;
		}

		definition.options.forEach( function ( option ) {
			box.appendChild( this.option( node, option ) );
		}, this );

		return box;
	};

	/**
	 * One option control.
	 *
	 * @param {Object} node   Model node.
	 * @param {Object} option Option descriptor from the catalog.
	 * @return {HTMLElement} Control block.
	 */
	Editor.prototype.option = function ( node, option ) {
		var value = has( node.options, option.key ) ? node.options[ option.key ] : '';
		var control;

		if ( 'bool' === option.kind ) {
			control = el( 'input', '', { type: 'checkbox', 'data-opt': option.key } );
			control.checked = !! value;

			var flag = el( 'label', 'sdc-fe-flag' );
			flag.appendChild( control );
			flag.appendChild( document.createTextNode( ' ' + option.label ) );

			return flag;
		}

		if ( 'pairs' === option.kind ) {
			return this.pairs( node, option );
		}

		if ( 'select' === option.kind ) {
			control = el( 'select', '', { 'data-opt': option.key } );
			control.appendChild( el( 'option', '', { value: '', text: '—' } ) );

			Object.keys( option.choices || {} ).forEach( function ( key ) {
				var choice = el( 'option', '', { value: key, text: option.choices[ key ] } );
				control.appendChild( choice );
			} );

			control.value = String( value );
			return labelled( option.label, control );
		}

		if ( 'textarea' === option.kind ) {
			control = el( 'textarea', '', { 'data-opt': option.key, rows: '3' } );
			control.value = str( value );
			return labelled( option.label, control );
		}

		control = el( 'input', '', {
			type: 'number' === option.kind ? 'number' : 'text',
			'data-opt': option.key
		} );

		if ( 'number' === option.kind ) {
			if ( option.min !== undefined ) {
				control.setAttribute( 'min', option.min );
			}
			if ( option.max !== undefined ) {
				control.setAttribute( 'max', option.max );
			}
		}

		control.value = str( value );

		return labelled( option.label, control );
	};

	/**
	 * The value/label list a `choices` option needs.
	 *
	 * @param {Object} node   Model node.
	 * @param {Object} option Option descriptor.
	 * @return {HTMLElement} Block.
	 */
	Editor.prototype.pairs = function ( node, option ) {
		var choices = isObject( node.options[ option.key ] ) ? node.options[ option.key ] : {};
		var box = el( 'div', 'sdc-fe-pairs' );
		var keys = Object.keys( choices );
		var list = el( 'div', 'sdc-fe-pairs__list' );

		box.setAttribute( 'data-pairs-for', option.key );
		box.appendChild( el( 'span', 'sdc-fe-field__caption', { text: option.label } ) );

		keys.forEach( function ( key ) {
			var line = el( 'div', 'sdc-fe-pair' );
			var value = el( 'input', 'code', { type: 'text', 'data-pair': 'value', 'data-pair-key': key } );
			var label = el( 'input', '', { type: 'text', 'data-pair': 'label', 'data-pair-key': key } );

			value.value = key;
			value.setAttribute( 'placeholder', text( 'pairValue' ) );
			label.value = str( choices[ key ] );
			label.setAttribute( 'placeholder', text( 'pairLabel' ) );

			line.appendChild( value );
			line.appendChild( label );
			line.appendChild( button( text( 'remove' ), 'remove-pair', 'button button-small sdc-fe-pair__remove' ) );
			list.appendChild( line );
		} );

		box.appendChild( list );
		box.appendChild( button( text( 'pairAdd' ), 'add-pair', 'button button-small' ) );

		return box;
	};

	/**
	 * Nested field list for a container type.
	 *
	 * @param {Object} node  Model node.
	 * @param {number} level Nesting level.
	 * @return {HTMLElement} Block.
	 */
	Editor.prototype.children = function ( node, level ) {
		var box = el( 'div', 'sdc-fe-children' );

		box.appendChild( el( 'span', 'sdc-fe-children__caption', { text: text( 'subFields' ) } ) );
		box.appendChild( this.listFor( node.sub_fields, level ) );

		return box;
	};

	/**
	 * A list of rows with its own picker.
	 *
	 * @param {Array}  parent Array of model nodes.
	 * @param {number} level  Nesting level.
	 * @return {HTMLElement} List container.
	 */
	Editor.prototype.listFor = function ( parent, level ) {
		var box = el( 'div', 'sdc-fe-list', { 'data-sdc-fe-list': '' } );
		var self = this;

		// The list knows which array it renders, so a picker inside it can add
		// to the right level without walking back up the tree.
		box.sdcModel = parent;
		box.sdcLevel = level;

		parent.forEach( function ( node, index ) {
			box.appendChild( self.row( node, parent, index, level ) );
		} );

		if ( ! parent.length ) {
			box.appendChild( el( 'p', 'sdc-fe-empty', { text: text( 'empty' ) } ) );
		}

		if ( this.picker ) {
			var picker = this.picker.cloneNode( true );
			var add = picker.querySelector( '[data-sdc-fe-add]' );

			if ( add ) {
				add.textContent = level > 0 ? text( 'addSubField' ) : text( 'addField' );
			}

			box.appendChild( picker );
		}

		return box;
	};

	/**
	 * Render the whole editor from the model.
	 *
	 * @param {number} focusIndex Row to focus afterwards, or -1.
	 */
	Editor.prototype.render = function ( focusIndex ) {
		var self = this;

		this.list.textContent = '';
		this.list.sdcModel = this.model;
		this.list.sdcLevel = 0;

		this.model.forEach( function ( node, index ) {
			self.list.appendChild( self.row( node, self.model, index, 0 ) );
		} );

		if ( this.emptyHint ) {
			this.emptyHint.hidden = this.model.length > 0;
		}

		if ( this.picker ) {
			var picker = this.picker.cloneNode( true );
			var add = picker.querySelector( '[data-sdc-fe-add]' );

			if ( add ) {
				add.textContent = text( 'addField' );
			}

			this.list.appendChild( picker );
		}

		this.sync();

		if ( 'number' === typeof focusIndex && focusIndex >= 0 ) {
			var rows = this.list.children;
			var target = rows[ focusIndex ];

			if ( target ) {
				var input = target.querySelector( '[data-prop="label"]' );

				if ( input ) {
					input.focus();
				}
			}
		}
	};

	/**
	 * Write the model into the textarea that gets submitted.
	 *
	 * Nothing is written while the stored JSON does not parse: the rows would
	 * be empty, and an empty model would replace a definition with `[]` the
	 * moment anybody pressed save. Broken input stays broken, visibly.
	 */
	Editor.prototype.sync = function () {
		if ( ! this.textarea ) {
			return;
		}

		if ( this.broken ) {
			return;
		}

		this.textarea.value = jsonFromModel( this.model );
		this.dirtyJson = false;
	};

	/* ------------------------------------------------------------- events */

	/**
	 * Row element and model node for an event target.
	 *
	 * @param {Element} target Event target.
	 * @return {Object|null} `{row, node, parent, index}`.
	 */
	function contextFor( target ) {
		var row = target && target.closest ? target.closest( '[data-sdc-row]' ) : null;

		if ( ! row ) {
			return null;
		}

		return { row: row, node: row.sdcNode, parent: row.sdcModel, index: row.sdcIndex };
	}

	function refreshHead( row ) {
		var node = row.sdcNode;
		var definition = TYPES[ node.type ];
		var title = row.querySelector( '.sdc-fe-row__label' );
		var badge = row.querySelector( '.sdc-fe-row__name' );
		var type = row.querySelector( '.sdc-fe-row__type' );

		if ( title ) {
			title.textContent = node.label || text( 'field' );
		}
		if ( badge ) {
			badge.textContent = node.name || '—';
		}
		if ( type ) {
			type.textContent = definition ? definition.label : node.type;
		}
	}

	/**
	 * Typing in a row: update the model, not the DOM.
	 *
	 * Re-rendering on every keystroke would drop the caret, and the header can
	 * be refreshed in place.
	 *
	 * @param {Event} event Input event.
	 */
	Editor.prototype.onInput = function ( event ) {
		var context = contextFor( event.target );

		if ( ! context ) {
			return;
		}

		var node = context.node;
		var target = event.target;
		var prop = target.getAttribute( 'data-prop' );
		var option = target.getAttribute( 'data-opt' );

		if ( 'label' === prop ) {
			node.label = target.value;

			if ( node.autoName ) {
				node.name = slug( target.value );

				var nameInput = context.row.querySelector( '[data-prop="name"]' );

				if ( nameInput ) {
					nameInput.value = node.name;
				}
			}
		} else if ( 'name' === prop ) {
			node.name = target.value;
			node.autoName = false;
		} else if ( 'instructions' === prop ) {
			node.instructions = target.value;
		} else if ( option ) {
			node.options[ option ] = target.value;
		} else {
			var pair = target.getAttribute( 'data-pair' );

			if ( pair ) {
				this.setPair( node, context.row, target, pair );
				return;
			}
		}

		refreshHead( context.row );
		this.sync();
	};

	/**
	 * Change events: checkboxes and the type select.
	 *
	 * @param {Event} event Change event.
	 */
	Editor.prototype.onChange = function ( event ) {
		var context = contextFor( event.target );

		if ( ! context ) {
			return;
		}

		var node = context.node;
		var prop = event.target.getAttribute( 'data-prop' );
		var option = event.target.getAttribute( 'data-opt' );

		if ( 'type' === prop ) {
			this.changeType( node, event.target.value );
			return;
		}

		if ( 'required' === prop ) {
			node.required = event.target.checked;
			this.sync();
			return;
		}

		if ( option ) {
			var definition = TYPES[ node.type ];

			if ( definition ) {
				definition.options.forEach( function ( descriptor ) {
					if ( descriptor.key === option && 'bool' === descriptor.kind ) {
						node.options[ option ] = event.target.checked ? 1 : '';
					}
				} );
			}

			this.sync();
		}
	};

	/**
	 * Swap a row's type.
	 *
	 * Changing the type changes which options exist. Keys the new type also
	 * knows survive; the rest is dropped, because a `rows` count on a text
	 * field is a property that does nothing and only confuses the file.
	 *
	 * @param {Object} node Model node.
	 * @param {string} type New type.
	 */
	Editor.prototype.changeType = function ( node, type ) {
		var wasContainer = !! CONTAINERS[ node.type ];
		var isContainer = !! CONTAINERS[ type ];

		if ( wasContainer && ! isContainer && node.sub_fields.length ) {
			if ( ! window.confirm( text( 'removeChildren' ) ) ) {
				this.render();
				return;
			}

			node.sub_fields = [];
		}

		var definition = TYPES[ type ];
		var keeper = {};

		if ( definition ) {
			definition.options.forEach( function ( option ) {
				keeper[ option.key ] = 1;
			} );
		}

		Object.keys( node.options ).forEach( function ( key ) {
			if ( ! keeper[ key ] ) {
				delete node.options[ key ];
			}
		} );

		node.type = TYPES[ type ] ? type : 'text';
		this.render();
	};

	/**
	 * Click handling for every row action.
	 *
	 * @param {Event} event Click event.
	 */
	Editor.prototype.onClick = function ( event ) {
		var target = event.target.closest ? event.target.closest( '[data-act], [data-sdc-fe-add]' ) : null;

		if ( ! target ) {
			return;
		}

		if ( target.hasAttribute( 'data-sdc-fe-add' ) ) {
			event.preventDefault();

			var picker = target.closest( '.sdc-fe__add' );
			var select = picker ? picker.querySelector( '[data-sdc-fe-add-type]' ) : null;
			var list = picker ? picker.closest( '[data-sdc-fe-list]' ) : null;
			// A nested picker sits inside the list it belongs to, so the list
			// answers both questions: which array, which depth.
			var parent = list && list.sdcModel ? list.sdcModel : this.model;
			var level = list && 'number' === typeof list.sdcLevel ? list.sdcLevel : 0;

			this.add( parent, select ? select.value : 'text', level );
			return;
		}

		var context = contextFor( target );

		if ( ! context ) {
			// Pair buttons live outside a row's input context but inside a row.
			context = contextFor( target.closest( '[data-sdc-row]' ) );
		}

		if ( ! context ) {
			return;
		}

		var action = target.getAttribute( 'data-act' );
		var node = context.node;
		var parent = context.parent;

		event.preventDefault();

		if ( 'remove' === action ) {
			if ( ! window.confirm( text( 'removeField' ) ) ) {
				return;
			}
			parent.splice( context.index, 1 );
			this.render();
			return;
		}

		if ( 'toggle' === action ) {
			node.open = ! node.open;
			context.row.classList.toggle( 'is-collapsed', ! node.open );
			target.textContent = node.open ? text( 'collapse' ) : text( 'expand' );
			return;
		}

		if ( 'up' === action || 'down' === action ) {
			var to = 'up' === action ? context.index - 1 : context.index + 1;

			if ( to < 0 || to >= parent.length ) {
				return;
			}

			var moved = parent.splice( context.index, 1 )[ 0 ];
			parent.splice( to, 0, moved );
			this.render();
			return;
		}

		if ( 'add-pair' === action ) {
			var block = target.closest( '[data-pairs-for]' );
			var key = block ? block.getAttribute( 'data-pairs-for' ) : '';

			if ( ! key ) {
				return;
			}

			if ( ! isObject( node.options[ key ] ) ) {
				node.options[ key ] = {};
			}

			var fresh = 'wert' + ( Object.keys( node.options[ key ] ).length + 1 );
			node.options[ key ][ fresh ] = '';
			this.render();
			return;
		}

		if ( 'remove-pair' === action ) {
			var pairs = target.closest( '[data-pairs-for]' );
			var pairsKey = pairs ? pairs.getAttribute( 'data-pairs-for' ) : '';
			var row = target.closest( '.sdc-fe-pair' );
			var valueInput = row ? row.querySelector( '[data-pair="value"]' ) : null;
			var pairKey = valueInput ? valueInput.getAttribute( 'data-pair-key' ) : '';

			if ( pairsKey && pairKey && isObject( node.options[ pairsKey ] ) ) {
				delete node.options[ pairsKey ][ pairKey ];
				this.render();
			}
		}
	};

	/**
	 * Track a pair input and keep the choices object keyed by its value.
	 *
	 * A changed value moves the entry: the key is the value the saved content
	 * is compared against, so it cannot stay behind under the old one.
	 *
	 * @param {Object}      node   Model node.
	 * @param {HTMLElement} row    Row element.
	 * @param {HTMLElement} input  The pair input.
	 * @param {string}      part   `value` or `label`.
	 */
	Editor.prototype.setPair = function ( node, row, input, part ) {
		var block = input.closest( '[data-pairs-for]' );
		var key = block ? block.getAttribute( 'data-pairs-for' ) : '';
		var oldKey = input.getAttribute( 'data-pair-key' );

		if ( ! key || ! isObject( node.options[ key ] ) || null === oldKey ) {
			return;
		}

		var entries = node.options[ key ];
		var label = str( entries[ oldKey ] );

		if ( 'label' === part ) {
			entries[ oldKey ] = input.value;
		} else {
			var newKey = input.value;

			delete entries[ oldKey ];

			if ( '' !== newKey ) {
				entries[ newKey ] = label;
			}

			var labelInput = row.querySelector( '[data-pair="label"][data-pair-key="' + oldKey + '"]' );

			if ( labelInput ) {
				labelInput.setAttribute( 'data-pair-key', newKey );
			}

			input.setAttribute( 'data-pair-key', newKey );
		}

		this.sync();
	};

	/**
	 * Append a field to a list and focus it.
	 *
	 * @param {Array|null} parent List to append to; null for a nested picker
	 *                            whose list is resolved from the DOM.
	 * @param {string}     type   Field type.
	 * @param {number}     level  Nesting level of the picker.
	 */
	Editor.prototype.add = function ( parent, type, level ) {
		if ( ! parent ) {
			return;
		}

		parent.push( newNode( type ) );
		this.render( level === 0 ? parent.length - 1 : -1 );
	};

	/**
	 * Drag start: remember which list the row came from.
	 *
	 * @param {Event} event Drag event.
	 */
	Editor.prototype.onDragStart = function ( event ) {
		var context = contextFor( event.target );

		if ( ! context ) {
			return;
		}

		this.drag = { parent: context.parent, index: context.index };

		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setData( 'text/plain', String( context.index ) );
		}
	};

	/**
	 * Dragging over a row in the same list.
	 *
	 * @param {Event} event Drag event.
	 */
	Editor.prototype.onDragOver = function ( event ) {
		var context = contextFor( event.target );

		if ( ! this.drag || ! context || context.parent !== this.drag.parent ) {
			return;
		}

		event.preventDefault();
		context.row.classList.add( 'is-drop-target' );
	};

	/**
	 * Leaving a row clears its drop highlight.
	 *
	 * @param {Event} event Drag event.
	 */
	Editor.prototype.onDragLeave = function ( event ) {
		var context = contextFor( event.target );

		if ( context ) {
			context.row.classList.remove( 'is-drop-target' );
		}
	};

	/**
	 * Drop: move the dragged row to the target position.
	 *
	 * @param {Event} event Drag event.
	 */
	Editor.prototype.onDrop = function ( event ) {
		var context = contextFor( event.target );

		if ( ! this.drag || ! context || context.parent !== this.drag.parent ) {
			return;
		}

		event.preventDefault();

		var from = this.drag.index;
		var to = context.index;

		this.drag = null;

		if ( from === to ) {
			this.render();
			return;
		}

		var moved = context.parent.splice( from, 1 )[ 0 ];
		context.parent.splice( to, 0, moved );
		this.render();
	};

	/**
	 * Take a hand-edited textarea back into rows.
	 */
	Editor.prototype.applyJson = function () {
		var parsed = modelFromJson( this.textarea ? this.textarea.value : '' );

		if ( parsed.error ) {
			// Remembered, not just shown: `onSubmit` blocks on `broken` and
			// has no parser result of its own to quote.
			this.jsonError = parsed.error;
			this.broken = true;
			this.showError( text( 'jsonBroken' ).replace( '%s', parsed.error ) );
			return;
		}

		this.jsonError = '';
		this.broken = false;
		this.model = parsed.list;
		this.clearError();
		this.render();
	};

	/**
	 * Block a submit that the server would reject anyway, and say where.
	 *
	 * The server stays the authority — this only spares the round trip and the
	 * retyping that comes with it.
	 *
	 * @param {Event} event Submit event.
	 */
	Editor.prototype.onSubmit = function ( event ) {
		if ( this.broken ) {
			event.preventDefault();
			this.showError( text( 'jsonBroken' ).replace( '%s', this.jsonError ) );
			return;
		}

		this.sync();

		var problems = this.validate();

		if ( ! problems.length ) {
			this.clearError();
			return;
		}

		event.preventDefault();
		this.showError( problems.join( ' ' ) );
	};

	/**
	 * Names that are missing or twice on the same level.
	 *
	 * @param {Array}  list  Nodes to check.
	 * @param {Array}  found Collects messages.
	 * @return {string[]} Messages.
	 */
	Editor.prototype.validate = function ( list, found ) {
		var problems = found || [];
		var nodes = list || this.model;
		var seen = {};
		var missing = [];
		var duplicates = [];

		nodes.forEach( function ( node ) {
			var name = str( node.name );

			if ( '' === name ) {
				missing.push( node.label || text( 'field' ) );
				return;
			}

			if ( seen[ name ] ) {
				duplicates.push( name );
			}

			seen[ name ] = true;
		} );

		if ( missing.length ) {
			problems.push( text( 'noName' ).replace( '%s', missing.join( ', ' ) ) );
		}

		if ( duplicates.length ) {
			problems.push( text( 'duplicateName' ).replace( '%s', duplicates.join( ', ' ) ) );
		}

		nodes.forEach( function ( node ) {
			if ( node.sub_fields && node.sub_fields.length ) {
				this.validate( node.sub_fields, problems );
			}
		}, this );

		return problems;
	};

	/**
	 * Show the editor's message line.
	 *
	 * @param {string} message Text.
	 */
	Editor.prototype.showError = function ( message ) {
		if ( ! this.errorBox ) {
			return;
		}

		this.errorBox.textContent = message;
		this.errorBox.hidden = false;
	};

	/**
	 * Hide the editor's message line.
	 */
	Editor.prototype.clearError = function () {
		if ( ! this.errorBox ) {
			return;
		}

		this.errorBox.textContent = '';
		this.errorBox.hidden = true;
	};

	/* --------------------------------------------------------------- boot */

	function boot() {
		var roots = document.querySelectorAll( '[data-sdc-fe]' );

		Array.prototype.forEach.call( roots, function ( root ) {
			if ( root.sdcEditor ) {
				return;
			}

			root.sdcEditor = new Editor( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

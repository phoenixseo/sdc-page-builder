/**
 * Row operations for the SDC inspector.
 *
 * The inspector edits repeaters and flexible content fields: rows of
 * sub-fields, where a flexible row also carries `acf_fc_layout`. The server
 * reads that key to pick the layout, so a row without it renders empty and is
 * dropped — a silent loss in the middle of a page.
 *
 * That is worth a test, and a test needs the logic outside React. So the
 * mutations live here: pure functions, no DOM, no `wp.*`, no globals besides
 * the export. `block.js` calls them and keeps only the rendering.
 *
 * Two rules hold for every function:
 *
 * 1. It takes the current rows and returns a NEW array. `rows` comes from
 *    React props; editing them in place would change state before the commit
 *    and the editor would not re-render.
 * 2. It keeps every key it is not asked to change. A row's unknown keys are
 *    the other layouts' typed values — switching layouts and back must not
 *    cost the author's work.
 *
 * ES5 on purpose: the file ships as written, there is no build step.
 */
( function ( root ) {
	'use strict';

	/**
	 * How deep a blank value may recurse.
	 *
	 * The renderer caps nesting at `MAX_DEPTH` (3) and logs beyond it. This is
	 * not that limit — it only stops a pathological schema from recursing
	 * forever while building an empty object.
	 */
	var MAX_BLANK_DEPTH = 8;

	/**
	 * Rows as an array, whatever came in.
	 *
	 * @param {*} rows Value from the block attribute.
	 * @return {Array} The same rows, or an empty array.
	 */
	function rowList( rows ) {
		return Array.isArray( rows ) ? rows : [];
	}

	/**
	 * Layouts as an array, whatever came in.
	 *
	 * @param {*} layouts Value from the field definition.
	 * @return {Array} The same layouts, or an empty array.
	 */
	function layoutList( layouts ) {
		return Array.isArray( layouts ) ? layouts : [];
	}

	/**
	 * Whether an index addresses an existing row.
	 *
	 * @param {Array} list Rows.
	 * @param {*}     index Candidate index.
	 * @return {boolean} True for an integer inside range.
	 */
	function isIndex( list, index ) {
		return typeof index === 'number' && isFinite( index ) && Math.floor( index ) === index &&
			index >= 0 && index < list.length;
	}

	/**
	 * The empty value of one sub-field.
	 *
	 * A container's empty value is not `''`. A `group` stores an object, a list
	 * container stores an array, and a `link` stores `{ title, url, target }`.
	 * Writing `''` under those names hands the server a string where it reads a
	 * structure. The renderer tolerates it today — `(array) ''` in
	 * `render_group()` and the `is_array()` guards in `render_list()` keep it
	 * from crashing — but it stores a shape the schema never declares, and the
	 * next reader that trusts the schema gets it wrong.
	 *
	 * @param {Object} field Sub-field definition.
	 * @param {number} depth Nesting depth, so a pathological schema terminates.
	 * @return {*} Empty value matching the field's type.
	 */
	function blankValue( field, depth ) {
		var type  = field && typeof field.type === 'string' ? field.type : '';
		var level = typeof depth === 'number' ? depth : 0;

		if ( 'group' === type ) {
			return level >= MAX_BLANK_DEPTH ? {} : blankFields( field.sub_fields, level + 1 );
		}

		if ( 'repeater' === type || 'flexible_content' === type ) {
			return [];
		}

		if ( 'link' === type ) {
			return {};
		}

		return '';
	}

	/**
	 * A row object with one empty value per sub-field.
	 *
	 * Used for repeaters, whose rows are plain field maps. A field without a
	 * name is skipped: it would write the key `undefined`. The empty value
	 * depends on the field's type — see `blankValue()`.
	 *
	 * @param {Array}  subFields Sub-field definitions.
	 * @param {number} depth     Nesting depth.
	 * @return {Object} New row.
	 */
	function blankFields( subFields, depth ) {
		var row   = {};
		var level = typeof depth === 'number' ? depth : 0;

		( Array.isArray( subFields ) ? subFields : [] ).forEach( function ( sub ) {
			if ( sub && typeof sub.name === 'string' && sub.name ) {
				row[ sub.name ] = blankValue( sub, level );
			}
		} );

		return row;
	}

	/**
	 * The first layout, or null when there is none.
	 *
	 * @param {*} layouts Layout definitions.
	 * @return {Object|null} Layout.
	 */
	function firstLayout( layouts ) {
		var list = layoutList( layouts );

		return list.length ? list[ 0 ] : null;
	}

	/**
	 * Look a layout up by name.
	 *
	 * @param {*}      layouts Layout definitions.
	 * @param {string} name    Layout name as stored in `acf_fc_layout`.
	 * @return {Object|null} Layout, null when the name is unknown.
	 */
	function layoutByName( layouts, name ) {
		var list = layoutList( layouts );
		var i;

		for ( i = 0; i < list.length; i++ ) {
			if ( list[ i ] && list[ i ].name === name ) {
				return list[ i ];
			}
		}

		return null;
	}

	/**
	 * The layout a row currently uses.
	 *
	 * The row's layout key is the module's business, so `block.js` never reads
	 * it itself — that is how the two sides drift apart.
	 *
	 * @param {*}     layouts Layout definitions.
	 * @param {Object} row    Row from the block attribute.
	 * @return {Object|null} Layout, null when the row names none or an unknown one.
	 */
	function layoutForRow( layouts, row ) {
		return layoutByName( layouts, row && typeof row === 'object' ? row.acf_fc_layout : undefined );
	}

	/**
	 * A new row for the first layout.
	 *
	 * The row names its layout from the start. Without `acf_fc_layout` the
	 * server has no fields to render and drops the row, so a blank row that
	 * only carries empty strings is a row that disappears.
	 *
	 * @param {*} layouts Layout definitions of the field.
	 * @return {Object|null} New row, null when the field has no layouts.
	 */
	function blankRow( layouts ) {
		var layout = firstLayout( layouts );

		if ( ! layout || ! layout.name ) {
			return null;
		}

		// Length first, layout last: a sub-field may not shadow the key the
		// server dispatches on.
		return Object.assign( blankFields( layout.sub_fields ), { acf_fc_layout: layout.name } );
	}

	/**
	 * Append a new row for the first layout.
	 *
	 * A field without layouts grows by nothing — there is no layout whose
	 * sub-fields a row could carry.
	 *
	 * @param {Array} rows    Current rows.
	 * @param {*}     layouts Layout definitions.
	 * @return {Array} New rows.
	 */
	function addRow( rows, layouts ) {
		var list = rowList( rows );
		var blank = blankRow( layouts );

		if ( ! blank ) {
			return list.slice();
		}

		return list.concat( [ blank ] );
	}

	/**
	 * Drop one row.
	 *
	 * @param {Array}  rows  Current rows.
	 * @param {number} index Row to drop; an index outside the array drops nothing.
	 * @return {Array} New rows.
	 */
	function removeRow( rows, index ) {
		var list = rowList( rows );

		if ( ! isIndex( list, index ) ) {
			return list.slice();
		}

		return list.filter( function ( row, position ) {
			return position !== index;
		} );
	}

	/**
	 * Move one row by one or more positions.
	 *
	 * Off the ends nothing moves: the rows come back in the same order, so the
	 * caller can commit unconditionally.
	 *
	 * @param {Array}  rows   Current rows.
	 * @param {number} index  Row to move.
	 * @param {number} offset Negative for up, positive for down.
	 * @return {Array} New rows.
	 */
	function moveRow( rows, index, offset ) {
		var list = rowList( rows );
		var target = index + offset;

		if ( ! isIndex( list, index ) || ! isIndex( list, target ) || target === index ) {
			return list.slice();
		}

		var next = list.slice();
		var moved = next.splice( index, 1 )[ 0 ];
		next.splice( target, 0, moved );

		return next;
	}

	/**
	 * Replace one row through a copy function.
	 *
	 * @param {Array}    rows  Current rows.
	 * @param {number}   index Row to replace.
	 * @param {Function} edit  Receives the row, returns the replacement.
	 * @return {Array} New rows.
	 */
	function replaceRow( rows, index, edit ) {
		var list = rowList( rows );

		if ( ! isIndex( list, index ) ) {
			return list.slice();
		}

		return list.map( function ( row, position ) {
			return position === index ? edit( row ) : row;
		} );
	}

	/**
	 * Set a row's layout.
	 *
	 * Every other key survives, and an unknown name is written as given:
	 * dropping it would leave the row without a layout and lose it. The
	 * renderer ignores keys the new layout does not declare, so switching
	 * back restores what was typed.
	 *
	 * @param {Array}  rows       Current rows.
	 * @param {number} index      Row to change.
	 * @param {string} layoutName New layout name.
	 * @return {Array} New rows.
	 */
	function setLayout( rows, index, layoutName ) {
		return replaceRow( rows, index, function ( row ) {
			var next = Object.assign( {}, row && typeof row === 'object' ? row : {} );

			next.acf_fc_layout = layoutName;

			return next;
		} );
	}

	/**
	 * Set one sub-field of one row.
	 *
	 * @param {Array}  rows  Current rows.
	 * @param {number} index Row to change.
	 * @param {string} name  Sub-field name.
	 * @param {*}      value New value.
	 * @return {Array} New rows.
	 */
	function setField( rows, index, name, value ) {
		return replaceRow( rows, index, function ( row ) {
			var next = Object.assign( {}, row && typeof row === 'object' ? row : {} );

			next[ name ] = value;

			return next;
		} );
	}

	var api = {
		blankFields: blankFields,
		blankValue: blankValue,
		blankRow: blankRow,
		addRow: addRow,
		removeRow: removeRow,
		moveRow: moveRow,
		replaceRow: replaceRow,
		setLayout: setLayout,
		setField: setField,
		firstLayout: firstLayout,
		layoutByName: layoutByName,
		layoutForRow: layoutForRow,
	};

	root.SDCBlockRows = api;

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
}( typeof window !== 'undefined' ? window : globalThis ) );

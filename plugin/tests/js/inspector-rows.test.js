/**
 * The row operations behind the SDC inspector.
 *
 * These run in plain node — no browser, no WordPress, no build step — because
 * that is the whole point of `assets/inspector-rows.js`. Each test pins a
 * failure mode the inspector had, or would have had: a row that loses its
 * `acf_fc_layout` and is silently dropped by the server, a layout switch that
 * throws away typed values, an in-place edit React never sees.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const rows = require( '../../assets/inspector-rows.js' );

const layouts = [
	{
		name: 'text',
		label: 'Text',
		sub_fields: [ { name: 'headline', type: 'text' }, { name: 'body', type: 'textarea' } ],
	},
	{
		name: 'image',
		label: 'Bild',
		sub_fields: [ { name: 'file', type: 'image' }, { name: 'caption', type: 'text' } ],
	},
];

test( 'blankRow names the first layout', () => {
	const row = rows.blankRow( layouts );

	assert.equal( row.acf_fc_layout, 'text' );
} );

test( 'blankRow pre-fills the sub-fields of the first layout with empty strings', () => {
	const row = rows.blankRow( layouts );

	assert.equal( row.headline, '' );
	assert.equal( row.body, '' );
	// The second layout's fields are not this row's.
	assert.equal( 'file' in row, false );
	assert.equal( 'caption' in row, false );
} );

test( 'blankRow without layouts is null, not a row without a layout', () => {
	assert.equal( rows.blankRow( [] ), null );
	assert.equal( rows.blankRow( undefined ), null );
} );

test( 'addRow on an empty array yields exactly one row', () => {
	const next = rows.addRow( [], layouts );

	assert.equal( next.length, 1 );
	assert.equal( next[ 0 ].acf_fc_layout, 'text' );
} );

test( 'addRow appends and leaves existing rows alone', () => {
	const existing = [ { acf_fc_layout: 'image', file: 7, caption: 'Alt' } ];
	const next = rows.addRow( existing, layouts );

	assert.equal( next.length, 2 );
	assert.deepEqual( next[ 0 ], existing[ 0 ] );
	assert.equal( next[ 1 ].acf_fc_layout, 'text' );
} );

test( 'a field without layouts grows by no row', () => {
	assert.deepEqual( rows.addRow( [], [] ), [] );
} );

test( 'setLayout keeps typed values across a switch and back', () => {
	const start = [ { acf_fc_layout: 'text', headline: 'Hallo', body: 'Welt' } ];

	const switched = rows.setLayout( start, 0, 'image' );

	assert.equal( switched[ 0 ].acf_fc_layout, 'image' );
	assert.equal( switched[ 0 ].headline, 'Hallo', 'switching layouts must not drop typed values' );
	assert.equal( switched[ 0 ].body, 'Welt' );

	const back = rows.setLayout( switched, 0, 'text' );

	assert.deepEqual( back[ 0 ], start[ 0 ] );
} );

test( 'setLayout writes an unknown name instead of dropping it', () => {
	// A layout can disappear from a component while content still names it.
	// Writing the name keeps the row identifiable and lets the author switch
	// it back; silently ignoring the choice would look like a dead control.
	const next = rows.setLayout( [ { acf_fc_layout: 'text', headline: 'A' } ], 0, 'gone' );

	assert.equal( next[ 0 ].acf_fc_layout, 'gone' );
	assert.equal( next[ 0 ].headline, 'A' );
} );

test( 'removeRow drops exactly one row and keeps the others in order', () => {
	const start = [
		{ acf_fc_layout: 'text', headline: 'eins' },
		{ acf_fc_layout: 'image', caption: 'zwei' },
		{ acf_fc_layout: 'text', headline: 'drei' },
	];

	assert.deepEqual( rows.removeRow( start, 1 ), [ start[ 0 ], start[ 2 ] ] );
	assert.equal( rows.removeRow( start, 1 ).length, 2 );
} );

test( 'removeRow off the ends leaves the rows as they are', () => {
	const start = [ { acf_fc_layout: 'text', headline: 'eins' } ];

	assert.deepEqual( rows.removeRow( start, 9 ), start );
	assert.deepEqual( rows.removeRow( start, -1 ), start );
} );

test( 'moveRow moves a row up and down', () => {
	const start = [ { id: 'a' }, { id: 'b' }, { id: 'c' } ];

	assert.deepEqual( rows.moveRow( start, 2, -1 ).map( ( row ) => row.id ), [ 'a', 'c', 'b' ] );
	assert.deepEqual( rows.moveRow( start, 0, 1 ).map( ( row ) => row.id ), [ 'b', 'a', 'c' ] );
} );

test( 'moveRow past the ends changes nothing', () => {
	const start = [ { id: 'a' }, { id: 'b' }, { id: 'c' } ];

	assert.deepEqual( rows.moveRow( start, 0, -1 ), start );
	assert.deepEqual( rows.moveRow( start, 2, 1 ), start );
} );

test( 'setField keeps the row siblings', () => {
	const start = [ { acf_fc_layout: 'text', headline: 'alt', body: 'bleibt' } ];
	const next = rows.setField( start, 0, 'headline', 'neu' );

	assert.equal( next[ 0 ].headline, 'neu' );
	assert.equal( next[ 0 ].body, 'bleibt' );
	assert.equal( next[ 0 ].acf_fc_layout, 'text' );
} );

test( 'setField on a group-style row does not touch the second row', () => {
	const start = [
		{ acf_fc_layout: 'text', headline: 'eins' },
		{ acf_fc_layout: 'text', headline: 'zwei' },
	];
	const next = rows.setField( start, 1, 'headline', 'geändert' );

	assert.equal( next[ 0 ].headline, 'eins' );
	assert.equal( next[ 1 ].headline, 'geändert' );
} );

test( 'no operation mutates the rows it is given', () => {
	const start = [ { acf_fc_layout: 'text', headline: 'eins' }, { acf_fc_layout: 'image', caption: 'zwei' } ];
	const snapshot = JSON.parse( JSON.stringify( start ) );

	const results = [
		rows.addRow( start, layouts ),
		rows.removeRow( start, 0 ),
		rows.moveRow( start, 1, -1 ),
		rows.moveRow( start, 0, -5 ),
		rows.setLayout( start, 0, 'image' ),
		rows.setField( start, 0, 'headline', 'neu' ),
	];

	results.forEach( ( result ) => assert.notStrictEqual( result, start ) );
	assert.deepEqual( start, snapshot, 'the input rows were mutated' );

	// An unchanged-outcome call still returns a fresh array, so a caller can
	// commit without comparing.
	assert.notStrictEqual( rows.moveRow( start, 0, -1 ), start );
	assert.notStrictEqual( rows.removeRow( start, 99 ), start );
} );

test( 'blankFields builds a plain row for a repeater, without a layout key', () => {
	const row = rows.blankFields( [ { name: 'title' }, { name: 'text' } ] );

	assert.deepEqual( row, { title: '', text: '' } );
	assert.equal( 'acf_fc_layout' in row, false );
} );

test( 'a container sub-field starts as the shape its type declares, not as ""', () => {
	// The server reads a group as an array and a list as rows. A string under
	// those names is a shape the schema never declares; the renderer happens to
	// survive it, which is exactly why it would go unnoticed.
	const row = rows.blankFields( [
		{ name: 'title', type: 'text' },
		{ name: 'address', type: 'group', sub_fields: [
			{ name: 'street', type: 'text' },
			{ name: 'city', type: 'text' },
		] },
		{ name: 'links', type: 'repeater', sub_fields: [ { name: 'label', type: 'text' } ] },
		{ name: 'blocks', type: 'flexible_content', layouts: [] },
		{ name: 'cta', type: 'link' },
	] );

	assert.equal( row.title, '' );
	assert.deepEqual( row.address, { street: '', city: '' } );
	assert.deepEqual( row.links, [] );
	assert.deepEqual( row.blocks, [] );
	assert.deepEqual( row.cta, {} );
} );

test( 'a group inside a group recurses, and a cycle in the schema terminates', () => {
	const nested = rows.blankFields( [ {
		name: 'outer',
		type: 'group',
		sub_fields: [
			{ name: 'label', type: 'text' },
			{ name: 'inner', type: 'group', sub_fields: [ { name: 'value', type: 'text' } ] },
		],
	} ] );

	assert.deepEqual( nested.outer, { label: '', inner: { value: '' } } );

	// A schema cannot be cyclic once it is JSON, but blankValue() must not be
	// the thing that hangs the editor if one ever is.
	const cyclic = { name: 'loop', type: 'group', sub_fields: [] };
	cyclic.sub_fields.push( cyclic );

	const value = rows.blankValue( cyclic );
	assert.equal( typeof value, 'object' );
	assert.equal( Array.isArray( value ), false );
} );

test( 'firstLayout and layoutByName look up by name, null when unknown', () => {
	assert.equal( rows.firstLayout( layouts ), layouts[ 0 ] );
	assert.equal( rows.firstLayout( [] ), null );
	assert.equal( rows.layoutByName( layouts, 'image' ), layouts[ 1 ] );
	assert.equal( rows.layoutByName( layouts, 'nope' ), null );
} );

test( 'layoutForRow reads the layout of a row and answers null for none', () => {
	assert.equal( rows.layoutForRow( layouts, { acf_fc_layout: 'image' } ), layouts[ 1 ] );
	// A row without a layout is the state this module exists to end: the
	// inspector has to show it as "no layout", not crash on it.
	assert.equal( rows.layoutForRow( layouts, { headline: 'ohne' } ), null );
	assert.equal( rows.layoutForRow( layouts, null ), null );
} );

test( 'the module exports itself for the browser as well', () => {
	assert.equal( globalThis.SDCBlockRows, rows );
} );

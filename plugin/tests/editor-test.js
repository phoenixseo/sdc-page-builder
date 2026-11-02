/**
 * SDC Page Builder — editor test (jsdom).
 *
 * Two things the static harness never covered:
 *   1. the save path: DOM -> collect() -> POST body -> stored document
 *   2. the defects found on this path (templates outside the builder root,
 *      `__c__` left behind in a cloned row template)
 *
 * Runs against real host markup: tests/fixtures/metabox-page2.html is exported
 * from WordPress by tests/export-metabox.php (do_meta_boxes(), i.e. the same
 * call post.php makes), so the inputs are ACF/SCF's own, not a hand-built copy.
 *
 *   npm test        (from plugin/)
 *
 * Exit 0 only when every assertion passes and nothing failed.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const ROOT = path.resolve( __dirname, '..' );
const FIXTURE = path.join( __dirname, 'fixtures', 'metabox-page2.html' );
const FIXTURE_META = path.join( __dirname, 'fixtures', 'metabox-page2.json' );
const EDITOR_JS = path.join( ROOT, 'assets', 'editor.js' );
const EDITOR_PHP = path.join( ROOT, 'includes', 'class-sdc-editor.php' );
const OUTPUT = process.env.SDC_TEST_OUTPUT || path.join( __dirname, '.editor-test-output.json' );
// For the red run: point the harness at a copy of the editor with one change.
const SCRIPT_UNDER_TEST = process.env.SDC_EDITOR_SCRIPT || EDITOR_JS;

const meta = JSON.parse( fs.readFileSync( FIXTURE_META, 'utf8' ) );

let passed = 0;
const failures = [];

function pass( name, detail ) {
	passed++;
	console.log( 'PASS ' + name + ( detail ? ' — ' + detail : '' ) );
}

function fail( name, detail ) {
	failures.push( name + ': ' + detail );
	console.log( 'FAIL ' + name + ' — ' + detail );
}

function check( name, condition, detail ) {
	if ( condition ) {
		pass( name, detail );
	} else {
		fail( name, detail );
	}
}

const show = ( value ) => JSON.stringify( value );

/* --------------------------------------------------------------- harness */

/**
 * Boot the metabox markup with the real editor script.
 *
 * @param {Object} options
 * @param {boolean} options.dirtyBeforeSave  Dispatch one `input` event first, as
 *                                           typing does: without it save() stops
 *                                           at the `!dirty` gate.
 * @param {string}  options.editorScript     Path to the script under test.
 * @return {Object} environment handle.
 */
function boot( options ) {
	const opts = options || {};
	const virtualConsole = new VirtualConsole();
	virtualConsole.on( 'jsdomError', ( error ) => {
		// requestSubmit() is stubbed below; anything else is a real problem.
		fail( 'jsdom', String( ( error && error.message ) || error ) );
	} );

	const dom = new JSDOM( fs.readFileSync( FIXTURE, 'utf8' ), {
		runScripts: 'dangerously',
		url: 'http://example.test/wp-admin/post.php?post=' + meta.post + '&action=edit',
		virtualConsole,
	} );
	const { window } = dom;

	const sent = [];
	window.confirm = () => true;
	window.HTMLElement.prototype.scrollIntoView = function () {};

	// jsdom has no requestSubmit(); note the call instead of navigating.
	const requested = [];
	window.HTMLFormElement.prototype.requestSubmit = function () {
		requested.push( true );
	};

	window.fetch = function ( url, init ) {
		sent.push( {
			url,
			method: init && init.method,
			headers: ( init && init.headers ) || {},
			body: init && init.body ? JSON.parse( init.body ) : null,
		} );
		return Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( { post_id: Number( meta.post ), document: { components: [] } } ),
		} );
	};

	window.SDC_PB = {
		restUrl: 'http://example.test/wp-json/sdc-pb/v1/pages/',
		nonce: 'test-nonce',
		fields: meta.fields,
		labels: {
			saved: 'Gespeichert.',
			saving: 'Speichert …',
			failed: 'Speichern fehlgeschlagen.',
			dirty: 'Nicht gespeicherte Änderungen',
			empty: 'Noch keine Komponenten.',
			confirm: 'Komponenten entfernen?',
		},
	};

	const script = window.document.createElement( 'script' );
	script.textContent = fs.readFileSync( opts.editorScript || SCRIPT_UNDER_TEST, 'utf8' );
	window.document.body.appendChild( script );

	return { dom, window, document: window.document, sent, requested };
}

const fire = ( window, element, type ) =>
	element.dispatchEvent( new window.Event( type, { bubbles: true, cancelable: true } ) );

/** Set a value and fire `input`, like a user typing. */
function type( window, element, value ) {
	element.value = value;
	fire( window, element, 'input' );
}

/** First leaf input of a field, whichever wrapper the host uses. */
const leaf = ( root, name ) =>
	root.querySelector( '[data-sdc-name="' + name + '"] input, [data-sdc-name="' + name + '"] textarea' );

const componentsOf = ( document ) =>
	document.querySelectorAll( '#post [data-sdc-components] > [data-sdc-component]' );

const rowsOf = ( component ) =>
	component.querySelectorAll( '[data-sdc-rows] > [data-sdc-row]' );

/** Submit the form and return the POST the editor made, or null. */
async function saveViaForm( env ) {
	fire( env.window, env.document.getElementById( 'post' ), 'submit' );
	await new Promise( ( resolve ) => env.window.setTimeout( resolve, 0 ) );
	return env.sent.length ? env.sent[ env.sent.length - 1 ] : null;
}

/* ------------------------------------------------------------- Szenarien */

/**
 * Adding a component. The component templates used to sit after the builder
 * root's closing div, so `builder.querySelector('[data-sdc-component-template]')`
 * found nothing and the button silently did nothing.
 */
async function scenarioAddComponent() {
	const env = boot();
	const { document } = env;
	const builder = document.querySelector( '[data-sdc-builder]' );

	check(
		'Komponenten-Template liegt innerhalb [data-sdc-builder]',
		!! builder.querySelector( '[data-sdc-component-template="faq"]' ),
		'data-sdc-component-template="faq"'
	);
	check(
		'Template-Inhalt ist inert, nicht Teil des sichtbaren DOM',
		builder.querySelector( '[data-sdc-component-template="faq"]' ).childNodes.length === 0,
		'childNodes=0'
	);

	const before = componentsOf( document ).length;
	document.querySelector( '[data-sdc-add-component]' ).click();
	const after = componentsOf( document );
	check( '„Komponente hinzufügen" hängt genau eine Komponente an', after.length === before + 1, before + ' -> ' + after.length );
	if ( after.length !== before + 1 ) {
		return;
	}

	const added = after[ after.length - 1 ];
	const id = added.getAttribute( 'data-sdc-id' );
	check( 'neue Komponente hat frische Instanz-Id', /^c_[a-z0-9]+$/.test( id ), id );

	const names = Array.from( added.querySelectorAll( '[name]' ) ).map( ( el ) => el.getAttribute( 'name' ) );
	check(
		'äußere Eingaben tragen die neue Id',
		names.length > 0 && names.every( ( name ) => name.indexOf( 'acf[' + id + ']' ) === 0 ),
		names.join( ' ' )
	);

	// template.content is a separate inert fragment; querySelectorAll on the
	// component never descends into it, innerHTML has already been serialised.
	// The row template inside the clone must still be rewritten.
	const stray = Array.from( added.querySelectorAll( 'template' ) )
		.flatMap( ( tpl ) => Array.from( tpl.content.querySelectorAll( '[name]' ) ) )
		.map( ( el ) => el.getAttribute( 'name' ) )
		.filter( ( name ) => name.indexOf( '__c__' ) !== -1 );
	check( 'Zeilen-Template im Klon trägt die neue Id', stray.length === 0, stray.length ? stray.join( ' , ' ) : 'kein __c__ in template.content' );

	// What a left-behind placeholder costs: rows added afterwards name their
	// inputs `acf[__c__]…` and collect() drops them.
	added.querySelector( '[data-sdc-add-row]' ).click();
	const row = rowsOf( added )[ 0 ];
	const rowNames = row ? Array.from( row.querySelectorAll( '[name]' ) ).map( ( el ) => el.getAttribute( 'name' ) ) : [];
	check(
		'Zeile der neuen Komponente wird gesammelt (Namen auf ihrer Id)',
		rowNames.length > 0 && rowNames.every( ( name ) => name.indexOf( 'acf[' + id + ']' ) === 0 ),
		rowNames.join( ' , ' ) || 'keine Eingaben'
	);

	// …and the user loses what they typed into that row.
	if ( row ) {
		type( env.window, leaf( row, 'q' ), 'Frage in der neuen Komponente' );
		type( env.window, leaf( added, 'heading' ), 'Neue Komponente' );

		const request = await saveViaForm( env );
		const stored = request ? request.body.components[ request.body.components.length - 1 ] : null;
		check(
			'Zeile der neuen Komponente steht im gespeicherten Dokument',
			!! stored && Array.isArray( stored.data.items ) && stored.data.items.length === 1 && stored.data.items[ 0 ].q === 'Frage in der neuen Komponente',
			stored ? show( stored.data ) : 'kein POST'
		);
	}
}

/**
 * collect() over the untouched seed: shape of the document, not a value check.
 */
async function scenarioCollectShape() {
	const env = boot();
	type( env.window, leaf( componentsOf( env.document )[ 0 ], 'heading' ), meta.expected[ 0 ].heading );
	const request = await saveViaForm( env );

	if ( ! request ) {
		fail( 'Speichern löst einen POST aus', 'kein fetch-Aufruf — dirty-Gate oder submit-Bindung fehlt' );
		return;
	}

	pass( 'Speichern löst einen POST aus', request.method + ' ' + request.url );
	check( 'POST geht an die REST-Route der Seite', request.url === window_restUrl( meta.post ), request.url );
	check( 'Nonce wird mitgeschickt', request.headers[ 'X-WP-Nonce' ] === 'test-nonce', show( request.headers ) );
	check( 'Content-Type ist JSON', request.headers[ 'Content-Type' ] === 'application/json', show( request.headers ) );
	check( 'Formular-Post wird erst nach dem REST-Speichern ausgelöst', env.requested.length === 1, env.requested.length + ' requestSubmit' );

	const components = request.body.components;
	check( 'beide Komponenten im Dokument', Array.isArray( components ) && components.length === 2, show( components.map( ( c ) => c._id ) ) );

	const first = components[ 0 ];
	check( 'items ist ein Array (kein __i__-Schlüsselobjekt)', Array.isArray( first.data.items ), show( first.data.items ) );
	check( 'zwei Zeilen', first.data.items.length === 2, String( first.data.items.length ) );
	check( 'kein __i__ im gesamten Dokument', JSON.stringify( request.body ).indexOf( '__i__' ) === -1, 'kein Vorkommen' );
	check(
		'Feld-Namen statt Feld-Keys',
		show( Object.keys( first.data ).sort() ) === '["heading","items"]',
		show( Object.keys( first.data ) )
	);
	check(
		'Zeilen-Ids stabil und in Reihenfolge',
		first.data.items.map( ( row ) => row._id ).join( ',' ) === 'r_1,r_2',
		first.data.items.map( ( row ) => row._id ).join( ',' )
	);
	check(
		'jede Zeile trägt q, a und _id',
		first.data.items.every( ( row ) => [ '_id', 'a', 'q' ].every( ( key ) => key in row ) ),
		show( Object.keys( first.data.items[ 0 ] ).sort() )
	);
}

function window_restUrl( postId ) {
	return 'http://example.test/wp-json/sdc-pb/v1/pages/' + postId;
}

/**
 * The order the task asks for: two components of one type, the first with
 * three rows, the middle row deleted, saved. Values compared field by field.
 */
async function scenarioEditAndSave() {
	const env = boot();
	const { window, document } = env;
	const all = componentsOf( document );
	const first = all[ 0 ];
	const second = all[ 1 ];

	// A) first component to three rows
	first.querySelector( '[data-sdc-add-row]' ).click();
	let rows = rowsOf( first );
	check( 'Zeile hinzufügen: drei Zeilen', rows.length === 3, String( rows.length ) );

	// B) delete the middle row (the one seeded with "A-Frage 2")
	check(
		'mittlere Zeile ist vor dem Löschen die geseedete Zeile 2',
		leaf( rows[ 1 ], 'q' ).value === 'A-Frage 2',
		leaf( rows[ 1 ], 'q' ).value
	);
	rows[ 1 ].querySelector( '[data-sdc-remove-row]' ).click();
	rows = rowsOf( first );
	check( 'mittlere Zeile gelöscht: zwei Zeilen', rows.length === 2, String( rows.length ) );
	check(
		'Indizes neu vergeben: 0,1',
		Array.from( rows ).map( ( row ) => row.getAttribute( 'data-sdc-row-index' ) ).join( ',' ) === '0,1',
		Array.from( rows ).map( ( row ) => row.getAttribute( 'data-sdc-row-index' ) ).join( ',' )
	);

	// C) the new row gets values, umlauts included
	const newRow = rows[ 1 ];
	check( 'die neue Zeile ist leer', leaf( newRow, 'q' ).value === '', show( leaf( newRow, 'q' ).value ) );
	type( window, leaf( newRow, 'q' ), 'Frage neu' );
	type( window, leaf( newRow, 'a' ), 'Grüße aus München — Straße 3' );
	type( window, leaf( rows[ 0 ], 'q' ), 'Ärger über Öl?' );
	type( window, leaf( first, 'heading' ), 'Überschrift mit Umlauten: äöü ß' );

	// second component, same type, must stay independent
	type( window, leaf( second, 'heading' ), 'Zweite Komponente – groß' );

	// Expected values read from the DOM the user sees. collect() walks the
	// inputs in document order, so the object follows the DOM: _id sits where
	// the host put the hidden input, i.e. last within a row.
	const expectedFirst = {
		heading: leaf( first, 'heading' ).value,
		items: Array.from( rows ).map( ( row ) => {
			const item = {};
			row.querySelectorAll( '[name]' ).forEach( ( el ) => {
				const parts = el.getAttribute( 'name' ).match( /\[([^\]]*)\]/g ).map( ( p ) => p.slice( 1, -1 ) );
				item[ parts[ parts.length - 1 ] ] = el.value;
			} );
			return { q: item.field_sdc_faq_item_q, a: item.field_sdc_faq_item_a, _id: item._id };
		} ),
	};

	const request = await saveViaForm( env );
	if ( ! request ) {
		fail( 'Speicherweg nach Bearbeitung', 'kein fetch-Aufruf — dirty-Gate oder submit-Bindung fehlt' );
		return;
	}

	const stored = request.body.components;
	const storedFirst = stored[ 0 ];
	const storedSecond = stored[ 1 ];

	check(
		'gespeichertes Dokument == Formularwerte',
		show( storedFirst.data ) === show( expectedFirst ),
		show( storedFirst.data ) === show( expectedFirst )
			? show( expectedFirst )
			: '\n  gespeichert: ' + show( storedFirst.data ) + '\n  Formular:    ' + show( expectedFirst )
	);
	check( 'Umlaute im Überschriftenfeld', storedFirst.data.heading === 'Überschrift mit Umlauten: äöü ß', storedFirst.data.heading );
	check( 'Umlaute in Zeile 1', storedFirst.data.items[ 0 ].q === 'Ärger über Öl?', storedFirst.data.items[ 0 ].q );
	check(
		'Umlaute und Gedankenstrich in Zeile 2',
		storedFirst.data.items[ 1 ].a === 'Grüße aus München — Straße 3',
		storedFirst.data.items[ 1 ].a
	);
	check(
		'Zeilenreihenfolge: gelöschte Zeile fehlt, neue steht hinten',
		storedFirst.data.items.map( ( row ) => row.q ).join( ' | ' ) === 'Ärger über Öl? | Frage neu',
		storedFirst.data.items.map( ( row ) => row.q ).join( ' | ' )
	);
	check(
		'Ids der überlebenden Zeilen unverändert',
		storedFirst.data.items[ 0 ]._id === meta.rowsAfterEdit[ 0 ]._id,
		storedFirst.data.items.map( ( row ) => row._id ).join( ',' )
	);
	// A row added after the delete must keep the id it got when it was added;
	// reindexRows() only fills empty ones.
	check(
		'die überlebende Seed-Zeile behält r_1',
		storedFirst.data.items[ 0 ]._id === 'r_1',
		storedFirst.data.items[ 0 ]._id
	);
	check(
		'die neue Zeile hat eine eigene Id, keine der gelöschten',
		/^c_[a-z0-9]+$/.test( storedFirst.data.items[ 1 ]._id ) && storedFirst.data.items[ 1 ]._id !== 'r_2',
		storedFirst.data.items[ 1 ]._id
	);
	check(
		'zweite Komponente: eigene Id, eigene Werte, unveränderte Zeilen',
		storedSecond._id === 'c_two' &&
			storedSecond.data.heading === 'Zweite Komponente – groß' &&
			storedSecond.data.items.length === 1 &&
			storedSecond.data.items[ 0 ].q === 'B-Frage 1',
		storedSecond._id + ' / ' + storedSecond.data.heading + ' / ' + storedSecond.data.items.length + ' Zeile(n)'
	);
	check(
		'keine Werte zwischen den beiden Komponenten vermischt',
		JSON.stringify( storedSecond ).indexOf( 'Umlauten' ) === -1 && JSON.stringify( storedFirst ).indexOf( 'B-Frage' ) === -1,
		'c_two ohne Werte von c_one und umgekehrt'
	);

	const statusEl = document.querySelector( '[data-sdc-status]' );
	check( 'Statuszeile meldet Erfolg', /Gespeichert\./.test( statusEl.textContent ), show( statusEl.textContent ) );
	check(
		'Builder-Eingaben verlieren vor dem Formular-Post ihre Namen',
		document.querySelectorAll( '[data-sdc-builder] [name]' ).length === 0,
		document.querySelectorAll( '[data-sdc-builder] [name]' ).length + ' benannte Eingaben'
	);
	check( 'genau ein Formular-Post nach dem Speichern', env.requested.length === 1, env.requested.length + ' requestSubmit' );

	fs.writeFileSync( OUTPUT, JSON.stringify( { url: request.url, method: request.method, body: request.body }, null, 2 ) + '\n' );
	pass( 'POST-Body für den REST-Rundlauf geschrieben', path.relative( ROOT, OUTPUT ) );
}

/** No test hooks, no debug output in shipped code. */
function scenarioNoDebugLeftovers() {
	const source = fs.readFileSync( EDITOR_JS, 'utf8' );
	check( 'editor.js ohne window.__* Hooks', ! /window\.__/.test( source ), 'grep window.__ → 0' );
	check( 'editor.js ohne console.log', ! /console\.log/.test( source ), 'grep console.log → 0' );
	const php = fs.readFileSync( EDITOR_PHP, 'utf8' );
	check( 'class-sdc-editor.php ohne Debug-Ausgabe', ! /var_dump|print_r\(|error_log/.test( php ), 'keine Debug-Aufrufe' );
}

/* ------------------------------------------------------------------- main */

( async function main() {
	const fixtureBytes = fs.readFileSync( FIXTURE, 'utf8' ).length;
	console.log( 'SDC Editor-Test — jsdom ' + require( 'jsdom/package.json' ).version + ', Node ' + process.version );
	console.log( 'Fixture: ' + path.relative( ROOT, FIXTURE ) + ' (' + fixtureBytes + ' Bytes)' );
	console.log( '' );

	await scenarioAddComponent();
	await scenarioCollectShape();
	await scenarioEditAndSave();
	scenarioNoDebugLeftovers();

	console.log( '' );
	console.log( passed + ' PASS, ' + failures.length + ' FAIL' );
	if ( failures.length ) {
		failures.forEach( ( line ) => console.log( '  FAIL ' + line ) );
		process.exitCode = 1;
	}
} )().catch( ( error ) => {
	console.log( 'FAIL Harness — ' + ( ( error && error.stack ) || error ) );
	console.log( '' );
	console.log( passed + ' PASS, ' + ( failures.length + 1 ) + ' FAIL' );
	process.exitCode = 1;
} );

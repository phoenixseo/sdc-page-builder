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

	// jsdom has no object URLs; the export scenario only needs them not to throw.
	window.URL.createObjectURL = () => 'blob:test';
	window.URL.revokeObjectURL = () => {};

	// The export triggers a download by clicking a temporary <a download>.
	// jsdom implements that click as a real navigation ("Not implemented"),
	// which aborts the run; call the handler and note the click instead.
	const downloads = [];
	const realClick = window.HTMLElement.prototype.click;
	window.HTMLElement.prototype.click = function () {
		if ( this.tagName === 'A' && this.hasAttribute( 'download' ) ) {
			downloads.push( { name: this.getAttribute( 'download' ), href: this.getAttribute( 'href' ) } );
			return;
		}
		return realClick.apply( this, arguments );
	};

	// The editor reloads the page after import/restore so the form shows the
	// stored document. jsdom makes `location` an own, non-configurable
	// property, so `reload` cannot be stubbed here; the assertions check the
	// request and the status line the user sees instead.

	const env = { dom, window, document: window.document, sent, requested, downloads, filters: {}, respond: null };

	// The block editor never submits a form, so the script hooks the host's
	// own pre-save filter. Standing in for `wp.hooks` / `wp.data` here is what
	// lets the test see whether that registration happens at all.
	if ( opts.wp ) {
		window.wp = {
			data: { select: () => ( {} ) },
			hooks: {
				addFilter: ( hook, namespace, callback ) => {
					env.filters[ hook ] = { namespace, callback };
				},
			},
		};
	}

	window.fetch = function ( url, init ) {
		sent.push( {
			url,
			method: init && init.method,
			headers: ( init && init.headers ) || {},
			body: init && init.body ? JSON.parse( init.body ) : null,
		} );
		const body = env.respond
			? env.respond( url, init )
			: { post_id: Number( meta.post ), document: { components: [] } };
		return Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( body ),
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

	return env;
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

/**
 * The tools (export / import / history / restore). Every one of them must
 * issue the request it advertises; a button that renders but does nothing is
 * a placeholder, and the card forbids placeholders.
 */
async function scenarioTools() {
	const env = boot();
	const { document, window } = env;

	const exportButton = document.querySelector( '[data-sdc-export]' );
	const historyToggle = document.querySelector( '[data-sdc-history-toggle]' );
	const historyPanel = document.querySelector( '[data-sdc-history]' );
	const importInput = document.querySelector( '[data-sdc-import-input]' );

	check( 'Export-Button vorhanden', !! exportButton );
	check( 'Verlauf-Button vorhanden', !! historyToggle );
	check( 'Import-Feld vorhanden', !! importInput );
	check( 'Verlauf ist anfangs zu', historyPanel.hasAttribute( 'hidden' ) );

	// Export issues GET .../export and hands the payload to a download.
	env.sent.length = 0;
	env.respond = () => ( { format: 1, slug: 'meine-seite', title: 'Meine Seite', document: { components: [] } } );
	fire( window, exportButton, 'click' );
	await tick( window );
	check(
		'Export ruft GET .../export',
		env.sent.length === 1 && /\/pages\/\d+\/export$/.test( env.sent[ 0 ].url ),
		env.sent.length ? env.sent[ 0 ].url : '(kein Request)'
	);
	check(
		'Export löst einen Download mit Slug-Dateinamen aus',
		env.downloads.length === 1 && env.downloads[ 0 ].name === 'meine-seite.json',
		env.downloads.length ? env.downloads[ 0 ].name : '(kein Download)'
	);

	// History toggle issues GET .../history and lists a restorable entry.
	env.sent.length = 0;
	env.respond = () => ( {
		revisions: [
			{ revision: 4711, offset: -120, date: '02.11.2026 14:03', components: 2, title: 'Stand A' },
		],
	} );
	fire( window, historyToggle, 'click' );
	await tick( window );
	check(
		'Verlauf ruft GET .../history',
		env.sent.length === 1 && /\/pages\/\d+\/history$/.test( env.sent[ 0 ].url ),
		env.sent.length ? env.sent[ 0 ].url : '(kein Request)'
	);
	const restoreButtons = document.querySelectorAll( '[data-sdc-history-list] button' );
	check( 'Verlauf listet den Stand mit Restore-Button', restoreButtons.length === 1, restoreButtons.length + ' Buttons' );
	check( 'Verlauf zeigt Zeitversatz und Umfang', /2 min/.test( historyPanel.textContent ) && /2 Komponenten/.test( historyPanel.textContent ), historyPanel.textContent.trim().slice( 0, 80 ) );

	// Restore posts the revision id from the list.
	env.sent.length = 0;
	env.respond = () => ( { changed: true, document: { components: [] } } );
	fire( window, restoreButtons[ 0 ], 'click' );
	await tick( window );
	check(
		'Restore POSTet die Revisions-Id aus der Liste',
		env.sent.length === 1 && /\/restore$/.test( env.sent[ 0 ].url ) && env.sent[ 0 ].body && env.sent[ 0 ].body.revision === 4711,
		env.sent.length ? JSON.stringify( env.sent[ 0 ].body ) : '(kein Request)'
	);

	// Import reads the file and posts it as `payload`.
	env.sent.length = 0;
	env.respond = () => ( { changed: true, document: { components: [ {}, {} ] } } );
	const file = new window.File(
		[ JSON.stringify( { format: 1, document: { components: [ { type: 'faq', data: {} } ] } } ) ],
		'doc.json',
		{ type: 'application/json' }
	);
	Object.defineProperty( importInput, 'files', { value: [ file ], configurable: true } );
	fire( window, importInput, 'change' );
	await tick( window, 60 );
	check(
		'Import POSTet die Datei als payload',
		env.sent.length === 1 && /\/import$/.test( env.sent[ 0 ].url ) && env.sent[ 0 ].body && env.sent[ 0 ].body.payload && env.sent[ 0 ].body.payload.format === 1,
		env.sent.length ? JSON.stringify( env.sent[ 0 ].body ).slice( 0, 90 ) : '(kein Request)'
	);
	check(
		'Import meldet Erfolg samt Komponentenzahl',
		/Importiert\..*2 Komponenten/.test( document.querySelector( '[data-sdc-status]' ).textContent ),
		document.querySelector( '[data-sdc-status]' ).textContent
	);

	// Close the window so pending reload timers do not fire at process end
	// (jsdom reports each as "navigation to another Document").
	env.dom.window.close();
}

/**
 * Block editor: the document is written before the post is saved.
 *
 * Gutenberg builds a FormData from the metabox DOM and posts it to post.php;
 * it never fires a form submit. A save path bound only to `submit` therefore
 * never ran, and an author's edit silently reverted. The script registers on
 * `editor.preSavePost` instead — this checks it does, and that the filter
 * actually writes when something changed and stays quiet when nothing did.
 */
async function scenarioBlockEditorBridge() {
	const env = boot( { wp: true } );
	const { window, document } = env;

	const filter = env.filters[ 'editor.preSavePost' ];
	check( 'Block-Editor: preSavePost-Filter registriert', !!filter, Object.keys( env.filters ).join( ',' ) || '(keiner)' );
	if ( ! filter ) {
		env.dom.window.close();
		return;
	}
	check( 'Block-Editor: Namespace sdc-page-builder', filter.namespace === 'sdc-page-builder', filter.namespace );

	// Nothing changed: the filter passes the edits through without a request.
	env.sent.length = 0;
	const untouched = { content: '<p>unberührt</p>' };
	const same = await filter.callback( untouched );
	check( 'Block-Editor: ohne Änderung kein POST', env.sent.length === 0, env.sent.length + ' Requests' );
	check( 'Block-Editor: ohne Änderung bleiben die Edits gleich', same === untouched, show( same && same.content ) );

	// Typing marks the document dirty; the filter must then write it.
	const first = componentsOf( document )[ 0 ];
	type( window, leaf( first, 'heading' ), 'Aus dem Block-Editor' );

	env.sent.length = 0;
	const edits = { content: '<p>geändert</p>' };
	const returned = await filter.callback( edits );

	check( 'Block-Editor: Änderung löst einen POST aus', env.sent.length === 1, env.sent.length + ' Requests' );
	check(
		'Block-Editor: POST geht an den Seiten-Endpunkt',
		env.sent.length === 1 && new RegExp( '/pages/' + meta.post + '$' ).test( env.sent[ 0 ].url ),
		env.sent.length ? env.sent[ 0 ].url : '(kein Request)'
	);
	check(
		'Block-Editor: POST trägt den geänderten Wert',
		env.sent.length === 1 && JSON.stringify( env.sent[ 0 ].body ).indexOf( 'Aus dem Block-Editor' ) !== -1,
		env.sent.length ? JSON.stringify( env.sent[ 0 ].body ).slice( 0, 90 ) : '(kein Request)'
	);
	check( 'Block-Editor: Edits kommen unverändert zurück', returned === edits, show( returned && returned.content ) );
	check(
		'Block-Editor: Status meldet gespeichert',
		/Gespeichert\./.test( document.querySelector( '[data-sdc-status]' ).textContent ),
		document.querySelector( '[data-sdc-status]' ).textContent
	);

	env.dom.window.close();
}

/** Let queued promises and timers settle. */
function tick( window, ms ) {
	return new Promise( ( resolve ) => window.setTimeout( resolve, ms || 10 ) );
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
	await scenarioBlockEditorBridge();
	await scenarioTools();
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

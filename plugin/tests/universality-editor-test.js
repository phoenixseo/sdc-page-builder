/**
 * SDC Page Builder — editor test for a SECOND component type.
 *
 * The claim: components are data. Type 2 (`hero`) must appear in the picker,
 * render its own inputs, and be collected into the saved document — with the
 * same editor.js that already served `faq`, untouched.
 *
 * Runs against real host markup: tests/fixtures/metabox-m3b.html is exported by
 * export-metabox.php from the page that holds one FAQ and one hero component,
 * i.e. do_meta_boxes() output, not a hand-built copy.
 *
 *   node tests/universality-editor-test.js
 *
 * Exit 0 only when every assertion passes.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const HERE = __dirname;
const FIXTURE = path.join( HERE, 'fixtures', 'metabox-m3b.html' );
// SDC_EDITOR_JS lets a red-green probe point at a deliberately broken copy:
// the test must fail against it, otherwise it proves nothing.
const EDITOR_JS = process.env.SDC_EDITOR_JS || path.join( HERE, '..', 'assets', 'editor.js' );

let passed = 0;
const failures = [];

function check( name, condition, detail ) {
	if ( condition ) {
		passed++;
		console.log( 'PASS ' + name + ( detail ? ' — ' + detail : '' ) );
	} else {
		failures.push( name + ': ' + detail );
		console.log( 'FAIL ' + name + ' — ' + detail );
	}
}

const show = ( value ) => JSON.stringify( value );

function boot() {
	const virtualConsole = new VirtualConsole();
	virtualConsole.on( 'jsdomError', ( error ) => {
		check( 'jsdom ohne Fehler', false, String( ( error && error.message ) || error ) );
	} );

	const dom = new JSDOM( fs.readFileSync( FIXTURE, 'utf8' ), {
		runScripts: 'dangerously',
		url: 'http://example.test/wp-admin/post.php?post=120&action=edit',
		virtualConsole,
	} );
	const { window } = dom;

	const sent = [];
	window.confirm = () => true;
	window.HTMLElement.prototype.scrollIntoView = function () {};
	window.HTMLFormElement.prototype.requestSubmit = function () {};

	window.fetch = function ( url, init ) {
		sent.push( {
			url,
			method: init && init.method,
			body: init && init.body ? JSON.parse( init.body ) : null,
		} );
		return Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( { post_id: 120, document: { components: [] } } ),
		} );
	};

	// The field map is what class-sdc-editor.php localizes: field KEY => NAME.
	window.SDC_PB = {
		restUrl: 'http://example.test/wp-json/sdc-pb/v1/pages/',
		nonce: 'test-nonce',
		fields: {
			field_sdc_faq_heading: 'heading',
			field_sdc_faq_items: 'items',
			field_sdc_faq_item_q: 'q',
			field_sdc_faq_item_a: 'a',
			field_sdc_hero_heading: 'heading',
			field_sdc_hero_subline: 'subline',
			field_sdc_hero_cta: 'cta',
		},
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
	script.textContent = fs.readFileSync( EDITOR_JS, 'utf8' );
	window.document.body.appendChild( script );

	return { window, document: window.document, sent };
}

const fire = ( window, element, type ) =>
	element.dispatchEvent( new window.Event( type, { bubbles: true, cancelable: true } ) );

const components = ( document ) =>
	document.querySelectorAll( '[data-sdc-components] > [data-sdc-component]' );

const byType = ( document, type ) =>
	Array.from( components( document ) ).filter(
		( node ) => node.getAttribute( 'data-sdc-type' ) === type
	);

async function saveViaForm( env ) {
	fire( env.window, env.document.getElementById( 'post' ), 'submit' );
	await new Promise( ( resolve ) => env.window.setTimeout( resolve, 0 ) );
	return env.sent.length ? env.sent[ env.sent.length - 1 ] : null;
}

/* ------------------------------------------------------------------ tests */

function scenarioPickerAndTemplate() {
	const env = boot();
	const { document } = env;

	const select = document.querySelector( '[data-sdc-add-select]' );
	const options = select
		? Array.from( select.options ).map( ( option ) => option.value )
		: [];
	check(
		'Komponenten-Auswahl bietet faq und hero',
		options.includes( 'faq' ) && options.includes( 'hero' ),
		options.join( ', ' )
	);

	const heroTemplate = document.querySelector(
		'[data-sdc-component-template="hero"]'
	);
	check(
		'zweites Komponenten-Template existiert',
		!! heroTemplate,
		'data-sdc-component-template="hero"'
	);
	check(
		'Hero-Template ist inert',
		heroTemplate && heroTemplate.childNodes.length === 0,
		heroTemplate ? 'childNodes=' + heroTemplate.childNodes.length : 'fehlt'
	);
}

function scenarioExistingComponents() {
	const env = boot();
	const { document } = env;

	const faqs = byType( document, 'faq' );
	const heroes = byType( document, 'hero' );
	check( 'eine gespeicherte FAQ-Komponente', faqs.length === 1, faqs.length + '' );
	check( 'eine gespeicherte Hero-Komponente', heroes.length === 1, heroes.length + '' );

	if ( heroes.length !== 1 ) {
		return;
	}

	const hero = heroes[ 0 ];
	const id = hero.getAttribute( 'data-sdc-id' );
	check( 'Hero trägt Instanz-Id', /^c_/.test( id || '' ), show( id ) );
	check(
		'Hero trägt Schema hero@1',
		hero.getAttribute( 'data-sdc-schema' ) === 'hero@1',
		show( hero.getAttribute( 'data-sdc-schema' ) )
	);

	const names = Array.from( hero.querySelectorAll( '[name]' ) ).map( ( el ) =>
		el.getAttribute( 'name' )
	);
	check(
		'Hero-Eingaben sind unter seiner Instanz-Id adressiert',
		names.length > 0 && names.every( ( name ) => name.indexOf( 'acf[' + id + ']' ) === 0 ),
		names.join( ' ' )
	);

	// Die Namen tragen die Feld-KEYS; gespeichert wird unter den Feld-NAMEN.
	check(
		'Hero hat ein Feld field_sdc_hero_heading',
		names.some( ( name ) => name.indexOf( 'field_sdc_hero_heading' ) !== -1 ),
		names.join( ' ' )
	);
	check(
		'Hero hat ein Feld field_sdc_hero_cta',
		names.some( ( name ) => name.indexOf( 'field_sdc_hero_cta' ) !== -1 ),
		names.join( ' ' )
	);
}

async function scenarioAddHero() {
	const env = boot();
	const { document } = env;

	const select = document.querySelector( '[data-sdc-add-select]' );
	const before = components( document ).length;

	select.value = 'hero';
	document.querySelector( '[data-sdc-add-component]' ).click();

	const after = components( document );
	check(
		'„Hinzufügen mit gewähltem hero" hängt genau eine Komponente an',
		after.length === before + 1,
		before + ' -> ' + after.length
	);
	if ( after.length !== before + 1 ) {
		return;
	}

	const added = after[ after.length - 1 ];
	check(
		'angehängte Komponente ist vom Typ hero',
		added.getAttribute( 'data-sdc-type' ) === 'hero',
		show( added.getAttribute( 'data-sdc-type' ) )
	);

	const id = added.getAttribute( 'data-sdc-id' );
	check( 'neue Hero-Id ist frisch, nicht __c__', /^c_[a-z0-9]+$/.test( id || '' ), show( id ) );

	const names = Array.from( added.querySelectorAll( '[name]' ) ).map( ( el ) =>
		el.getAttribute( 'name' )
	);
	check(
		'Platzhalter __c__ ist ersetzt',
		names.length > 0 && names.every( ( name ) => name.indexOf( '__c__' ) === -1 ),
		names.join( ' ' )
	);
	check(
		'neue Hero-Eingaben tragen ihre Id',
		names.length > 0 && names.every( ( name ) => name.indexOf( 'acf[' + id + ']' ) === 0 ),
		names.join( ' ' )
	);

	// Neue Werte eintragen und speichern: der Hero muss im POST-Body landen.
	const headingInput = added.querySelector( '[name*="field_sdc_hero_heading"]' );
	const sublineInput = added.querySelector( '[name*="field_sdc_hero_subline"]' );
	if ( headingInput ) {
		headingInput.value = 'NEUER-HERO-4718';
		fire( env.window, headingInput, 'input' );
	}
	if ( sublineInput ) {
		sublineInput.value = 'NEUE-UNTERZEILE-4719';
		fire( env.window, sublineInput, 'input' );
	}

	const post = await saveViaForm( env );
	check( 'Speichern hat einen POST ausgelöst', !!post, post ? post.method : 'kein POST' );
	if ( ! post ) {
		return;
	}

	const posted = ( post.body && post.body.components ) || [];
	check( 'POST-Body enthält 3 Komponenten', posted.length === 3, posted.length + '' );

	const postedHeroes = posted.filter( ( component ) => component.type === 'hero' );
	const newHero = postedHeroes.find( ( component ) => component._id === id );
	check( 'angehängter Hero ist im POST-Body', !!newHero, show( posted.map( ( c ) => c._id ) ) );
	if ( ! newHero ) {
		return;
	}

	check(
		'POST-Body: hero.heading unter dem Feld-NAMEN',
		newHero.data.heading === 'NEUER-HERO-4718',
		show( newHero.data )
	);
	check(
		'POST-Body: hero.subline unter dem Feld-NAMEN',
		newHero.data.subline === 'NEUE-UNTERZEILE-4719',
		show( newHero.data )
	);
	check(
		'POST-Body: hero.schema ist hero@1',
		newHero.schema === 'hero@1',
		show( newHero.schema )
	);
	check(
		'POST-Body: kein Feld-Key als Schlüssel übrig',
		! Object.keys( newHero.data ).some( ( key ) => key.indexOf( 'field_' ) === 0 ),
		Object.keys( newHero.data ).join( ', ' )
	);

	// Der bestehende Hero muss unangetastet mitgesendet werden.
	const existing = postedHeroes.find( ( component ) => component._id === 'c_m3b_hero' );
	check( 'bestehender Hero bleibt im POST-Body', !!existing, show( postedHeroes.map( ( c ) => c._id ) ) );
	if ( existing ) {
		check(
			'bestehender Hero behält seine Überschrift',
			existing.data.heading === 'SENTINEL-Hero-Kopf 4714',
			show( existing.data.heading )
		);
	}

	const faq = posted.find( ( component ) => component.type === 'faq' );
	check( 'FAQ bleibt im selben POST-Body', !!faq, show( posted.map( ( c ) => c.type ) ) );
}

async function main() {
	scenarioPickerAndTemplate();
	scenarioExistingComponents();
	await scenarioAddHero();

	console.log(
		'\n' +
			( failures.length === 0 ? 'ERGEBNIS: ALLE PRÜFUNGEN GRÜN' : 'ERGEBNIS: FEHLSCHLÄGE' ) +
			' (' +
			passed +
			' bestanden, ' +
			failures.length +
			' Fehler)'
	);
	if ( failures.length ) {
		failures.forEach( ( line ) => console.log( '  - ' + line ) );
	}
	process.exit( failures.length === 0 ? 0 : 1 );
}

main();

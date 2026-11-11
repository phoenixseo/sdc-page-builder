/**
 * The value rules behind the media fields of the SDC inspector.
 *
 * These run in plain node — no browser, no WordPress, no build step — because
 * that is the point of `assets/media-field.js`. An image value can be an ID, a
 * URL or an attachment array, and the two failures are different: an ID read
 * as a URL renders `<img src="42">`, a URL read as an ID renders nothing.
 * Neither announces itself in the editor, so both are pinned here.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const media = require( '../../assets/media-field.js' );

test( 'idOf reads a numeric string, the shape the picker writes', () => {
	assert.equal( media.idOf( '42' ), 42 );
	assert.equal( media.idOf( ' 42 ' ), 42 );
	assert.equal( media.idOf( 42 ), 42 );
	assert.equal( media.idOf( 42.9 ), 42 );
} );

test( 'idOf answers 0 for empty, zero and negative values', () => {
	assert.equal( media.idOf( '' ), 0 );
	assert.equal( media.idOf( '0' ), 0 );
	assert.equal( media.idOf( 0 ), 0 );
	assert.equal( media.idOf( -5 ), 0 );
	assert.equal( media.idOf( null ), 0 );
	assert.equal( media.idOf( undefined ), 0 );
	assert.equal( media.idOf( false ), 0 );
} );

test( 'idOf does not read digits out of a filename or a number with a suffix', () => {
	// `parseInt` would answer 2024 and 42 for both — and then the editor would
	// render a broken image instead of the URL it actually has.
	assert.equal( media.idOf( '2024-05-foto.jpg' ), 0 );
	assert.equal( media.idOf( '42px' ), 0 );
	assert.equal( media.idOf( '12/2024' ), 0 );
} );

test( 'idOf reads the attachment array in both shapes ACF returns', () => {
	assert.equal( media.idOf( { ID: 7, url: 'https://example.test/a.jpg' } ), 7 );
	assert.equal( media.idOf( { id: 7, url: 'https://example.test/a.jpg' } ), 7 );
	assert.equal( media.idOf( { ID: '7' } ), 7 );
	// An array without an ID is a URL-only value, not an ID of 0-th.
	assert.equal( media.idOf( { url: 'https://example.test/a.jpg' } ), 0 );
} );

test( 'urlOf keeps a URL and refuses to pass an ID off as one', () => {
	assert.equal( media.urlOf( 'https://example.test/a.jpg' ), 'https://example.test/a.jpg' );
	assert.equal( media.urlOf( '  https://example.test/a.jpg  ' ), 'https://example.test/a.jpg' );
	assert.equal( media.urlOf( { url: 'https://example.test/a.jpg' } ), 'https://example.test/a.jpg' );
	assert.equal( media.urlOf( { ID: 7, url: 'https://example.test/a.jpg' } ), 'https://example.test/a.jpg' );
} );

test( 'urlOf answers an empty string for an ID, empty and unrelated values', () => {
	assert.equal( media.urlOf( '42' ), '' );
	assert.equal( media.urlOf( 42 ), '' );
	assert.equal( media.urlOf( '' ), '' );
	assert.equal( media.urlOf( null ), '' );
	assert.equal( media.urlOf( { ID: 7 } ), '' );
} );

test( 'valueFor stores the attachment ID as a string', () => {
	// The ID, not the URL: it still resolves after the file is replaced or the
	// site moves, and it brings alt text, title and sizes along.
	assert.equal( media.valueFor( { id: 42, url: 'https://example.test/a.jpg' } ), '42' );
	assert.equal( media.valueFor( { ID: '42' } ), '42' );
} );

test( 'valueFor answers an empty string when the selection has no ID', () => {
	assert.equal( media.valueFor( {} ), '' );
	assert.equal( media.valueFor( null ), '' );
	assert.equal( media.valueFor( { url: 'https://example.test/a.jpg' } ), '' );
} );

test( 'the module exports itself for the browser as well', () => {
	assert.equal( globalThis.SDCMediaField, media );
} );

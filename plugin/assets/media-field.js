/**
 * What a media field's stored value means.
 *
 * An `image` or `file` value can arrive in three shapes, and the picker has to
 * read all of them: a bare attachment ID (`"42"`, what the picker writes and
 * what ACF stores), a URL (typed before the picker existed, or written by an
 * import), or an attachment array (`{ id, url, alt }`, what ACF returns with
 * `return_format: array`). Reading it wrong is not symmetric — an ID read as a
 * URL renders a broken `<img src="42">`, and a URL read as an ID renders
 * nothing at all.
 *
 * Pure functions, no DOM and no `wp.*`, so node can test them: `assets/block.js`
 * needs WordPress, and a branch that only exists inside it cannot be run.
 *
 * ES5 on purpose: the file ships as written, there is no build step.
 */
( function ( root ) {
	'use strict';

	/**
	 * The attachment ID in a value, or 0.
	 *
	 * `0` is the answer for everything that is not an ID, including a URL — it
	 * is not an error, it means "no attachment to look up".
	 *
	 * @param {*} value Stored field value.
	 * @return {number} Attachment ID, 0 when the value carries none.
	 */
	function idOf( value ) {
		if ( typeof value === 'number' ) {
			return isFinite( value ) && value > 0 ? Math.floor( value ) : 0;
		}

		if ( typeof value === 'string' ) {
			// Digits only: `parseInt` alone would read "42px" as 42, and a
			// filename such as "2024-05-foto.jpg" starts with digits too.
			var digits = /^\s*(\d+)\s*$/.exec( value );

			return digits && parseInt( digits[ 1 ], 10 ) > 0 ? parseInt( digits[ 1 ], 10 ) : 0;
		}

		if ( value && typeof value === 'object' ) {
			// ACF's array shape is `ID`; its REST shape is `id`. Both occur.
			if ( value.ID !== undefined ) {
				return idOf( value.ID );
			}

			if ( value.id !== undefined ) {
				return idOf( value.id );
			}
		}

		return 0;
	}

	/**
	 * The URL in a value, or an empty string.
	 *
	 * @param {*} value Stored field value.
	 * @return {string} URL, empty when the value carries none.
	 */
	function urlOf( value ) {
		if ( typeof value === 'string' ) {
			var trimmed = value.trim();

			// A pure number is an ID, not a URL: `<img src="42">` is exactly
			// the broken output this function exists to prevent.
			return '' !== trimmed && 0 === idOf( trimmed ) ? trimmed : '';
		}

		if ( value && typeof value === 'object' && typeof value.url === 'string' ) {
			return value.url.trim();
		}

		return '';
	}

	/**
	 * The value to store for a picked attachment.
	 *
	 * The ID, never the URL: a URL goes stale as soon as the file is replaced
	 * or the site moves, while the ID still resolves and brings alt text,
	 * title and the registered sizes with it. The server reads all three
	 * shapes, so this is a choice, not a constraint — the durable one.
	 *
	 * @param {Object} attachment Media library selection, as `toJSON()`.
	 * @return {string} Value to store, empty when the selection has no ID.
	 */
	function valueFor( attachment ) {
		var id = idOf( attachment );

		return id > 0 ? String( id ) : '';
	}

	var api = {
		idOf: idOf,
		urlOf: urlOf,
		valueFor: valueFor,
	};

	root.SDCMediaField = api;

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
}( typeof window !== 'undefined' ? window : globalThis ) );

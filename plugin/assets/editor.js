/**
 * SDC Page Builder — editor behaviour.
 *
 * Job: let the user compose components in wp-admin and hand the result to
 * `sdc-pb/v1/pages/{id}` as ONE JSON document.
 *
 * Why not a plain form post: a builder page with 30 components renders 1373
 * inputs, and PHP cuts POST data at max_input_vars (1000) without an error.
 * So the values are collected here and sent as JSON; before the real post
 * submit the builder inputs lose their names so they cannot crowd out the
 * remaining fields.
 *
 * Name handling: the host names every input after the field KEY
 * (`acf_prepare_field` forces name = key) and wraps it in the component's
 * prefix `acf[<instance-id>]`. The stored document is keyed by field NAME.
 * This file strips the prefix and translates keys to names.
 */
( function () {
	'use strict';

	var config = window.SDC_PB || {};
	var fieldNames = config.fields || {};
	var labels = config.labels || {};

	var builder = document.querySelector( '[data-sdc-builder]' );
	if ( ! builder || ! config.restUrl ) {
		return;
	}

	var form = document.getElementById( 'post' );
	var list = builder.querySelector( '[data-sdc-components]' );
	var emptyHint = builder.querySelector( '[data-sdc-empty]' );
	var statusEl = builder.querySelector( '[data-sdc-status]' );
	var historyPanel = builder.querySelector( '[data-sdc-history]' );
	var historyList = builder.querySelector( '[data-sdc-history-list]' );
	var importInput = builder.querySelector( '[data-sdc-import-input]' );
	var postId = builder.getAttribute( 'data-sdc-post' );
	var dirty = false;
	var bypassSubmit = false;

	/* ---------------------------------------------------------------- utils */

	function setStatus( text, isError ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = text || '';
		statusEl.className = 'sdc-builder__status' + ( isError ? ' is-error' : '' );
	}

	function markDirty() {
		dirty = true;
		setStatus( labels.dirty || 'Unsaved changes', false );
	}

	function newId() {
		return 'c_' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function syncEditors() {
		// TinyMCE keeps its own copy of the content; without this the markup
		// read below is one edit behind.
		if ( window.tinymce && typeof window.tinymce.triggerSave === 'function' ) {
			window.tinymce.triggerSave();
		}
	}

	/**
	 * Replace the first occurrence of a placeholder in every name carrying it.
	 *
	 * Only the FIRST one: a row template can contain a nested row template whose
	 * own placeholder must survive for its later clones.
	 *
	 * Templates are stepped into explicitly: elements inside `<template>` live
	 * in `content`, and querySelectorAll does not descend into that fragment.
	 * A cloned component keeps its row template unrewritten otherwise, and every
	 * row added afterwards names its inputs `acf[__c__]…` — collect() drops them.
	 */
	function replacePlaceholder( root, placeholder, replacement ) {
		var nodes = root.querySelectorAll( '[name]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var name = nodes[ i ].getAttribute( 'name' );
			var at = name.indexOf( placeholder );
			if ( at === -1 ) {
				continue;
			}
			nodes[ i ].setAttribute(
				'name',
				name.slice( 0, at ) + replacement + name.slice( at + placeholder.length )
			);
		}

		var templates = root.querySelectorAll( 'template' );
		for ( var j = 0; j < templates.length; j++ ) {
			if ( templates[ j ].content ) {
				replacePlaceholder( templates[ j ].content, placeholder, replacement );
			}
		}
	}

	function replacePrefix( root, oldPrefix, newPrefix ) {
		var nodes = root.querySelectorAll( '[name]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var name = nodes[ i ].getAttribute( 'name' );
			if ( name.indexOf( oldPrefix ) === 0 ) {
				nodes[ i ].setAttribute( 'name', newPrefix + name.slice( oldPrefix.length ) );
			}
		}
	}

	/* ------------------------------------------------------------- components */

	var componentSelector = '[data-sdc-component]';

	function refreshEmptyHint() {
		if ( ! emptyHint ) {
			return;
		}
		emptyHint.classList.toggle( 'is-hidden', list.querySelectorAll( componentSelector ).length > 0 );
	}

	function addComponent() {
		var select = builder.querySelector( '[data-sdc-add-select]' );
		var type = select ? select.value : '';
		var template = builder.querySelector( '[data-sdc-component-template="' + type + '"]' );
		if ( ! template ) {
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		var id = newId();

		node.setAttribute( 'data-sdc-id', id );
		var inner = node.querySelector( '.sdc-component__body > [data-sdc-instance]' );
		if ( inner ) {
			inner.setAttribute( 'data-sdc-instance', id );
		}
		replacePlaceholder( node, '[__c__]', '[' + id + ']' );

		list.appendChild( node );
		refreshEmptyHint();
		markDirty();
		node.scrollIntoView( { block: 'center' } );
	}

	function removeComponent( node ) {
		if ( ! node ) {
			return;
		}
		if ( ! window.confirm( labels.confirm || 'Remove?' ) ) {
			return;
		}
		node.parentNode.removeChild( node );
		refreshEmptyHint();
		markDirty();
	}

	/* ------------------------------------------------------------------ rows */

	function listOf( node ) {
		return node.closest( '[data-sdc-container="list"]' );
	}

	function rowsOf( listEl ) {
		var out = [];
		var rowsEl = listEl.querySelector( '[data-sdc-rows]' );
		if ( ! rowsEl ) {
			return out;
		}
		var kids = rowsEl.children;
		for ( var i = 0; i < kids.length; i++ ) {
			if ( kids[ i ].hasAttribute( 'data-sdc-row' ) ) {
				out.push( kids[ i ] );
			}
		}
		return out;
	}

	function nextRowIndex( listEl ) {
		var rows = rowsOf( listEl );
		var max = -1;
		for ( var i = 0; i < rows.length; i++ ) {
			var idx = parseInt( rows[ i ].getAttribute( 'data-sdc-row-index' ), 10 );
			if ( ! isNaN( idx ) && idx > max ) {
				max = idx;
			}
		}
		return max + 1;
	}

	function setRowPrefix( row, newPrefix ) {
		var oldPrefix = row.getAttribute( 'data-sdc-prefix' ) || '';
		if ( oldPrefix && oldPrefix !== newPrefix ) {
			replacePrefix( row, oldPrefix, newPrefix );
		}
		row.setAttribute( 'data-sdc-prefix', newPrefix );
	}

	/**
	 * Renumber a list's rows to 0..n-1 and rewrite their name prefixes.
	 *
	 * A row's own prefix ends with its own index, so only that trailing index
	 * changes; deeper (nested) prefixes hang off it and follow automatically.
	 */
	function reindexRows( listEl ) {
		var rows = rowsOf( listEl );
		for ( var i = 0; i < rows.length; i++ ) {
			var row = rows[ i ];
			var prefix = row.getAttribute( 'data-sdc-prefix' ) || '';
			var next = prefix.replace( /\[\d+\]$/, '[' + i + ']' );

			row.setAttribute( 'data-sdc-row-index', String( i ) );
			var idInput = row.querySelector( 'input[name="' + prefix + '[_id]"]' );
			if ( idInput && ! idInput.value ) {
				idInput.value = newId();
			}
			if ( next !== prefix ) {
				setRowPrefix( row, next );
			}
		}
	}

	function addRow( button ) {
		var listEl = listOf( button );
		if ( ! listEl ) {
			return;
		}
		var template = listEl.querySelector( '[data-sdc-row-template]' );
		if ( ! template || ! template.content || ! template.content.firstElementChild ) {
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		var index = nextRowIndex( listEl );
		var id = newId();
		var prefix = node.getAttribute( 'data-sdc-prefix' ).replace( '[__i__]', '[' + index + ']' );

		replacePlaceholder( node, '[__i__]', '[' + index + ']' );
		node.setAttribute( 'data-sdc-row-index', String( index ) );
		node.setAttribute( 'data-sdc-row-id', id );
		node.setAttribute( 'data-sdc-prefix', prefix );

		var idInput = node.querySelector( 'input[name="' + prefix + '[_id]"]' );
		if ( idInput ) {
			idInput.value = id;
		}

		listEl.querySelector( '[data-sdc-rows]' ).appendChild( node );
		markDirty();
	}

	function removeRow( button ) {
		var row = button.closest( '[data-sdc-row]' );
		if ( ! row ) {
			return;
		}
		var listEl = listOf( row );
		row.parentNode.removeChild( row );
		if ( listEl ) {
			reindexRows( listEl );
		}
		markDirty();
	}

	/* ------------------------------------------------------------------ drag */

	var dragged = null;
	var draggedSelector = '';

	/**
	 * What a drag handle belongs to: a row wins over the component around it.
	 */
	function draggableOf( handle ) {
		var row = handle.closest( '[data-sdc-row]' );
		if ( row ) {
			return { item: row, selector: '[data-sdc-row]' };
		}
		var component = handle.closest( componentSelector );
		if ( component ) {
			return { item: component, selector: componentSelector };
		}
		return null;
	}

	builder.addEventListener( 'dragstart', function ( event ) {
		var handle = event.target.closest ? event.target.closest( '[data-sdc-drag-handle]' ) : null;
		if ( ! handle ) {
			return;
		}
		var found = draggableOf( handle );
		if ( ! found ) {
			return;
		}
		dragged = found.item;
		draggedSelector = found.selector;
		dragged.classList.add( 'is-dragging' );
		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			// Firefox refuses to start a drag without payload.
			event.dataTransfer.setData( 'text/plain', '' );
		}
	} );

	builder.addEventListener( 'dragover', function ( event ) {
		if ( ! dragged ) {
			return;
		}
		var over = event.target.closest ? event.target.closest( draggedSelector ) : null;
		// Same parent only: moving a row between different lists would break
		// its name prefix, and reordering components across the root is the
		// only cross-list case we support.
		if ( ! over || over === dragged || over.parentNode !== dragged.parentNode ) {
			return;
		}
		event.preventDefault();
		var box = over.getBoundingClientRect();
		var after = event.clientY > box.top + box.height / 2;
		over.parentNode.insertBefore( dragged, after ? over.nextSibling : over );
	} );

	builder.addEventListener( 'drop', function ( event ) {
		if ( dragged ) {
			event.preventDefault();
		}
	} );

	builder.addEventListener( 'dragend', function () {
		if ( ! dragged ) {
			return;
		}
		dragged.classList.remove( 'is-dragging' );
		var wasRow = dragged.hasAttribute( 'data-sdc-row' );
		var listEl = wasRow ? listOf( dragged ) : null;
		dragged = null;
		draggedSelector = '';
		if ( listEl ) {
			reindexRows( listEl );
		}
		markDirty();
	} );

	/* --------------------------------------------------------- serialisation */

	function parseName( name ) {
		var parts = [];
		var first = name.indexOf( '[' );
		if ( first > 0 ) {
			parts.push( name.slice( 0, first ) );
		}
		var re = /\[([^\]]*)\]/g;
		var match;
		while ( ( match = re.exec( name ) ) !== null ) {
			parts.push( match[ 1 ] );
		}
		return parts;
	}

	function assign( target, parts, value ) {
		var node = target;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			var key = parts[ i ];
			if ( ! node[ key ] || typeof node[ key ] !== 'object' ) {
				node[ key ] = {};
			}
			node = node[ key ];
		}
		var last = parts[ parts.length - 1 ];
		if ( last in node ) {
			if ( Array.isArray( node[ last ] ) ) {
				node[ last ].push( value );
			} else {
				node[ last ] = [ node[ last ], value ];
			}
		} else {
			node[ last ] = value;
		}
	}

	/** Numeric-keyed objects become arrays; PHP sends rows as 0,1,2. */
	function toArrays( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( toArrays );
		}
		if ( ! value || typeof value !== 'object' ) {
			return value;
		}
		var keys = Object.keys( value );
		var numeric = keys.length > 0 && keys.every( function ( k, i ) {
			return String( i ) === k;
		} );
		var out = numeric ? [] : {};
		keys.forEach( function ( k ) {
			var converted = toArrays( value[ k ] );
			if ( numeric ) {
				out.push( converted );
			} else {
				out[ k ] = converted;
			}
		} );
		return out;
	}

	function renameKeys( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( renameKeys );
		}
		if ( ! value || typeof value !== 'object' ) {
			return value;
		}
		var out = {};
		Object.keys( value ).forEach( function ( k ) {
			out[ fieldNames[ k ] || k ] = renameKeys( value[ k ] );
		} );
		return out;
	}

	function readValue( el ) {
		var type = ( el.type || '' ).toLowerCase();
		if ( type === 'checkbox' ) {
			return el.checked ? el.value : null;
		}
		if ( type === 'radio' ) {
			return el.checked ? el.value : null;
		}
		if ( el.tagName === 'SELECT' && el.multiple ) {
			var picked = [];
			for ( var i = 0; i < el.options.length; i++ ) {
				if ( el.options[ i ].selected ) {
					picked.push( el.options[ i ].value );
				}
			}
			return picked;
		}
		return el.value;
	}

	function collectComponent( node ) {
		var instance = node.getAttribute( 'data-sdc-id' );
		var data = {};
		var inputs = node.querySelectorAll( '[name]' );

		for ( var i = 0; i < inputs.length; i++ ) {
			var el = inputs[ i ];
			if ( el.disabled ) {
				continue;
			}
			var parts = parseName( el.getAttribute( 'name' ) );
			// parts[0] = 'acf', parts[1] = this component's instance id.
			if ( parts.length < 3 || parts[ 0 ] !== 'acf' || parts[ 1 ] !== instance ) {
				continue;
			}
			var value = readValue( el );
			if ( null === value ) {
				continue;
			}
			assign( data, parts.slice( 2 ), value );
		}

		return {
			_id: instance,
			type: node.getAttribute( 'data-sdc-type' ),
			schema: node.getAttribute( 'data-sdc-schema' ),
			data: renameKeys( toArrays( data ) )
		};
	}

	function collect() {
		syncEditors();
		var out = [];
		var nodes = list.querySelectorAll( componentSelector );
		for ( var i = 0; i < nodes.length; i++ ) {
			out.push( collectComponent( nodes[ i ] ) );
		}
		return out;
	}

	/* ---------------------------------------------------------------- saving */

	function save() {
		var components = collect();
		setStatus( labels.saving || 'Saving …', false );

		return window.fetch( config.restUrl + postId, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( { components: components } )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( body && body.message ? body.message : ( labels.failed || 'Failed' ) );
				}
				return body;
			} );
		} ).then( function ( body ) {
			dirty = false;
			var count = body && body.document ? body.document.components.length : components.length;
			setStatus( ( labels.saved || 'Saved' ) + ' ' + count + ' Komponenten.', false );
			return body;
		} ).catch( function ( error ) {
			setStatus( ( labels.failed || 'Failed' ) + ' ' + error.message, true );
			throw error;
		} );
	}

	/**
	 * Drop the builder inputs' names before the real post submit.
	 *
	 * The document is already saved over REST; leaving 1373 named inputs in the
	 * form would push the remaining fields past max_input_vars and silently
	 * truncate them.
	 */
	function detachNames() {
		var nodes = builder.querySelectorAll( '[name]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].removeAttribute( 'name' );
		}
	}

	/* --------------------------------------------------------------- tools */

	/**
	 * Read a JSON response, turning a non-OK status into an Error that
	 * carries the server's message rather than "HTTP 422".
	 *
	 * @param {Response} response Fetch response.
	 * @return {Promise} Resolves to the parsed body.
	 */
	function asJson( response ) {
		return response.json().then( function ( body ) {
			if ( ! response.ok ) {
				var detail = body && body.message ? body.message : ( labels.failed || 'Failed' );
				if ( body && body.data && body.data.errors && body.data.errors.length ) {
					detail += ' ' + body.data.errors.join( ' ' );
				}
				throw new Error( detail );
			}
			return body;
		} );
	}

	function exportDocument() {
		setStatus( labels.exporting || 'Exportiere …', false );

		return window.fetch( config.restUrl + postId + '/export', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( asJson ).then( function ( payload ) {
			var blob = new window.Blob( [ JSON.stringify( payload, null, 2 ) ], { type: 'application/json' } );
			var url = window.URL.createObjectURL( blob );
			var link = document.createElement( 'a' );
			link.href = url;
			link.download = ( payload.slug || 'sdc-dokument' ) + '.json';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			window.URL.revokeObjectURL( url );
			setStatus( labels.exported || 'Exportiert.', false );
		} ).catch( function ( error ) {
			setStatus( ( labels.failed || 'Failed' ) + ' ' + error.message, true );
		} );
	}

	/**
	 * Import a file into this page. The whole payload goes up in one request;
	 * the server validates and writes nothing on error, so there is no
	 * half-imported state to repair here.
	 *
	 * @param {File} file Chosen file.
	 */
	function importDocument( file ) {
		setStatus( labels.importing || 'Importiere …', false );

		var reader = new window.FileReader();
		reader.onload = function () {
			var text = String( reader.result || '' );
			var payload;

			try {
				payload = JSON.parse( text );
			} catch ( error ) {
				setStatus( ( labels.failed || 'Failed' ) + ' ' + ( labels.badJson || 'Die Datei ist kein gültiges JSON.' ), true );
				return;
			}

			if ( ! window.confirm( labels.importConfirm || 'Import überschreibt das aktuelle Dokument. Der jetzige Stand wird vorher gesichert. Fortfahren?' ) ) {
				return;
			}

			window.fetch( config.restUrl + postId + '/import', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce
				},
				body: JSON.stringify( { payload: payload } )
			} ).then( asJson ).then( function ( body ) {
				var count = body && body.document ? body.document.components.length : '?';
				setStatus( ( labels.imported || 'Importiert.' ) + ' ' + count + ' Komponenten. Seite wird neu geladen …', false );
				// Reload, because the form markup on screen is the old document.
				reloadSoon( 900 );
			} ).catch( function ( error ) {
				setStatus( ( labels.failed || 'Failed' ) + ' ' + error.message, true );
			} );
		};
		reader.readAsText( file );
	}

	/**
	 * Reload the editor after the document changed underneath the form.
	 *
	 * Isolated so it can be swapped in tests: jsdom makes `location` an own,
	 * non-configurable property, so a harness cannot stub `reload` and would
	 * abort the run with "navigation to another Document".
	 *
	 * @param {number} delay Delay in ms, so the status line is readable.
	 */
	function reloadSoon( delay ) {
		window.setTimeout( function () {
			window.location.reload();
		}, delay );
	}

	function formatOffset( offset ) {
		if ( typeof offset !== 'number' ) {
			return '';
		}
		if ( offset > -60 ) {
			return Math.abs( offset ) + ' s';
		}
		if ( offset > -3600 ) {
			return Math.round( Math.abs( offset ) / 60 ) + ' min';
		}
		if ( offset > -86400 ) {
			return Math.round( Math.abs( offset ) / 3600 ) + ' h';
		}
		return Math.round( Math.abs( offset ) / 86400 ) + ' d';
	}

	function restore( revision, button ) {
		if ( ! window.confirm( labels.restoreConfirm || 'Diesen Stand wiederherstellen? Das aktuelle Dokument wird vorher gesichert.' ) ) {
			return;
		}

		button.disabled = true;
		setStatus( labels.restoring || 'Stelle wieder her …', false );

		window.fetch( config.restUrl + postId + '/restore', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( { revision: revision } )
		} ).then( asJson ).then( function () {
			setStatus( labels.restored || 'Wiederhergestellt. Seite wird neu geladen …', false );
			reloadSoon( 700 );
		} ).catch( function ( error ) {
			button.disabled = false;
			setStatus( ( labels.failed || 'Failed' ) + ' ' + error.message, true );
		} );
	}

	function renderHistory( entries ) {
		historyList.textContent = '';

		if ( ! entries.length ) {
			var none = document.createElement( 'li' );
			none.textContent = labels.noHistory || 'Keine gespeicherten Stände.';
			historyList.appendChild( none );
			return;
		}

		entries.forEach( function ( entry ) {
			var item = document.createElement( 'li' );
			item.className = 'sdc-builder__history-item';

			var meta = document.createElement( 'span' );
			meta.textContent = ( entry.date || '' ) + ' · ' + ( entry.components || 0 ) + ' Komponenten';
			if ( entry.title ) {
				meta.textContent += ' · ' + entry.title;
			}
			item.appendChild( meta );

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button button-small';
			button.textContent = ( labels.restore || 'Wiederherstellen' ) + ' (' + formatOffset( entry.offset ) + ')';
			button.addEventListener( 'click', function () {
				restore( entry.revision, button );
			} );
			item.appendChild( button );

			historyList.appendChild( item );
		} );
	}

	function toggleHistory( toggle ) {
		var open = historyPanel.hasAttribute( 'hidden' );

		if ( ! open ) {
			historyPanel.setAttribute( 'hidden', '' );
			toggle.setAttribute( 'aria-expanded', 'false' );
			return;
		}

		historyPanel.removeAttribute( 'hidden' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		historyList.textContent = labels.loading || 'Lade …';

		window.fetch( config.restUrl + postId + '/history', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( asJson ).then( function ( body ) {
			renderHistory( ( body && body.revisions ) || [] );
		} ).catch( function ( error ) {
			historyList.textContent = '';
			setStatus( ( labels.failed || 'Failed' ) + ' ' + error.message, true );
		} );
	}

	/* ---------------------------------------------------------------- wiring */

	builder.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( target.closest( '[data-sdc-export]' ) ) {
			event.preventDefault();
			exportDocument();
			return;
		}
		if ( target.closest( '[data-sdc-history-toggle]' ) ) {
			event.preventDefault();
			toggleHistory( target.closest( '[data-sdc-history-toggle]' ) );
			return;
		}
		if ( target.closest( '[data-sdc-add-component]' ) ) {
			event.preventDefault();
			addComponent();
			return;
		}
		if ( target.closest( '[data-sdc-add-row]' ) ) {
			event.preventDefault();
			addRow( target.closest( '[data-sdc-add-row]' ) );
			return;
		}
		if ( target.closest( '[data-sdc-remove-row]' ) ) {
			event.preventDefault();
			removeRow( target.closest( '[data-sdc-remove-row]' ) );
			return;
		}
		if ( target.closest( '[data-sdc-remove-component]' ) ) {
			event.preventDefault();
			removeComponent( target.closest( componentSelector ) );
		}
	} );

	builder.addEventListener( 'input', markDirty );
	builder.addEventListener( 'change', markDirty );

	if ( importInput ) {
		importInput.addEventListener( 'change', function () {
			if ( importInput.files && importInput.files.length ) {
				importDocument( importInput.files[ 0 ] );
			}
			importInput.value = '';
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( bypassSubmit || ! dirty ) {
				return;
			}

			event.preventDefault();
			var submitter = event.submitter || null;

			save().then( function () {
				bypassSubmit = true;
				detachNames();
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit( submitter || undefined );
				} else {
					form.submit();
				}
			} ).catch( function () {
				// Stay on the page: the status line carries the reason.
			} );
		} );
	}

	/*
	 * Block editor bridge.
	 *
	 * The block editor never submits a form. Measured in its own source: it
	 * builds a FormData from the metabox DOM and posts that to post.php, so a
	 * `submit` listener — which is what the classic editor fires — never runs.
	 * Without this the document was never written and an author watched their
	 * edit revert to the stored value.
	 *
	 * `editor.preSavePost` is the host's own hook for this moment, applied
	 * with `applyFiltersAsync` before the post is saved. Throwing aborts the
	 * post save: a page whose components could not be written must not look
	 * saved.
	 */
	var gutenberg = window.wp && window.wp.data ? window.wp.data.select( 'core/editor' ) : null;

	if ( gutenberg && window.wp.hooks ) {
		window.wp.hooks.addFilter( 'editor.preSavePost', 'sdc-page-builder', function ( edits ) {
			if ( ! dirty ) {
				return edits;
			}

			return save().then( function () {
				return edits;
			} );
		} );
	}

	refreshEmptyHint();

	/**
	 * ACF's own JS (media picker, colour picker) binds to `.acf-field`
	 * wrappers. Builder leaves are rendered bare, so those inputs stay
	 * un-enhanced until they get a wrapper — flag them instead of pretending.
	 */
	var needsHost = [ 'image', 'gallery', 'file', 'wysiwyg', 'color_picker', 'date_picker', 'relationship', 'post_object', 'taxonomy' ];
	var leaves = builder.querySelectorAll( '.sdc-field' );
	for ( var i = 0; i < leaves.length; i++ ) {
		var match = leaves[ i ].className.match( /sdc-field--([a-z0-9_]+)/ );
		if ( match && needsHost.indexOf( match[ 1 ] ) !== -1 ) {
			leaves[ i ].classList.add( 'sdc-field--needs-host' );
		}
	}
}() );

/**
 * SDC Page Builder — the tabs on the component screen.
 *
 * Without this file the screen still works: every panel is in the DOM and
 * visible, one below the other. That is the deliberate fallback for a browser
 * where the script does not run — losing the author's typed text because a
 * tab could not be opened would be the worst possible failure here.
 */

( function () {
	var lists = document.querySelectorAll( '.sdc-tabs__nav' );

	if ( ! lists.length ) {
		return;
	}

	Array.prototype.forEach.call( lists, function ( nav ) {
		var tabs = nav.querySelectorAll( '[data-sdc-tab]' );
		var root = nav.parentNode;
		var panels = root.querySelectorAll( '[data-sdc-panel]' );

		if ( ! tabs.length || ! panels.length ) {
			return;
		}

		function show( id, focus ) {
			Array.prototype.forEach.call( tabs, function ( tab ) {
				var on = tab.getAttribute( 'data-sdc-tab' ) === id;

				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', on ? '0' : '-1' );
				tab.classList.toggle( 'is-active', on );

				if ( on && focus ) {
					tab.focus();
				}
			} );

			Array.prototype.forEach.call( panels, function ( panel ) {
				var on = panel.getAttribute( 'data-sdc-panel' ) === id;

				panel.classList.toggle( 'is-active', on );

				if ( on ) {
					panel.removeAttribute( 'aria-hidden' );
				} else {
					panel.setAttribute( 'aria-hidden', 'true' );
				}
			} );
		}

		function current() {
			for ( var i = 0; i < tabs.length; i++ ) {
				if ( 'true' === tabs[ i ].getAttribute( 'aria-selected' ) ) {
					return i;
				}
			}

			return 0;
		}

		Array.prototype.forEach.call( tabs, function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				show( tab.getAttribute( 'data-sdc-tab' ), false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var next = -1;

				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					next = ( index + 1 ) % tabs.length;
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					next = ( index - 1 + tabs.length ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = tabs.length - 1;
				}

				if ( -1 === next ) {
					return;
				}

				event.preventDefault();
				show( tabs[ next ].getAttribute( 'data-sdc-tab' ), true );
			} );
		} );

		// Collapse only now: before this point the panels are stacked and
		// readable, which is what a script-less browser keeps.
		root.classList.add( 'is-ready' );
		show( tabs[ current() ].getAttribute( 'data-sdc-tab' ), false );
	} );
}() );

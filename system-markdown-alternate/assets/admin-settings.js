/**
 * System Markdown Alternate — settings page tabs.
 *
 * Progressive enhancement: without this script every panel remains visible
 * (stacked) and the page is fully usable. Fields always remain in the form, so
 * saving behavior does not change. Dependency-free vanilla JS. Inactive panels
 * are hidden only after adding the `sysmda-js-tabs` class (see CSS), so the
 * no-JS experience hides nothing.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.sysmda-settings-page' );
	if ( ! root ) {
		return;
	}

	var tabs = Array.prototype.slice.call( root.querySelectorAll( '.sysmda-tabs .nav-tab' ) );
	var panels = Array.prototype.slice.call( root.querySelectorAll( '.sysmda-tab-panel' ) );
	if ( tabs.length < 2 || ! panels.length ) {
		return;
	}

	root.classList.add( 'sysmda-js-tabs' );

	function hasPanel( id ) {
		return panels.some( function ( p ) {
			return p.getAttribute( 'data-tab' ) === id;
		} );
	}

	function activate( id, store, focus ) {
		if ( ! hasPanel( id ) ) {
			return;
		}
		panels.forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.getAttribute( 'data-tab' ) === id );
		} );
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === id;
			t.classList.toggle( 'nav-tab-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			// Roving tabindex: only the selected tab is in the tab order, so Tab
			// leaves the tablist and the arrow keys move between tabs.
			t.setAttribute( 'tabindex', on ? '0' : '-1' );
			if ( on && focus ) {
				t.focus();
			}
		} );
		if ( store ) {
			try {
				window.sessionStorage.setItem( 'sysmdaActiveTab', id );
			} catch ( e ) {}
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#sysmda-panel-' + id );
			}
		}
	}

	tabs.forEach( function ( t, index ) {
		t.setAttribute( 'role', 'tab' );
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activate( t.getAttribute( 'data-tab' ), true );
		} );
		t.addEventListener( 'keydown', function ( e ) {
			var step = 0;
			if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
				step = 1;
			} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
				step = -1;
			} else if ( 'Home' === e.key ) {
				step = -index;
			} else if ( 'End' === e.key ) {
				step = tabs.length - 1 - index;
			} else {
				return;
			}
			e.preventDefault();
			var next = tabs[ ( index + step + tabs.length ) % tabs.length ];
			activate( next.getAttribute( 'data-tab' ), true, true );
		} );
	} );

	// Initial state: URL hash > sessionStorage > first tab.
	var initial = '';
	var hash = ( window.location.hash || '' ).replace( '#sysmda-panel-', '' );
	if ( hash && hasPanel( hash ) ) {
		initial = hash;
	} else {
		try {
			var saved = window.sessionStorage.getItem( 'sysmdaActiveTab' );
			if ( saved && hasPanel( saved ) ) {
				initial = saved;
			}
		} catch ( e ) {}
	}
	// Always activate something, even when it is the tab already marked active
	// server-side: this is what sets aria-selected and the roving tabindex.
	activate( initial || tabs[ 0 ].getAttribute( 'data-tab' ), false );
} )();

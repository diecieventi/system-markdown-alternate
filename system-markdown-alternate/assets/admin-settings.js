/**
 * System Markdown Alternate — tab della pagina impostazioni.
 *
 * Progressive enhancement: senza questo script tutti i pannelli restano
 * visibili (impilati) e la pagina è pienamente utilizzabile; i campi sono
 * sempre nel form, quindi il salvataggio non cambia. Vanilla JS, nessuna
 * dipendenza. Nasconde i pannelli inattivi solo dopo aver aggiunto la classe
 * `sma-js-tabs` (vedi CSS), così il no-JS non nasconde nulla.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.sma-settings-page' );
	if ( ! root ) {
		return;
	}

	var tabs = Array.prototype.slice.call( root.querySelectorAll( '.sma-tabs .nav-tab' ) );
	var panels = Array.prototype.slice.call( root.querySelectorAll( '.sma-tab-panel' ) );
	if ( tabs.length < 2 || ! panels.length ) {
		return;
	}

	root.classList.add( 'sma-js-tabs' );

	function hasPanel( id ) {
		return panels.some( function ( p ) {
			return p.getAttribute( 'data-tab' ) === id;
		} );
	}

	function activate( id, store ) {
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
		} );
		if ( store ) {
			try {
				window.sessionStorage.setItem( 'smaActiveTab', id );
			} catch ( e ) {}
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#sma-panel-' + id );
			}
		}
	}

	tabs.forEach( function ( t ) {
		t.setAttribute( 'role', 'tab' );
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activate( t.getAttribute( 'data-tab' ), true );
		} );
	} );

	// Stato iniziale: hash dell'URL > sessionStorage > primo tab.
	var initial = '';
	var hash = ( window.location.hash || '' ).replace( '#sma-panel-', '' );
	if ( hash && hasPanel( hash ) ) {
		initial = hash;
	} else {
		try {
			var saved = window.sessionStorage.getItem( 'smaActiveTab' );
			if ( saved && hasPanel( saved ) ) {
				initial = saved;
			}
		} catch ( e ) {}
	}
	if ( initial ) {
		activate( initial, false );
	}
} )();

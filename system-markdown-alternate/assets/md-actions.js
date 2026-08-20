/**
 * System Markdown Alternate — opt-in Markdown actions shortcode.
 *
 * Vanilla JavaScript, no build step and no dependencies. The menu is moved to
 * document.body after setup so transformed/overflowing theme containers cannot
 * clip it, then positioned against the split button: aligned to the group's
 * start edge and dropping below it, with flip and clamp fallbacks used only
 * when the viewport leaves no room for that placement.
 */
( function () {
	'use strict';

	var l10n = window.sysmdaMarkdownActionsL10n || {};
	var COPIED = l10n.copied || 'Copied!';
	var COPYING = l10n.copying || 'Copying…';
	var FAILED = l10n.failed || 'Copy failed';
	var FEEDBACK_MS = 2000;
	var VIEWPORT_GAP = 8;
	var MENU_GAP = 6;
	var MIN_MENU_HEIGHT = 48;

	function init() {
		var roots = document.querySelectorAll( '.sysmda-md-actions' );
		var index;

		for ( index = 0; index < roots.length; index++ ) {
			setup( roots[ index ] );
		}
	}

	function directionOf( element ) {
		var styles = window.getComputedStyle ? window.getComputedStyle( element ) : null;

		return styles && styles.direction ? styles.direction : 'ltr';
	}

	function canCopyMarkdown() {
		var clipboard = window.navigator && navigator.clipboard;
		var nativeCopy = clipboard && ( clipboard.write || clipboard.writeText );
		var legacyCopy = document.queryCommandSupported && document.queryCommandSupported( 'copy' );

		return !! ( window.fetch && window.Promise && ( nativeCopy || legacyCopy ) );
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			var copied = false;

			area.value = text;
			area.setAttribute( 'readonly', 'readonly' );
			area.style.position = 'fixed';
			area.style.top = '-9999px';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				copied = document.execCommand( 'copy' );
			} catch ( error ) {
				copied = false;
			}

			document.body.removeChild( area );

			if ( copied ) {
				resolve();
			} else {
				reject( new Error( 'Copy failed' ) );
			}
		} );
	}

	function fetchMarkdown( url ) {
		return fetch( url, {
			credentials: 'omit',
			headers: { Accept: 'text/markdown' }
		} ).then( function ( response ) {
			var type = response.headers.get( 'content-type' ) || '';

			if ( ! response.ok || -1 === type.toLowerCase().indexOf( 'text/markdown' ) ) {
				throw new Error( 'Invalid Markdown response' );
			}

			return response.text();
		} );
	}

	function copyMarkdown( url ) {
		var pending = fetchMarkdown( url );

		if ( window.isSecureContext && window.ClipboardItem && navigator.clipboard && navigator.clipboard.write ) {
			var blob = pending.then( function ( text ) {
				return new Blob( [ text ], { type: 'text/plain' } );
			} );

			return navigator.clipboard
				.write( [ new ClipboardItem( { 'text/plain': blob } ) ] )
				.catch( function () {
					return pending.then( copyText );
				} );
		}

		return pending.then( copyText );
	}

	function setup( root ) {
		if ( 'true' === root.getAttribute( 'data-sysmda-initialized' ) ) {
			return;
		}

		var copyButton = root.querySelector( '.sysmda-md-actions__copy' );
		var toggle = root.querySelector( '.sysmda-md-actions__toggle' );
		var menu = root.querySelector( '.sysmda-md-actions__menu' );
		var menuCopy = menu ? menu.querySelector( '[data-sysmda-action="copy"]' ) : null;
		var status = root.querySelector( '.sysmda-md-actions__status' );
		var url = root.getAttribute( 'data-sysmda-md-url' );
		var copyActions = [ copyButton, menuCopy ].filter( Boolean );
		var group = root.querySelector( '.sysmda-md-actions__group' ) || toggle;
		var copyable = canCopyMarkdown();
		var busy = false;
		var scheduled = false;

		if ( ! toggle || ! menu || ! url ) {
			return;
		}

		root.setAttribute( 'data-sysmda-initialized', 'true' );
		root.classList.add( 'sysmda-md-actions--js' );

		if ( copyable ) {
			copyActions.forEach( function ( action ) {
				action.removeAttribute( 'hidden' );
				action.setAttribute( 'data-sysmda-label', labelFor( action ) );
			} );
		} else {
			root.classList.add( 'sysmda-md-actions--no-copy' );
		}

		toggle.removeAttribute( 'hidden' );
		menu.hidden = true;
		document.body.appendChild( menu );
		root.removeAttribute( 'hidden' );

		function labelFor( action ) {
			var label = action.querySelector( '.sysmda-md-actions__label' );
			return label ? label.textContent : '';
		}

		function setLabel( action, text ) {
			var label = action.querySelector( '.sysmda-md-actions__label' );

			if ( label ) {
				label.textContent = text;
			}
		}

		function announce( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		function isOpen() {
			return 'true' === toggle.getAttribute( 'aria-expanded' );
		}

		/**
		 * Does a menu of this width fit the viewport when its left edge is at x?
		 */
		function fitsAt( x, width ) {
			return x >= VIEWPORT_GAP && x + width <= window.innerWidth - VIEWPORT_GAP;
		}

		/**
		 * Place the menu under the split button.
		 *
		 * The menu is anchored to the whole group, not to the caret: its start
		 * edge lines up with the group's start edge, so it drops straight down
		 * under the button instead of hanging off to one side. The end-aligned
		 * placement is a fallback for a group sitting too close to the viewport
		 * edge, and the clamp below it is the last resort for a viewport too
		 * narrow for either.
		 */
		function positionMenu() {
			if ( ! isOpen() ) {
				return;
			}

			// Reset what a previous pass constrained, or the menu is measured
			// against its own clamped size instead of its natural one.
			menu.style.maxHeight = '';

			var anchor = group.getBoundingClientRect();
			var box = menu.getBoundingClientRect();
			// Read per pass rather than cached: a theme may set the direction
			// after this script has run, and the two placements are mirrored.
			var rtl = 'rtl' === directionOf( root );
			var start = rtl ? anchor.right - box.width : anchor.left;
			var end = rtl ? anchor.left : anchor.right - box.width;
			var horizontal = 'start';
			var left = start;

			if ( ! fitsAt( start, box.width ) && fitsAt( end, box.width ) ) {
				left = end;
				horizontal = 'end';
			}

			left = Math.min(
				Math.max( VIEWPORT_GAP, left ),
				Math.max( VIEWPORT_GAP, window.innerWidth - box.width - VIEWPORT_GAP )
			);

			var below = window.innerHeight - anchor.bottom - MENU_GAP - VIEWPORT_GAP;
			var above = anchor.top - MENU_GAP - VIEWPORT_GAP;
			var vertical = 'bottom';
			var room = below;

			if ( box.height > below && above > below ) {
				vertical = 'top';
				room = above;
			}

			// With room on neither side, cap the height and let the menu scroll
			// rather than let it grow across the button it belongs to. The floor
			// is the one case where covering the button is the lesser evil: below
			// it the menu would be too short to operate at all.
			room = Math.max( room, MIN_MENU_HEIGHT );

			if ( box.height > room ) {
				menu.style.maxHeight = Math.round( room ) + 'px';
				box = menu.getBoundingClientRect();
			}

			var top = 'top' === vertical ? anchor.top - MENU_GAP - box.height : anchor.bottom + MENU_GAP;

			top = Math.min(
				Math.max( VIEWPORT_GAP, top ),
				Math.max( VIEWPORT_GAP, window.innerHeight - box.height - VIEWPORT_GAP )
			);

			menu.style.left = Math.round( left ) + 'px';
			menu.style.top = Math.round( top ) + 'px';
			menu.setAttribute( 'data-sysmda-placement', vertical + '-' + horizontal );
		}

		function schedulePosition() {
			if ( scheduled || ! isOpen() ) {
				return;
			}

			scheduled = true;
			window.requestAnimationFrame( function () {
				scheduled = false;
				positionMenu();
			} );
		}

		function open() {
			if ( isOpen() ) {
				return;
			}

			menu.style.visibility = 'hidden';
			menu.removeAttribute( 'hidden' );
			root.classList.add( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
			positionMenu();
			menu.style.visibility = '';
		}

		function close( refocus ) {
			if ( ! isOpen() ) {
				return;
			}

			menu.setAttribute( 'hidden', 'hidden' );
			root.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );

			if ( refocus ) {
				toggle.focus();
			}
		}

		function items() {
			return Array.prototype.slice.call(
				menu.querySelectorAll( '.sysmda-md-actions__item:not([hidden])' )
			);
		}

		function focusItem( index ) {
			var all = items();

			if ( ! all.length ) {
				return;
			}

			all[ ( index + all.length ) % all.length ].focus();
		}

		function restoreLabel( action ) {
			setLabel( action, action.getAttribute( 'data-sysmda-label' ) || 'Copy as Markdown' );
		}

		function feedback( action, message ) {
			setLabel( action, message );
			announce( message );

			if ( action.sysmdaTimer ) {
				window.clearTimeout( action.sysmdaTimer );
			}

			action.sysmdaTimer = window.setTimeout( function () {
				restoreLabel( action );
			}, FEEDBACK_MS );
		}

		function runCopy( action ) {
			if ( busy || ! copyable ) {
				return;
			}

			busy = true;
			copyActions.forEach( function ( item ) {
				item.disabled = true;
			} );
			setLabel( action, COPYING );
			announce( COPYING );

			copyMarkdown( url ).then(
				function () {
					feedback( action, COPIED );
				},
				function () {
					feedback( action, FAILED );
				}
			).then( function () {
				busy = false;
				copyActions.forEach( function ( item ) {
					item.disabled = false;
				} );
			} );
		}

		if ( copyButton ) {
			copyButton.addEventListener( 'click', function () {
				runCopy( copyButton );
			} );
		}

		toggle.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( isOpen() ) {
				close( false );
			} else {
				open();
			}
		} );

		toggle.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key || 'Down' === event.key ) {
				event.preventDefault();
				open();
				focusItem( 0 );
			}
		} );

		menu.addEventListener( 'click', function ( event ) {
			var copy = event.target.closest ? event.target.closest( '[data-sysmda-action="copy"]' ) : null;
			var item = event.target.closest ? event.target.closest( '.sysmda-md-actions__item' ) : null;

			if ( copy ) {
				event.preventDefault();
				runCopy( copy );
			} else if ( item ) {
				close( false );
			}
		} );

		menu.addEventListener( 'keydown', function ( event ) {
			var all = items();
			var index = all.indexOf( document.activeElement );

			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				event.preventDefault();
				close( true );
			} else if ( 'ArrowDown' === event.key || 'Down' === event.key ) {
				event.preventDefault();
				focusItem( index + 1 );
			} else if ( 'ArrowUp' === event.key || 'Up' === event.key ) {
				event.preventDefault();
				focusItem( index - 1 );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				focusItem( 0 );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				focusItem( all.length - 1 );
			}
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				event.preventDefault();
				close( true );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! root.contains( event.target ) && ! menu.contains( event.target ) ) {
				close( false );
			}
		} );

		document.addEventListener( 'focusin', function ( event ) {
			if ( isOpen() && ! root.contains( event.target ) && ! menu.contains( event.target ) ) {
				close( false );
			}
		} );

		window.addEventListener( 'resize', schedulePosition );
		document.addEventListener( 'scroll', schedulePosition, true );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

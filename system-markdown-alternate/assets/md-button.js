/**
 * System Markdown Alternate — front-end Markdown button.
 *
 * Dependency-free vanilla JS (no jQuery, no build step), matching the style of
 * assets/admin-settings.js.
 *
 * Progressive enhancement: the markup ships with the toggle and the clipboard
 * buttons `hidden`, and the menu therefore renders as a plain list of the two
 * entries that work without scripting. This script unhides those controls and
 * marks the root with `sysmda-md-button--js`, which is the only thing that turns
 * the list into a dropdown. A control is unhidden only once the API it needs has
 * been found, so a browser without a clipboard never shows a dead "Copy" button.
 */
( function () {
	'use strict';

	var l10n = window.sysmdaMdButtonL10n || {};
	var COPIED = l10n.copied || 'Copied!';
	var FAILED = l10n.failed || 'Copy failed';
	var COPYING = l10n.copying || 'Copying…';
	var FEEDBACK_MS = 2000;

	/**
	 * Runs setup once the markup exists.
	 *
	 * The script is enqueued in the footer, so the DOM is already there; the
	 * readyState check only matters when an optimizer hoists it into <head>,
	 * which several caching plugins do.
	 */
	function init() {
		var roots = document.querySelectorAll( '.sysmda-md-button' );
		var index;

		for ( index = 0; index < roots.length; index++ ) {
			setup( roots[ index ] );
		}
	}

	/**
	 * Whether the browser offers any way at all to write to the clipboard.
	 */
	function canCopy() {
		return !! ( window.navigator && navigator.clipboard && navigator.clipboard.writeText ) ||
			!! ( document.queryCommandSupported && document.queryCommandSupported( 'copy' ) );
	}

	/**
	 * Whether the .md body can be fetched (needed by "Copy Markdown content").
	 */
	function canFetch() {
		return !! ( window.fetch && window.Promise );
	}

	/**
	 * Copies a string, falling back to a throwaway textarea.
	 *
	 * navigator.clipboard is undefined outside a secure context, which is exactly
	 * the plain-HTTP site that still needs this to work.
	 *
	 * @return {Promise}
	 */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );

			area.value = text;
			area.setAttribute( 'readonly', 'readonly' );
			area.style.position = 'fixed';
			area.style.top = '-9999px';
			area.style.opacity = '0';

			document.body.appendChild( area );
			area.select();

			var ok = false;

			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}

			document.body.removeChild( area );

			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'execCommand failed' ) );
			}
		} );
	}

	/**
	 * Fetches the Markdown body of a URL.
	 *
	 * @return {Promise<string>}
	 */
	function fetchMarkdown( url ) {
		// credentials: 'omit' — the .md never varies by visitor (protected content
		// has no Markdown representation and the body comes from cleaned blocks,
		// so no personalisation filter runs). Dropping the cookies lets a page
		// cache or CDN answer instead of booting WordPress for a logged-in reader.
		return fetch( url, { credentials: 'omit' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			return response.text();
		} );
	}

	/**
	 * Copies the Markdown document itself.
	 *
	 * Safari refuses a clipboard write that happens after an await, which is what
	 * fetching first forces on us. It does accept a ClipboardItem whose value is a
	 * Promise, so that path is tried first; Chrome and Firefox are happy with it
	 * too, and anything that rejects falls back to fetch-then-write.
	 *
	 * @return {Promise}
	 */
	function copyMarkdown( url ) {
		var pending = fetchMarkdown( url );

		if ( window.ClipboardItem && navigator.clipboard && navigator.clipboard.write ) {
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

	/**
	 * Sets up one button.
	 */
	function setup( root ) {
		var toggle = root.querySelector( '.sysmda-md-button__toggle' );
		var menu = root.querySelector( '.sysmda-md-button__menu' );
		var status = root.querySelector( '.sysmda-md-button__status' );
		var url = root.getAttribute( 'data-sysmda-md-url' );

		if ( ! toggle || ! menu || ! url ) {
			return;
		}

		var copyable = canCopy();
		var actions = root.querySelectorAll( '[data-sysmda-action]' );
		var i;

		// Unhide only what this browser can actually carry out.
		for ( i = 0; i < actions.length; i++ ) {
			var action = actions[ i ].getAttribute( 'data-sysmda-action' );
			var usable = copyable && ( 'copy-content' !== action || canFetch() );

			if ( usable ) {
				// Captured now, while it is still the real label: the click
				// handler swaps in "Copying…" and "Copied!", so reading it
				// later would record one of those as the original.
				actions[ i ].setAttribute( 'data-sysmda-label', actions[ i ].textContent );
				actions[ i ].removeAttribute( 'hidden' );
			}
		}

		// Every remaining entry hidden means an empty menu: leave the markup alone.
		if ( ! menu.querySelector( '.sysmda-md-button__item:not([hidden])' ) ) {
			return;
		}

		root.className += ' sysmda-md-button--js';
		toggle.removeAttribute( 'hidden' );

		function announce( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		function isOpen() {
			return 'true' === toggle.getAttribute( 'aria-expanded' );
		}

		function open() {
			// Flip the menu when the button sits too close to the right edge.
			var box = root.getBoundingClientRect();
			var width = menu.offsetWidth || 224;

			root.classList.toggle( 'is-flipped', box.left + width > window.innerWidth );
			root.classList.add( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
		}

		function close( refocus ) {
			root.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );

			if ( refocus ) {
				toggle.focus();
			}
		}

		function items() {
			return Array.prototype.slice.call(
				menu.querySelectorAll( '.sysmda-md-button__item:not([hidden])' )
			);
		}

		function focusItem( index ) {
			var all = items();

			if ( ! all.length ) {
				return;
			}

			var target = ( index + all.length ) % all.length;

			all[ target ].focus();
		}

		/**
		 * Restores an entry's label after the "Copied!" confirmation.
		 */
		function feedback( item, message ) {
			item.textContent = message;
			announce( message );

			if ( item.sysmdaTimer ) {
				window.clearTimeout( item.sysmdaTimer );
			}

			item.sysmdaTimer = window.setTimeout( function () {
				item.textContent = item.getAttribute( 'data-sysmda-label' ) || item.textContent;
			}, FEEDBACK_MS );
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
			if ( 'ArrowDown' !== event.key && 'Down' !== event.key ) {
				return;
			}

			event.preventDefault();
			open();
			focusItem( 0 );
		} );

		menu.addEventListener( 'click', function ( event ) {
			var item = event.target.closest ? event.target.closest( '[data-sysmda-action]' ) : null;

			if ( ! item ) {
				// A plain link (view/download): let the browser handle it.
				if ( event.target.closest && event.target.closest( '.sysmda-md-button__item' ) ) {
					close( false );
				}

				return;
			}

			event.preventDefault();

			var fetches = 'copy-content' === item.getAttribute( 'data-sysmda-action' );

			if ( fetches ) {
				// This one goes over the network, so it is the only action with a
				// delay worth reporting.
				if ( item.sysmdaTimer ) {
					window.clearTimeout( item.sysmdaTimer );
				}

				item.textContent = COPYING;
			}

			var done = fetches ? copyMarkdown( url ) : copyText( url );

			done.then(
				function () {
					feedback( item, COPIED );
				},
				function () {
					feedback( item, FAILED );
				}
			);
		} );

		menu.addEventListener( 'keydown', function ( event ) {
			var all = items();
			var index = all.indexOf( document.activeElement );

			if ( 'ArrowDown' === event.key || 'Down' === event.key ) {
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
				if ( isOpen() ) {
					event.preventDefault();
					close( true );
				}
			}
		} );

		// Closing on an outside click, and on focus moving away (keyboard/AT).
		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! root.contains( event.target ) ) {
				close( false );
			}
		} );

		document.addEventListener( 'focusin', function ( event ) {
			if ( isOpen() && ! root.contains( event.target ) ) {
				close( false );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

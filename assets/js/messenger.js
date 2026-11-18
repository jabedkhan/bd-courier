/*
 * BD Courier: Messenger links open Messenger, not Facebook.
 * Phones keep m.me (it opens the Messenger app with the message typed in).
 * Computers go to messenger.com; it cannot pre-fill text, so the message is
 * copied and a short hint says to paste it.
 */
( function () {
	'use strict';

	var computer = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
	if ( ! computer ) {
		return;
	}

	function hint( text ) {
		var tip = document.createElement( 'span' );
		tip.className = 'bdc-copied-tip';
		tip.setAttribute( 'role', 'status' );
		tip.textContent = text;
		document.body.appendChild( tip );
		window.setTimeout( function () {
			tip.classList.add( 'is-gone' );
		}, 2600 );
		window.setTimeout( function () {
			tip.remove();
		}, 3000 );
	}

	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).then( function () {
				return true;
			}, function () {
				return false;
			} );
		}
		return Promise.resolve( false );
	}

	Array.prototype.forEach.call( document.querySelectorAll( 'a[data-bdc-desktop]' ), function ( link ) {
		link.setAttribute( 'href', link.getAttribute( 'data-bdc-desktop' ) );
		link.addEventListener( 'click', function () {
			var text = link.getAttribute( 'data-bdc-text' ) || '';
			if ( text ) {
				copy( text ).then( function ( ok ) {
					if ( ok ) {
						hint( document.documentElement.lang && 0 === document.documentElement.lang.indexOf( 'bn' ) ? 'মেসেজ কপি হয়েছে — Messenger-এ পেস্ট করুন' : 'Message copied: paste it in Messenger' );
					}
				} );
			}
		} );
	} );
}() );

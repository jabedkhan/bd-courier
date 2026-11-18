/*
 * BD Courier: Messenger links open Messenger, not Facebook.
 *
 * - Computers: messenger.com (it cannot pre-fill text, so the message is
 *   copied and a short hint says to paste it).
 * - Phones in a normal browser: m.me, which opens the Messenger app.
 * - Phones inside the Facebook / Instagram app: their built-in browser keeps
 *   m.me inside Facebook, so the link asks the phone for the Messenger app
 *   itself (Android: an app intent; iPhone: the Messenger app link, which
 *   needs the Page ID from settings).
 */
( function () {
	'use strict';

	var ua       = navigator.userAgent || '';
	var computer = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
	var inApp    = /FBAN|FBAV|FB_IAB|FBIOS|FB4A|Instagram|Messenger/i.test( ua );
	var android  = /Android/i.test( ua );
	var ios      = /iPhone|iPad|iPod/i.test( ua ) || ( /Macintosh/.test( ua ) && navigator.maxTouchPoints > 1 );

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
		if ( text && navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).then( function () {
				return true;
			}, function () {
				return false;
			} );
		}
		return Promise.resolve( false );
	}

	function bangla() {
		return 0 === ( document.documentElement.lang || '' ).indexOf( 'bn' );
	}

	/** The address that opens the Messenger app from inside Facebook's browser, or ''. */
	function appLink( link ) {
		var page = link.getAttribute( 'data-bdc-page' ) || '';
		var id   = link.getAttribute( 'data-bdc-page-id' ) || '';
		var web  = link.getAttribute( 'href' );

		if ( android && page ) {
			// Hand the m.me link to the Messenger app; without the app, the browser opens m.me.
			var rest = web.replace( /^https:\/\//, '' );
			return 'intent://' + rest + '#Intent;scheme=https;package=com.facebook.orca;S.browser_fallback_url=' + encodeURIComponent( web ) + ';end';
		}
		if ( ios && id ) {
			return 'fb-messenger://user-thread/' + id;
		}
		return '';
	}

	Array.prototype.forEach.call( document.querySelectorAll( 'a[data-bdc-desktop]' ), function ( link ) {
		var text = link.getAttribute( 'data-bdc-text' ) || '';

		if ( computer ) {
			link.setAttribute( 'href', link.getAttribute( 'data-bdc-desktop' ) );
			link.addEventListener( 'click', function () {
				copy( text ).then( function ( ok ) {
					if ( ok ) {
						hint( bangla() ? 'মেসেজ কপি হয়েছে — Messenger-এ পেস্ট করুন' : 'Message copied: paste it in Messenger' );
					}
				} );
			} );
			return;
		}

		if ( ! inApp ) {
			return; // m.me already opens the Messenger app from a normal phone browser.
		}

		var target = appLink( link );
		if ( ! target ) {
			return;
		}

		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var web = link.getAttribute( 'href' );
			if ( ios && text ) {
				// The app link cannot carry the message: copy it so it can be pasted.
				copy( text );
			}
			window.location.href = target;
			if ( ios ) {
				// Messenger not installed: fall back to m.me after a moment.
				window.setTimeout( function () {
					if ( ! document.hidden ) {
						window.location.href = web;
					}
				}, 1500 );
			}
		} );
	} );
}() );

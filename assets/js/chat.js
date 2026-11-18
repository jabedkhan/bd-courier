/* BD Courier chat button: open and close the Messenger / WhatsApp choices. */
( function () {
	'use strict';

	var chat = document.querySelector( '[data-bdc-chat]' );
	if ( ! chat ) {
		return;
	}

	var toggle = chat.querySelector( '.bdc-chat__toggle' );
	var links  = chat.querySelectorAll( '.bdc-chat__link' );

	function setOpen( open ) {
		chat.classList.toggle( 'is-open', open );
		chat.classList.add( 'was-opened' );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		Array.prototype.forEach.call( links, function ( link ) {
			link.tabIndex = open ? 0 : -1;
		} );
	}

	setOpen( false );
	chat.classList.remove( 'was-opened' );

	toggle.addEventListener( 'click', function () {
		setOpen( ! chat.classList.contains( 'is-open' ) );
	} );

	// Close when tapping elsewhere or pressing Escape.
	document.addEventListener( 'click', function ( event ) {
		if ( chat.classList.contains( 'is-open' ) && ! chat.contains( event.target ) ) {
			setOpen( false );
		}
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && chat.classList.contains( 'is-open' ) ) {
			setOpen( false );
			toggle.focus();
		}
	} );
}() );

/* BD Courier simple checkout: the area cards choose WooCommerce's delivery option. */
( function ( $ ) {
	'use strict';

	function pick( area ) {
		var $method = $( 'input[name^="shipping_method"][value="bdc_area:' + area + '"]' );
		if ( $method.length && ! $method.is( ':checked' ) ) {
			$method.prop( 'checked', true ).trigger( 'change' );
		}
	}

	$( document.body ).on( 'change', 'input[name="bdc_area"]', function () {
		pick( this.value );
	} );

	// After WooCommerce redraws the order summary, keep it in step with the chosen card.
	$( document.body ).on( 'updated_checkout', function () {
		var area = $( 'input[name="bdc_area"]:checked' ).val();
		if ( area ) {
			pick( area );
		}
	} );

	// Phones: the delivery area follows the address, so order notes move below it.
	// Desktop: notes stay in the details box under the address.
	var phone = window.matchMedia( '(max-width: 768px)' );

	function placeNotes() {
		var $notes = $( '#order_comments_field' );
		var $areas = $( '.bdc-areas' );
		if ( ! $notes.length || ! $areas.length ) {
			return;
		}
		if ( phone.matches ) {
			if ( ! $notes.prev().is( '.bdc-areas' ) ) {
				$areas.after( $notes );
			}
		} else if ( ! $notes.parent().is( '.woocommerce-billing-fields__field-wrapper' ) ) {
			$( '.woocommerce-billing-fields__field-wrapper' ).append( $notes );
		}
	}

	$( placeNotes );
	if ( phone.addEventListener ) {
		phone.addEventListener( 'change', placeNotes );
	} else if ( phone.addListener ) {
		phone.addListener( placeNotes );
	}
}( jQuery ) );

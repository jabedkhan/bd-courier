/* BD Courier – order screens and settings. */
( function ( $ ) {
	'use strict';

	var cfg = window.bdcAdmin || {};
	var text = cfg.text || {};
	var $modal = null;
	var lastFocus = null;

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: 'bdc_' + action, nonce: cfg.nonce }, data ) );
	}

	function errorOf( xhr ) {
		var json = xhr && xhr.responseJSON;
		return ( json && json.data ) || { message: text.genericError };
	}

	/* ---- Toast ---- */

	function toast( message, isError ) {
		$( '.bdc-toast' ).remove();
		var $toast = $( '<div class="bdc-toast" role="status"></div>' ).text( message );
		if ( isError ) {
			$toast.addClass( 'bdc-toast--error' );
		}
		$( 'body' ).append( $toast );
		setTimeout( function () {
			$toast.fadeOut( 300, function () {
				$toast.remove();
			} );
		}, isError ? 7000 : 4000 );
	}

	/* ---- Updating an order row ---- */

	function updateOrder( orderId, data ) {
		var $row = $( 'tr.bdc-shipment[data-order="' + orderId + '"]' );

		// Shipments page: redraw the whole row.
		if ( $row.length ) {
			return post( 'shipment_row', { order_id: orderId } ).done( function ( response ) {
				$row.replaceWith( response.data.row );
			} );
		}

		if ( data.cell ) {
			$( '.bdc-cell[data-order="' + orderId + '"]' ).replaceWith( data.cell );
		}
		if ( data.status ) {
			$( '#order-' + orderId + ', #post-' + orderId ).find( 'td.column-order_status' ).html( data.status );
		}
	}

	/* ---- Popup ---- */

	function openModal() {
		closeModal();
		lastFocus = document.activeElement;
		$modal = $(
			'<div class="bdc-modal" role="dialog" aria-modal="true" aria-labelledby="bdc-modal-title">' +
				'<div class="bdc-modal__dialog"><div class="bdc-modal__loading"></div></div>' +
			'</div>'
		);
		$modal.find( '.bdc-modal__loading' ).text( text.loading );
		$( 'body' ).append( $modal );
	}

	function closeModal() {
		if ( $modal ) {
			$modal.remove();
			$modal = null;
			if ( lastFocus ) {
				lastFocus.focus();
			}
		}
	}

	$( document ).on( 'click', '.bdc-send, .bdc-edit', function ( event ) {
		event.preventDefault();
		event.stopPropagation();

		var $button = $( this );
		openModal();

		post( 'form', {
			order_id: $button.data( 'order' ),
			courier: $button.data( 'courier' ),
			mode: $button.hasClass( 'bdc-edit' ) ? 'edit' : 'send'
		} )
			.done( function ( response ) {
				if ( ! $modal ) {
					return;
				}
				$modal.find( '.bdc-modal__dialog' ).html( response.data.html );
				$modal.find( 'input, select, textarea' ).first().trigger( 'focus' );
			} )
			.fail( function ( xhr ) {
				closeModal();
				toast( errorOf( xhr ).message, true );
			} );
	} );

	$( document ).on( 'click', '.bdc-modal__close, .bdc-modal__cancel', closeModal );

	$( document ).on( 'mousedown', '.bdc-modal', function ( event ) {
		if ( event.target === this && ! $( this ).find( '.bdc-form, .bdc-bulk' ).hasClass( 'is-sending' ) ) {
			closeModal();
		}
	} );

	$( document ).on( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && $modal && ! $modal.find( '.bdc-form, .bdc-bulk' ).hasClass( 'is-sending' ) ) {
			closeModal();
		}
	} );

	/* ---- Pathao city → zone → area ---- */

	function fillSelect( $select, options, placeholder ) {
		$select.empty().append( $( '<option value=""></option>' ).text( placeholder ) );
		$.each( options, function ( index, option ) {
			$select.append( $( '<option></option>' ).val( option.id ).text( option.name ) );
		} );
	}

	function loadLocations( $select, type, parent, placeholder ) {
		fillSelect( $select, [], text.loading );
		$select.prop( 'disabled', true );

		post( 'locations', { type: type, parent: parent } )
			.done( function ( response ) {
				fillSelect( $select, response.data.options, placeholder );
			} )
			.fail( function ( xhr ) {
				fillSelect( $select, [], placeholder );
				toast( errorOf( xhr ).message, true );
			} )
			.always( function () {
				$select.prop( 'disabled', false );
			} );
	}

	$( document ).on( 'change', '.bdc-form [name="recipient_city"]', function () {
		var $form = $( this ).closest( '.bdc-form' );
		var $zone = $form.find( '[name="recipient_zone"]' );
		var $area = $form.find( '[name="recipient_area"]' );

		fillSelect( $area, [], text.chooseZone );
		if ( this.value ) {
			loadLocations( $zone, 'zones', this.value, text.selectZone );
		} else {
			fillSelect( $zone, [], text.chooseCity );
		}
	} );

	$( document ).on( 'change', '.bdc-form [name="recipient_zone"]', function () {
		var $area = $( this ).closest( '.bdc-form' ).find( '[name="recipient_area"]' );

		if ( this.value ) {
			loadLocations( $area, 'areas', this.value, text.selectArea );
		} else {
			fillSelect( $area, [], text.chooseZone );
		}
	} );

	/* ---- Sending ---- */

	function clearErrors( $form ) {
		$form.find( '.bdc-field' ).removeClass( 'has-error' );
		$form.find( '.bdc-field__error' ).prop( 'hidden', true ).text( '' );
		$form.find( '.bdc-form__error' ).prop( 'hidden', true ).text( '' );
	}

	function showErrors( $form, error ) {
		var shown = 0;

		$.each( error.fields || {}, function ( key, message ) {
			var $field = $form.find( '.bdc-field[data-field="' + key + '"]' );
			if ( $field.length ) {
				$field.addClass( 'has-error' ).find( '.bdc-field__error' ).prop( 'hidden', false ).text( message );
				shown++;
			}
		} );

		var summary = error.message || text.genericError;
		// A courier may complain about a field the form does not show.
		$.each( error.fields || {}, function ( key, message ) {
			if ( ! $form.find( '.bdc-field[data-field="' + key + '"]' ).length ) {
				summary += ' ' + message;
			}
		} );

		$form.find( '.bdc-form__error' ).prop( 'hidden', false ).text( summary );

		var $first = $form.find( '.bdc-field.has-error' ).first().find( 'input, select, textarea' );
		if ( shown && $first.length ) {
			$first.trigger( 'focus' );
		}
	}

	$( document ).on( 'input change', '.bdc-field.has-error input, .bdc-field.has-error select, .bdc-field.has-error textarea', function () {
		$( this ).closest( '.bdc-field' ).removeClass( 'has-error' ).find( '.bdc-field__error' ).prop( 'hidden', true );
	} );

	$( document ).on( 'submit', '.bdc-form', function ( event ) {
		event.preventDefault();

		var $form = $( this );
		if ( $form.hasClass( 'is-sending' ) ) {
			return;
		}

		var $submit = $form.find( '.bdc-form__submit' );
		var label = $submit.text();
		var fields = {};

		$.each( $form.serializeArray(), function ( index, item ) {
			fields[ item.name ] = item.value;
		} );

		clearErrors( $form );
		$form.addClass( 'is-sending' );
		$submit.prop( 'disabled', true ).text( 'edit' === $form.data( 'mode' ) ? text.saving : text.sending );

		post( 'edit' === $form.data( 'mode' ) ? 'edit' : 'send', { order_id: $form.data( 'order' ), courier: $form.data( 'courier' ), fields: fields } )
			.done( function ( response ) {
				updateOrder( $form.data( 'order' ), response.data );
				closeModal();
				toast( response.data.message );
			} )
			.fail( function ( xhr ) {
				$form.removeClass( 'is-sending' );
				$submit.prop( 'disabled', false ).text( label );
				showErrors( $form, errorOf( xhr ) );
			} );
	} );

	/* ---- Refresh status ---- */

	$( document ).on( 'click', '.bdc-refresh', function ( event ) {
		event.preventDefault();
		event.stopPropagation();

		var orderId = $( this ).data( 'order' );
		var $cell = $( this ).closest( '.bdc-cell, tr.bdc-shipment' ).addClass( 'is-busy' );

		post( 'refresh', { order_id: orderId } )
			.done( function ( response ) {
				updateOrder( orderId, response.data );
			} )
			.fail( function ( xhr ) {
				$cell.removeClass( 'is-busy' );
				toast( errorOf( xhr ).message, true );
			} );
	} );

	/* ---- Quick view: status buttons and private notes ---- */

	function quickDone( orderId, data ) {
		$( '.bdc-quick[data-order="' + orderId + '"]' ).replaceWith( data.panel );
		$( '.wc-backbone-modal-header mark.order-status' ).replaceWith( data.status );
		// WooCommerce remembers the quick view per row; forget it so it reopens fresh.
		$( '.order-preview[data-order-id="' + orderId + '"]' ).removeData( 'order-data' ).removeData( 'orderData' );
		updateOrder( orderId, data );
		toast( data.message );
	}

	$( document ).on( 'click', '.bdc-status-button', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var orderId = $button.data( 'order' );
		var $panel = $button.closest( '.bdc-quick' );

		if ( $button.hasClass( 'is-current' ) || $panel.hasClass( 'is-busy' ) ) {
			return;
		}

		$panel.addClass( 'is-busy' );

		post( 'set_status', { order_id: orderId, status: $button.data( 'status' ) } )
			.done( function ( response ) {
				quickDone( orderId, response.data );
			} )
			.fail( function ( xhr ) {
				$panel.removeClass( 'is-busy' );
				toast( errorOf( xhr ).message, true );
			} );
	} );

	$( document ).on( 'click', '.bdc-note-add', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var orderId = $button.data( 'order' );
		var $panel = $button.closest( '.bdc-quick' );
		var $input = $panel.find( '.bdc-quick__add textarea' );

		if ( $panel.hasClass( 'is-busy' ) ) {
			return;
		}
		if ( ! $.trim( $input.val() ) ) {
			$input.trigger( 'focus' );
			return;
		}

		$panel.addClass( 'is-busy' );

		post( 'add_note', { order_id: orderId, note: $input.val() } )
			.done( function ( response ) {
				quickDone( orderId, response.data );
			} )
			.fail( function ( xhr ) {
				$panel.removeClass( 'is-busy' );
				toast( errorOf( xhr ).message, true );
			} );
	} );

	// After sending, refreshing or editing from inside the quick view, forget WooCommerce's saved copy too.
	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		var match = settings && 'string' === typeof settings.data && /action=bdc_(send|refresh|edit|synced)\b/.test( settings.data ) && settings.data.match( /order_id=(\d+)/ );
		if ( match ) {
			$( '.order-preview[data-order-id="' + match[ 1 ] + '"]' ).removeData( 'order-data' ).removeData( 'orderData' );
		}
	} );

	/* ---- Customer record ---- */

	function loadScore( orderId, force, phone ) {
		return post( 'score', { order_id: orderId, force: force ? 1 : 0, phone: phone || '' } ).done( function ( response ) {
			$( '.bdc-score[data-order="' + orderId + '"]' ).replaceWith( response.data.panel );
			$( '.bdc-score-chip[data-order="' + orderId + '"]' ).replaceWith( response.data.chip );
		} );
	}

	// "Check customer record" / "Check again" inside a popup.
	$( document ).on( 'click', '.bdc-score-check', function ( event ) {
		event.preventDefault();

		var $button = $( this ).prop( 'disabled', true ).text( text.checking );
		var $panel = $button.closest( '.bdc-score' );
		// In the send form, check the number as it is typed there.
		var phone = $panel.closest( '.bdc-form' ).find( '[name="recipient_phone"]' ).val();

		loadScore( $button.data( 'order' ), $button.data( 'force' ), phone ).fail( function ( xhr ) {
			$panel.find( '.bdc-score__problem' ).remove();
			$panel.append( $( '<span class="bdc-score__problem bdc-score__error"></span>' ).text( errorOf( xhr ).message ) );
			$button.prop( 'disabled', false ).text( $button.data( 'force' ) ? text.checkAgain : text.checkRecord );
		} );
	} );

	// The line under the send buttons opens the record on its own.
	$( document ).on( 'click', '.bdc-score-open', function ( event ) {
		event.preventDefault();
		event.stopPropagation();

		var orderId = $( this ).data( 'order' );
		openModal();

		post( 'score', { order_id: orderId } )
			.done( function ( response ) {
				if ( ! $modal ) {
					return;
				}
				var $box = $( '<div class="bdc-record"></div>' );
				$box.append(
					$( '<header class="bdc-form__header"></header>' ).append(
						$( '<h2 id="bdc-modal-title"></h2>' ).text( response.data.title ),
						$( '<button type="button" class="bdc-modal__close">&times;</button>' ).attr( 'aria-label', text.close )
					),
					$( '<div class="bdc-form__body"></div>' ).html( response.data.panel ),
					$( '<footer class="bdc-form__footer"><span class="bdc-form__hint"></span></footer>' ).append(
						$( '<button type="button" class="button bdc-modal__cancel"></button>' ).text( text.close )
					)
				);
				$modal.find( '.bdc-modal__dialog' ).empty().append( $box );
				$( '.bdc-score-chip[data-order="' + orderId + '"]' ).replaceWith( response.data.chip );
			} )
			.fail( function ( xhr ) {
				closeModal();
				toast( errorOf( xhr ).message, true );
			} );
	} );

	/* ---- Printing ---- */

	function openPrint( ids ) {
		window.open( cfg.printUrl + '&ids=' + ids.join( ',' ), '_blank', 'noopener' );
	}

	$( document ).on( 'click', '.bdc-print-page', function () {
		openPrint( $( 'tr.bdc-shipment' ).map( function () {
			return $( this ).data( 'order' );
		} ).get() );
	} );

	/* ---- Settings: logo for printouts ---- */

	$( document ).on( 'click', '.bdc-logo__choose', function ( event ) {
		event.preventDefault();

		var $box = $( this ).closest( '.bdc-logo' );
		var frame = wp.media( {
			title: text.chooseLogo,
			button: { text: text.useLogo },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var image = frame.state().get( 'selection' ).first().toJSON();
			var url = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;

			$box.find( 'input' ).val( image.id );
			$box.find( '.bdc-logo__preview' ).empty().append( $( '<img alt="">' ).attr( 'src', url ) );
			$box.find( '.bdc-logo__remove' ).prop( 'hidden', false );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.bdc-logo__remove', function ( event ) {
		event.preventDefault();

		var $box = $( this ).closest( '.bdc-logo' );
		$box.find( 'input' ).val( '' );
		$box.find( '.bdc-logo__preview' ).empty();
		$( this ).prop( 'hidden', true );
	} );

	/* ---- Bulk send ---- */

	var bulkActions = { bdc_send_pathao: 'pathao', bdc_send_steadfast: 'steadfast' };

	function fill( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b ).replace( '%s', a );
	}

	$( document ).on( 'submit', '#posts-filter, #wc-orders-filter', function ( event ) {
		var submitter = event.originalEvent && event.originalEvent.submitter;
		var which = submitter && 'doaction2' === submitter.id ? 'action2' : 'action';
		var chosen = $( this ).find( 'select[name="' + which + '"]' ).val();
		var courier = bulkActions[ chosen ];
		var printing = 'bdc_print' === chosen;

		if ( ( ! courier && ! printing ) || ( submitter && 'doaction' !== submitter.id && 'doaction2' !== submitter.id ) ) {
			return;
		}

		event.preventDefault();

		var ids = $( this ).find( 'input[name="post[]"]:checked, input[name="id[]"]:checked' ).map( function () {
			return this.value;
		} ).get();

		if ( ! ids.length ) {
			toast( text.pickOrders, true );
			return;
		}

		if ( printing ) {
			openPrint( ids );
			return;
		}

		openModal();
		$modal.addClass( 'bdc-modal--wide' );

		post( 'bulk_form', { courier: courier, order_ids: ids } )
			.done( function ( response ) {
				if ( $modal ) {
					$modal.find( '.bdc-modal__dialog' ).html( response.data.html );
				}
			} )
			.fail( function ( xhr ) {
				closeModal();
				toast( errorOf( xhr ).message, true );
			} );
	} );

	function bulkRowFields( $bulk, $row ) {
		var fields = {};
		$bulk.find( '.bdc-bulk__shared select' ).add( $row.find( 'input, textarea' ) ).each( function () {
			fields[ this.name ] = this.value;
		} );
		return fields;
	}

	function bulkSendRow( $bulk, $row ) {
		var $result = $row.find( '.bdc-bulk__result' );
		var orderId = $row.data( 'order' );

		$row.removeClass( 'is-failed' ).addClass( 'is-sending' ).find( 'td' ).removeClass( 'has-error' );
		$result.text( text.sending );

		return post( 'send', { order_id: orderId, courier: $bulk.data( 'courier' ), fields: bulkRowFields( $bulk, $row ) } )
			.then( function ( response ) {
				updateOrder( orderId, response.data );
				$row.removeClass( 'is-sending' ).addClass( 'is-done' ).find( 'input, textarea' ).prop( 'readonly', true );
				$result.empty().append(
					$( '<strong class="bdc-bulk__ok"></strong>' ).text( text.sent ),
					$( '<span class="bdc-consignment"></span>' ).text( response.data.id || '' )
				);
			}, function ( xhr ) {
				var error = errorOf( xhr );
				var message = error.message || text.genericError;

				$.each( error.fields || {}, function ( key, fieldMessage ) {
					$row.find( 'td[data-field="' + key + '"]' ).addClass( 'has-error' );
					message = fieldMessage;
				} );

				$row.removeClass( 'is-sending' ).addClass( 'is-failed' );
				$result.empty().append( $( '<span class="bdc-bulk__error"></span>' ).text( message ) );

				// Keep going with the next order.
				return $.Deferred().resolve().promise();
			} );
	}

	$( document ).on( 'click', '.bdc-bulk__submit', function () {
		var $button = $( this );
		var $bulk = $button.closest( '.bdc-bulk' );
		var $rows = $bulk.find( '.bdc-bulk__row' ).not( '.is-done' );
		var $summary = $bulk.find( '.bdc-bulk__summary' );
		var total = $rows.length;
		var index = 0;

		if ( $bulk.hasClass( 'is-sending' ) || ! total ) {
			return;
		}

		$bulk.addClass( 'is-sending' );
		$button.prop( 'disabled', true );
		$bulk.find( '.bdc-modal__cancel, .bdc-modal__close, .bdc-bulk__shared select' ).prop( 'disabled', true );
		$rows.find( '.bdc-bulk__result' ).text( text.waiting );

		function next() {
			if ( index >= total ) {
				return finish();
			}
			$summary.text( fill( text.bulkProgress, index + 1, total ) );
			var $row = $rows.eq( index++ );
			$row.get( 0 ).scrollIntoView( { block: 'nearest' } );
			bulkSendRow( $bulk, $row ).always( next );
		}

		function finish() {
			var sent = $bulk.find( '.bdc-bulk__row.is-done' ).length;
			var failed = $bulk.find( '.bdc-bulk__row.is-failed' ).length;

			$bulk.removeClass( 'is-sending' );
			$bulk.find( '.bdc-modal__cancel, .bdc-modal__close' ).prop( 'disabled', false );
			$bulk.find( '.bdc-modal__cancel' ).text( text.close );

			if ( failed ) {
				$summary.text( fill( text.bulkSomeFail, sent, failed ) );
				$button.prop( 'disabled', false ).text( fill( text.bulkRetry, failed ) );
				$bulk.find( '.bdc-bulk__shared select' ).prop( 'disabled', false );
			} else {
				$summary.text( fill( text.bulkAllSent, sent ) );
				$button.remove();
			}
		}

		next();
	} );

	$( document ).on( 'input', '.bdc-bulk__row td.has-error input, .bdc-bulk__row td.has-error textarea', function () {
		$( this ).closest( 'td' ).removeClass( 'has-error' );
	} );

	/* ---- Shipments page: refresh every parcel shown ---- */

	$( document ).on( 'click', '.bdc-refresh-all', function () {
		var $button = $( this ).prop( 'disabled', true );
		var $progress = $( '.bdc-shipments__progress' );
		var ids = $( 'tr.bdc-shipment[data-finished="0"]' ).map( function () {
			return $( this ).data( 'order' );
		} ).get();
		var index = 0;
		var failed = 0;

		function next() {
			if ( index >= ids.length ) {
				$button.prop( 'disabled', false );
				$progress.text( failed ? fill( text.refreshFail, failed ) : text.refreshDone );
				return;
			}

			var orderId = ids[ index++ ];
			var $row = $( 'tr.bdc-shipment[data-order="' + orderId + '"]' ).addClass( 'is-busy' );
			$progress.text( fill( text.refreshMany, index, ids.length ) );

			post( 'refresh', { order_id: orderId } )
				.then( function ( response ) {
					return updateOrder( orderId, response.data );
				}, function () {
					failed++;
					$row.removeClass( 'is-busy' );
					return $.Deferred().resolve().promise();
				} )
				.always( next );
		}

		next();
	} );

	/* ---- Edited parcel: confirm it was changed at the courier ---- */

	$( document ).on( 'click', '.bdc-synced', function ( event ) {
		event.preventDefault();
		event.stopPropagation();

		var orderId = $( this ).data( 'order' );
		var $cell = $( this ).closest( '.bdc-cell, tr.bdc-shipment' ).addClass( 'is-busy' );

		post( 'synced', { order_id: orderId } )
			.done( function ( response ) {
				updateOrder( orderId, response.data );
			} )
			.fail( function ( xhr ) {
				$cell.removeClass( 'is-busy' );
				toast( errorOf( xhr ).message, true );
			} );
	} );

	/* ---- Orders list: phone number under the customer name (new order storage) ---- */

	$( function () {
		$( '.woocommerce_page_wc-orders .column-order_number a.order-view strong' ).each( function () {
			var parts = $( this ).text().split( ' \u00b7 ' );
			var phone = parts.length > 1 ? parts.pop() : '';

			if ( /^\d{6,}$/.test( phone ) ) {
				$( this ).text( parts.join( ' \u00b7 ' ) );
				$( this ).closest( 'a.order-view' ).after( $( '<a class="bdc-order-phone"></a>' ).attr( 'href', 'tel:' + phone ).text( phone ) );
			}
		} );
	} );

	/* ---- Orders list: customer's order count under their name ---- */

	$( function () {
		$( 'template.bdc-history-seed' ).each( function () {
			var $row = $( this ).closest( 'tr' );
			var $name = $row.find( '.column-order_number' );
			var badge = $( this ).html();

			if ( $name.length && badge ) {
				var $after = $name.find( '.bdc-order-phone' ).last();
				if ( ! $after.length ) {
					$after = $name.find( 'a.order-view' ).first();
				}
				$after.after( $( '<span class="bdc-history-slot"></span>' ).html( badge ) );
			}
			$( this ).remove();
		} );
	} );

	// The phone number belongs with the address: move it into the Billing column when it is shown.
	$( function () {
		$( '.column-order_number .bdc-order-phone' ).each( function () {
			var $billing = $( this ).closest( 'tr' ).find( 'td.column-billing_address' );

			if ( $billing.length && ! $billing.hasClass( 'hidden' ) && $billing.is( ':visible' ) ) {
				var $via = $billing.children( '.description' ).first();
				$( this ).addClass( 'bdc-order-phone--billing' );
				if ( $via.length ) {
					$via.before( this );
				} else {
					$billing.append( this );
				}
			}
		} );
	} );

	$( document ).on( 'click', '.bdc-history-badge', function ( event ) {
		event.preventDefault();
		event.stopPropagation();

		openModal();
		$modal.addClass( 'bdc-modal--wide' );

		post( 'history', { order_id: $( this ).data( 'order' ) } )
			.done( function ( response ) {
				if ( ! $modal ) {
					return;
				}
				var $box = $( '<div class="bdc-record"></div>' );
				$box.append(
					$( '<header class="bdc-form__header"></header>' ).append(
						$( '<h2 id="bdc-modal-title"></h2>' ).text( response.data.title ),
						$( '<button type="button" class="bdc-modal__close">&times;</button>' ).attr( 'aria-label', text.close )
					),
					$( '<div class="bdc-form__body"></div>' ).html( response.data.html ),
					$( '<footer class="bdc-form__footer"><span class="bdc-form__hint"></span></footer>' ).append(
						$( '<button type="button" class="button bdc-modal__cancel"></button>' ).text( text.close )
					)
				);
				$modal.find( '.bdc-modal__dialog' ).empty().append( $box );
			} )
			.fail( function ( xhr ) {
				closeModal();
				toast( errorOf( xhr ).message, true );
			} );
	} );

	/* ---- Settings: Pathao test account ---- */

	$( document ).on( 'click', '.bdc-pathao-test-account', function () {
		var $b = $( this );
		$( '#bdc-pathao-env' ).val( 'sandbox' );
		$( '#bdc-pathao-id' ).val( $b.data( 'client-id' ) );
		$( '#bdc-pathao-secret' ).val( $b.data( 'client-secret' ) );
		$( '#bdc-pathao-username' ).val( $b.data( 'username' ) );
		$( '#bdc-pathao-password' ).val( $b.data( 'password' ) );
		toast( text.testAccount );
	} );

	/* ---- Settings: accent colour picker ---- */

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.bdc-colour' ).wpColorPicker();
		}
	} );

	/* ---- Settings: copy buttons ---- */

	$( document ).on( 'click', '.bdc-copy__button', function () {
		var $button = $( this );
		var input = $button.siblings( 'input' ).get( 0 );
		var label = $button.text();

		input.select();
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( input.value );
		} else {
			document.execCommand( 'copy' );
		}

		$button.text( text.copied );
		setTimeout( function () {
			$button.text( label );
		}, 1500 );
	} );
} )( jQuery );

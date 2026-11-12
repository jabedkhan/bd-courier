<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dashboard actions. Every one checks the user's permission and a nonce.
 */
class BDC_Ajax {

	public static function init() {
		foreach ( array( 'form', 'send', 'edit', 'synced', 'refresh', 'locations', 'score' ) as $action ) {
			add_action( 'wp_ajax_bdc_' . $action, array( __CLASS__, $action ) );
		}
	}

	public static function guard() {
		check_ajax_referer( 'bdc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'bd-courier' ) ), 403 );
		}
	}

	/**
	 * @return WC_Order
	 */
	private static function order() {
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'This order could not be found. Please reload the page.', 'bd-courier' ) ), 404 );
		}

		return $order;
	}

	/**
	 * @return BDC_Courier
	 */
	private static function courier() {
		$courier = BDC_Couriers::get( sanitize_key( $_POST['courier'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $courier ) {
			wp_send_json_error( array( 'message' => __( 'Unknown courier.', 'bd-courier' ) ), 400 );
		}
		if ( ! $courier->is_connected() ) {
			wp_send_json_error(
				array(
					/* translators: %s: courier name */
					'message' => sprintf( __( '%s is not connected yet. Add your credentials in BD Courier settings.', 'bd-courier' ), $courier->label() ),
				),
				400
			);
		}

		return $courier;
	}

	private static function already_sent( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		if ( ! $shipment ) {
			return;
		}

		$with = BDC_Couriers::get( $shipment['courier'] );
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %s: courier name */
					__( 'This order has already been sent with %s. Reload the page to see it.', 'bd-courier' ),
					$with ? $with->label() : $shipment['courier']
				),
			),
			409
		);
	}

	/**
	 * The parcel of an order and its courier, for actions on a sent parcel.
	 *
	 * @return array array( shipment, BDC_Courier )
	 */
	private static function sent_parcel( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		$courier  = $shipment ? BDC_Couriers::get( $shipment['courier'] ) : null;

		if ( ! $shipment || ! $courier ) {
			wp_send_json_error( array( 'message' => __( 'This order has not been sent with a courier yet.', 'bd-courier' ) ), 400 );
		}

		return array( $shipment, $courier );
	}

	/** Only the fields this courier's form has are accepted. */
	private static function posted_fields( array $fields ) {
		$posted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$data   = array();

		foreach ( $fields as $key => $field ) {
			if ( 'note' === $field['type'] ) {
				continue;
			}
			$value        = isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
			$data[ $key ] = 'textarea' === $field['type'] ? trim( sanitize_textarea_field( $value ) ) : sanitize_text_field( $value );
		}

		return $data;
	}

	/** The send form, or the edit form of a parcel that was already sent. */
	public static function form() {
		self::guard();
		$order = self::order();

		if ( 'edit' === sanitize_key( $_POST['mode'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			list( $shipment, $courier ) = self::sent_parcel( $order );

			if ( ! BDC_Shipment::can_edit( $shipment ) ) {
				wp_send_json_error( array( 'message' => __( 'This parcel is already finished, so it can no longer be edited.', 'bd-courier' ) ), 400 );
			}

			wp_send_json_success( array( 'html' => BDC_View::form( $courier, $order, 'edit', $courier->edit_fields( $order, (array) $shipment['details'] ) ) ) );
		}

		$courier = self::courier();
		self::already_sent( $order );

		wp_send_json_success( array( 'html' => BDC_View::form( $courier, $order ) ) );
	}

	/**
	 * Save changes to a parcel that was already sent.
	 *
	 * The couriers do not accept changes through their API, so the parcel is
	 * flagged until staff confirm they changed it in the courier's own panel.
	 */
	public static function edit() {
		self::guard();
		$order                      = self::order();
		list( $shipment, $courier ) = self::sent_parcel( $order );

		if ( ! BDC_Shipment::can_edit( $shipment ) ) {
			wp_send_json_error( array( 'message' => __( 'This parcel is already finished, so it can no longer be edited.', 'bd-courier' ) ), 400 );
		}

		$fields                = $courier->edit_fields( $order, (array) $shipment['details'] );
		list( $data, $errors ) = $courier->check( self::posted_fields( $fields ) );

		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the highlighted fields and try again.', 'bd-courier' ),
					'fields'  => $errors,
				),
				400
			);
		}

		// What was in the form before, to work out what really changed.
		$changed = array();
		foreach ( $data as $key => $value ) {
			$before = isset( $fields[ $key ]['value'] ) ? (string) $fields[ $key ]['value'] : '';
			if ( trim( $before ) !== trim( (string) $value ) && ! ( is_numeric( $before ) && is_numeric( $value ) && (float) $before === (float) $value ) ) {
				$changed[] = trim( preg_replace( '/\s*\(.*\)$/', '', $fields[ $key ]['label'] ) );
			}
		}

		if ( ! $changed ) {
			wp_send_json_success(
				array(
					'message' => __( 'Nothing was changed.', 'bd-courier' ),
					'cell'    => BDC_View::cell( $order ),
				)
			);
		}

		$shipment['details']   = $data;
		$shipment['cod']       = (int) round( (float) $data[ $courier->cod_field() ] );
		$shipment['unsynced']  = true;
		$shipment['edited_at'] = time();
		$shipment['imported']  = false;
		BDC_Shipment::save( $order, $shipment );

		$order->add_order_note(
			sprintf(
				/* translators: 1: list of changed fields, 2: courier name */
				__( 'Parcel details edited (%1$s). Not yet updated on %2$s.', 'bd-courier' ),
				implode( ', ', $changed ),
				$courier->label()
			),
			false,
			false
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: courier name */
					__( 'Changes saved. Now make the same change in the %s merchant panel.', 'bd-courier' ),
					$courier->label()
				),
				'cell'    => BDC_View::cell( $order ),
			)
		);
	}

	/** Staff confirm the edit was also made in the courier's panel. */
	public static function synced() {
		self::guard();
		$order                      = self::order();
		list( $shipment, $courier ) = self::sent_parcel( $order );

		if ( ! empty( $shipment['unsynced'] ) ) {
			$shipment['unsynced'] = false;
			BDC_Shipment::save( $order, $shipment );

			$order->add_order_note(
				sprintf(
					/* translators: %s: courier name */
					__( 'Edited parcel details marked as updated on %s.', 'bd-courier' ),
					$courier->label()
				),
				false,
				false
			);
		}

		wp_send_json_success( array( 'cell' => BDC_View::cell( $order ) ) );
	}

	/** Create the parcel at the courier. */
	public static function send() {
		self::guard();
		$order   = self::order();
		$courier = self::courier();
		self::already_sent( $order );

		$data = self::posted_fields( $courier->form_fields( $order ) );

		// Stops a double click, or two staff members, creating two parcels for one order.
		$lock = 'bdc_sending_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is being sent right now. Please wait a moment.', 'bd-courier' ) ), 409 );
		}
		set_transient( $lock, 1, 60 );

		$result = $courier->create_parcel( $order, $data );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock );
			$error_data = $result->get_error_data();
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'fields'  => is_array( $error_data ) && isset( $error_data['fields'] ) ? $error_data['fields'] : array(),
				),
				400
			);
		}

		$shipment = array_merge(
			BDC_Shipment::blank(),
			array(
				'courier'        => $courier->id(),
				'consignment_id' => $result['consignment_id'],
				'tracking_code'  => $result['tracking_code'],
				'courier_status' => $result['courier_status'],
				'status'         => 'sent',
				'delivery_fee'   => $result['delivery_fee'],
				'cod'            => $result['cod'],
				'sent_at'        => time(),
				'checked_at'     => time(),
				'details'        => $data,
				'history'        => array(
					array(
						'time'    => time(),
						'status'  => $result['courier_status'],
						'message' => '',
					),
				),
			)
		);

		BDC_Shipment::save( $order, $shipment );
		delete_transient( $lock );

		$order->add_order_note(
			sprintf(
				/* translators: 1: courier name, 2: consignment ID */
				__( 'Sent with %1$s. Consignment ID: %2$s.', 'bd-courier' ),
				$courier->label(),
				$result['consignment_id']
			),
			false,
			false
		);
		BDC_Statuses::set_order_status( $order, 'sent' );

		/**
		 * A parcel was created at a courier. The "Order sent with courier" customer email listens to this.
		 *
		 * @param int    $order_id
		 * @param string $courier_id pathao|steadfast
		 */
		do_action( 'bdc_parcel_sent', $order->get_id(), $courier->id() );

		$order = wc_get_order( $order->get_id() );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: order number, 2: courier name */
					__( 'Order #%1$s was sent with %2$s.', 'bd-courier' ),
					$order->get_order_number(),
					$courier->label()
				),
				'cell'    => BDC_View::cell( $order ),
				'status'  => BDC_View::status_mark( $order ),
				'id'      => $result['consignment_id'],
			)
		);
	}

	/** Ask the courier for the latest status of a parcel. */
	public static function refresh() {
		self::guard();
		$order                      = self::order();
		list( $shipment, $courier ) = self::sent_parcel( $order );

		if ( '' === $shipment['consignment_id'] ) {
			wp_send_json_error( array( 'message' => __( 'The courier has not given this parcel an ID yet. Please try again shortly.', 'bd-courier' ) ), 400 );
		}

		$result = $courier->fetch_status( $shipment );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		BDC_Shipment::update_status( $order, $result['courier_status'], $result );
		$order = wc_get_order( $order->get_id() );

		wp_send_json_success(
			array(
				'cell'   => BDC_View::cell( $order ),
				'status' => BDC_View::status_mark( $order ),
			)
		);
	}

	/** The customer's delivery record for an order's phone number. */
	public static function score() {
		self::guard();
		$order = self::order();
		$phone = isset( $_POST['phone'] ) ? BDC_Courier::clean_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$phone = '' !== $phone ? $phone : BDC_Fraud::order_phone( $order );

		$record = BDC_Fraud::get( $phone, ! empty( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $record ) ) {
			wp_send_json_error( array( 'message' => $record->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'panel' => BDC_View::score_panel( $order, $record ),
				'chip'  => BDC_View::score_chip( $order ),
				'title' => sprintf(
					/* translators: %s: phone number */
					__( 'Customer record · %s', 'bd-courier' ),
					$record['phone']
				),
			)
		);
	}

	/** Pathao zones of a city, or areas of a zone. */
	public static function locations() {
		self::guard();

		$type   = sanitize_key( $_POST['type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$parent = absint( $_POST['parent'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$pathao = BDC_Couriers::get( 'pathao' );

		if ( ! in_array( $type, array( 'zones', 'areas' ), true ) || ! $parent ) {
			wp_send_json_success( array( 'options' => array() ) );
		}

		$list = $pathao->locations( $type, $parent );
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ), 400 );
		}

		$options = array();
		foreach ( $list as $id => $name ) {
			$options[] = array(
				'id'   => (string) $id,
				'name' => $name,
			);
		}

		wp_send_json_success( array( 'options' => $options ) );
	}
}

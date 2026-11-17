<?php
defined( 'ABSPATH' ) || exit;

class BDC_Steadfast extends BDC_Courier {

	const BASE_URL = 'https://portal.packzy.com/api/v1';

	/** Steadfast's public tracking pages look like https://steadfast.com.bd/tl/<code>. */
	const LINK_PATTERN = '#https?://(?:www\\.)?steadfast\\.com\\.bd/tl/[A-Za-z0-9_-]{8,}#';

	public function id() {
		return 'steadfast';
	}

	public function label() {
		return 'Steadfast';
	}

	public function is_connected() {
		return '' !== $this->setting( 'api_key' ) && '' !== $this->setting( 'secret_key' );
	}

	/**
	 * @return array|WP_Error
	 */
	private function api( $method, $path, $body = null ) {
		if ( ! $this->is_connected() ) {
			return new WP_Error( 'bdc_not_connected', __( 'Steadfast is not connected yet. Add your API Key and Secret Key in BD Courier → Steadfast.', 'bd-courier' ) );
		}

		$response = BDC_Http::request(
			$method,
			self::BASE_URL . $path,
			array(
				'Api-Key'    => $this->setting( 'api_key' ),
				'Secret-Key' => $this->setting( 'secret_key' ),
			),
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) ( $response['body']['status'] ?? 0 );
		if ( in_array( $response['code'], array( 401, 403 ), true ) || 401 === $status ) {
			return new WP_Error( 'bdc_auth', __( 'Steadfast did not accept the API Key and Secret Key. Please check them in BD Courier → Steadfast.', 'bd-courier' ) );
		}

		return $response;
	}

	public function test_connection() {
		$balance = $this->balance();
		return is_wp_error( $balance ) ? $balance : true;
	}

	/**
	 * @return string|WP_Error Current balance.
	 */
	public function balance() {
		$response = $this->api( 'GET', '/get_balance' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! isset( $response['body']['current_balance'] ) ) {
			return new WP_Error( 'bdc_auth', __( 'Steadfast did not confirm the connection. Please check the API Key and Secret Key.', 'bd-courier' ) );
		}
		return (string) $response['body']['current_balance'];
	}

	/* ---- Send form ---- */

	public function form_fields( WC_Order $order ) {
		return array(
			'recipient_name'    => array(
				'label'    => __( 'Customer name', 'bd-courier' ),
				'type'     => 'text',
				'value'    => $this->recipient_name( $order ),
				'required' => true,
				'group'    => 'customer',
				'width'    => 'third',
			),
			'recipient_phone'   => array(
				'label'    => __( 'Phone', 'bd-courier' ),
				'type'     => 'tel',
				'value'    => $this->recipient_phone( $order ),
				'required' => true,
				'group'    => 'customer',
				'width'    => 'third',
			),
			'alternative_phone' => array(
				'label' => __( 'Second phone (optional)', 'bd-courier' ),
				'type'  => 'tel',
				'value' => '',
				'group' => 'customer',
				'width' => 'third',
			),
			'recipient_address' => array(
				'label'    => __( 'Delivery address', 'bd-courier' ),
				'type'     => 'textarea',
				'value'    => $this->recipient_address( $order ),
				'required' => true,
				'group'    => 'customer',
			),
			'cod_amount'        => array(
				'label'    => __( 'Cash to collect (৳)', 'bd-courier' ),
				'type'     => 'number',
				'value'    => $this->cod_amount( $order ),
				'required' => true,
				'min'      => 0,
				'step'     => 1,
				'group'    => 'parcel',
				'width'    => 'half',
			),
			'delivery_type'     => array(
				'label'   => __( 'Delivery type', 'bd-courier' ),
				'type'    => 'select',
				'value'   => $this->setting( 'delivery_type', '0' ),
				'options' => self::delivery_types(),
				'group'   => 'parcel',
				'width'   => 'half',
			),
			'item_description'  => array(
				'label' => __( 'Item description', 'bd-courier' ),
				'type'  => 'textarea',
				'value' => $this->item_description( $order ),
				'group' => 'parcel',
				'width' => 'half',
			),
			'note'              => array(
				'label' => __( 'Note for the rider (optional)', 'bd-courier' ),
				'type'  => 'textarea',
				'value' => $this->rider_note( $order, 'yes' === $this->setting( 'send_note', 'yes' ) ),
				'group' => 'parcel',
				'width' => 'half',
			),
		);
	}

	public static function delivery_types() {
		return array(
			'0' => __( 'Home delivery', 'bd-courier' ),
			'1' => __( 'Point delivery (hub pick-up)', 'bd-courier' ),
		);
	}

	/* ---- Create parcel ---- */

	public function cod_field() {
		return 'cod_amount';
	}

	public function check( array $data ) {
		$data['recipient_phone']   = self::clean_phone( $data['recipient_phone'] ?? '' );
		$data['alternative_phone'] = self::clean_phone( $data['alternative_phone'] ?? '' );

		$errors = $this->validate_recipient( $data, 'recipient_name', 'recipient_phone', 'recipient_address' );

		if ( '' !== $data['alternative_phone'] && ! self::is_valid_phone( $data['alternative_phone'] ) ) {
			$errors['alternative_phone'] = __( 'Please enter a valid 11-digit mobile number, or leave this empty.', 'bd-courier' );
		}
		if ( ! is_numeric( $data['cod_amount'] ?? '' ) || (float) $data['cod_amount'] < 0 ) {
			$errors['cod_amount'] = __( 'Please enter the cash to collect. Use 0 for a paid order.', 'bd-courier' );
		}

		return array( $data, $errors );
	}

	public function create_parcel( WC_Order $order, array $data ) {
		list( $data, $errors ) = $this->check( $data );
		if ( $errors ) {
			return $this->field_error( $errors );
		}

		$payload = array(
			'invoice'           => $this->invoice_number( $order ),
			'recipient_name'    => $data['recipient_name'],
			'recipient_phone'   => $data['recipient_phone'],
			'recipient_address' => $data['recipient_address'],
			'cod_amount'        => (int) round( (float) $data['cod_amount'] ),
			'delivery_type'     => '1' === (string) ( $data['delivery_type'] ?? '0' ) ? 1 : 0,
			'note'              => (string) ( $data['note'] ?? '' ),
			'item_description'  => (string) ( $data['item_description'] ?? '' ),
		);

		if ( '' !== $data['alternative_phone'] ) {
			$payload['alternative_phone'] = $data['alternative_phone'];
		}

		$response = $this->api( 'POST', '/create_order', $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body        = $response['body'];
		$consignment = (array) ( $body['consignment'] ?? array() );

		if ( 200 !== (int) ( $body['status'] ?? 0 ) || empty( $consignment['consignment_id'] ) ) {
			return $this->api_error( $body );
		}

		return array(
			'consignment_id' => (string) $consignment['consignment_id'],
			'tracking_code'  => (string) ( $consignment['tracking_code'] ?? '' ),
			'tracking_link'  => self::find_link( $body, self::LINK_PATTERN ),
			'courier_status' => (string) ( $consignment['status'] ?? 'in_review' ),
			'delivery_fee'   => '',
			'cod'            => $payload['cod_amount'],
		);
	}

	/**
	 * Steadfast needs a unique invoice per parcel. The order number is used,
	 * with "-2", "-3"... if the same order ever has to be sent again.
	 */
	private function invoice_number( WC_Order $order ) {
		$attempt = (int) $order->get_meta( '_bdc_steadfast_attempts' ) + 1;
		$order->update_meta_data( '_bdc_steadfast_attempts', $attempt );

		return $order->get_order_number() . ( $attempt > 1 ? '-' . $attempt : '' );
	}

	private function api_error( array $body ) {
		$fields = array();

		foreach ( (array) ( $body['errors'] ?? array() ) as $field => $messages ) {
			$message = is_array( $messages ) ? reset( $messages ) : $messages;
			if ( is_string( $message ) && '' !== $message ) {
				$fields[ sanitize_key( $field ) ] = sanitize_text_field( $message );
			}
		}

		if ( $fields ) {
			return new WP_Error(
				'bdc_rejected',
				__( 'Steadfast did not accept this parcel. Please correct the highlighted fields.', 'bd-courier' ),
				array( 'fields' => $fields )
			);
		}

		$message = isset( $body['message'] ) && is_string( $body['message'] ) ? sanitize_text_field( $body['message'] ) : '';

		return new WP_Error(
			'bdc_rejected',
			'' !== $message
				/* translators: %s: message from Steadfast */
				? sprintf( __( 'Steadfast did not accept this parcel: %s', 'bd-courier' ), $message )
				: __( 'Steadfast did not accept this parcel. Please try again in a moment.', 'bd-courier' )
		);
	}

	/* ---- Customer record ---- */

	public function customer_record( $phone ) {
		$response = $this->api( 'GET', '/fraud_check/score/' . rawurlencode( $phone ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data      = $response['body'];
		$total     = self::pick_number( $data, array( 'total_parcels', 'total_orders' ) );
		$delivered = self::pick_number( $data, array( 'delivered_count', 'total_delivered' ) );
		$cancelled = self::pick_number( $data, array( 'cancelled_count', 'total_cancelled' ) );
		$ratio     = null;

		foreach ( array( 'delivery_ratio', 'success_ratio' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( rtrim( (string) $data[ $key ], '% ' ) ) ) {
				$ratio = round( (float) rtrim( (string) $data[ $key ], '% ' ) );
				break;
			}
		}

		if ( null === $total && null !== $delivered && null !== $cancelled ) {
			$total = $delivered + $cancelled;
		}

		if ( null === $total && null === $ratio ) {
			$message = '';
			foreach ( array( 'message', 'error' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$message = sanitize_text_field( $data[ $key ] );
					break;
				}
			}
			return new WP_Error(
				'bdc_score',
				'' !== $message
					/* translators: %s: message from Steadfast */
					? sprintf( __( 'Steadfast could not give a record: %s', 'bd-courier' ), $message )
					: __( 'Steadfast did not return a record for this number.', 'bd-courier' )
			);
		}

		return array(
			'total'     => $total,
			'delivered' => $delivered,
			'cancelled' => $cancelled,
			'ratio'     => null !== $total && null !== $delivered && $total > 0 ? round( $delivered / $total * 100 ) : $ratio,
		);
	}

	/* ---- Status ---- */

	public function fetch_status( array $shipment ) {
		$response = $this->api( 'GET', '/status_by_cid/' . rawurlencode( $shipment['consignment_id'] ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (string) ( $response['body']['delivery_status'] ?? '' );
		if ( 200 !== (int) ( $response['body']['status'] ?? 0 ) || '' === $status ) {
			return new WP_Error( 'bdc_status', __( 'Steadfast could not find this parcel. It may have been removed in the merchant panel.', 'bd-courier' ) );
		}

		return array(
			'courier_status' => $status,
			'tracking_link'  => self::find_link( $response['body'], self::LINK_PATTERN ),
		);
	}

	public function map_status( $raw_status ) {
		$key = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $raw_status ), '_' ) );

		$map = array(
			'pending'                            => 'sent',
			'in_review'                          => 'sent',
			'delivered'                          => 'delivered',
			'delivered_approval_pending'         => 'delivered',
			'partial_delivered'                  => 'partial',
			'partial_delivered_approval_pending' => 'partial',
			'cancelled'                          => 'cancelled',
			'cancelled_approval_pending'         => 'cancelled',
			'hold'                               => 'hold',
			'unknown'                            => null,
			'unknown_approval_pending'           => null,
		);

		return array_key_exists( $key, $map ) ? $map[ $key ] : null;
	}

	/**
	 * Steadfast's public tracking page needs a private link code that
	 * Steadfast sends the customer by SMS; it is not the consignment ID or the
	 * tracking code. It is used when Steadfast includes it in a reply, or when
	 * staff paste it in the parcel's Edit form.
	 */
	public function tracking_url( array $shipment ) {
		$link = (string) ( $shipment['tracking_link'] ?? '' );
		return preg_match( self::LINK_PATTERN, $link ) ? $link : '';
	}

	/** The parcel in the Steadfast merchant panel (staff only; asks to log in). */
	public function panel_url( array $shipment ) {
		return '' === $shipment['consignment_id'] ? '' : 'https://steadfast.com.bd/user/consignment/' . rawurlencode( $shipment['consignment_id'] );
	}

	/** The Edit form has one more field: the public tracking link, if staff have it. */
	public function edit_fields( WC_Order $order, array $details ) {
		$fields = parent::edit_fields( $order, $details );

		$shipment = BDC_Shipment::get( $order );
		$fields['tracking_link'] = array(
			'label'       => __( 'Customer tracking link (optional)', 'bd-courier' ),
			'type'        => 'text',
			'value'       => $shipment ? (string) $shipment['tracking_link'] : '',
			'placeholder' => 'https://steadfast.com.bd/tl/…',
			'help'        => __( 'Paste the link Steadfast sends the customer by SMS. It is shown to the customer and used by the Track button. Saving it does not change the parcel.', 'bd-courier' ),
			'group'       => 'parcel',
		);

		return $fields;
	}
}

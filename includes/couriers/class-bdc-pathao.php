<?php
defined( 'ABSPATH' ) || exit;

class BDC_Pathao extends BDC_Courier {

	const TOKEN_OPTION = 'bdc_pathao_token';

	public function id() {
		return 'pathao';
	}

	public function label() {
		return 'Pathao';
	}

	public function is_connected() {
		return '' !== $this->setting( 'client_id' ) && '' !== $this->setting( 'client_secret' );
	}

	private function base_url() {
		return 'sandbox' === $this->setting( 'environment', 'live' )
			? 'https://courier-api-sandbox.pathao.com'
			: 'https://api-hermes.pathao.com';
	}

	/* ---- Connection ---- */

	/**
	 * @return string|WP_Error Access token.
	 */
	private function token( $force_new = false ) {
		if ( ! $this->is_connected() ) {
			return new WP_Error( 'bdc_not_connected', __( 'Pathao is not connected yet. Add your Client ID and Client Secret in BD Courier → Pathao.', 'bd-courier' ) );
		}

		$username = $this->setting( 'username' );
		$password = $this->setting( 'password' );

		// A token belongs to one set of credentials; changing them must not reuse it.
		$fingerprint = md5( $this->setting( 'environment', 'live' ) . '|' . $this->setting( 'client_id' ) . '|' . $this->setting( 'client_secret' ) . '|' . $username . '|' . $password );
		$saved       = get_option( self::TOKEN_OPTION );

		if ( ! $force_new && is_array( $saved ) && ( $saved['for'] ?? '' ) === $fingerprint && ( $saved['expires'] ?? 0 ) > time() + 60 ) {
			return $saved['token'];
		}

		/*
		 * Pathao has two ways to log in:
		 * - with the merchant's username and password ("issue-token"), which
		 *   the sandbox needs and every account supports;
		 * - with only the Client ID and Secret ("external/login"), which
		 *   newer live accounts support.
		 * The first is used whenever a username and password are saved.
		 */
		if ( '' !== $username && '' !== $password ) {
			$response = BDC_Http::request(
				'POST',
				$this->base_url() . '/aladdin/api/v1/issue-token',
				array(),
				array(
					'client_id'     => $this->setting( 'client_id' ),
					'client_secret' => $this->setting( 'client_secret' ),
					'grant_type'    => 'password',
					'username'      => $username,
					'password'      => $password,
				),
				60
			);
		} else {
			$response = BDC_Http::request(
				'POST',
				$this->base_url() . '/aladdin/api/v1/external/login',
				array(),
				array(
					'client_id'     => $this->setting( 'client_id' ),
					'client_secret' => $this->setting( 'client_secret' ),
				),
				60
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['body']['access_token'] ) ) {
			delete_option( self::TOKEN_OPTION );

			if ( '' === $username || '' === $password ) {
				return new WP_Error(
					'bdc_auth',
					'sandbox' === $this->setting( 'environment', 'live' )
						? __( 'Pathao sandbox needs a username and password as well. Fill them in, or click "Use Pathao test account".', 'bd-courier' )
						: __( 'Pathao did not accept the Client ID and Client Secret. Check them, or also enter your Pathao merchant username and password.', 'bd-courier' )
				);
			}

			return new WP_Error( 'bdc_auth', __( 'Pathao did not accept these login details. Check the Client ID, Client Secret, username and password, and that the right environment is selected.', 'bd-courier' ) );
		}

		update_option(
			self::TOKEN_OPTION,
			array(
				'token'   => $response['body']['access_token'],
				'expires' => time() + (int) ( $response['body']['expires_in'] ?? 3600 ),
				'for'     => $fingerprint,
			),
			false
		);

		return $response['body']['access_token'];
	}

	/**
	 * Call the Pathao API. Gets a fresh token once if the saved one was rejected.
	 *
	 * @return array|WP_Error
	 */
	private function api( $method, $path, $body = null, $retry = true ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = BDC_Http::request( $method, $this->base_url() . $path, array( 'Authorization' => 'Bearer ' . $token ), $body );

		if ( ! is_wp_error( $response ) && 401 === $response['code'] && $retry ) {
			$token = $this->token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			return $this->api( $method, $path, $body, false );
		}

		return $response;
	}

	public function test_connection() {
		$token = $this->token( true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		delete_transient( 'bdc_pathao_stores' );
		$stores = $this->stores();
		if ( is_wp_error( $stores ) ) {
			return $stores;
		}
		if ( empty( $stores ) ) {
			return new WP_Error( 'bdc_no_store', __( 'Connected, but your Pathao account has no store yet. Create a store in the Pathao merchant panel first.', 'bd-courier' ) );
		}

		return true;
	}

	/* ---- Stores and locations ---- */

	/**
	 * @return array|WP_Error store_id => name
	 */
	public function stores() {
		$cached = get_transient( 'bdc_pathao_stores' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->api( 'GET', '/aladdin/api/v1/stores' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$stores = array();
		foreach ( (array) ( $response['body']['data']['data'] ?? array() ) as $store ) {
			if ( isset( $store['store_id'] ) ) {
				$stores[ (string) $store['store_id'] ] = (string) ( $store['store_name'] ?? $store['store_id'] );
			}
		}

		if ( $stores ) {
			set_transient( 'bdc_pathao_stores', $stores, HOUR_IN_SECONDS );
		}

		return $stores;
	}

	/**
	 * City, zone and area lists. Pathao's lists rarely change, so they are kept for a week.
	 *
	 * @param string $type   cities|zones|areas
	 * @param int    $parent City ID for zones, zone ID for areas.
	 * @return array|WP_Error id => name
	 */
	public function locations( $type, $parent = 0 ) {
		$parent = absint( $parent );
		$paths  = array(
			'cities' => '/aladdin/api/v1/countries/1/city-list',
			'zones'  => '/aladdin/api/v1/cities/' . $parent . '/zone-list',
			'areas'  => '/aladdin/api/v1/zones/' . $parent . '/area-list',
		);
		$fields = array(
			'cities' => array( 'city_id', 'city_name' ),
			'zones'  => array( 'zone_id', 'zone_name' ),
			'areas'  => array( 'area_id', 'area_name' ),
		);

		if ( ! isset( $paths[ $type ] ) || ( 'cities' !== $type && ! $parent ) ) {
			return array();
		}

		$cache_key = 'bdc_pathao_' . $type . '_' . $parent . '_' . $this->setting( 'environment', 'live' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->api( 'GET', $paths[ $type ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		list( $id_field, $name_field ) = $fields[ $type ];
		$list                          = array();
		foreach ( (array) ( $response['body']['data']['data'] ?? array() ) as $row ) {
			if ( isset( $row[ $id_field ] ) ) {
				$list[ (string) $row[ $id_field ] ] = (string) ( $row[ $name_field ] ?? $row[ $id_field ] );
			}
		}
		asort( $list, SORT_NATURAL | SORT_FLAG_CASE );

		if ( $list ) {
			set_transient( $cache_key, $list, WEEK_IN_SECONDS );
		}

		return $list;
	}

	/* ---- Send form ---- */

	public function form_fields( WC_Order $order ) {
		$stores = $this->stores();
		$cities = $this->locations( 'cities' );
		$stores = is_wp_error( $stores ) ? array() : $stores;
		$cities = is_wp_error( $cities ) ? array() : $cities;

		$default_store = $this->setting( 'store_id' );
		if ( ! isset( $stores[ $default_store ] ) ) {
			$default_store = $stores ? (string) array_key_first( $stores ) : '';
		}

		return array(
			'recipient_name'            => array(
				'label'    => __( 'Customer name', 'bd-courier' ),
				'type'     => 'text',
				'value'    => $this->recipient_name( $order ),
				'required' => true,
				'group'    => 'customer',
				'width'    => 'third',
			),
			'recipient_phone'           => array(
				'label'    => __( 'Phone', 'bd-courier' ),
				'type'     => 'tel',
				'value'    => $this->recipient_phone( $order ),
				'required' => true,
				'group'    => 'customer',
				'width'    => 'third',
			),
			'recipient_secondary_phone' => array(
				'label' => __( 'Second phone (optional)', 'bd-courier' ),
				'type'  => 'tel',
				'value' => '',
				'group' => 'customer',
				'width' => 'third',
			),
			'recipient_address'         => array(
				'label'    => __( 'Delivery address', 'bd-courier' ),
				'type'     => 'textarea',
				'value'    => $this->recipient_address( $order ),
				'required' => true,
				'group'    => 'customer',
			),
			'recipient_city'            => array(
				'label'       => __( 'City', 'bd-courier' ),
				'type'        => 'select',
				'value'       => '',
				'options'     => $cities,
				'placeholder' => __( 'Detect from address', 'bd-courier' ),
				'group'       => 'customer',
				'width'       => 'third',
			),
			'recipient_zone'            => array(
				'label'       => __( 'Zone', 'bd-courier' ),
				'type'        => 'select',
				'value'       => '',
				'options'     => array(),
				'placeholder' => __( 'Choose a city first', 'bd-courier' ),
				'group'       => 'customer',
				'width'       => 'third',
			),
			'recipient_area'            => array(
				'label'       => __( 'Area (optional)', 'bd-courier' ),
				'type'        => 'select',
				'value'       => '',
				'options'     => array(),
				'placeholder' => __( 'Choose a zone first', 'bd-courier' ),
				'group'       => 'customer',
				'width'       => 'third',
			),
			'location_note'             => array(
				'type'  => 'note',
				'label' => __( 'City, zone and area are optional. Leave them empty and Pathao will work them out from the address.', 'bd-courier' ),
				'group' => 'customer',
			),
			'store_id'                  => array(
				'label'       => __( 'Pickup store', 'bd-courier' ),
				'type'        => 'select',
				'value'       => $default_store,
				'options'     => $stores,
				'placeholder' => __( 'Select a store', 'bd-courier' ),
				'required'    => true,
				'group'       => 'parcel',
				'width'       => 'third',
			),
			'delivery_type'             => array(
				'label'   => __( 'Delivery type', 'bd-courier' ),
				'type'    => 'select',
				'value'   => $this->setting( 'delivery_type', '48' ),
				'options' => self::delivery_types(),
				'group'   => 'parcel',
				'width'   => 'third',
			),
			'item_type'                 => array(
				'label'   => __( 'Item type', 'bd-courier' ),
				'type'    => 'select',
				'value'   => $this->setting( 'item_type', '2' ),
				'options' => self::item_types(),
				'group'   => 'parcel',
				'width'   => 'third',
			),
			'amount_to_collect'         => array(
				'label'    => __( 'Cash to collect (৳)', 'bd-courier' ),
				'type'     => 'number',
				'value'    => $this->cod_amount( $order ),
				'required' => true,
				'min'      => 0,
				'step'     => 1,
				'group'    => 'parcel',
				'width'    => 'third',
			),
			'item_weight'               => array(
				'label'    => __( 'Weight (kg)', 'bd-courier' ),
				'type'     => 'number',
				'value'    => max( 0.5, $this->total_weight( $order ) ),
				'required' => true,
				'min'      => 0.5,
				'max'      => 10,
				'step'     => 0.1,
				'group'    => 'parcel',
				'width'    => 'third',
			),
			'item_quantity'             => array(
				'label'    => __( 'Quantity', 'bd-courier' ),
				'type'     => 'number',
				'value'    => $this->item_quantity( $order ),
				'required' => true,
				'min'      => 1,
				'step'     => 1,
				'group'    => 'parcel',
				'width'    => 'third',
			),
			'item_description'          => array(
				'label' => __( 'Item description', 'bd-courier' ),
				'type'  => 'textarea',
				'value' => $this->item_description( $order ),
				'group' => 'parcel',
				'width' => 'half',
			),
			'special_instruction'       => array(
				'label' => __( 'Note for the rider (optional)', 'bd-courier' ),
				'type'  => 'textarea',
				'value' => $this->rider_note( $order ),
				'group' => 'parcel',
				'width' => 'half',
			),
		);
	}

	public static function delivery_types() {
		return array(
			'48' => __( 'Normal delivery', 'bd-courier' ),
			'12' => __( 'On-demand delivery', 'bd-courier' ),
		);
	}

	public static function item_types() {
		return array(
			'2' => __( 'Parcel', 'bd-courier' ),
			'1' => __( 'Document', 'bd-courier' ),
		);
	}

	/* ---- Create parcel ---- */

	public function cod_field() {
		return 'amount_to_collect';
	}

	public function edit_fields( WC_Order $order, array $details ) {
		$fields = parent::edit_fields( $order, $details );

		// The zone and area lists depend on what was chosen above them.
		if ( ! empty( $details['recipient_city'] ) ) {
			$zones                                = $this->locations( 'zones', $details['recipient_city'] );
			$fields['recipient_zone']['options']     = is_wp_error( $zones ) ? array() : $zones;
			$fields['recipient_zone']['placeholder'] = __( 'Select a zone', 'bd-courier' );
		}
		if ( ! empty( $details['recipient_zone'] ) ) {
			$areas                                = $this->locations( 'areas', $details['recipient_zone'] );
			$fields['recipient_area']['options']     = is_wp_error( $areas ) ? array() : $areas;
			$fields['recipient_area']['placeholder'] = __( 'Select an area', 'bd-courier' );
		}

		return $fields;
	}

	public function check( array $data ) {
		$data['recipient_phone']           = self::clean_phone( $data['recipient_phone'] ?? '' );
		$data['recipient_secondary_phone'] = self::clean_phone( $data['recipient_secondary_phone'] ?? '' );

		$errors = $this->validate_recipient( $data, 'recipient_name', 'recipient_phone', 'recipient_address' );

		if ( '' !== $data['recipient_secondary_phone'] && ! self::is_valid_phone( $data['recipient_secondary_phone'] ) ) {
			$errors['recipient_secondary_phone'] = __( 'Please enter a valid 11-digit mobile number, or leave this empty.', 'bd-courier' );
		}
		if ( empty( $data['store_id'] ) ) {
			$errors['store_id'] = __( 'Please choose the store Pathao should pick up from.', 'bd-courier' );
		}
		if ( ! empty( $data['recipient_city'] ) && empty( $data['recipient_zone'] ) ) {
			$errors['recipient_zone'] = __( 'Please choose a zone, or clear the city to let Pathao detect it.', 'bd-courier' );
		}
		$weight = (float) ( $data['item_weight'] ?? 0 );
		if ( $weight < 0.5 || $weight > 10 ) {
			$errors['item_weight'] = __( 'Weight must be between 0.5 and 10 kg.', 'bd-courier' );
		}
		if ( ! is_numeric( $data['amount_to_collect'] ?? '' ) || (float) $data['amount_to_collect'] < 0 ) {
			$errors['amount_to_collect'] = __( 'Please enter the cash to collect. Use 0 for a paid order.', 'bd-courier' );
		}
		if ( (int) ( $data['item_quantity'] ?? 0 ) < 1 ) {
			$errors['item_quantity'] = __( 'Quantity must be at least 1.', 'bd-courier' );
		}

		return array( $data, $errors );
	}

	public function create_parcel( WC_Order $order, array $data ) {
		list( $data, $errors ) = $this->check( $data );
		if ( $errors ) {
			return $this->field_error( $errors );
		}
		$weight = (float) $data['item_weight'];

		$payload = array(
			'store_id'            => (int) $data['store_id'],
			'merchant_order_id'   => (string) $order->get_id(),
			'recipient_name'      => $data['recipient_name'],
			'recipient_phone'     => $data['recipient_phone'],
			'recipient_address'   => $data['recipient_address'],
			'delivery_type'       => isset( self::delivery_types()[ $data['delivery_type'] ?? '' ] ) ? (int) $data['delivery_type'] : 48,
			'item_type'           => isset( self::item_types()[ $data['item_type'] ?? '' ] ) ? (int) $data['item_type'] : 2,
			'item_quantity'       => (int) $data['item_quantity'],
			'item_weight'         => $weight,
			'amount_to_collect'   => (int) round( (float) $data['amount_to_collect'] ),
			'item_description'    => (string) ( $data['item_description'] ?? '' ),
			'special_instruction' => (string) ( $data['special_instruction'] ?? '' ),
		);

		if ( '' !== $data['recipient_secondary_phone'] ) {
			$payload['recipient_secondary_phone'] = $data['recipient_secondary_phone'];
		}
		foreach ( array( 'recipient_city', 'recipient_zone', 'recipient_area' ) as $location ) {
			if ( ! empty( $data[ $location ] ) ) {
				$payload[ $location ] = (int) $data[ $location ];
			}
		}

		$response = $this->api( 'POST', '/aladdin/api/v1/orders', $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = $response['body'];

		if ( $response['code'] >= 300 || empty( $body['data']['consignment_id'] ) ) {
			return $this->api_error( $body );
		}

		return array(
			'consignment_id' => (string) $body['data']['consignment_id'],
			'tracking_code'  => '',
			'courier_status' => (string) ( $body['data']['order_status'] ?? 'Pending' ),
			'delivery_fee'   => isset( $body['data']['delivery_fee'] ) ? (string) $body['data']['delivery_fee'] : '',
			'cod'            => $payload['amount_to_collect'],
		);
	}

	/** Turn Pathao's error response into a message staff can act on. */
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
				__( 'Pathao did not accept this parcel. Please correct the highlighted fields.', 'bd-courier' ),
				array( 'fields' => $fields )
			);
		}

		$message = isset( $body['message'] ) && is_string( $body['message'] ) ? sanitize_text_field( $body['message'] ) : '';

		return new WP_Error(
			'bdc_rejected',
			'' !== $message
				/* translators: %s: message from Pathao */
				? sprintf( __( 'Pathao did not accept this parcel: %s', 'bd-courier' ), $message )
				: __( 'Pathao did not accept this parcel. Please try again in a moment.', 'bd-courier' )
		);
	}

	/* ---- Customer record ---- */

	public function customer_record( $phone ) {
		$response = $this->api( 'POST', '/aladdin/api/v1/user/success', array( 'phone' => $phone ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = (array) ( $response['body']['data'] ?? array() );
		if ( isset( $data['customer'] ) && is_array( $data['customer'] ) ) {
			$data = array_merge( $data, $data['customer'] );
		}

		$total     = self::pick_number( $data, array( 'total_delivery', 'total_parcels', 'total_orders', 'total' ) );
		$delivered = self::pick_number( $data, array( 'successful_delivery', 'total_delivered', 'delivered', 'success' ) );
		$ratio     = self::pick_number( $data, array( 'success_rate', 'success_ratio', 'delivery_ratio' ) );

		// Newer Pathao accounts answer with a rating word instead of numbers.
		$rating = isset( $data['customer_rating'] ) && is_string( $data['customer_rating'] ) ? sanitize_key( $data['customer_rating'] ) : '';
		if ( $response['code'] < 300 && null === $total && null === $ratio && '' !== $rating ) {
			return array(
				'total'     => null,
				'delivered' => null,
				'cancelled' => null,
				'ratio'     => null,
				'rating'    => $rating,
			);
		}

		if ( $response['code'] >= 300 || ( null === $total && null === $ratio ) ) {
			$message = isset( $response['body']['message'] ) && is_string( $response['body']['message'] ) ? sanitize_text_field( $response['body']['message'] ) : '';
			return new WP_Error(
				'bdc_score',
				'' !== $message
					/* translators: %s: message from Pathao */
					? sprintf( __( 'Pathao could not give a record: %s', 'bd-courier' ), $message )
					: __( 'Pathao did not return a record for this number.', 'bd-courier' )
			);
		}

		return array(
			'total'     => $total,
			'delivered' => $delivered,
			'cancelled' => null !== $total && null !== $delivered ? max( 0, $total - $delivered ) : null,
			'ratio'     => null !== $total && null !== $delivered && $total > 0 ? round( $delivered / $total * 100 ) : $ratio,
		);
	}

	/* ---- Status ---- */

	public function fetch_status( array $shipment ) {
		$path     = '/aladdin/api/v1/orders/' . rawurlencode( $shipment['consignment_id'] );
		$response = $this->api( 'GET', $path . '/info' );
		// Some Pathao accounts answer on the address without "/info".
		if ( ! is_wp_error( $response ) && 404 === $response['code'] ) {
			$response = $this->api( 'GET', $path );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data   = (array) ( $response['body']['data'] ?? array() );
		$status = (string) ( $data['order_status'] ?? '' );

		if ( $response['code'] >= 300 || '' === $status ) {
			return new WP_Error( 'bdc_status', __( 'Pathao could not find this parcel. It may have been removed in the merchant panel.', 'bd-courier' ) );
		}

		return array(
			'courier_status' => $status,
			'delivery_fee'   => isset( $data['delivery_fee'] ) ? (string) $data['delivery_fee'] : '',
		);
	}

	public function map_status( $raw_status ) {
		$key = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $raw_status ), '_' ) );

		$map = array(
			'pending'                   => 'sent',
			'order_created'             => 'sent',
			'order_updated'             => null,
			'pickup_requested'          => 'sent',
			'assigned_for_pickup'       => 'sent',
			'pickup_failed'             => 'hold',
			'pickup_cancelled'          => 'cancelled',
			'pickup_cancel'             => 'cancelled',
			'picked'                    => 'picked',
			'at_the_sorting_hub'        => 'transit',
			'in_transit'                => 'transit',
			'received_at_last_mile_hub' => 'transit',
			'assigned_for_delivery'     => 'out',
			'delivered'                 => 'delivered',
			'partial_delivery'          => 'partial',
			'exchange'                  => 'delivered',
			'return'                    => 'returned',
			'returned'                  => 'returned',
			'paid_return'               => 'returned',
			'delivery_failed'           => 'hold',
			'on_hold'                   => 'hold',
			'hold'                      => 'hold',
			'cancelled'                 => 'cancelled',
			'payment_invoice'           => null,
		);

		return array_key_exists( $key, $map ) ? $map[ $key ] : null;
	}

	public function tracking_url( array $shipment ) {
		if ( '' === $shipment['consignment_id'] ) {
			return '';
		}
		$phone = isset( $shipment['details']['recipient_phone'] ) ? $shipment['details']['recipient_phone'] : '';
		return add_query_arg(
			array_filter(
				array(
					'consignment_id' => rawurlencode( $shipment['consignment_id'] ),
					'phone'          => rawurlencode( $phone ),
				)
			),
			'https://merchant.pathao.com/tracking'
		);
	}

	public function panel_url( array $shipment ) {
		return '' === $shipment['consignment_id'] ? '' : 'https://merchant.pathao.com/courier/orders/' . rawurlencode( $shipment['consignment_id'] );
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * The parcel record of an order: which courier has it and where it is.
 *
 * Stored on the order through WooCommerce's own order API, so it works with
 * both the classic and the new (HPOS) order storage. One order has at most
 * one parcel, which is what stops it being sent to both couriers.
 */
class BDC_Shipment {

	const META = '_bdc_shipment';

	/**
	 * @return array|null
	 */
	public static function get( WC_Order $order ) {
		$data = $order->get_meta( self::META );

		if ( is_array( $data ) && ! empty( $data['courier'] ) ) {
			return array_merge( self::blank(), $data );
		}

		return self::from_official_plugins( $order );
	}

	public static function blank() {
		return array(
			'courier'        => '',
			'consignment_id' => '',
			'tracking_code'  => '',
			'courier_status' => '', // The courier's own wording.
			'status'         => '', // Our common status key.
			'delivery_fee'   => '',
			'cod'            => '',
			'sent_at'        => 0,
			'updated_at'     => 0,
			'details'        => array(), // What was submitted in the send form.
			'history'        => array(),
			'imported'       => false,
			'checked_at'     => 0, // Last time the courier told us, or was asked, about this parcel.
			'unsynced'       => false, // Edited here, not yet changed at the courier.
			'edited_at'      => 0,
		);
	}

	/** A parcel that is delivered, returned or cancelled is finished and cannot be edited. */
	public static function can_edit( array $shipment ) {
		return ! in_array( $shipment['status'], array( 'delivered', 'partial', 'returned', 'cancelled' ), true );
	}

	public static function save( WC_Order $order, array $data ) {
		$data['updated_at'] = time();

		$order->update_meta_data( self::META, $data );
		// Separate copies so orders can be searched and filtered by them.
		$order->update_meta_data( '_bdc_courier', $data['courier'] );
		$order->update_meta_data( '_bdc_consignment_id', $data['consignment_id'] );
		$order->update_meta_data( '_bdc_status', $data['status'] );
		$order->update_meta_data( '_bdc_sent_at', (int) $data['sent_at'] );
		$order->update_meta_data( '_bdc_checked_at', (int) ( $data['checked_at'] ?? 0 ) );
		$order->update_meta_data( '_bdc_unsynced', ! empty( $data['unsynced'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_bdc_phone', (string) ( $data['details']['recipient_phone'] ?? $order->get_billing_phone() ) );
		$order->update_meta_data( '_bdc_name', (string) ( $data['details']['recipient_name'] ?? $order->get_formatted_billing_full_name() ) );
		$order->save();
	}

	public static function init() {
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( __CLASS__, 'classic_storage_query' ), 10, 2 );
	}

	/** With the classic order storage, WooCommerce needs the conditions handed over this way. */
	public static function classic_storage_query( $query, $query_vars ) {
		if ( ! empty( $query_vars['bdc_meta_query'] ) ) {
			$query['meta_query'][] = $query_vars['bdc_meta_query'];
		}
		return $query;
	}

	/**
	 * Find orders by their parcel data. Works with both order storages.
	 *
	 * @param array $meta Conditions, in WP meta query form.
	 * @param array $args Extra wc_get_orders() arguments.
	 * @return WC_Order[]|object Orders, or the paginated result when 'paginate' is set.
	 */
	public static function query_orders( array $meta, array $args = array() ) {
		$args = array_merge(
			array(
				'type'   => 'shop_order',
				'status' => array_keys( wc_get_order_statuses() ),
				'limit'  => -1,
			),
			$args
		);

		// The new order storage (HPOS) takes the conditions directly; the classic one through classic_storage_query().
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$args[ $hpos ? 'meta_query' : 'bdc_meta_query' ] = $meta;

		return wc_get_orders( $args );
	}

	/** Note that the courier was asked about this parcel, without changing anything else. */
	public static function mark_checked( WC_Order $order ) {
		$data = $order->get_meta( self::META );
		if ( is_array( $data ) && ! empty( $data['courier'] ) ) {
			$data['checked_at'] = time();
			$order->update_meta_data( self::META, $data );
			$order->update_meta_data( '_bdc_checked_at', $data['checked_at'] );
			$order->save();
		}
	}

	/**
	 * Parcels saved by an earlier version get the searchable copies too.
	 * Runs once per data version.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'bdc_data_version', 0 ) >= 3 ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'meta_key'   => '_bdc_courier', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $orders as $order ) {
			$data = $order->get_meta( self::META );
			if ( is_array( $data ) && ! empty( $data['courier'] ) ) {
				$updated_at = $data['updated_at'] ?? time();
				self::save( $order, array_merge( self::blank(), $data ) );
				// Keep the real "last updated" time.
				$data = $order->get_meta( self::META );
				$data['updated_at'] = $updated_at;
				$order->update_meta_data( self::META, $data );
				$order->save();
			}
		}

		update_option( 'bdc_data_version', 3, false );
	}

	/**
	 * Record a new status from the courier and move the order along with it.
	 *
	 * @param string $raw_status The courier's own status text.
	 * @param array  $extra      Optional: delivery_fee, consignment_id, tracking_code, message.
	 * @return array The updated shipment.
	 */
	public static function update_status( WC_Order $order, $raw_status, array $extra = array() ) {
		$shipment = self::get( $order );
		if ( ! $shipment ) {
			return array();
		}

		$courier = BDC_Couriers::get( $shipment['courier'] );
		if ( ! $courier ) {
			return $shipment;
		}

		foreach ( array( 'delivery_fee', 'consignment_id', 'tracking_code' ) as $field ) {
			if ( isset( $extra[ $field ] ) && '' !== (string) $extra[ $field ] ) {
				$shipment[ $field ] = sanitize_text_field( (string) $extra[ $field ] );
			}
		}

		$raw_status = sanitize_text_field( (string) $raw_status );
		$changed    = '' !== $raw_status && $raw_status !== $shipment['courier_status'];
		$status_key = '' !== $raw_status ? $courier->map_status( $raw_status ) : null;

		if ( $changed ) {
			$shipment['courier_status'] = $raw_status;
			$shipment['history'][]      = array(
				'time'    => time(),
				'status'  => $raw_status,
				'message' => isset( $extra['message'] ) ? sanitize_text_field( (string) $extra['message'] ) : '',
			);
			$shipment['history']        = array_slice( $shipment['history'], -50 );
		}

		if ( $status_key ) {
			$shipment['status'] = $status_key;
		}

		$shipment['imported']   = false;
		$shipment['checked_at'] = time();
		self::save( $order, $shipment );

		if ( $changed && $status_key ) {
			BDC_Statuses::set_order_status(
				$order,
				$status_key,
				sprintf(
					/* translators: 1: courier name, 2: courier status */
					__( '%1$s: parcel status is now "%2$s".', 'bd-courier' ),
					$courier->label(),
					$courier->status_label( $raw_status )
				)
			);
		}

		return $shipment;
	}

	/**
	 * Find the order a webhook is talking about.
	 *
	 * @return WC_Order|null
	 */
	public static function find_order( $courier_id, $consignment_id, $order_id = 0 ) {
		if ( $order_id ) {
			$order = wc_get_order( absint( $order_id ) );
			if ( $order instanceof WC_Order ) {
				$shipment = self::get( $order );
				if ( $shipment && $shipment['courier'] === $courier_id ) {
					return $order;
				}
			}
		}

		if ( '' === (string) $consignment_id ) {
			return null;
		}

		$keys = array( '_bdc_consignment_id' );
		// Parcels created earlier by the couriers' own plugins.
		$keys[] = 'pathao' === $courier_id ? 'ptc_consignment_id' : 'steadfast_consignment_id';

		foreach ( $keys as $key ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => (string) $consignment_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( ! empty( $orders ) ) {
				$shipment = self::get( $orders[0] );
				if ( $shipment && $shipment['courier'] === $courier_id ) {
					return $orders[0];
				}
			}
		}

		return null;
	}

	/**
	 * Orders that were sent with the official Pathao or Steadfast plugin
	 * before BD Courier was installed still show their parcel.
	 */
	private static function from_official_plugins( WC_Order $order ) {
		$read = function ( $key ) use ( $order ) {
			$value = $order->get_meta( $key );
			if ( '' === $value || null === $value ) {
				$value = get_post_meta( $order->get_id(), $key, true );
			}
			return is_scalar( $value ) ? (string) $value : '';
		};

		$found = null;

		$pathao_id = $read( 'ptc_consignment_id' );
		if ( '' !== $pathao_id && '-' !== $pathao_id ) {
			$found = array(
				'courier'        => 'pathao',
				'consignment_id' => $pathao_id,
				'courier_status' => $read( 'ptc_status' ),
				'delivery_fee'   => $read( 'ptc_delivery_fee' ),
			);
		}

		$steadfast_id = $read( 'steadfast_consignment_id' );
		if ( ! $found && '' !== $steadfast_id ) {
			$found = array(
				'courier'        => 'steadfast',
				'consignment_id' => $steadfast_id,
				'courier_status' => $read( 'stdf_delivery_status' ),
			);
		}

		if ( ! $found ) {
			return null;
		}

		$courier         = BDC_Couriers::get( $found['courier'] );
		$found['status'] = $courier && '' !== $found['courier_status'] ? (string) $courier->map_status( $found['courier_status'] ) : '';
		if ( '' === $found['status'] ) {
			$found['status'] = 'sent';
		}
		$found['imported'] = true;

		return array_merge( self::blank(), $found );
	}
}

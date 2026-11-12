<?php
defined( 'ABSPATH' ) || exit;

/**
 * Parcel statuses, registered as real WooCommerce order statuses.
 *
 * Each courier keeps its own wording (shown next to the order), and maps it
 * to one of these so filters and reports work the same for both.
 */
class BDC_Statuses {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_list' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'report_statuses' ) );
	}

	/**
	 * key => label. The WooCommerce status slug is "bdc-{key}".
	 */
	public static function all() {
		return array(
			'sent'      => __( 'Sent to Courier', 'bd-courier' ),
			'picked'    => __( 'Picked Up', 'bd-courier' ),
			'transit'   => __( 'In Transit', 'bd-courier' ),
			'out'       => __( 'Out for Delivery', 'bd-courier' ),
			'delivered' => __( 'Delivered', 'bd-courier' ),
			'partial'   => __( 'Partial Delivery', 'bd-courier' ),
			'hold'      => __( 'On Hold at Courier', 'bd-courier' ),
			'returned'  => __( 'Returned', 'bd-courier' ),
			'cancelled' => __( 'Courier Cancelled', 'bd-courier' ),
		);
	}

	/**
	 * Order statuses that are not about a parcel. "Confirmed" is for the team
	 * to mark an order as checked with the customer.
	 */
	public static function extra() {
		return array(
			'confirmed' => __( 'Confirmed', 'bd-courier' ),
		);
	}

	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function slug( $key ) {
		return 'bdc-' . $key;
	}

	public static function register() {
		foreach ( self::extra() + self::all() as $key => $label ) {
			register_post_status(
				'wc-' . self::slug( $key ),
				array(
					'label'                     => $label,
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'bd-courier' ), // phpcs:ignore WordPress.WP.I18n
				)
			);
		}
	}

	public static function add_to_list( $statuses ) {
		$result = array();

		// "Confirmed" sits with the everyday statuses, right after On hold.
		foreach ( $statuses as $slug => $label ) {
			$result[ $slug ] = $label;
			if ( 'wc-on-hold' === $slug ) {
				foreach ( self::extra() as $key => $extra_label ) {
					$result[ 'wc-' . self::slug( $key ) ] = $extra_label;
				}
			}
		}
		foreach ( self::extra() + self::all() as $key => $label ) {
			if ( ! isset( $result[ 'wc-' . self::slug( $key ) ] ) ) {
				$result[ 'wc-' . self::slug( $key ) ] = $label;
			}
		}

		return $result;
	}

	/** A delivered parcel counts as a paid order. */
	public static function paid_statuses( $statuses ) {
		$statuses[] = self::slug( 'delivered' );
		$statuses[] = self::slug( 'partial' );
		return $statuses;
	}

	/** Keep orders that are with the courier in the sales reports. */
	public static function report_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		foreach ( array( 'confirmed', 'sent', 'picked', 'transit', 'out', 'delivered', 'partial', 'hold' ) as $key ) {
			$statuses[] = self::slug( $key );
		}
		return array_unique( $statuses );
	}

	/**
	 * Move the order to the status that matches the parcel.
	 */
	public static function set_order_status( WC_Order $order, $key, $note = '' ) {
		if ( 'yes' !== BDC_Settings::get( 'general', 'update_order_status', 'yes' ) ) {
			return;
		}
		if ( '' === self::label( $key ) || $order->has_status( self::slug( $key ) ) ) {
			return;
		}
		$order->update_status( self::slug( $key ), $note );
	}
}

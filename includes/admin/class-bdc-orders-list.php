<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small additions to WooCommerce's own Orders list:
 * the customer's phone number under their name, and search by phone
 * number or consignment ID.
 */
class BDC_Orders_List {

	/** Marks the phone inside the buyer name so the script can restyle it (new order storage only). */
	const MARK = ' · ';

	public static function init() {
		// Classic order storage: add the phone under the name.
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'phone_classic' ), 20, 2 );
		// New order storage (HPOS): the name cell has no hook, so the phone rides along with the name.
		add_filter( 'woocommerce_admin_order_buyer_name', array( __CLASS__, 'phone_hpos' ), 20, 2 );

		// Search.
		add_filter( 'woocommerce_shop_order_search_fields', array( __CLASS__, 'search_fields' ) );
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( __CLASS__, 'search_fields' ) );
		add_filter( 'woocommerce_shop_order_search_results', array( __CLASS__, 'search_classic' ), 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( __CLASS__, 'search_hpos' ) );
	}

	private static function phone( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$phone = '' !== (string) $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
		return BDC_Courier::clean_phone( $phone );
	}

	/* ---- Phone under the name ---- */

	public static function phone_classic( $column, $post_id ) {
		if ( 'order_number' !== $column ) {
			return;
		}

		$phone = self::phone( wc_get_order( $post_id ) );
		if ( '' !== $phone ) {
			printf( '<a class="bdc-order-phone" href="tel:%1$s">%1$s</a>', esc_attr( $phone ) );
		}
	}

	public static function phone_hpos( $name, $order ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $name;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! function_exists( 'wc_get_page_screen_id' ) || wc_get_page_screen_id( 'shop-order' ) !== $screen->id || isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $name;
		}

		$phone = self::phone( $order );
		return '' !== $phone ? $name . self::MARK . $phone : $name;
	}

	/* ---- Search ---- */

	/** Also look in the phone number and the courier's consignment ID. */
	public static function search_fields( $fields ) {
		return array_values( array_unique( array_merge( (array) $fields, array( '_billing_phone', '_bdc_phone', '_bdc_consignment_id' ) ) ) );
	}

	/**
	 * The part of a search that identifies a phone number however it was
	 * typed or saved: 01712345678, +8801712345678 and 1712345678 all share
	 * their last ten digits.
	 */
	private static function phone_tail( $term ) {
		if ( preg_match( '/[a-z]/i', (string) $term ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', (string) $term );
		return strlen( $digits ) >= 6 ? substr( $digits, -10 ) : '';
	}

	public static function search_classic( $order_ids, $term ) {
		global $wpdb;

		$tail = self::phone_tail( $term );
		if ( '' === $tail ) {
			return $order_ids;
		}

		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( '_billing_phone', '_shipping_phone', '_bdc_phone' ) AND meta_value LIKE %s LIMIT 500",
				'%' . $wpdb->esc_like( $tail ) . '%'
			)
		);

		return array_values( array_unique( array_merge( array_map( 'absint', (array) $order_ids ), array_map( 'absint', (array) $found ) ) ) );
	}

	public static function search_hpos( $args ) {
		global $wpdb;

		$tail = isset( $args['s'] ) ? self::phone_tail( $args['s'] ) : '';
		if ( '' === $tail ) {
			return $args;
		}

		$table = $wpdb->prefix . 'wc_order_addresses';
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$table} WHERE phone LIKE %s LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $tail ) . '%'
			)
		);

		if ( $found ) {
			// Show exactly the orders with this number instead of WooCommerce's text search.
			unset( $args['s'] );
			$args['post__in'] = array_map( 'absint', $found );
		}

		return $args;
	}
}

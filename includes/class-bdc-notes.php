<?php
defined( 'ABSPATH' ) || exit;

/**
 * The team's private notes on an order.
 *
 * These are WooCommerce's own private order notes. Only notes typed by a
 * staff member count; automatic ones ("Order status changed…") are left out.
 */
class BDC_Notes {

	/** True while an order whose status is changing is being saved. */
	private static $status_changing = false;

	/**
	 * WooCommerce writes "Order status changed…" in the staff member's name
	 * when they change a status by hand. Those are marked here as automatic,
	 * so they are not mistaken for a note the staff member typed.
	 */
	public static function init() {
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'before_save' ) );
		// WooCommerce writes its status note right after saving, then announces the change.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'after_status_note' ), 0 );
		add_action( 'woocommerce_order_note_added', array( __CLASS__, 'note_added' ) );
	}

	public static function before_save( $order ) {
		self::$status_changing = $order instanceof WC_Order && array_key_exists( 'status', $order->get_changes() );
	}

	public static function after_status_note() {
		self::$status_changing = false;
	}

	public static function note_added( $note_id ) {
		if ( self::$status_changing ) {
			add_comment_meta( (int) $note_id, '_bdc_automatic', 1, true );
		}
	}

	/**
	 * @return array[] Newest first: text, by, date.
	 */
	public static function staff_notes( WC_Order $order, $limit = 10 ) {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'internal',
				'orderby'  => 'date_created_gmt',
				'order'    => 'DESC',
				'limit'    => 50,
			)
		);

		$result = array();
		foreach ( $notes as $note ) {
			if ( 'system' === $note->added_by || '' === trim( (string) $note->content ) || get_comment_meta( (int) $note->id, '_bdc_automatic', true ) ) {
				continue;
			}

			$result[] = array(
				'text' => trim( wp_strip_all_tags( (string) $note->content ) ),
				'by'   => (string) $note->added_by,
				'date' => $note->date_created ? wc_format_datetime( $note->date_created, get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ) : '',
			);

			if ( count( $result ) >= $limit ) {
				break;
			}
		}

		return $result;
	}

	/** The newest staff note, or '' when there is none. */
	public static function latest( WC_Order $order ) {
		$notes = self::staff_notes( $order, 1 );
		return $notes ? $notes[0]['text'] : '';
	}
}

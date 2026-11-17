<?php
defined( 'ABSPATH' ) || exit;

/**
 * What every courier must be able to do. Pathao and Steadfast each have
 * their own class and never share credentials or settings.
 */
abstract class BDC_Courier {

	/** Short machine name, e.g. "pathao". */
	abstract public function id();

	/** Name shown to staff, e.g. "Pathao". */
	abstract public function label();

	/** True when credentials have been entered. */
	abstract public function is_connected();

	/** @return true|WP_Error */
	abstract public function test_connection();

	/**
	 * Fields of the send form, prefilled from the order.
	 *
	 * @return array key => field definition.
	 */
	abstract public function form_fields( WC_Order $order );

	/**
	 * Clean and check what was typed in the form.
	 *
	 * @return array array( cleaned data, array( field => message ) )
	 */
	abstract public function check( array $data );

	/** Name of the form field that holds the cash to collect. */
	abstract public function cod_field();

	/**
	 * Form fields for editing a parcel that was already sent:
	 * the same form, filled with what was sent.
	 */
	public function edit_fields( WC_Order $order, array $details ) {
		$fields = $this->form_fields( $order );
		foreach ( $fields as $key => $field ) {
			if ( array_key_exists( $key, $details ) ) {
				$fields[ $key ]['value'] = $details[ $key ];
			}
		}
		return $fields;
	}

	/**
	 * Create the parcel at the courier.
	 *
	 * @return array|WP_Error consignment_id, tracking_code, courier_status, delivery_fee, cod.
	 *                        A WP_Error may carry array( 'fields' => array( key => message ) ).
	 */
	abstract public function create_parcel( WC_Order $order, array $data );

	/**
	 * Ask the courier for the current status.
	 *
	 * @return array|WP_Error courier_status, delivery_fee (optional).
	 */
	abstract public function fetch_status( array $shipment );

	/** Map the courier's status to one of BDC_Statuses::all(), or null to leave the order alone. */
	abstract public function map_status( $raw_status );

	/**
	 * How this customer did with this courier before.
	 *
	 * @return array|WP_Error|null total, delivered, cancelled (int or null when the courier
	 *                             does not say), ratio (delivered %, or null). Null when
	 *                             the courier has no such lookup.
	 */
	public function customer_record( $phone ) {
		return null;
	}

	/**
	 * The first web address in a courier reply that matches a pattern,
	 * searching nested values too. Used to pick up public tracking links.
	 */
	public static function find_link( $data, $pattern ) {
		if ( is_string( $data ) ) {
			return preg_match( $pattern, $data, $match ) ? esc_url_raw( $match[0] ) : '';
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $value ) {
				$link = self::find_link( $value, $pattern );
				if ( '' !== $link ) {
					return $link;
				}
			}
		}
		return '';
	}

	/** First whole number found under any of the given names, or null. */
	protected static function pick_number( array $data, array $names ) {
		foreach ( $names as $name ) {
			if ( isset( $data[ $name ] ) && is_numeric( $data[ $name ] ) ) {
				return (int) $data[ $name ];
			}
		}
		return null;
	}

	public function settings() {
		return BDC_Settings::section( $this->id() );
	}

	public function setting( $key, $fallback = '' ) {
		return BDC_Settings::get( $this->id(), $key, $fallback );
	}

	/** Courier status in readable form: "in_transit" becomes "In transit". */
	public function status_label( $raw_status ) {
		$text = trim( str_replace( array( '_', '-' ), ' ', (string) $raw_status ) );
		return '' === $text ? '' : ucfirst( strtolower( $text ) );
	}

	/** Public tracking page for the customer, or '' when there is none. */
	public function tracking_url( array $shipment ) {
		return '';
	}

	/** The parcel's page in the courier's merchant panel, or ''. */
	public function panel_url( array $shipment ) {
		return '';
	}

	/* ---- Helpers shared by both couriers ---- */

	protected function recipient_name( WC_Order $order ) {
		$name = trim( $order->get_formatted_shipping_full_name() );
		return '' !== $name ? $name : trim( $order->get_formatted_billing_full_name() );
	}

	protected function recipient_phone( WC_Order $order ) {
		$phone = $order->get_shipping_phone();
		return self::clean_phone( '' !== (string) $phone ? $phone : $order->get_billing_phone() );
	}

	protected function recipient_address( WC_Order $order ) {
		$type  = '' !== (string) $order->get_shipping_address_1() ? 'shipping' : 'billing';
		$parts = array();

		foreach ( array( 'address_1', 'address_2', 'city', 'postcode' ) as $part ) {
			$value = trim( (string) $order->{"get_{$type}_{$part}"}() );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ', ', $parts );
	}

	/** Paid orders collect nothing; everything else collects the order total. */
	protected function cod_amount( WC_Order $order ) {
		if ( $order->get_date_paid() && 'cod' !== $order->get_payment_method() ) {
			return 0;
		}
		return (int) round( (float) $order->get_total() );
	}

	protected function item_description( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		return implode( ', ', $lines );
	}

	/**
	 * Starting text for the rider note: this courier's default note from
	 * Settings, then the team's newest private note. When there is no private
	 * note, the customer's checkout note can be used instead.
	 */
	protected function rider_note( WC_Order $order, $use_customer_note = false ) {
		$note = BDC_Notes::latest( $order );

		if ( '' === $note && $use_customer_note ) {
			$note = trim( (string) $order->get_customer_note() );
		}

		$default = trim( (string) $this->setting( 'default_note' ) );

		return trim( implode( "\n", array_filter( array( $default, $note ), 'strlen' ) ) );
	}

	protected function item_quantity( WC_Order $order ) {
		return max( 1, (int) $order->get_item_count() );
	}

	/** Total weight in kg: product weight multiplied by quantity. */
	protected function total_weight( WC_Order $order ) {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->has_weight() ) {
				$total += (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) * (int) $item->get_quantity();
			}
		}
		if ( $total <= 0 ) {
			$total = (float) BDC_Settings::get( 'general', 'default_weight', '0.5' );
		}
		return round( $total, 2 );
	}

	/** Bangladeshi mobile number as 11 digits: 01XXXXXXXXX. */
	public static function clean_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '880' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 10 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = '0' . $digits;
		}

		return $digits;
	}

	public static function is_valid_phone( $phone ) {
		return (bool) preg_match( '/^01[3-9]\d{8}$/', (string) $phone );
	}

	/**
	 * Checks shared by both send forms.
	 *
	 * @return array key => message
	 */
	protected function validate_recipient( array $data, $name_key, $phone_key, $address_key ) {
		$errors = array();

		if ( '' === trim( (string) ( $data[ $name_key ] ?? '' ) ) ) {
			$errors[ $name_key ] = __( 'Please enter the customer name.', 'bd-courier' );
		}
		if ( ! self::is_valid_phone( $data[ $phone_key ] ?? '' ) ) {
			$errors[ $phone_key ] = __( 'Please enter a valid 11-digit mobile number, like 01712345678.', 'bd-courier' );
		}
		if ( strlen( trim( (string) ( $data[ $address_key ] ?? '' ) ) ) < 10 ) {
			$errors[ $address_key ] = __( 'Please enter the full delivery address (at least 10 characters).', 'bd-courier' );
		}

		return $errors;
	}

	protected function field_error( array $fields ) {
		return new WP_Error(
			'bdc_invalid',
			__( 'Please correct the highlighted fields and try again.', 'bd-courier' ),
			array( 'fields' => $fields )
		);
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer delivery record ("fraud score").
 *
 * For a phone number, asks each connected courier how many parcels this
 * customer received and refused, and adds the customer's history in this
 * shop. Each source is kept and shown separately under its own name.
 * Results are remembered for a day, because couriers limit these lookups.
 */
class BDC_Fraud {

	const CACHE_HOURS = 24;

	private static function cache_key( $phone ) {
		return 'bdc_score_' . $phone;
	}

	/** The phone number an order's customer is known by. */
	public static function order_phone( WC_Order $order ) {
		$phone = '' !== (string) $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
		return BDC_Courier::clean_phone( $phone );
	}

	/**
	 * A saved record, if there is a fresh one. Never contacts a courier.
	 *
	 * @return array|null
	 */
	public static function cached( $phone ) {
		$record = get_transient( self::cache_key( $phone ) );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * The record for a phone number, looked up now unless a fresh one is saved.
	 *
	 * @return array|WP_Error
	 */
	public static function get( $phone, $force = false ) {
		$phone = BDC_Courier::clean_phone( $phone );

		if ( ! BDC_Courier::is_valid_phone( $phone ) ) {
			return new WP_Error( 'bdc_phone', __( 'This order has no valid mobile number to check.', 'bd-courier' ) );
		}

		if ( ! $force ) {
			$record = self::cached( $phone );
			if ( $record ) {
				return $record;
			}
		}

		$record = array(
			'phone'      => $phone,
			'checked_at' => time(),
			'couriers'   => array(),
			'shop'       => self::shop_history( $phone ),
		);

		foreach ( self::lookup_all( $phone ) as $id => $result ) {
			if ( null === $result ) {
				continue;
			}
			$record['couriers'][ $id ] = is_wp_error( $result )
				? array( 'error' => $result->get_error_message() )
				: $result;
		}

		$record = array_merge( $record, self::summary( $record ) );
		set_transient( self::cache_key( $phone ), $record, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $record;
	}

	/**
	 * Asks every active courier about this number at the same time, so the
	 * check takes as long as the slowest courier instead of all of them added up.
	 *
	 * @return array courier id => record|WP_Error|null
	 */
	private static function lookup_all( $phone ) {
		$couriers = array_filter(
			BDC_Couriers::active(),
			function ( $courier ) {
				return $courier->is_active();
			}
		);
		$results  = array();
		$requests = array();

		foreach ( $couriers as $id => $courier ) {
			$request = method_exists( $courier, 'customer_record_request' ) ? $courier->customer_record_request( $phone ) : null;
			if ( is_wp_error( $request ) ) {
				$results[ $id ] = $request;
			} elseif ( is_array( $request ) ) {
				$requests[ $id ] = $request;
			} else {
				$results[ $id ] = null;
			}
		}

		$multiple = class_exists( 'WpOrg\\Requests\\Requests' ) ? array( 'WpOrg\\Requests\\Requests', 'request_multiple' ) : ( class_exists( 'Requests' ) ? array( 'Requests', 'request_multiple' ) : null );

		if ( count( $requests ) > 1 && $multiple ) {
			try {
				$responses = call_user_func(
					$multiple,
					$requests,
					array(
						'timeout'         => 20,
						'connect_timeout' => 10,
						'verify'          => true,
					)
				);
			} catch ( \Throwable $e ) {
				$responses = array();
			}

			foreach ( $requests as $id => $request ) {
				$response = $responses[ $id ] ?? null;
				$courier  = $couriers[ $id ];

				if ( is_object( $response ) && isset( $response->status_code ) && ! ( $response instanceof \Exception ) ) {
					$decoded = json_decode( (string) $response->body, true );
					$parsed  = array(
						'code' => (int) $response->status_code,
						'body' => is_array( $decoded ) ? $decoded : array(),
					);
					// An expired Pathao token, or anything unexpected: ask that courier again the normal way.
					$results[ $id ] = 401 === $parsed['code'] ? $courier->customer_record( $phone ) : $courier->parse_customer_record( $parsed );
				} else {
					$results[ $id ] = $courier->customer_record( $phone );
				}
			}
		} else {
			foreach ( $requests as $id => $request ) {
				$results[ $id ] = $couriers[ $id ]->customer_record( $phone );
			}
		}

		// Keep the couriers in their usual order.
		$ordered = array();
		foreach ( array_keys( $couriers ) as $id ) {
			if ( array_key_exists( $id, $results ) ) {
				$ordered[ $id ] = $results[ $id ];
			}
		}
		return $ordered;
	}

	/**
	 * This customer's past orders in this shop.
	 *
	 * @return array total, delivered, cancelled
	 */
	public static function shop_history( $phone ) {
		$ids = array();

		foreach ( array_unique( array( $phone, '+88' . $phone, '88' . $phone, substr( $phone, 1 ) ) ) as $variant ) {
			$found = wc_get_orders(
				array(
					'billing_phone' => $variant,
					'limit'         => 100,
					'return'        => 'ids',
					'type'          => 'shop_order',
					'status'        => array_keys( wc_get_order_statuses() ),
				)
			);
			$ids   = array_merge( $ids, (array) $found );
		}

		$history = array(
			'total'     => 0,
			'delivered' => 0,
			'cancelled' => 0,
		);

		foreach ( array_unique( $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$history['total'];
			if ( $order->has_status( array( 'completed', BDC_Statuses::slug( 'delivered' ), BDC_Statuses::slug( 'partial' ) ) ) ) {
				++$history['delivered'];
			} elseif ( $order->has_status( array( 'cancelled', 'refunded', 'failed', BDC_Statuses::slug( 'returned' ), BDC_Statuses::slug( 'cancelled' ) ) ) ) {
				++$history['cancelled'];
			}
		}

		return $history;
	}

	/**
	 * One overall reading from the couriers' numbers.
	 *
	 * @return array level (good|caution|risk|new|unknown), percent (int|null), parcels (int)
	 */
	private static function summary( array $record ) {
		$total     = 0;
		$delivered = 0;
		$ratios    = array();
		$ratings   = array();

		foreach ( $record['couriers'] as $data ) {
			if ( isset( $data['error'] ) ) {
				continue;
			}
			if ( ! empty( $data['rating'] ) ) {
				$ratings[] = self::rating_level( $data['rating'] );
			}
			if ( null !== $data['total'] && null !== $data['delivered'] ) {
				$total     += (int) $data['total'];
				$delivered += (int) $data['delivered'];
			} elseif ( null !== $data['ratio'] ) {
				$ratios[] = (float) $data['ratio'];
			}
		}

		$percent = null;
		if ( $total > 0 ) {
			$percent = (int) round( $delivered / $total * 100 );
		} elseif ( $ratios ) {
			$percent = (int) round( array_sum( $ratios ) / count( $ratios ) );
		}

		$has_answer = false;
		foreach ( $record['couriers'] as $data ) {
			$has_answer = $has_answer || ! isset( $data['error'] );
		}

		if ( ! $has_answer ) {
			$level = 'unknown';
		} elseif ( null === $percent && $ratings ) {
			// Only a rating word to go on: take the most cautious one.
			$order = array( 'risk', 'caution', 'new', 'good' );
			usort(
				$ratings,
				function ( $a, $b ) use ( $order ) {
					return array_search( $a, $order, true ) - array_search( $b, $order, true );
				}
			);
			$level = $ratings[0];
		} elseif ( null === $percent || ( $total > 0 && $total < 3 ) ) {
			$level = 'new';
		} elseif ( $percent >= 80 ) {
			$level = 'good';
		} elseif ( $percent >= 50 ) {
			$level = 'caution';
		} else {
			$level = 'risk';
		}

		return array(
			'level'   => $level,
			'percent' => $percent,
			'parcels' => $total,
		);
	}

	/** Pathao's rating word as one of our levels. */
	public static function rating_level( $rating ) {
		$rating = (string) $rating;
		if ( false !== strpos( $rating, 'fraud' ) || false !== strpos( $rating, 'risk' ) || false !== strpos( $rating, 'bad' ) ) {
			return 'risk';
		}
		if ( false !== strpos( $rating, 'good' ) || false !== strpos( $rating, 'excellent' ) || false !== strpos( $rating, 'trusted' ) ) {
			return 'good';
		}
		if ( false !== strpos( $rating, 'average' ) || false !== strpos( $rating, 'moderate' ) ) {
			return 'caution';
		}
		return 'new';
	}

	/** The rating word in readable form: "new_customer" becomes "New customer". */
	public static function rating_text( $rating ) {
		return ucfirst( trim( str_replace( array( '_', '-' ), ' ', (string) $rating ) ) );
	}

	/** The shortest wording, for the narrow Orders column. The coloured dot beside it carries the level. */
	public static function short_label( array $record ) {
		if ( in_array( $record['level'], array( 'good', 'caution', 'risk' ), true ) ) {
			if ( null === $record['percent'] ) {
				$words = array(
					'good'    => __( 'Good customer', 'bd-courier' ),
					'caution' => __( 'Be careful', 'bd-courier' ),
					'risk'    => __( 'High risk', 'bd-courier' ),
				);
				return $words[ $record['level'] ];
			}
			/* translators: %d: percentage */
			return sprintf( __( '%d%% delivered', 'bd-courier' ), $record['percent'] );
		}
		return 'new' === $record['level'] ? __( 'New customer', 'bd-courier' ) : __( 'No record', 'bd-courier' );
	}

	/** Short wording for a level, e.g. on the order list. */
	public static function level_label( array $record ) {
		switch ( $record['level'] ) {
			case 'good':
				if ( null === $record['percent'] ) {
					return __( 'Good record', 'bd-courier' );
				}
				/* translators: %d: percentage */
				return sprintf( __( 'Good record · %d%% delivered', 'bd-courier' ), $record['percent'] );
			case 'caution':
				if ( null === $record['percent'] ) {
					return __( 'Be careful', 'bd-courier' );
				}
				/* translators: %d: percentage */
				return sprintf( __( 'Be careful · %d%% delivered', 'bd-courier' ), $record['percent'] );
			case 'risk':
				if ( null === $record['percent'] ) {
					return __( 'High risk', 'bd-courier' );
				}
				/* translators: %d: percentage */
				return sprintf( __( 'High risk · %d%% delivered', 'bd-courier' ), $record['percent'] );
			case 'new':
				return __( 'New customer · little history', 'bd-courier' );
			default:
				return __( 'No courier record available', 'bd-courier' );
		}
	}
}

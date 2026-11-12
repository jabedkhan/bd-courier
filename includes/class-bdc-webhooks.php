<?php
defined( 'ABSPATH' ) || exit;

/**
 * Status updates pushed by the couriers. Each courier has its own address
 * and its own secret.
 */
class BDC_Webhooks {

	/** Fixed value Pathao expects back when it verifies a webhook address. */
	const PATHAO_VERIFY_HEADER = 'f3992ecc-59da-4cbe-a049-a13da2018d51';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url( $courier ) {
		return rest_url( 'bd-courier/v1/' . $courier . '/webhook' );
	}

	public static function routes() {
		register_rest_route(
			'bd-courier/v1',
			'/pathao/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pathao' ),
				'permission_callback' => array( __CLASS__, 'pathao_allowed' ),
			)
		);

		register_rest_route(
			'bd-courier/v1',
			'/steadfast/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'steadfast' ),
				'permission_callback' => array( __CLASS__, 'steadfast_allowed' ),
			)
		);
	}

	/* ---- Pathao ---- */

	public static function pathao_allowed( WP_REST_Request $request ) {
		$secret = BDC_Settings::get( 'pathao', 'webhook_secret' );
		$given  = (string) $request->get_header( 'x_pathao_signature' );

		return '' !== $secret && '' !== $given && hash_equals( $secret, $given );
	}

	public static function pathao( WP_REST_Request $request ) {
		$event  = (string) $request->get_param( 'event' );
		$status = (string) $request->get_param( 'order_status' );

		if ( '' === $status && 0 === strpos( $event, 'order.' ) ) {
			$status = self::pathao_status_from_event( $event );
		}

		$order = BDC_Shipment::find_order(
			'pathao',
			(string) $request->get_param( 'consignment_id' ),
			absint( $request->get_param( 'merchant_order_id' ) )
		);

		if ( $order && '' !== $status ) {
			BDC_Shipment::update_status(
				$order,
				$status,
				array(
					'delivery_fee'   => $request->get_param( 'delivery_fee' ),
					'consignment_id' => $request->get_param( 'consignment_id' ),
				)
			);
			update_option( 'bdc_pathao_last_webhook', time(), false );
		}

		// Pathao expects 202 and this header, also for its "is this address alive" test call.
		$response = new WP_REST_Response( array( 'message' => 'Accepted' ), 202 );
		$response->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', self::PATHAO_VERIFY_HEADER );

		return $response;
	}

	private static function pathao_status_from_event( $event ) {
		$map = array(
			'order.created'                   => 'Pending',
			'order.pickup-requested'          => 'Pickup_Requested',
			'order.assigned-for-pickup'       => 'Assigned_for_Pickup',
			'order.picked'                    => 'Picked',
			'order.pickup-failed'             => 'Pickup_Failed',
			'order.pickup-cancelled'          => 'Pickup_Cancelled',
			'order.at-the-sorting-hub'        => 'At_the_Sorting_HUB',
			'order.in-transit'                => 'In_Transit',
			'order.received-at-last-mile-hub' => 'Received_at_Last_Mile_HUB',
			'order.assigned-for-delivery'     => 'Assigned_for_Delivery',
			'order.delivered'                 => 'Delivered',
			'order.partial-delivery'          => 'Partial_Delivery',
			'order.returned'                  => 'Return',
			'order.delivery-failed'           => 'Delivery_Failed',
			'order.on-hold'                   => 'On_Hold',
			'order.paid-return'               => 'Paid_Return',
			'order.exchanged'                 => 'Exchange',
		);

		return isset( $map[ $event ] ) ? $map[ $event ] : '';
	}

	/* ---- Steadfast ---- */

	public static function steadfast_allowed( WP_REST_Request $request ) {
		$token = BDC_Settings::get( 'steadfast', 'webhook_token' );
		$given = '';

		if ( preg_match( '/Bearer\s+(.+)$/i', (string) $request->get_header( 'authorization' ), $match ) ) {
			$given = trim( $match[1] );
		}

		return '' !== $token && '' !== $given && hash_equals( $token, $given );
	}

	public static function steadfast( WP_REST_Request $request ) {
		$consignment_id = (string) $request->get_param( 'consignment_id' );
		$status         = (string) $request->get_param( 'status' );
		$order          = BDC_Shipment::find_order( 'steadfast', $consignment_id );

		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'No order found for this consignment.',
				),
				404
			);
		}

		BDC_Shipment::update_status(
			$order,
			$status,
			array(
				'delivery_fee' => $request->get_param( 'delivery_charge' ),
				'message'      => $request->get_param( 'tracking_message' ),
			)
		);
		update_option( 'bdc_steadfast_last_webhook', time(), false );

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => 'Webhook received successfully.',
			),
			200
		);
	}
}

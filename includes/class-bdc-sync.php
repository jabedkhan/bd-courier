<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scheduled status check.
 *
 * Webhooks are the fast way to learn that a parcel moved, but a webhook can
 * be missed. On a schedule, this asks each courier about the parcels that are
 * still on their way, a few at a time, starting with the ones that have gone
 * longest without news. It only reads statuses; it never sends a parcel.
 */
class BDC_Sync {

	const HOOK      = 'bdc_sync_parcels';
	const GROUP     = 'bd-courier';
	const BATCH     = 20;
	const MAX_AGE   = 45; // Days after sending that a parcel is still followed.
	const TIME_LIMIT = 20; // Seconds per run.

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/** Choices for "how often", in minutes. 0 turns the check off. */
	public static function intervals() {
		return array(
			'0'   => __( 'Off', 'bd-courier' ),
			'30'  => __( 'Every 30 minutes', 'bd-courier' ),
			'60'  => __( 'Every hour', 'bd-courier' ),
			'180' => __( 'Every 3 hours', 'bd-courier' ),
			'360' => __( 'Every 6 hours', 'bd-courier' ),
		);
	}

	public static function interval() {
		$minutes = (string) BDC_Settings::get( 'general', 'sync_interval', '60' );
		return isset( self::intervals()[ $minutes ] ) ? (int) $minutes : 60;
	}

	/** Keep the schedule in step with the setting. */
	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$minutes   = self::interval();
		$scheduled = (int) get_option( 'bdc_sync_scheduled', -1 );

		if ( $minutes === $scheduled && ( 0 === $minutes || as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		if ( $minutes > 0 ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $minutes * MINUTE_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
		update_option( 'bdc_sync_scheduled', $minutes, false );
	}

	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		delete_option( 'bdc_sync_scheduled' );
	}

	/** Parcel statuses that can still change. */
	public static function open_statuses() {
		return array( 'sent', 'picked', 'transit', 'out', 'hold' );
	}

	/**
	 * Check one batch of parcels.
	 *
	 * @param int $min_gap Seconds a parcel must have gone without a check. 0 checks regardless.
	 * @return array checked, changed, failed
	 */
	public static function run( $min_gap = null ) {
		$min_gap = is_numeric( $min_gap ) ? (int) $min_gap : (int) ( self::interval() * MINUTE_IN_SECONDS / 2 );
		$started = time();
		$summary = array(
			'time'    => $started,
			'checked' => 0,
			'changed' => 0,
			'failed'  => 0,
		);

		$orders = BDC_Shipment::query_orders(
			array(
				'relation' => 'AND',
				array(
					'key'     => '_bdc_status',
					'value'   => self::open_statuses(),
					'compare' => 'IN',
				),
				array(
					'key'     => '_bdc_checked_at',
					'value'   => $started - $min_gap,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_bdc_sent_at',
					'value'   => $started - self::MAX_AGE * DAY_IN_SECONDS,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
			array(
				'limit'   => self::BATCH,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		$paused = array(); // Couriers that refused our credentials in this run.

		foreach ( $orders as $order ) {
			if ( time() - $started > self::TIME_LIMIT ) {
				break;
			}

			$shipment = BDC_Shipment::get( $order );
			$courier  = $shipment ? BDC_Couriers::get( $shipment['courier'] ) : null;

			if ( ! $shipment || ! $courier || isset( $paused[ $shipment['courier'] ] ) ) {
				continue;
			}
			if ( ! $courier->is_connected() || '' === $shipment['consignment_id'] ) {
				BDC_Shipment::mark_checked( $order );
				continue;
			}

			$result = $courier->fetch_status( $shipment );
			++$summary['checked'];

			if ( is_wp_error( $result ) ) {
				++$summary['failed'];
				if ( in_array( $result->get_error_code(), array( 'bdc_auth', 'bdc_not_connected', 'bdc_network' ), true ) ) {
					$paused[ $shipment['courier'] ] = true;
				} else {
					// This parcel has a problem of its own; move on and come back to it later.
					BDC_Shipment::mark_checked( $order );
				}
				continue;
			}

			$before = $shipment['courier_status'];
			$after  = BDC_Shipment::update_status( $order, $result['courier_status'], $result );
			if ( $after && $after['courier_status'] !== $before ) {
				++$summary['changed'];
			}
		}

		update_option( 'bdc_sync_last', $summary, false );

		return $summary;
	}
}

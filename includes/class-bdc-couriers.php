<?php
defined( 'ABSPATH' ) || exit;

/**
 * The list of couriers the plugin knows about.
 */
class BDC_Couriers {

	private static $couriers = null;

	/**
	 * @return BDC_Courier[] id => courier
	 */
	public static function all() {
		if ( null === self::$couriers ) {
			self::$couriers = array(
				'pathao'    => new BDC_Pathao(),
				'steadfast' => new BDC_Steadfast(),
			);
		}
		return self::$couriers;
	}

	/**
	 * Couriers that are turned on and connected.
	 *
	 * @return BDC_Courier[] id => courier
	 */
	public static function active() {
		return array_filter(
			self::all(),
			function ( $courier ) {
				return $courier->is_active();
			}
		);
	}

	/**
	 * Couriers to show on screens such as Reports and Shipments:
	 * the active ones, or every courier while none is set up yet.
	 *
	 * @return BDC_Courier[] id => courier
	 */
	public static function shown() {
		$active = self::active();
		return $active ? $active : self::all();
	}

	/**
	 * Names of the couriers in use, for sentences: "Steadfast" or "Steadfast or Pathao".
	 */
	public static function names() {
		$names = array();
		foreach ( self::shown() as $courier ) {
			$names[] = $courier->label();
		}
		sort( $names );
		$names = array_reverse( $names );
		return implode( ' ' . __( 'or', 'bd-courier' ) . ' ', $names );
	}

	/**
	 * @return BDC_Courier|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
}

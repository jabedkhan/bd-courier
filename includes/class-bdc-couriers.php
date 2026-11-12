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
	 * @return BDC_Courier|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
}

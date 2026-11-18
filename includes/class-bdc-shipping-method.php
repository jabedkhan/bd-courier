<?php
defined( 'ABSPATH' ) || exit;

/**
 * The delivery areas of the simple checkout as a WooCommerce delivery method.
 * It needs no setup in WooCommerce: names and charges come from
 * BD Courier → Settings → Checkout.
 */
class BDC_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = 'bdc_area';
		$this->method_title       = __( 'BD Courier delivery areas', 'bd-courier' );
		$this->method_description = __( 'Inside Dhaka, sub Dhaka and outside Dhaka, with the charges set in BD Courier → Settings → Checkout.', 'bd-courier' );
		$this->title              = $this->method_title;
		$this->enabled            = 'yes';
		$this->supports           = array();
	}

	public function is_available( $package ) {
		return true;
	}

	public function calculate_shipping( $package = array() ) {
		foreach ( BDC_Checkout::areas() as $key => $area ) {
			$this->add_rate(
				array(
					'id'    => 'bdc_area:' . $key,
					'label' => $area['label'],
					'cost'  => $area['charge'],
				)
			);
		}
	}
}

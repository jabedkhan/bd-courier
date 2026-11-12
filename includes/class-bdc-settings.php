<?php
defined( 'ABSPATH' ) || exit;

/**
 * All plugin settings live in one option, split by section:
 * general, pathao, steadfast. The two couriers never share values.
 */
class BDC_Settings {

	const OPTION = 'bdc_settings';

	public static function defaults() {
		return array(
			'general'   => array(
				'update_order_status' => 'yes',
				'default_weight'      => '0.5',
				'sync_interval'       => '60',
				'customer_tracking'   => 'yes',
				'bulk_statuses'       => 'confirmed,delivered,returned',
			),
			'brand'     => array(
				'name'   => '',
				'accent' => '',
			),
			'print'     => array(
				'business_name'    => '',
				'business_phone'   => '',
				'business_address' => '',
				'logo_id'          => '',
				'footer_note'      => '',
			),
			'pathao'    => array(
				'environment'    => 'live',
				'client_id'      => '',
				'client_secret'  => '',
				'webhook_secret' => '',
				'store_id'       => '',
				'delivery_type'  => '48',
				'item_type'      => '2',
			),
			'steadfast' => array(
				'api_key'       => '',
				'secret_key'    => '',
				'webhook_token' => '',
				'delivery_type' => '0',
				'send_note'     => 'yes',
			),
		);
	}

	public static function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings = array();

		foreach ( self::defaults() as $section => $defaults ) {
			$values               = isset( $saved[ $section ] ) && is_array( $saved[ $section ] ) ? $saved[ $section ] : array();
			$settings[ $section ] = array_merge( $defaults, array_intersect_key( $values, $defaults ) );
		}

		return $settings;
	}

	public static function section( $section ) {
		$all = self::all();
		return isset( $all[ $section ] ) ? $all[ $section ] : array();
	}

	public static function get( $section, $key, $fallback = '' ) {
		$values = self::section( $section );
		return isset( $values[ $key ] ) && '' !== $values[ $key ] ? $values[ $key ] : $fallback;
	}

	public static function update_section( $section, array $values ) {
		$all      = self::all();
		$defaults = self::defaults();

		if ( ! isset( $defaults[ $section ] ) ) {
			return;
		}

		$all[ $section ] = array_merge( $all[ $section ], array_intersect_key( $values, $defaults[ $section ] ) );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Secret used to check that a webhook call really comes from the courier.
	 * Created on first use so every site has its own.
	 */
	public static function webhook_secret( $courier ) {
		$key    = 'pathao' === $courier ? 'webhook_secret' : 'webhook_token';
		$secret = self::get( $courier, $key );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false );
			self::update_section( $courier, array( $key => $secret ) );
		}

		return $secret;
	}
}

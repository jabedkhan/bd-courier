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
			'checkout'  => array(
				'enabled'        => 'no',
				'label_name'     => 'আপনার নাম',
				'label_phone'    => 'মোবাইল নম্বর',
				'label_address'  => 'সম্পূর্ণ ঠিকানা',
				'label_area'     => 'ডেলিভারি এলাকা',
				'label_notes'    => 'অর্ডার নোট',
				'heading_details' => 'আপনার তথ্য',
				'heading_order'  => 'আপনার অর্ডার',
				'button_text'    => 'অর্ডার কনফার্ম করুন',
				'time_inside'    => '১-২ দিন',
				'time_sub'       => '২-৩ দিন',
				'time_outside'   => '৩-৫ দিন',
				'trust_points'   => "ক্যাশ অন ডেলিভারি — পণ্য হাতে পেয়ে টাকা দিন\nসারা বাংলাদেশে দ্রুত ডেলিভারি\nসহজ রিটার্ন ও এক্সচেঞ্জ",
				'help_text'      => 'অর্ডার করতে সমস্যা হচ্ছে? আমাদের মেসেজ দিন',
				'thankyou_title' => 'ধন্যবাদ! আপনার অর্ডারটি গ্রহণ করা হয়েছে',
				'thankyou_text'  => 'আমাদের একজন প্রতিনিধি শীঘ্রই আপনাকে কল করে অর্ডারটি কনফার্ম করবেন।',
				'thankyou_steps' => "অর্ডার কনফার্ম করতে আমরা আপনাকে কল করব\nকুরিয়ারে আপনার পার্সেল পাঠানো হবে\nপণ্য হাতে পেয়ে মূল্য পরিশোধ করুন",
				'area_inside'    => 'ঢাকা সিটির ভেতরে',
				'area_sub'       => 'ঢাকা সাব-সিটি',
				'area_outside'   => 'ঢাকা সিটির বাহিরে',
				'charge_inside'  => '70',
				'charge_sub'     => '100',
				'charge_outside' => '120',
				'order_notes'    => 'yes',
			),
			'chat'      => array(
				'enabled'   => 'no',
				'whatsapp'  => '',
				'messenger' => '',
				'message'   => '',
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
				'enabled'        => 'yes',
				'environment'    => 'live',
				'client_id'      => '',
				'client_secret'  => '',
				'username'       => '',
				'password'       => '',
				'webhook_secret' => '',
				'store_id'       => '',
				'delivery_type'  => '48',
				'item_type'      => '2',
				'default_note'   => '',
			),
			'steadfast' => array(
				'enabled'       => 'yes',
				'api_key'       => '',
				'secret_key'    => '',
				'webhook_token' => '',
				'delivery_type' => '0',
				'send_note'     => 'yes',
				'default_note'  => '',
			),
		);
	}

	/** Settings for this request; they are read many times per page. */
	private static $cache = null;

	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings = array();

		foreach ( self::defaults() as $section => $defaults ) {
			$values               = isset( $saved[ $section ] ) && is_array( $saved[ $section ] ) ? $saved[ $section ] : array();
			$settings[ $section ] = array_merge( $defaults, array_intersect_key( $values, $defaults ) );
		}

		self::$cache = $settings;
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
		// Loaded with WordPress's other options: the settings are needed on most requests.
		update_option( self::OPTION, $all, true );
		self::$cache = null;
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

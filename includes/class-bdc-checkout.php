<?php
defined( 'ABSPATH' ) || exit;

/**
 * Simple checkout for Bangladesh: name, mobile number and address, and a
 * delivery area (inside Dhaka, sub Dhaka, outside Dhaka) whose charge is
 * added to the order. Labels, area names and charges come from settings.
 */
class BDC_Checkout {

	const AREAS = array( 'inside', 'sub', 'outside' );

	public static function init() {
		// Shown on orders even if the simple checkout is later switched off.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_area' ) );

		if ( 'yes' !== BDC_Settings::get( 'checkout', 'enabled', 'no' ) ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 9999 );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'billing_fields' ), 9999 );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 9999 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rates' ), 9999, 2 );
		add_filter( 'woocommerce_cart_needs_shipping', array( __CLASS__, 'needs_shipping' ), 9999 );
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'define_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
		add_filter( 'transient_wc_shipping_method_count', array( __CLASS__, 'method_count' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'packages' ), 9999 );
		add_filter( 'default_checkout_billing_country', array( __CLASS__, 'country' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_area' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Top of the right-hand column on desktop; on phones the columns stack, so it follows the address.
		add_action( 'woocommerce_before_order_notes', array( __CLASS__, 'area_picker' ), 5 );
		add_filter( 'gettext_woocommerce', array( __CLASS__, 'texts' ), 20, 2 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'button_text' ), 9999 );
		add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 9999 );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false', 9999 );
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_filter( 'woocommerce_thankyou_order_received_text', '__return_empty_string', 9999 );
	}

	private static function setting( $key ) {
		$value    = BDC_Settings::get( 'checkout', $key, '' );
		$defaults = BDC_Settings::defaults()['checkout'];
		return '' !== $value ? $value : $defaults[ $key ];
	}

	public static function country() {
		return 'BD';
	}

	/** Area key => array( label, charge ). */
	public static function areas() {
		$areas = array();
		foreach ( self::AREAS as $key ) {
			$areas[ $key ] = array(
				'label'  => self::setting( 'area_' . $key ),
				'charge' => (float) self::setting( 'charge_' . $key ),
				'time'   => (string) BDC_Settings::get( 'checkout', 'time_' . $key, BDC_Settings::defaults()['checkout'][ 'time_' . $key ] ),
			);
		}
		return $areas;
	}

	/** Only name, mobile number and address; everything else is removed or filled in quietly. */
	public static function billing_fields( $fields ) {
		$keep = array(
			'billing_first_name' => array(
				'label'        => self::setting( 'label_name' ),
				'required'     => true,
				'class'        => array( 'form-row-wide' ),
				'autocomplete' => 'name',
				'priority'     => 10,
			),
			'billing_phone'      => array(
				'label'        => self::setting( 'label_phone' ),
				'required'     => true,
				'type'         => 'tel',
				'class'        => array( 'form-row-wide' ),
				'placeholder'  => '01XXXXXXXXX',
				'validate'     => array( 'phone' ),
				'autocomplete' => 'tel',
				'priority'     => 20,
			),
			'billing_address_1'  => array(
				'label'        => self::setting( 'label_address' ),
				'required'     => true,
				'class'        => array( 'form-row-wide' ),
				'placeholder'  => '',
				'autocomplete' => 'street-address',
				'priority'     => 30,
			),
		);

		$result = array();
		foreach ( $keep as $key => $field ) {
			$result[ $key ] = array_merge( isset( $fields[ $key ] ) ? $fields[ $key ] : array(), $field );
		}

		// Country stays (WooCommerce needs it for delivery charges) but is fixed to Bangladesh and hidden.
		$result['billing_country'] = array(
			'type'     => 'hidden',
			'default'  => 'BD',
			'required' => false,
			'priority' => 90,
			'class'    => array( 'bdc-hidden-field' ),
		);

		return $result;
	}

	public static function fields( $fields ) {
		$fields['billing']  = self::billing_fields( isset( $fields['billing'] ) ? $fields['billing'] : array() );
		$fields['shipping'] = array();

		// Order notes move into the details box, under the address. WooCommerce still saves
		// them as the customer note because the field keeps its usual name.
		$notes = isset( $fields['order']['order_comments'] ) ? $fields['order']['order_comments'] : array();
		unset( $fields['order']['order_comments'] );

		if ( 'yes' === BDC_Settings::get( 'checkout', 'order_notes', 'yes' ) ) {
			$fields['billing']['order_comments'] = array_merge(
				$notes,
				array(
					'type'        => 'textarea',
					'label'       => self::setting( 'label_notes' ),
					'placeholder' => '',
					'required'    => false,
					'class'       => array( 'form-row-wide', 'notes' ),
					'priority'    => 40,
				)
			);
		}

		return $fields;
	}

	/** The checkout headings in the shop's own words (only on the checkout page). */
	public static function texts( $translation, $text ) {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $translation;
		}
		switch ( $text ) {
			case 'Billing details':
			case 'Billing &amp; Shipping':
				return self::setting( 'heading_details' );
			case 'Your order':
				return self::setting( 'heading_order' );
			case 'optional':
				return 'ঐচ্ছিক';
			case 'Have a coupon?':
				return 'কুপন কোড আছে?';
			case 'Click here to enter your code':
				return 'এখানে ক্লিক করে কোড দিন';
			case 'If you have a coupon code, please apply it below.':
				return 'আপনার কুপন কোডটি নিচে লিখে প্রয়োগ করুন।';
			case 'Coupon code':
				return 'কুপন কোড';
			case 'Apply coupon':
				return 'প্রয়োগ করুন';
			case 'Product':
				return 'পণ্য';
			case 'Subtotal':
				return 'সাবটোটাল';
			case 'Shipping':
			case 'Shipment':
			case 'Shipment %d':
				return 'ডেলিভারি চার্জ';
			case 'Total':
				return 'মোট';
		}
		return $translation;
	}

	public static function package_name() {
		return 'ডেলিভারি চার্জ';
	}

	public static function button_text() {
		return self::setting( 'button_text' );
	}

	/** The three delivery areas take the place of any other delivery options. */
	public static function rates( $rates, $package ) {
		$ours = array();
		foreach ( self::areas() as $key => $area ) {
			$rate = new WC_Shipping_Rate( 'bdc_area:' . $key, $area['label'], $area['charge'], array(), 'bdc_area' );
			$ours[ $rate->get_id() ] = $rate;
		}
		return $ours;
	}

	/**
	 * WooCommerce only offers delivery when it can count at least one delivery
	 * method. Our areas are registered as one, so delivery works even when no
	 * shipping zones are set up in WooCommerce.
	 */
	public static function define_method() {
		if ( class_exists( 'BDC_Shipping_Method' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			return;
		}
		require_once BDC_DIR . 'includes/class-bdc-shipping-method.php';
	}

	public static function register_method( $methods ) {
		self::define_method();
		if ( class_exists( 'BDC_Shipping_Method' ) ) {
			$methods['bdc_area'] = 'BDC_Shipping_Method';
		}
		return $methods;
	}

	/** WooCommerce caches its method count; make sure ours is part of it. */
	public static function method_count( $value ) {
		if ( is_array( $value ) && isset( $value['legacy'] ) && (int) $value['legacy'] < 1 ) {
			$value['legacy'] = 1;
		}
		return $value;
	}

	/**
	 * WooCommerce remembers delivery options per cart. Adding the area names and
	 * charges to each package means an edit in settings shows up straight away.
	 */
	public static function packages( $packages ) {
		$version = md5( wp_json_encode( self::areas() ) );
		foreach ( $packages as $index => $package ) {
			$packages[ $index ]['bdc_areas'] = $version;
			if ( empty( $packages[ $index ]['destination']['country'] ) ) {
				$packages[ $index ]['destination']['country'] = 'BD';
			}
		}
		return $packages;
	}

	/**
	 * WooCommerce skips delivery entirely when no shipping method is set up in its
	 * settings. Our areas do not need one, so physical products always get them.
	 */
	public static function needs_shipping( $needs ) {
		if ( $needs || ! WC()->cart ) {
			return $needs;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product && $item['data']->needs_shipping() ) {
				return true;
			}
		}
		return $needs;
	}

	/** A Bangladeshi mobile number: 11 digits starting with 013 to 019 (88 or +88 in front is fine). */
	public static function validate( $data, $errors ) {
		$phone = preg_replace( '/[^\d+]/', '', (string) ( $data['billing_phone'] ?? '' ) );
		$phone = preg_replace( '/^(\+?88)/', '', $phone );

		if ( '' !== $phone && ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) {
			$errors->add( 'validation', __( 'Please enter a valid 11-digit mobile number, for example 01712345678.', 'bd-courier' ) );
		}
	}

	/** Remember the chosen area on the order (also visible in the shipping line). */
	public static function save_area( $order, $data ) {
		$chosen = isset( $data['shipping_method'] ) ? (array) $data['shipping_method'] : array();
		foreach ( $chosen as $method ) {
			if ( 0 === strpos( (string) $method, 'bdc_area:' ) ) {
				$key = substr( (string) $method, 9 );
				if ( in_array( $key, self::AREAS, true ) ) {
					$order->update_meta_data( '_bdc_delivery_area', $key );
				}
			}
		}
		if ( ! $order->get_billing_country() ) {
			$order->set_billing_country( 'BD' );
		}
	}

	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'bdc-checkout', BDC_URL . 'assets/css/checkout.css', array(), BDC_VERSION );
		wp_add_inline_style( 'bdc-checkout', '.bdc-checkout-on{--bdc-co-accent:' . BDC_Brand::accent() . ';}' );
		if ( ! is_order_received_page() ) {
			wp_enqueue_script( 'bdc-checkout', BDC_URL . 'assets/js/checkout.js', array( 'jquery' ), BDC_VERSION, true );
		}
		add_filter(
			'body_class',
			function ( $classes ) {
				$classes[] = 'bdc-checkout-on';
				return $classes;
			}
		);
	}

	/** The delivery areas as cards: right column on desktop, straight after the address on phones. */
	public static function area_picker() {
		$chosen = '';
		if ( WC()->session ) {
			$methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
			$chosen  = isset( $methods[0] ) && 0 === strpos( (string) $methods[0], 'bdc_area:' ) ? substr( (string) $methods[0], 9 ) : '';
		}
		$chosen = '' !== $chosen ? $chosen : 'inside';
		?>
		<fieldset class="bdc-areas">
			<legend class="bdc-areas__title"><?php echo esc_html( self::setting( 'label_area' ) ); ?> <abbr class="required" title="<?php esc_attr_e( 'required', 'bd-courier' ); ?>">*</abbr></legend>
			<div class="bdc-areas__list">
				<?php foreach ( self::areas() as $key => $area ) : ?>
					<label class="bdc-area">
						<input type="radio" name="bdc_area" value="<?php echo esc_attr( $key ); ?>" <?php checked( $chosen, $key ); ?>>
						<span class="bdc-area__dot" aria-hidden="true"></span>
						<span class="bdc-area__text">
							<span class="bdc-area__name"><?php echo esc_html( $area['label'] ); ?></span>
							<?php if ( '' !== $area['time'] ) : ?>
								<span class="bdc-area__time"><?php echo esc_html( $area['time'] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="bdc-area__price"><?php echo wp_kses_post( wc_price( $area['charge'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php
		self::reassurance();
	}

	/** Lines of a multi-line setting. */
	private static function lines( $key ) {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) self::setting( $key ) ) ) ) );
	}

	/** Messenger / WhatsApp links from the Chat buttons settings, with an optional message. */
	public static function help_links( $message = '' ) {
		$chat  = BDC_Settings::section( 'chat' );
		$text  = '' !== $message ? '?text=' . rawurlencode( $message ) : '';
		$links = array();
		if ( '' !== $chat['whatsapp'] ) {
			$links['whatsapp'] = array( 'WhatsApp', 'https://wa.me/' . rawurlencode( $chat['whatsapp'] ) . $text );
		}
		if ( '' !== $chat['messenger'] ) {
			$links['messenger'] = array( 'Messenger', 'https://m.me/' . rawurlencode( $chat['messenger'] ) . $text );
		}
		return $links;
	}

	private static function help_buttons( $message = '' ) {
		foreach ( self::help_links( $message ) as $key => $link ) {
			printf(
				'<a class="bdc-help__btn bdc-help__btn--%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
				esc_attr( $key ),
				esc_attr( $link[1] ), // Built here from encoded parts on a fixed https host.
				esc_html( $link[0] )
			);
		}
	}

	/** Trust points and the help line under the delivery areas. */
	private static function reassurance() {
		$points = self::lines( 'trust_points' );
		$links  = self::help_links();
		if ( ! $points && ! $links ) {
			return;
		}
		?>
		<div class="bdc-trust">
			<?php if ( $points ) : ?>
				<ul class="bdc-trust__list">
					<?php foreach ( $points as $point ) : ?>
						<li><span class="bdc-trust__tick" aria-hidden="true"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span><?php echo esc_html( $point ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $links ) : ?>
				<div class="bdc-help">
					<span class="bdc-help__text"><?php echo esc_html( self::setting( 'help_text' ) ); ?></span>
					<span class="bdc-help__buttons"><?php self::help_buttons(); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Thank-you page: a calm confirmation card with the key facts and what happens next. */
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->has_status( 'failed' ) ) {
			return;
		}
		$steps = self::lines( 'thankyou_steps' );
		$area  = self::order_area( $order );
		$facts = array(
			'অর্ডার নম্বর' => '#' . $order->get_order_number(),
			'মোট'          => wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			'পেমেন্ট'       => $order->get_payment_method_title(),
		);
		if ( '' !== $area ) {
			$facts['ডেলিভারি'] = $area;
		}
		/* translators: %s: order number */
		$help_message = sprintf( 'আসসালামু আলাইকুম! আমার অর্ডার #%s সম্পর্কে জানতে চাই।', $order->get_order_number() );
		?>
		<section class="bdc-thanks">
			<div class="bdc-thanks__hero">
				<span class="bdc-thanks__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
				<h2 class="bdc-thanks__title"><?php echo esc_html( self::setting( 'thankyou_title' ) ); ?></h2>
				<p class="bdc-thanks__text"><?php echo esc_html( self::setting( 'thankyou_text' ) ); ?></p>
			</div>

			<dl class="bdc-thanks__facts">
				<?php foreach ( $facts as $label => $value ) : ?>
					<?php if ( '' === (string) $value ) { continue; } ?>
					<div class="bdc-thanks__fact">
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( $value ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>

			<?php if ( $steps ) : ?>
				<ol class="bdc-thanks__steps">
					<?php foreach ( $steps as $index => $step ) : ?>
						<li><span class="bdc-thanks__num"><?php echo esc_html( number_format_i18n( $index + 1 ) ); ?></span><span><?php echo esc_html( $step ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( self::help_links() ) : ?>
				<div class="bdc-help bdc-help--thanks">
					<span class="bdc-help__text"><?php echo esc_html( self::setting( 'help_text' ) ); ?></span>
					<span class="bdc-help__buttons"><?php self::help_buttons( $help_message ); ?></span>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/** "Delivery area" line under the billing address on the order page. */
	public static function admin_area( $order ) {
		$area = $order instanceof WC_Order ? self::order_area( $order ) : '';
		if ( '' !== $area ) {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Delivery area', 'bd-courier' ), esc_html( $area ) );
		}
	}

	/** Label of the area saved on an order, or ''. */
	public static function order_area( WC_Order $order ) {
		$key   = (string) $order->get_meta( '_bdc_delivery_area' );
		$areas = self::areas();
		return isset( $areas[ $key ] ) ? $areas[ $key ]['label'] : '';
	}
}

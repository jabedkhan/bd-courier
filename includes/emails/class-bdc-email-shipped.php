<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer email: "your order is on its way", sent when staff send the
 * parcel with Pathao or Steadfast.
 *
 * It appears under WooCommerce → Settings → Emails like every other email,
 * where it can be switched on or off and its subject and heading changed.
 * It is off until switched on there.
 */
class BDC_Email_Shipped extends WC_Email {

	public function __construct() {
		$this->id             = 'bdc_shipped';
		$this->customer_email = true;
		$this->title          = __( 'Order sent with courier', 'bd-courier' );
		$this->description    = __( 'Sent to the customer when you send their order with Pathao or Steadfast. It includes the courier, the tracking ID and a tracking link.', 'bd-courier' );
		$this->template_base  = BDC_DIR . 'templates/';
		$this->template_html  = 'emails/bdc-shipped.php';
		$this->template_plain = 'emails/plain/bdc-shipped.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
			'{courier}'      => '',
		);

		add_action( 'bdc_parcel_sent_notification', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	/** Off by default: the shop owner decides whether customers get this email. */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['enabled']['default'] = 'no';
	}

	public function is_enabled() {
		return 'yes' === $this->get_option( 'enabled', 'no' );
	}

	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is on its way', 'bd-courier' );
	}

	public function get_default_heading() {
		return __( 'Your order is on its way', 'bd-courier' );
	}

	public function trigger( $order_id ) {
		$this->setup_locale();

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$delivery = BDC_Customer::delivery( $order );

			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{courier}']      = $delivery ? $delivery['courier'] : '';

			if ( $delivery && $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		}

		$this->restore_locale();
	}

	private function template_args( $plain_text ) {
		$delivery = $this->object instanceof WC_Order ? BDC_Customer::delivery( $this->object ) : null;

		return array(
			'order'              => $this->object,
			'courier'            => $delivery ? $delivery['courier'] : '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	public function get_default_additional_content() {
		return __( 'Thanks for shopping with us.', 'bd-courier' );
	}
}

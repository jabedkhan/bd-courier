<?php
/**
 * Customer email: order sent with courier (HTML).
 *
 * @var WC_Order $order
 * @var string   $courier
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	/* translators: %s: customer first name */
	echo esc_html( sprintf( __( 'Hi %s,', 'bd-courier' ), $order->get_billing_first_name() ) );
	?>
</p>
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: order number, 2: courier name */
			__( 'Good news: your order #%1$s has been handed to %2$s and is on its way to you. Please keep your phone nearby, as the delivery rider will call before arriving.', 'bd-courier' ),
			$order->get_order_number(),
			$courier
		)
	);
	?>
</p>

<?php
// The Delivery block (courier, tracking ID, status, tracking link) is added here by BD Courier.
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

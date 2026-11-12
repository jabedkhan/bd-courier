<?php
defined( 'ABSPATH' ) || exit;

/**
 * Printable invoice and parcel label, for one order or many.
 *
 * Opens as its own clean page. A bar at the top (not printed) switches
 * between the A4 invoice and the small label, then prints.
 */
class BDC_Print {

	const MAX_ORDERS = 100;

	public static function init() {
		add_action( 'admin_post_bdc_print', array( __CLASS__, 'page' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_action' ), 40 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_action' ), 40 );
	}

	public static function bulk_action( $actions ) {
		$actions['bdc_print'] = __( 'Print invoices / labels', 'bd-courier' );
		return $actions;
	}

	/** Address of the print page. Order IDs are added as "&ids=1,2,3". */
	public static function base_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=bdc_print' ), 'bdc_print' );
	}

	public static function url( $order_id ) {
		return add_query_arg( 'ids', absint( $order_id ), self::base_url() );
	}

	/** The shop's details shown on every printout. */
	public static function business() {
		$settings = BDC_Settings::section( 'print' );

		$address = $settings['business_address'];
		if ( '' === $address ) {
			$address = implode(
				', ',
				array_filter(
					array(
						get_option( 'woocommerce_store_address' ),
						get_option( 'woocommerce_store_address_2' ),
						get_option( 'woocommerce_store_city' ),
						get_option( 'woocommerce_store_postcode' ),
					)
				)
			);
		}

		return array(
			'name'    => '' !== $settings['business_name'] ? $settings['business_name'] : get_bloginfo( 'name' ),
			'phone'   => $settings['business_phone'],
			'address' => $address,
			'logo'    => $settings['logo_id'] ? (string) wp_get_attachment_image_url( (int) $settings['logo_id'], 'medium' ) : '',
			'note'    => $settings['footer_note'],
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to print orders.', 'bd-courier' ) );
		}
		check_admin_referer( 'bdc_print' );

		$ids    = isset( $_GET['ids'] ) ? array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) ) : array();
		$orders = array();

		foreach ( array_slice( $ids, 0, self::MAX_ORDERS ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && ! $order instanceof WC_Order_Refund ) {
				$orders[] = $order;
			}
		}

		if ( ! $orders ) {
			wp_die( esc_html__( 'There is nothing to print. Please go back and choose at least one order.', 'bd-courier' ) );
		}

		$business = self::business();
		$format   = isset( $_GET['format'] ) && 'label' === $_GET['format'] ? 'label' : 'invoice';

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>
		<?php
		/* translators: %d: number of orders */
		echo esc_html( sprintf( _n( 'Print %d order', 'Print %d orders', count( $orders ), 'bd-courier' ), count( $orders ) ) );
		?>
	</title>
	<link rel="stylesheet" href="<?php echo esc_url( BDC_URL . 'assets/css/print.css?ver=' . BDC_VERSION ); ?>">
	<style><?php echo BDC_Brand::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
	<style id="bdc-page-size"></style>
</head>
<body class="bdc-print bdc-print--<?php echo esc_attr( $format ); ?>">

	<div class="bdc-print__bar">
		<strong>
			<?php
			/* translators: %d: number of orders */
			echo esc_html( sprintf( _n( '%d order', '%d orders', count( $orders ), 'bd-courier' ), count( $orders ) ) );
			?>
		</strong>
		<div class="bdc-print__switch" role="group" aria-label="<?php esc_attr_e( 'What to print', 'bd-courier' ); ?>">
			<button type="button" data-format="invoice"><?php esc_html_e( 'Invoice', 'bd-courier' ); ?><small>A4</small></button>
			<button type="button" data-format="label"><?php esc_html_e( 'Parcel label', 'bd-courier' ); ?><small>100 × 150 mm</small></button>
		</div>
		<button type="button" class="bdc-print__go">
			<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>
			<?php esc_html_e( 'Print', 'bd-courier' ); ?>
		</button>
	</div>

	<?php
	foreach ( $orders as $order ) {
		$data = self::order_data( $order );
		self::invoice( $order, $data, $business );
		self::label( $order, $data, $business );
	}
	?>

	<script>
	( function () {
		var sizes = { invoice: '@page { size: A4; margin: 12mm; }', label: '@page { size: 100mm 150mm; margin: 0; }' };
		var buttons = document.querySelectorAll( '.bdc-print__switch button' );

		function choose( format ) {
			document.body.className = 'bdc-print bdc-print--' + format;
			document.getElementById( 'bdc-page-size' ).textContent = sizes[ format ];
			buttons.forEach( function ( button ) {
				button.setAttribute( 'aria-pressed', button.dataset.format === format ? 'true' : 'false' );
			} );
			try { localStorage.setItem( 'bdcPrintFormat', format ); } catch ( e ) {}
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () { choose( button.dataset.format ); } );
		} );
		document.querySelector( '.bdc-print__go' ).addEventListener( 'click', function () { window.print(); } );

		var saved = null;
		try { saved = localStorage.getItem( 'bdcPrintFormat' ); } catch ( e ) {}
		choose( <?php echo isset( $_GET['format'] ) ? wp_json_encode( $format ) : "sizes[ saved ] ? saved : 'invoice'"; // phpcs:ignore WordPress.Security.EscapeOutput ?> );
	} )();
	</script>
</body>
</html>
		<?php
		exit;
	}

	/** Everything about an order that both printouts use. */
	private static function order_data( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		$courier  = $shipment ? BDC_Couriers::get( $shipment['courier'] ) : null;
		$details  = $shipment ? (array) $shipment['details'] : array();

		$name = (string) ( $details['recipient_name'] ?? '' );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_shipping_full_name() );
			$name = '' !== $name ? $name : trim( $order->get_formatted_billing_full_name() );
		}

		$phone = (string) ( $details['recipient_phone'] ?? '' );
		if ( '' === $phone ) {
			$phone = BDC_Courier::clean_phone( '' !== (string) $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() );
		}

		$address = (string) ( $details['recipient_address'] ?? '' );
		if ( '' === $address ) {
			$type  = '' !== (string) $order->get_shipping_address_1() ? 'shipping' : 'billing';
			$parts = array();
			foreach ( array( 'address_1', 'address_2', 'city', 'postcode' ) as $part ) {
				$parts[] = trim( (string) $order->{"get_{$type}_{$part}"}() );
			}
			$address = implode( ', ', array_filter( $parts ) );
		}

		$paid = $order->get_date_paid() && 'cod' !== $order->get_payment_method();
		$cod  = $shipment && '' !== (string) $shipment['cod'] ? (float) $shipment['cod'] : ( $paid ? 0 : (float) $order->get_total() );

		$second_phone = (string) ( $details['recipient_secondary_phone'] ?? $details['alternative_phone'] ?? '' );
		$note         = (string) ( $details['special_instruction'] ?? $details['note'] ?? $order->get_customer_note() );

		return array(
			'shipment'     => $shipment,
			'courier'      => $courier,
			'name'         => $name,
			'phone'        => $phone,
			'second_phone' => $second_phone,
			'address'      => $address,
			'cod'          => $cod,
			'paid'         => $paid,
			'note'         => $note,
			'weight'       => (string) ( $details['item_weight'] ?? '' ),
			'date'         => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
		);
	}

	private static function money( $amount, WC_Order $order ) {
		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) );
	}

	private static function courier_block( array $data ) {
		if ( ! $data['courier'] ) {
			echo '<div class="bdc-doc__courier bdc-doc__courier--none">' . esc_html__( 'Not sent with a courier yet', 'bd-courier' ) . '</div>';
			return;
		}
		?>
		<div class="bdc-doc__courier">
			<span class="bdc-doc__courier-name"><?php echo esc_html( $data['courier']->label() ); ?></span>
			<span class="bdc-doc__consignment"><?php echo esc_html( '' !== $data['shipment']['consignment_id'] ? $data['shipment']['consignment_id'] : __( 'ID pending', 'bd-courier' ) ); ?></span>
			<?php if ( '' !== $data['shipment']['tracking_code'] ) : ?>
				<span class="bdc-doc__tracking">
					<?php
					/* translators: %s: tracking code */
					echo esc_html( sprintf( __( 'Tracking: %s', 'bd-courier' ), $data['shipment']['tracking_code'] ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---- A4 invoice ---- */

	private static function invoice( WC_Order $order, array $data, array $business ) {
		$line = 0;
		?>
		<section class="bdc-doc bdc-invoice">
			<header class="bdc-invoice__head">
				<div class="bdc-invoice__shop">
					<?php if ( $business['logo'] ) : ?>
						<img src="<?php echo esc_url( $business['logo'] ); ?>" alt="">
					<?php endif; ?>
					<div>
						<strong><?php echo esc_html( $business['name'] ); ?></strong>
						<?php if ( $business['address'] ) : ?>
							<span><?php echo esc_html( $business['address'] ); ?></span>
						<?php endif; ?>
						<?php if ( $business['phone'] ) : ?>
							<span><?php echo esc_html( $business['phone'] ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="bdc-invoice__title">
					<h1><?php esc_html_e( 'Invoice', 'bd-courier' ); ?></h1>
					<span>#<?php echo esc_html( $order->get_order_number() ); ?></span>
				</div>
			</header>

			<dl class="bdc-invoice__meta">
				<div>
					<dt><?php esc_html_e( 'Invoice no.', 'bd-courier' ); ?></dt>
					<dd>#<?php echo esc_html( $order->get_order_number() ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Order date', 'bd-courier' ); ?></dt>
					<dd><?php echo esc_html( $data['date'] ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Payment', 'bd-courier' ); ?></dt>
					<dd><?php echo esc_html( $order->get_payment_method_title() ? $order->get_payment_method_title() : '—' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Status', 'bd-courier' ); ?></dt>
					<dd><span class="bdc-invoice__paid <?php echo $data['paid'] ? 'is-paid' : ''; ?>"><?php echo $data['paid'] ? esc_html__( 'Paid', 'bd-courier' ) : esc_html__( 'Pay on delivery', 'bd-courier' ); ?></span></dd>
				</div>
			</dl>

			<div class="bdc-invoice__parties">
				<div class="bdc-invoice__card">
					<h2><?php esc_html_e( 'Deliver to', 'bd-courier' ); ?></h2>
					<strong><?php echo esc_html( $data['name'] ); ?></strong>
					<span><?php echo esc_html( $data['phone'] . ( '' !== $data['second_phone'] ? ', ' . $data['second_phone'] : '' ) ); ?></span>
					<span><?php echo esc_html( $data['address'] ); ?></span>
				</div>
				<div class="bdc-invoice__card">
					<h2><?php esc_html_e( 'Courier', 'bd-courier' ); ?></h2>
					<?php self::courier_block( $data ); ?>
				</div>
			</div>

			<table class="bdc-invoice__items">
				<thead>
					<tr>
						<th class="bdc-invoice__no">#</th>
						<th><?php esc_html_e( 'Item', 'bd-courier' ); ?></th>
						<th class="bdc-num bdc-invoice__price"><?php esc_html_e( 'Price', 'bd-courier' ); ?></th>
						<th class="bdc-num"><?php esc_html_e( 'Qty', 'bd-courier' ); ?></th>
						<th class="bdc-num"><?php esc_html_e( 'Total', 'bd-courier' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order->get_items() as $item ) : ?>
						<?php
						$product = $item->get_product();
						$qty     = max( 1, (int) $item->get_quantity() );
						?>
						<tr>
							<td class="bdc-invoice__no"><?php echo esc_html( ++$line ); ?></td>
							<td>
								<?php echo esc_html( $item->get_name() ); ?>
								<?php if ( $product && $product->get_sku() ) : ?>
									<small><?php echo esc_html( $product->get_sku() ); ?></small>
								<?php endif; ?>
							</td>
							<td class="bdc-num bdc-invoice__price"><?php echo self::money( (float) $item->get_subtotal() / $qty, $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td class="bdc-num"><?php echo esc_html( $item->get_quantity() ); ?></td>
							<td class="bdc-num"><?php echo self::money( $item->get_subtotal(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="bdc-invoice__bottom">
				<div class="bdc-invoice__note">
					<?php if ( '' !== $data['note'] ) : ?>
						<h2><?php esc_html_e( 'Delivery note', 'bd-courier' ); ?></h2>
						<p><?php echo esc_html( $data['note'] ); ?></p>
					<?php endif; ?>
				</div>
				<table class="bdc-invoice__totals">
					<tr>
						<th><?php esc_html_e( 'Subtotal', 'bd-courier' ); ?></th>
						<td><?php echo self::money( $order->get_subtotal(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
					<?php if ( (float) $order->get_discount_total() > 0 ) : ?>
						<tr>
							<th><?php esc_html_e( 'Discount', 'bd-courier' ); ?></th>
							<td>&minus;<?php echo self::money( $order->get_discount_total(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
						<tr>
							<th><?php esc_html_e( 'Delivery charge', 'bd-courier' ); ?></th>
							<td><?php echo self::money( $order->get_shipping_total(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $order->get_fees() as $fee ) : ?>
						<tr>
							<th><?php echo esc_html( $fee->get_name() ); ?></th>
							<td><?php echo self::money( $fee->get_total(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( (float) $order->get_total_tax() > 0 ) : ?>
						<tr>
							<th><?php esc_html_e( 'Tax', 'bd-courier' ); ?></th>
							<td><?php echo self::money( $order->get_total_tax(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endif; ?>
					<tr class="bdc-invoice__total">
						<th><?php esc_html_e( 'Order total', 'bd-courier' ); ?></th>
						<td><?php echo self::money( $order->get_total(), $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
					<tr class="bdc-invoice__cod">
						<th><?php esc_html_e( 'Cash to collect', 'bd-courier' ); ?></th>
						<td><?php echo self::money( $data['cod'], $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				</table>
			</div>

			<footer class="bdc-invoice__foot">
				<?php if ( '' !== $business['note'] ) : ?>
					<p><?php echo esc_html( $business['note'] ); ?></p>
				<?php endif; ?>
				<p class="bdc-invoice__thanks">
					<?php
					/* translators: %s: shop name */
					echo esc_html( sprintf( __( 'Thank you for ordering from %s.', 'bd-courier' ), $business['name'] ) );
					?>
				</p>
			</footer>
		</section>
		<?php
	}

	/* ---- 100 × 150 mm label ---- */

	private static function label( WC_Order $order, array $data, array $business ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' ×' . $item->get_quantity();
		}

		$shipment = $data['shipment'];
		$courier  = $data['courier'];
		?>
		<section class="bdc-doc bdc-label">
			<header class="bdc-label__head">
				<div class="bdc-label__from">
					<small><?php esc_html_e( 'From', 'bd-courier' ); ?></small>
					<strong><?php echo esc_html( $business['name'] ); ?></strong>
					<?php if ( $business['phone'] ) : ?>
						<span><?php echo esc_html( $business['phone'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bdc-label__order">
					<small><?php esc_html_e( 'Order', 'bd-courier' ); ?></small>
					<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
					<span><?php echo esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'j M Y' ) : '' ); ?></span>
				</div>
			</header>

			<div class="bdc-label__courier <?php echo $courier ? '' : 'is-empty'; ?>">
				<?php if ( $courier ) : ?>
					<span class="bdc-label__courier-name"><?php echo esc_html( $courier->label() ); ?></span>
					<span class="bdc-label__consignment"><?php echo esc_html( '' !== $shipment['consignment_id'] ? $shipment['consignment_id'] : __( 'ID pending', 'bd-courier' ) ); ?></span>
				<?php else : ?>
					<span class="bdc-label__courier-name"><?php esc_html_e( 'Not sent with a courier yet', 'bd-courier' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $courier && '' !== $shipment['tracking_code'] ) : ?>
				<p class="bdc-label__tracking">
					<?php
					/* translators: %s: tracking code */
					echo esc_html( sprintf( __( 'Tracking code %s', 'bd-courier' ), $shipment['tracking_code'] ) );
					?>
				</p>
			<?php endif; ?>

			<div class="bdc-label__to">
				<small><?php esc_html_e( 'Deliver to', 'bd-courier' ); ?></small>
				<strong><?php echo esc_html( $data['name'] ); ?></strong>
				<span class="bdc-label__phone"><?php echo esc_html( $data['phone'] . ( '' !== $data['second_phone'] ? '  /  ' . $data['second_phone'] : '' ) ); ?></span>
				<span class="bdc-label__address"><?php echo esc_html( $data['address'] ); ?></span>
			</div>

			<div class="bdc-label__cod <?php echo $data['cod'] <= 0 ? 'is-paid' : ''; ?>">
				<div>
					<small><?php esc_html_e( 'Cash to collect', 'bd-courier' ); ?></small>
					<?php if ( $data['cod'] <= 0 ) : ?>
						<span><?php esc_html_e( 'Already paid. Collect nothing.', 'bd-courier' ); ?></span>
					<?php endif; ?>
				</div>
				<strong><?php echo $data['cod'] <= 0 ? esc_html__( 'PAID', 'bd-courier' ) : wp_kses_post( wc_price( $data['cod'], array( 'currency' => $order->get_currency(), 'decimals' => 0 ) ) ); ?></strong>
			</div>

			<dl class="bdc-label__facts">
				<div>
					<dt><?php esc_html_e( 'Items', 'bd-courier' ); ?></dt>
					<dd><?php echo esc_html( $order->get_item_count() ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Weight', 'bd-courier' ); ?></dt>
					<dd><?php echo esc_html( '' !== $data['weight'] ? $data['weight'] . ' kg' : '—' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Payment', 'bd-courier' ); ?></dt>
					<dd><?php echo $data['paid'] ? esc_html__( 'Prepaid', 'bd-courier' ) : esc_html__( 'COD', 'bd-courier' ); ?></dd>
				</div>
			</dl>

			<p class="bdc-label__items"><?php echo esc_html( implode( ', ', $items ) ); ?></p>

			<?php if ( '' !== $data['note'] ) : ?>
				<div class="bdc-label__note">
					<small><?php esc_html_e( 'Note for the rider', 'bd-courier' ); ?></small>
					<span><?php echo esc_html( $data['note'] ); ?></span>
				</div>
			<?php endif; ?>

			<footer class="bdc-label__foot">
				<span><?php esc_html_e( 'Please call the customer before delivery', 'bd-courier' ); ?></span>
			</footer>
		</section>
		<?php
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * What the customer sees: a "Delivery" block with the courier, the parcel
 * ID, its status and a tracking link, on the order page in My Account, on
 * the order-received page and in WooCommerce's customer emails.
 */
class BDC_Customer {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ) );
		add_filter( 'option_woocommerce_email_header_image', array( __CLASS__, 'email_logo' ) );
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );

		if ( 'yes' !== BDC_Settings::get( 'general', 'customer_tracking', 'yes' ) ) {
			return;
		}

		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_page' ), 5 );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email' ), 5, 3 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_action' ), 10, 2 );
	}

	/** WooCommerce's email logo, or the business logo from BD Courier when that is empty. */
	public static function email_logo( $value ) {
		if ( '' !== trim( (string) $value ) || ! class_exists( 'BDC_Brand' ) ) {
			return $value;
		}
		$logo = BDC_Brand::logo_url( 'medium' );
		return '' !== $logo ? $logo : $value;
	}

	public static function email_classes( $emails ) {
		require_once BDC_DIR . 'includes/emails/class-bdc-email-shipped.php';
		$emails['BDC_Email_Shipped'] = new BDC_Email_Shipped();
		return $emails;
	}

	/** Lets WooCommerce's mailer listen for "a parcel was sent". */
	public static function email_actions( $actions ) {
		$actions[] = 'bdc_parcel_sent';
		return $actions;
	}

	/**
	 * What to tell the customer about an order's parcel.
	 *
	 * @return array|null courier, id, status, updated, url, history
	 */
	public static function delivery( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		$courier  = $shipment ? BDC_Couriers::get( $shipment['courier'] ) : null;

		if ( ! $shipment || ! $courier ) {
			return null;
		}

		$status = BDC_Statuses::label( $shipment['status'] );
		if ( '' === $status ) {
			$status = BDC_Statuses::label( 'sent' );
		}

		$history = array();
		foreach ( array_slice( array_reverse( (array) $shipment['history'] ), 0, 6 ) as $entry ) {
			$history[] = array(
				'status' => $courier->status_label( $entry['status'] ?? '' ),
				'time'   => (int) ( $entry['time'] ?? 0 ),
			);
		}

		return array(
			'courier' => $courier->label(),
			'id'      => '' !== $shipment['tracking_code'] ? $shipment['tracking_code'] : $shipment['consignment_id'],
			'status'  => $status,
			'state'   => $shipment['status'] ? $shipment['status'] : 'sent',
			'updated' => (int) $shipment['updated_at'],
			'url'     => $courier->tracking_url( $shipment ),
			'history' => $history,
		);
	}

	private static function date( $time ) {
		return wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), (int) $time );
	}

	/* ---- My Account order page and order-received page ---- */

	public static function order_page( $order ) {
		$delivery = $order instanceof WC_Order ? self::delivery( $order ) : null;
		if ( ! $delivery ) {
			return;
		}
		?>
		<section class="bdc-delivery bdc-delivery--<?php echo esc_attr( $delivery['state'] ); ?>">
			<h2 class="woocommerce-column__title"><?php esc_html_e( 'Delivery', 'bd-courier' ); ?></h2>
			<div class="bdc-delivery__box">
				<p class="bdc-delivery__status">
					<strong><?php echo esc_html( $delivery['status'] ); ?></strong>
					<?php if ( $delivery['updated'] ) : ?>
						<span><?php echo esc_html( self::date( $delivery['updated'] ) ); ?></span>
					<?php endif; ?>
				</p>
				<dl class="bdc-delivery__facts">
					<dt><?php esc_html_e( 'Courier', 'bd-courier' ); ?></dt>
					<dd><?php echo esc_html( $delivery['courier'] ); ?></dd>
					<?php if ( '' !== $delivery['id'] ) : ?>
						<dt><?php esc_html_e( 'Tracking ID', 'bd-courier' ); ?></dt>
						<dd><?php echo esc_html( $delivery['id'] ); ?></dd>
					<?php endif; ?>
				</dl>
				<?php if ( $delivery['url'] ) : ?>
					<p class="bdc-delivery__track">
						<a class="button" href="<?php echo esc_url( $delivery['url'] ); ?>" target="_blank" rel="noopener">
							<?php
							/* translators: %s: courier name */
							echo esc_html( sprintf( __( 'Track on %s', 'bd-courier' ), $delivery['courier'] ) );
							?>
						</a>
					</p>
				<?php endif; ?>
				<?php if ( count( $delivery['history'] ) > 1 ) : ?>
					<ol class="bdc-delivery__history">
						<?php foreach ( $delivery['history'] as $entry ) : ?>
							<li>
								<strong><?php echo esc_html( $entry['status'] ); ?></strong>
								<span><?php echo esc_html( self::date( $entry['time'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
		</section>
		<style>
			.bdc-delivery { margin: 0 0 2em; }
			.bdc-delivery__box { padding: 1em 1.25em; border: 1px solid rgba(0, 0, 0, 0.12); border-radius: 4px; }
			.bdc-delivery__status { margin: 0 0 .75em; }
			.bdc-delivery__status strong { display: inline-block; margin-right: .5em; padding: .15em .7em; border-radius: 999px; background: #e7f0fa; color: #0a3d66; }
			.bdc-delivery--delivered .bdc-delivery__status strong, .bdc-delivery--partial .bdc-delivery__status strong { background: #e3f4e8; color: #0a5c2b; }
			.bdc-delivery--hold .bdc-delivery__status strong { background: #fcf0d6; color: #7a4b00; }
			.bdc-delivery--returned .bdc-delivery__status strong, .bdc-delivery--cancelled .bdc-delivery__status strong { background: #fbe4e4; color: #8a1f1f; }
			.bdc-delivery__status span, .bdc-delivery__history span { opacity: .7; font-size: .9em; }
			.bdc-delivery__facts { display: grid; grid-template-columns: max-content 1fr; gap: .25em 1.25em; margin: 0 0 .75em; }
			.bdc-delivery__facts dt { font-weight: 600; }
			.bdc-delivery__facts dd { margin: 0; }
			.bdc-delivery__track { margin: 0 0 .75em; }
			.bdc-delivery__history { margin: .75em 0 0; padding: .75em 0 0 1.25em; border-top: 1px solid rgba(0, 0, 0, 0.12); }
			.bdc-delivery__history li { margin: 0 0 .35em; }
			.bdc-delivery__history strong { margin-right: .5em; font-weight: 600; }
		</style>
		<?php
	}

	/* ---- "Track" button in the My Account orders list ---- */

	public static function account_action( $actions, $order ) {
		$delivery = $order instanceof WC_Order ? self::delivery( $order ) : null;

		if ( $delivery && $delivery['url'] ) {
			$actions['bdc_track'] = array(
				'url'  => $delivery['url'],
				'name' => __( 'Track parcel', 'bd-courier' ),
			);
		}

		return $actions;
	}

	/* ---- Customer emails ---- */

	public static function email( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$delivery = self::delivery( $order );
		if ( ! $delivery ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'Delivery', 'bd-courier' ) ) ) . "\n";
			echo esc_html( __( 'Courier', 'bd-courier' ) . ': ' . $delivery['courier'] ) . "\n";
			if ( '' !== $delivery['id'] ) {
				echo esc_html( __( 'Tracking ID', 'bd-courier' ) . ': ' . $delivery['id'] ) . "\n";
			}
			echo esc_html( __( 'Status', 'bd-courier' ) . ': ' . $delivery['status'] ) . "\n";
			if ( $delivery['url'] ) {
				echo esc_html( __( 'Track your parcel', 'bd-courier' ) . ': ' ) . esc_url_raw( $delivery['url'] ) . "\n";
			}
			echo "\n";
			return;
		}
		?>
		<h2><?php esc_html_e( 'Delivery', 'bd-courier' ); ?></h2>
		<table cellspacing="0" cellpadding="6" border="0" style="width: 100%; margin-bottom: 24px; border: 1px solid #e5e5e5;">
			<tr>
				<th scope="row" style="text-align: left; width: 35%; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Courier', 'bd-courier' ); ?></th>
				<td style="text-align: left; border-bottom: 1px solid #e5e5e5;"><?php echo esc_html( $delivery['courier'] ); ?></td>
			</tr>
			<?php if ( '' !== $delivery['id'] ) : ?>
				<tr>
					<th scope="row" style="text-align: left; border-bottom: 1px solid #e5e5e5;"><?php esc_html_e( 'Tracking ID', 'bd-courier' ); ?></th>
					<td style="text-align: left; border-bottom: 1px solid #e5e5e5;"><?php echo esc_html( $delivery['id'] ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row" style="text-align: left;"><?php esc_html_e( 'Status', 'bd-courier' ); ?></th>
				<td style="text-align: left;"><?php echo esc_html( $delivery['status'] ); ?></td>
			</tr>
		</table>
		<?php if ( $delivery['url'] ) : ?>
			<p style="margin: 0 0 24px;">
				<a href="<?php echo esc_url( $delivery['url'] ); ?>" style="display: inline-block; padding: 10px 18px; border-radius: 4px; background: #1d2327; color: #ffffff; font-weight: bold; text-decoration: none;">
					<?php esc_html_e( 'Track your parcel', 'bd-courier' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}
}

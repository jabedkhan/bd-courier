<?php
defined( 'ABSPATH' ) || exit;

/**
 * A customer's orders in this shop, found by phone number.
 *
 * Phone numbers are compared by their last ten digits, so 01712345678,
 * +8801712345678 and 1712345678 are the same customer.
 */
class BDC_History {

	/** @var array phone tail => order IDs, for this page load. */
	private static $cache = array();

	public static function init() {
		add_action( 'wp_ajax_bdc_history', array( __CLASS__, 'ajax' ) );
	}

	/** The last ten digits of a phone number, or '' when it is too short to be one. */
	public static function tail( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
	}

	public static function order_phone( WC_Order $order ) {
		return '' !== (string) $order->get_billing_phone() ? (string) $order->get_billing_phone() : (string) $order->get_shipping_phone();
	}

	/**
	 * IDs of every order placed with this phone number, newest first.
	 *
	 * @return int[]
	 */
	public static function order_ids( $phone ) {
		global $wpdb;

		$tail = self::tail( $phone );
		if ( '' === $tail ) {
			return array();
		}
		if ( isset( self::$cache[ $tail ] ) ) {
			return self::$cache[ $tail ];
		}

		$like = '%' . $wpdb->esc_like( $tail );
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT a.order_id FROM {$wpdb->prefix}wc_order_addresses a
					INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id
					WHERE a.phone LIKE %s AND o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					ORDER BY a.order_id DESC LIMIT 200",
					$like
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
					INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key IN ( '_billing_phone', '_shipping_phone' ) AND m.meta_value LIKE %s
					AND p.post_type = 'shop_order' AND p.post_status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' )
					ORDER BY m.post_id DESC LIMIT 200",
					$like
				)
			);
		}

		self::$cache[ $tail ] = array_map( 'absint', (array) $ids );

		return self::$cache[ $tail ];
	}

	/**
	 * Summary of the customer behind an order.
	 *
	 * @return array total, delivered, returned, open, spent, position (this order's number in their history, 1 = first)
	 */
	public static function summary( WC_Order $order ) {
		$ids     = self::order_ids( self::order_phone( $order ) );
		$summary = array(
			'total'     => count( $ids ),
			'delivered' => 0,
			'returned'  => 0,
			'open'      => 0,
			'spent'     => 0.0,
			'position'  => 0,
		);

		if ( ! $ids ) {
			return $summary;
		}

		// Oldest first, to say "2nd order", "3rd order".
		$sorted = $ids;
		sort( $sorted );
		$position            = array_search( $order->get_id(), $sorted, true );
		$summary['position'] = false === $position ? count( $sorted ) : $position + 1;

		foreach ( $ids as $id ) {
			$status = self::status_of( $id );
			if ( in_array( $status, array( 'completed', BDC_Statuses::slug( 'delivered' ), BDC_Statuses::slug( 'partial' ) ), true ) ) {
				++$summary['delivered'];
			} elseif ( in_array( $status, array( 'cancelled', 'refunded', 'failed', BDC_Statuses::slug( 'returned' ), BDC_Statuses::slug( 'cancelled' ) ), true ) ) {
				++$summary['returned'];
			} else {
				++$summary['open'];
			}
		}

		return $summary;
	}

	/** An order's status without loading the whole order when it can be avoided. */
	private static function status_of( $id ) {
		$order = wc_get_order( $id );
		return $order ? $order->get_status() : '';
	}

	/** "1st", "2nd", "3rd", "4th" … */
	public static function ordinal( $number ) {
		$number = (int) $number;
		$suffix = 'th';
		if ( ! in_array( $number % 100, array( 11, 12, 13 ), true ) ) {
			$last   = $number % 10;
			$suffix = 1 === $last ? 'st' : ( 2 === $last ? 'nd' : ( 3 === $last ? 'rd' : 'th' ) );
		}
		return $number . $suffix;
	}

	/** The small tag shown under the customer's name in the Orders list. */
	public static function badge( WC_Order $order ) {
		$count = count( self::order_ids( self::order_phone( $order ) ) );
		if ( $count < 1 ) {
			return '';
		}

		$summary = self::summary( $order );
		$label   = $count > 1
			/* translators: 1: e.g. "3rd", 2: total orders */
			? sprintf( __( '%1$s order of %2$d', 'bd-courier' ), self::ordinal( $summary['position'] ), $count )
			: __( 'New customer', 'bd-courier' );

		return sprintf(
			'<button type="button" class="bdc-history-badge %s" data-order="%d" title="%s">%s</button>',
			$count > 1 ? ( $summary['returned'] > $summary['delivered'] ? 'is-risky' : 'is-returning' ) : 'is-new',
			$order->get_id(),
			esc_attr__( 'Show this customer\'s orders', 'bd-courier' ),
			esc_html( $label )
		);
	}

	/**
	 * The customer's orders as a table, for the quick view, the order page and the popup.
	 *
	 * @param int $limit How many orders to list.
	 */
	public static function render( WC_Order $order, $limit = 10 ) {
		$phone   = self::order_phone( $order );
		$ids     = self::order_ids( $phone );
		$summary = self::summary( $order );

		if ( ! $ids ) {
			return '<p class="bdc-muted">' . esc_html__( 'No other orders with this phone number.', 'bd-courier' ) . '</p>';
		}

		ob_start();
		?>
		<div class="bdc-history-box">
			<div class="bdc-history-box__stats">
				<span><strong><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></strong> <?php echo esc_html( _n( 'order', 'orders', $summary['total'], 'bd-courier' ) ); ?></span>
				<span class="is-good"><strong><?php echo esc_html( number_format_i18n( $summary['delivered'] ) ); ?></strong> <?php esc_html_e( 'delivered', 'bd-courier' ); ?></span>
				<span class="is-bad"><strong><?php echo esc_html( number_format_i18n( $summary['returned'] ) ); ?></strong> <?php esc_html_e( 'cancelled or returned', 'bd-courier' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $summary['open'] ) ); ?></strong> <?php esc_html_e( 'open', 'bd-courier' ); ?></span>
			</div>
			<div class="bdc-history-box__scroll">
				<table class="bdc-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'bd-courier' ); ?></th>
							<th><?php esc_html_e( 'Date', 'bd-courier' ); ?></th>
							<th><?php esc_html_e( 'Status', 'bd-courier' ); ?></th>
							<th><?php esc_html_e( 'Courier', 'bd-courier' ); ?></th>
							<th class="bdc-num"><?php esc_html_e( 'Total', 'bd-courier' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $ids, 0, $limit ) as $id ) : ?>
							<?php
							$past = wc_get_order( $id );
							if ( ! $past instanceof WC_Order ) {
								continue;
							}
							$shipment = BDC_Shipment::get( $past );
							$courier  = $shipment ? BDC_Couriers::get( $shipment['courier'] ) : null;
							?>
							<tr class="<?php echo $id === $order->get_id() ? 'is-this' : ''; ?>">
								<td>
									<a href="<?php echo esc_url( $past->get_edit_order_url() ); ?>">#<?php echo esc_html( $past->get_order_number() ); ?></a>
									<?php if ( $id === $order->get_id() ) : ?>
										<span class="bdc-muted"><?php esc_html_e( '(this order)', 'bd-courier' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $past->get_date_created() ? wc_format_datetime( $past->get_date_created(), 'j M Y' ) : '' ); ?></td>
								<td><?php echo BDC_View::status_mark( $past ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td>
									<?php if ( $courier ) : ?>
										<?php echo BDC_View::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<span class="bdc-muted"><?php echo esc_html( '' !== $shipment['courier_status'] ? $courier->status_label( $shipment['courier_status'] ) : BDC_Statuses::label( $shipment['status'] ) ); ?></span>
									<?php else : ?>
										<span class="bdc-muted">&mdash;</span>
									<?php endif; ?>
								</td>
								<td class="bdc-num"><?php echo esc_html( BDC_View::money( $past->get_total() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( count( $ids ) > $limit ) : ?>
				<p class="bdc-muted">
					<?php
					/* translators: 1: shown, 2: total */
					echo esc_html( sprintf( __( 'Showing the latest %1$d of %2$d orders.', 'bd-courier' ), $limit, count( $ids ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** The history popup opened from the badge in the Orders list. */
	public static function ajax() {
		BDC_Ajax::guard();

		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'This order could not be found. Please reload the page.', 'bd-courier' ) ), 404 );
		}

		$name = trim( $order->get_formatted_billing_full_name() );

		wp_send_json_success(
			array(
				'title' => sprintf(
					/* translators: 1: customer name, 2: phone */
					__( 'Orders from %1$s · %2$s', 'bd-courier' ),
					'' !== $name ? $name : __( 'this customer', 'bd-courier' ),
					BDC_Courier::clean_phone( self::order_phone( $order ) )
				),
				'html'  => self::render( $order, 50 ),
			)
		);
	}
}

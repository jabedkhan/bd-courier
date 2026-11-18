<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk send: pick orders in the order list, choose "Send with Pathao" or
 * "Send with Steadfast", review them in one popup, then send.
 *
 * Nothing is sent by choosing the bulk action. Parcels are only created when
 * staff press the send button in the popup, one order after another, so every
 * order gets its own result.
 */
class BDC_Bulk {

	const MAX_ORDERS = 50;

	public static function init() {
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'actions' ), 30 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'actions' ), 30 );
		add_action( 'wp_ajax_bdc_bulk_form', array( __CLASS__, 'form' ) );
	}

	public static function actions( $actions ) {
		foreach ( BDC_Couriers::all() as $courier ) {
			if ( $courier->is_active() ) {
				/* translators: %s: courier name */
				$actions[ 'bdc_send_' . $courier->id() ] = sprintf( __( 'Send with %s', 'bd-courier' ), $courier->label() );
			}
		}
		return $actions;
	}

	/** Fields that are the same for every parcel in a bulk send. */
	private static function shared_keys( BDC_Courier $courier ) {
		return 'pathao' === $courier->id() ? array( 'store_id', 'delivery_type', 'item_type' ) : array( 'delivery_type' );
	}

	/** Fields shown per order. Everything else keeps the value the single form would start with. */
	private static function row_keys( BDC_Courier $courier ) {
		return 'pathao' === $courier->id()
			? array( 'recipient_name', 'recipient_phone', 'recipient_address', 'amount_to_collect', 'item_weight' )
			: array( 'recipient_name', 'recipient_phone', 'recipient_address', 'cod_amount' );
	}

	public static function form() {
		BDC_Ajax::guard();

		$courier = BDC_Couriers::get( sanitize_key( $_POST['courier'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $courier || ! $courier->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'This courier is turned off or not connected. Check BD Courier settings.', 'bd-courier' ) ), 400 );
		}

		$ids = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] ) ? array_unique( array_filter( array_map( 'absint', $_POST['order_ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Please tick the orders you want to send first.', 'bd-courier' ) ), 400 );
		}
		if ( count( $ids ) > self::MAX_ORDERS ) {
			wp_send_json_error(
				array(
					/* translators: %d: maximum number of orders */
					'message' => sprintf( __( 'You can send up to %d orders at a time. Please select fewer orders.', 'bd-courier' ), self::MAX_ORDERS ),
				),
				400
			);
		}

		$ready   = array();
		$skipped = array();

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
				continue;
			}

			$shipment = BDC_Shipment::get( $order );
			if ( $shipment ) {
				$with = BDC_Couriers::get( $shipment['courier'] );
				/* translators: %s: courier name */
				$skipped[] = array( $order, sprintf( __( 'already sent with %s', 'bd-courier' ), $with ? $with->label() : $shipment['courier'] ) );
			} elseif ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ) ) ) {
				/* translators: %s: order status */
				$skipped[] = array( $order, sprintf( __( 'order is %s', 'bd-courier' ), strtolower( wc_get_order_status_name( $order->get_status() ) ) ) );
			} else {
				$ready[] = $order;
			}
		}

		wp_send_json_success( array( 'html' => self::render( $courier, $ready, $skipped ) ) );
	}

	private static function render( BDC_Courier $courier, array $orders, array $skipped ) {
		$shared_keys = self::shared_keys( $courier );
		$row_keys    = self::row_keys( $courier );
		$first       = $orders ? $courier->form_fields( $orders[0] ) : array();
		$count       = count( $orders );

		ob_start();
		?>
		<div class="bdc-bulk" data-courier="<?php echo esc_attr( $courier->id() ); ?>" data-label="<?php echo esc_attr( $courier->label() ); ?>">
			<header class="bdc-form__header">
				<?php echo BDC_View::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2 id="bdc-modal-title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of orders, 2: courier name */
							_n( 'Send %1$d order with %2$s', 'Send %1$d orders with %2$s', $count, 'bd-courier' ),
							$count,
							$courier->label()
						)
					);
					?>
				</h2>
				<button type="button" class="bdc-modal__close" aria-label="<?php esc_attr_e( 'Close', 'bd-courier' ); ?>">&times;</button>
			</header>

			<div class="bdc-form__body">
				<?php if ( $skipped ) : ?>
					<div class="bdc-form__notice">
						<strong><?php esc_html_e( 'Left out:', 'bd-courier' ); ?></strong>
						<?php
						$parts = array();
						foreach ( $skipped as $item ) {
							$parts[] = '#' . $item[0]->get_order_number() . ' (' . $item[1] . ')';
						}
						echo esc_html( implode( ', ', $parts ) );
						?>
					</div>
				<?php endif; ?>

				<?php if ( ! $orders ) : ?>
					<p class="bdc-bulk__empty"><?php esc_html_e( 'None of the selected orders can be sent.', 'bd-courier' ); ?></p>
				<?php else : ?>
					<fieldset class="bdc-fieldset bdc-bulk__shared">
						<legend><?php esc_html_e( 'For every parcel', 'bd-courier' ); ?></legend>
						<div class="bdc-grid">
							<?php foreach ( $shared_keys as $key ) : ?>
								<div class="bdc-field bdc-field--third">
									<label for="bdc-bulk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $first[ $key ]['label'] ); ?></label>
									<select id="bdc-bulk-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
										<?php foreach ( (array) $first[ $key ]['options'] as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $value, (string) $first[ $key ]['value'] ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endforeach; ?>
						</div>
						<?php if ( 'pathao' === $courier->id() ) : ?>
							<p class="bdc-field__help"><?php esc_html_e( 'Pathao works out the city and zone from each address. To choose them yourself, send that order on its own.', 'bd-courier' ); ?></p>
						<?php endif; ?>
					</fieldset>

					<div class="bdc-bulk__scroll">
						<table class="bdc-bulk__table">
							<thead>
								<tr>
									<th class="bdc-bulk__order"><?php esc_html_e( 'Order', 'bd-courier' ); ?></th>
									<?php foreach ( $row_keys as $key ) : ?>
										<th class="bdc-bulk__col--<?php echo esc_attr( $key ); ?>"><?php echo esc_html( trim( preg_replace( '/\s*\(.*\)$/', '', $first[ $key ]['label'] ) ) ); ?></th>
									<?php endforeach; ?>
									<th class="bdc-bulk__result"><?php esc_html_e( 'Result', 'bd-courier' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $orders as $order ) {
									self::row( $courier, $order, $row_keys, $shared_keys );
								}
								?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<footer class="bdc-form__footer">
				<span class="bdc-form__hint bdc-bulk__summary" role="status">
					<?php
					/* translators: %s: courier name */
					echo esc_html( sprintf( __( 'Check the details, then send. Nothing is sent to %s until you press the button.', 'bd-courier' ), $courier->label() ) );
					?>
				</span>
				<button type="button" class="button bdc-modal__cancel"><?php esc_html_e( 'Cancel', 'bd-courier' ); ?></button>
				<?php if ( $orders ) : ?>
					<button type="button" class="button button-primary bdc-bulk__submit">
						<?php echo BDC_View::truck_icon( 'bdc-form__submit-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of parcels, 2: courier name */
								_n( 'Send %1$d parcel with %2$s', 'Send %1$d parcels with %2$s', $count, 'bd-courier' ),
								$count,
								$courier->label()
							)
						);
						?>
					</button>
				<?php endif; ?>
			</footer>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function row( BDC_Courier $courier, WC_Order $order, array $row_keys, array $shared_keys ) {
		$fields = $courier->form_fields( $order );
		$paid   = $order->get_date_paid() && 'cod' !== $order->get_payment_method();
		?>
		<tr class="bdc-bulk__row" data-order="<?php echo esc_attr( $order->get_id() ); ?>">
			<td class="bdc-bulk__order">
				<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
				<span><?php echo esc_html( BDC_View::money( $order->get_total() ) ); ?></span>
				<span class="bdc-pill <?php echo $paid ? 'bdc-pill--ok' : 'bdc-pill--warn'; ?>"><?php echo $paid ? esc_html__( 'Paid', 'bd-courier' ) : esc_html__( 'Not paid', 'bd-courier' ); ?></span>
				<?php
				// Fields that are not shown per order still travel with the row.
				foreach ( $fields as $key => $field ) {
					if ( 'note' === $field['type'] || in_array( $key, $row_keys, true ) || in_array( $key, $shared_keys, true ) ) {
						continue;
					}
					printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( (string) $field['value'] ) );
				}
				?>
			</td>
			<?php foreach ( $row_keys as $key ) : ?>
				<?php
				$field = $fields[ $key ];
				$label = sprintf( '%s, #%s', $field['label'], $order->get_order_number() );
				?>
				<td data-field="<?php echo esc_attr( $key ); ?>">
					<?php if ( 'textarea' === $field['type'] ) : ?>
						<textarea name="<?php echo esc_attr( $key ); ?>" rows="2" aria-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_textarea( (string) $field['value'] ); ?></textarea>
					<?php else : ?>
						<input
							type="<?php echo esc_attr( $field['type'] ); ?>"
							name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( (string) $field['value'] ); ?>"
							aria-label="<?php echo esc_attr( $label ); ?>"
							<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : ''; ?>
							<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : ''; ?>
							<?php echo isset( $field['step'] ) ? 'step="' . esc_attr( $field['step'] ) . '"' : ''; ?>
						>
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
			<td class="bdc-bulk__result"><span class="bdc-muted"><?php esc_html_e( 'Ready', 'bd-courier' ); ?></span></td>
		</tr>
		<?php
	}
}

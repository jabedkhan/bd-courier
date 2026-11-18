<?php
defined( 'ABSPATH' ) || exit;

/**
 * HTML for the parts of the dashboard BD Courier adds.
 */
class BDC_View {

	public static function badge( BDC_Courier $courier ) {
		return sprintf(
			'<span class="bdc-badge bdc-badge--%s">%s</span>',
			esc_attr( $courier->id() ),
			esc_html( $courier->label() )
		);
	}

	/**
	 * The bar at the top of every BD Courier page: logo and name on the
	 * left, the pages on the right.
	 *
	 * @param string $current shipments|reports|settings
	 */
	public static function header( $current ) {
		$pages = array(
			'shipments' => array( __( 'Shipments', 'bd-courier' ), BDC_Shipments::url() ),
			'reports'   => array( __( 'Reports', 'bd-courier' ), BDC_Reports::url() ),
			'settings'  => array( __( 'Settings', 'bd-courier' ), BDC_Settings_Page::url() ),
		);
		$logo  = BDC_Brand::logo_url( 'thumbnail' );

		$html  = '<div class="bdc-header"><div class="bdc-header__brand">';
		$html .= $logo
			? '<img src="' . esc_url( $logo ) . '" alt="">'
			: '<span class="bdc-header__mark" aria-hidden="true">' . BDC_Brand::icon_svg( 20 ) . '</span>';
		$html .= '<span class="bdc-header__name">' . esc_html( BDC_Brand::name() ) . '</span></div>';
		$html .= '<nav class="bdc-header__nav" aria-label="' . esc_attr( BDC_Brand::name() ) . '">';

		foreach ( $pages as $key => $page ) {
			$html .= sprintf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( $page[1] ),
				$key === $current ? 'is-current' : '',
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $page[0] )
			);
		}

		return $html . '</nav></div>';
	}

	/** A small square button that shows only an icon; the label is read out and shown on hover. */
	private static function icon_button( $icon, $label, $class, $order_id ) {
		return sprintf(
			'<button type="button" class="bdc-icon %s" data-order="%d" title="%s" aria-label="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span></button>',
			esc_attr( $class ),
			$order_id,
			esc_attr( $label ),
			esc_attr( $label ),
			esc_attr( $icon )
		);
	}

	private static function icon_link( $icon, $label, $url ) {
		return sprintf(
			'<a class="bdc-icon" href="%s" target="_blank" rel="noopener" title="%s" aria-label="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span></a>',
			esc_url( $url ),
			esc_attr( $label ),
			esc_attr( $label ),
			esc_attr( $icon )
		);
	}

	public static function money( $amount ) {
		return wp_strip_all_tags( wc_price( (float) $amount, array( 'decimals' => 0 ) ) );
	}

	/**
	 * The "Courier" cell of an order: send buttons, or the parcel summary.
	 */
	public static function cell( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		$html     = $shipment ? self::parcel( $order, $shipment ) : self::send_buttons( $order );

		return sprintf( '<div class="bdc-cell" data-order="%d">%s</div>', $order->get_id(), $html );
	}

	private static function send_buttons( WC_Order $order ) {
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ) ) ) {
			return '<span class="bdc-muted">&mdash;</span>';
		}

		$buttons = '';
		foreach ( BDC_Couriers::active() as $courier ) {
			$buttons .= sprintf(
				'<button type="button" class="bdc-send bdc-send--%1$s" data-courier="%1$s" data-order="%2$d" title="%3$s" aria-label="%3$s"><svg class="bdc-send__icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>%4$s</button>',
				esc_attr( $courier->id() ),
				$order->get_id(),
				/* translators: 1: courier name, 2: order number */
				esc_attr( sprintf( __( 'Send order #%2$s with %1$s', 'bd-courier' ), $courier->label(), $order->get_order_number() ) ),
				esc_html( $courier->label() )
			);
		}

		if ( '' === $buttons ) {
			return sprintf(
				'<a class="bdc-muted" href="%s">%s</a>',
				esc_url( BDC_Settings_Page::url() ),
				esc_html__( 'Connect a courier', 'bd-courier' )
			);
		}

		return '<span class="bdc-cell__buttons">' . $buttons . '</span>' . self::score_chip( $order );
	}

	private static function parcel( WC_Order $order, array $shipment ) {
		$parts = self::parcel_parts( $order, $shipment );
		if ( ! $parts ) {
			return '';
		}

		return sprintf(
			'<div class="bdc-parcel__top">%1$s%2$s</div>%3$s%4$s%5$s<div class="bdc-parcel__actions">%6$s</div>',
			$parts['badge'],
			$parts['consignment'],
			$parts['status'],
			$parts['facts'],
			$parts['flag'],
			$parts['actions']
		);
	}

	/**
	 * The pieces a parcel is shown with, each as ready HTML.
	 *
	 * @return array badge, consignment, status, facts, flag, actions. Empty when the courier is unknown.
	 */
	public static function parcel_parts( WC_Order $order, array $shipment ) {
		$courier = BDC_Couriers::get( $shipment['courier'] );
		if ( ! $courier ) {
			return array();
		}

		$panel_url = $courier->panel_url( $shipment );
		$track_url = $courier->tracking_url( $shipment );
		$id_text   = '' !== $shipment['consignment_id'] ? $shipment['consignment_id'] : __( 'ID pending', 'bd-courier' );

		$consignment = $panel_url
			? sprintf(
				'<a class="bdc-consignment" href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
				esc_url( $panel_url ),
				/* translators: %s: courier name */
				esc_attr( sprintf( __( 'Open in the %s merchant panel', 'bd-courier' ), $courier->label() ) ),
				esc_html( $id_text )
			)
			: '<span class="bdc-consignment">' . esc_html( $id_text ) . '</span>';

		$status = '' !== $shipment['courier_status'] ? $courier->status_label( $shipment['courier_status'] ) : BDC_Statuses::label( $shipment['status'] );

		$facts = array();
		if ( '' !== (string) $shipment['cod'] ) {
			/* translators: %s: amount */
			$facts[] = sprintf( __( 'COD %s', 'bd-courier' ), self::money( $shipment['cod'] ) );
		}
		if ( '' !== (string) $shipment['delivery_fee'] ) {
			/* translators: %s: amount */
			$facts[] = sprintf( __( 'Fee %s', 'bd-courier' ), self::money( $shipment['delivery_fee'] ) );
		}

		$flag = '';
		if ( ! empty( $shipment['unsynced'] ) ) {
			$flag = sprintf(
				'<div class="bdc-flag"><span class="bdc-flag__text"><span class="dashicons dashicons-warning" aria-hidden="true"></span>%s</span><span class="bdc-flag__actions">%s<button type="button" class="bdc-link bdc-synced" data-order="%d">%s</button></span></div>',
				/* translators: %s: courier name */
				esc_html( sprintf( __( 'Edited here, not on %s yet', 'bd-courier' ), $courier->label() ) ),
				$panel_url
					? sprintf(
						'<a class="bdc-link" href="%s" target="_blank" rel="noopener">%s</a>',
						esc_url( $panel_url ),
						/* translators: %s: courier name */
						esc_html( sprintf( __( 'Open %s', 'bd-courier' ), $courier->label() ) )
					)
					: '',
				$order->get_id(),
				esc_html__( 'Mark as updated', 'bd-courier' )
			);
		}

		$id      = $order->get_id();
		/* translators: %s: courier name */
		$actions = self::icon_button( 'update', sprintf( __( 'Get the latest status from %s', 'bd-courier' ), $courier->label() ), 'bdc-refresh', $id );
		// No edit button: neither Pathao nor Steadfast lets other software change a booked parcel.
		if ( $track_url ) {
			/* translators: %s: courier name */
			$actions .= self::icon_link( 'location', sprintf( __( 'Track on %s', 'bd-courier' ), $courier->label() ), $track_url );
		} elseif ( $panel_url ) {
			/* translators: %s: courier name */
			$actions .= self::icon_link( 'location', sprintf( __( 'Open this parcel in the %s merchant panel', 'bd-courier' ), $courier->label() ), $panel_url );
		}
		$actions .= self::icon_link( 'printer', __( 'Print the invoice or parcel label', 'bd-courier' ), BDC_Print::url( $id ) );

		return array(
			'badge'       => self::badge( $courier ),
			'consignment' => $consignment,
			'status'      => sprintf(
				'<div class="bdc-parcel__status bdc-state--%s">%s</div>',
				esc_attr( $shipment['status'] ? $shipment['status'] : 'sent' ),
				esc_html( $status )
			),
			'facts'       => $facts ? '<div class="bdc-parcel__facts">' . esc_html( implode( ' · ', $facts ) ) . '</div>' : '',
			'flag'        => $flag,
			'actions'     => $actions,
		);
	}

	/**
	 * Small line under the send buttons: the customer's record if it was
	 * already looked up, otherwise a link to look it up.
	 */
	public static function score_chip( WC_Order $order ) {
		$phone  = BDC_Fraud::order_phone( $order );
		$record = BDC_Courier::is_valid_phone( $phone ) ? BDC_Fraud::cached( $phone ) : null;

		return sprintf(
			'<button type="button" class="bdc-link bdc-score-open bdc-score-chip bdc-level--%s" data-order="%d">%s</button>',
			esc_attr( $record ? $record['level'] : 'none' ),
			$order->get_id(),
			esc_html( $record ? BDC_Fraud::short_label( $record ) : __( 'Check customer', 'bd-courier' ) )
		);
	}

	private static function score_line( $what, array $data ) {
		$bits = array();

		if ( null !== $data['total'] ) {
			/* translators: 1: number, 2: "parcels" or "orders" */
			$bits[] = sprintf( '%1$d %2$s', $data['total'], $what );
		}
		if ( null !== $data['delivered'] ) {
			/* translators: %d: number delivered */
			$bits[] = sprintf( __( '%d delivered', 'bd-courier' ), $data['delivered'] );
		}
		if ( null !== $data['cancelled'] ) {
			/* translators: %d: number cancelled or returned */
			$bits[] = sprintf( __( '%d cancelled or returned', 'bd-courier' ), $data['cancelled'] );
		}
		if ( isset( $data['ratio'] ) && null !== $data['ratio'] ) {
			/* translators: %d: percentage */
			$bits[] = sprintf( __( '%d%% delivered', 'bd-courier' ), $data['ratio'] );
		}
		if ( ! $bits && ! empty( $data['rating'] ) ) {
			/* translators: %s: rating from the courier, e.g. "New customer" */
			$bits[] = sprintf( __( 'Rated "%s" by the courier', 'bd-courier' ), BDC_Fraud::rating_text( $data['rating'] ) );
		}

		return implode( ' · ', $bits );
	}

	/**
	 * The customer's delivery record: one line per courier, plus this shop.
	 *
	 * @param array|null $record Null shows the "look it up" button instead.
	 */
	public static function score_panel( WC_Order $order, $record = null ) {
		$id = $order->get_id();

		if ( ! $record ) {
			return sprintf(
				'<div class="bdc-score" data-order="%d"><button type="button" class="button bdc-score-check" data-order="%d">%s</button><span class="bdc-muted">%s</span></div>',
				$id,
				$id,
				esc_html__( 'Check customer record', 'bd-courier' ),
				esc_html__( 'Looks up this phone number on your connected couriers.', 'bd-courier' )
			);
		}

		$rows = '';
		foreach ( $record['couriers'] as $courier_id => $data ) {
			$courier = BDC_Couriers::get( $courier_id );
			if ( ! $courier ) {
				continue;
			}
			$rows .= sprintf(
				'<li>%s<span class="%s">%s</span></li>',
				self::badge( $courier ),
				isset( $data['error'] ) ? 'bdc-score__error' : '',
				esc_html( isset( $data['error'] ) ? $data['error'] : self::score_line( __( 'parcels', 'bd-courier' ), $data ) )
			);
		}
		if ( ! $record['couriers'] ) {
			$rows .= '<li><span class="bdc-muted">' . esc_html__( 'No courier is connected, so only your own shop history is shown.', 'bd-courier' ) . '</span></li>';
		}
		$rows .= sprintf(
			'<li><span class="bdc-badge bdc-badge--shop">%s</span><span>%s</span></li>',
			esc_html__( 'Your shop', 'bd-courier' ),
			esc_html( $record['shop']['total'] ? self::score_line( __( 'orders', 'bd-courier' ), $record['shop'] + array( 'ratio' => null ) ) : __( 'No earlier orders', 'bd-courier' ) )
		);

		return sprintf(
			'<div class="bdc-score" data-order="%1$d"><div class="bdc-score__head"><span class="bdc-score__level bdc-level--%2$s">%3$s</span><span class="bdc-muted">%4$s</span><button type="button" class="bdc-link bdc-score-check" data-order="%1$d" data-force="1">%5$s</button></div><ul class="bdc-score__rows">%6$s</ul></div>',
			$id,
			esc_attr( $record['level'] ),
			esc_html( BDC_Fraud::level_label( $record ) ),
			/* translators: %s: time ago, e.g. "2 hours" */
			esc_html( sprintf( __( 'Checked %s ago', 'bd-courier' ), human_time_diff( (int) $record['checked_at'] ) ) ),
			esc_html__( 'Check again', 'bd-courier' ),
			$rows
		);
	}

	/** The order status pill, as WooCommerce draws it in the order list. */
	/** Small delivery-truck icon used on send buttons. */
	public static function truck_icon( $class = 'bdc-send__icon' ) {
		return str_replace( 'class="bdc-send__icon"', 'class="' . esc_attr( $class ) . '"', '<svg class="bdc-send__icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>' );
	}

	public static function status_mark( WC_Order $order ) {
		return sprintf(
			'<mark class="order-status %s"><span>%s</span></mark>',
			esc_attr( sanitize_html_class( 'status-' . $order->get_status() ) ),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);
	}

	/**
	 * Timeline shown on the order page.
	 */
	public static function history( array $shipment ) {
		$courier = BDC_Couriers::get( $shipment['courier'] );
		if ( ! $courier || empty( $shipment['history'] ) ) {
			return '';
		}

		$rows = '';
		foreach ( array_reverse( $shipment['history'] ) as $entry ) {
			$rows .= sprintf(
				'<li><span class="bdc-history__status">%s</span><span class="bdc-history__time">%s</span>%s</li>',
				esc_html( $courier->status_label( $entry['status'] ?? '' ) ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $entry['time'] ?? 0 ) ) ),
				! empty( $entry['message'] ) ? '<span class="bdc-history__message">' . esc_html( $entry['message'] ) . '</span>' : ''
			);
		}

		return '<ul class="bdc-history">' . $rows . '</ul>';
	}

	/** The send form shown in the popup, prefilled from the order. */
	public static function form( BDC_Courier $courier, WC_Order $order ) {
		$fields = $courier->form_fields( $order );
		$groups = array(
			'customer' => __( 'Customer', 'bd-courier' ),
			'parcel'   => __( 'Parcel', 'bd-courier' ),
		);

		$paid = $order->get_date_paid() && 'cod' !== $order->get_payment_method();

		ob_start();
		?>
		<form class="bdc-form" data-courier="<?php echo esc_attr( $courier->id() ); ?>" data-order="<?php echo esc_attr( $order->get_id() ); ?>" novalidate>
			<header class="bdc-form__header">
				<?php echo self::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2 id="bdc-modal-title">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: order number, 2: courier name */
							__( 'Send order #%1$s with %2$s', 'bd-courier' ),
							$order->get_order_number(),
							$courier->label()
						)
					);
					?>
				</h2>
				<button type="button" class="bdc-modal__close" aria-label="<?php esc_attr_e( 'Close', 'bd-courier' ); ?>">&times;</button>
			</header>

			<div class="bdc-form__body">
				<div class="bdc-summary">
					<span><strong><?php esc_html_e( 'Order total', 'bd-courier' ); ?></strong> <?php echo esc_html( self::money( $order->get_total() ) ); ?></span>
					<span class="bdc-pill <?php echo $paid ? 'bdc-pill--ok' : 'bdc-pill--warn'; ?>">
						<?php echo $paid ? esc_html__( 'Paid', 'bd-courier' ) : esc_html__( 'Not paid yet', 'bd-courier' ); ?>
					</span>
					<span><?php echo esc_html( $order->get_payment_method_title() ); ?></span>
					<span>
						<?php
						/* translators: %d: number of items */
						echo esc_html( sprintf( _n( '%d item', '%d items', $order->get_item_count(), 'bd-courier' ), $order->get_item_count() ) );
						?>
					</span>
				</div>

				<?php
				$score_phone = BDC_Fraud::order_phone( $order );
				echo self::score_panel( $order, BDC_Courier::is_valid_phone( $score_phone ) ? BDC_Fraud::cached( $score_phone ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>

				<?php foreach ( $groups as $group => $title ) : ?>
					<fieldset class="bdc-fieldset">
						<legend><?php echo esc_html( $title ); ?></legend>
						<div class="bdc-grid">
							<?php
							foreach ( $fields as $key => $field ) {
								if ( ( $field['group'] ?? 'parcel' ) === $group ) {
									self::field( $key, $field );
								}
							}
							?>
						</div>
					</fieldset>
				<?php endforeach; ?>

				<div class="bdc-form__error" role="alert" hidden></div>
			</div>

			<footer class="bdc-form__footer">
				<span class="bdc-form__hint">
					<?php
					/* translators: %s: courier name */
					echo esc_html( sprintf( __( 'Nothing is sent to %s until you press the button.', 'bd-courier' ), $courier->label() ) );
					?>
				</span>
				<button type="button" class="button bdc-modal__cancel"><?php esc_html_e( 'Cancel', 'bd-courier' ); ?></button>
				<button type="submit" class="button button-primary bdc-form__submit">
					<?php echo self::truck_icon( 'bdc-form__submit-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php
					/* translators: %s: courier name */
					echo esc_html( sprintf( __( 'Send with %s', 'bd-courier' ), $courier->label() ) );
					?>
				</button>
			</footer>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function field( $key, array $field ) {
		$id       = 'bdc-field-' . $key;
		$type     = $field['type'] ?? 'text';
		$required = ! empty( $field['required'] );
		$width    = $field['width'] ?? 'full';
		$value    = (string) ( $field['value'] ?? '' );

		if ( 'note' === $type ) {
			echo '<p class="bdc-field bdc-field__help bdc-field--note">' . esc_html( $field['label'] ) . '</p>';
			return;
		}
		?>
		<div class="bdc-field bdc-field--<?php echo esc_attr( $width ); ?>" data-field="<?php echo esc_attr( $key ); ?>">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( $required ) : ?>
					<span class="bdc-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>

			<?php if ( 'textarea' === $type ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="2" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( $value ); ?></textarea>
			<?php elseif ( 'select' === $type ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php echo $required ? 'required' : ''; ?>>
					<?php if ( isset( $field['placeholder'] ) ) : ?>
						<option value=""><?php echo esc_html( $field['placeholder'] ); ?></option>
					<?php endif; ?>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $option_value, $value ); ?>><?php echo esc_html( $option_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php echo isset( $field['placeholder'] ) ? 'placeholder="' . esc_attr( $field['placeholder'] ) . '"' : ''; ?>
					<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : ''; ?>
					<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : ''; ?>
					<?php echo isset( $field['step'] ) ? 'step="' . esc_attr( $field['step'] ) . '"' : ''; ?>
					<?php echo 'tel' === $type ? 'inputmode="numeric" autocomplete="off"' : ''; ?>
					<?php echo $required ? 'required' : ''; ?>
				>
			<?php endif; ?>

			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="bdc-field__help"><?php echo esc_html( $field['help'] ); ?></p>
			<?php endif; ?>
			<p class="bdc-field__error" hidden></p>
		</div>
		<?php
	}
}

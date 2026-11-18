<?php
defined( 'ABSPATH' ) || exit;

/**
 * BD Courier → Shipments: every parcel from both couriers in one table.
 */
class BDC_Shipments {

	const SLUG     = 'bd-courier';
	const PER_PAGE = 25;

	public static $hook = '';

	public static function init() {
		add_action( 'wp_ajax_bdc_shipment_row', array( __CLASS__, 'ajax_row' ) );
	}

	public static function is_current_screen() {
		$screen = get_current_screen();
		return $screen && self::$hook === $screen->id;
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), array_filter( $args, 'strlen' ) ), admin_url( 'admin.php' ) );
	}

	/* ---- Filters ---- */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$text = function ( $key ) {
			return isset( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
		};
		$date = function ( $key ) use ( $text ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text( $key ) ) ? $text( $key ) : '';
		};

		$courier = sanitize_key( $text( 'courier' ) );
		$state   = sanitize_key( $text( 'state' ) );

		return array(
			'courier' => isset( BDC_Couriers::shown()[ $courier ] ) ? $courier : '',
			'state'   => '' !== BDC_Statuses::label( $state ) ? $state : '',
			's'       => $text( 's' ),
			'from'    => $date( 'from' ),
			'to'      => $date( 'to' ),
			'paged'   => max( 1, absint( $_GET['paged'] ?? 1 ) ),
		);
		// phpcs:enable
	}

	/**
	 * Conditions for the parcels to show.
	 *
	 * @param array $filters See filters().
	 * @param array $skip    Filter names to leave out (used for the counts).
	 */
	private static function conditions( array $filters, array $skip = array() ) {
		$filters = array_merge( $filters, array_fill_keys( $skip, '' ) );
		$meta    = array(
			'relation' => 'AND',
			array(
				'key'     => '_bdc_courier',
				'value'   => $filters['courier'] ? array( $filters['courier'] ) : array_keys( BDC_Couriers::shown() ),
				'compare' => 'IN',
			),
		);

		if ( $filters['state'] ) {
			$meta[] = array(
				'key'   => '_bdc_status',
				'value' => $filters['state'],
			);
		}

		if ( $filters['from'] || $filters['to'] ) {
			$zone   = wp_timezone();
			$from   = $filters['from'] ? ( new DateTime( $filters['from'] . ' 00:00:00', $zone ) )->getTimestamp() : 0;
			$to     = $filters['to'] ? ( new DateTime( $filters['to'] . ' 23:59:59', $zone ) )->getTimestamp() : time() + DAY_IN_SECONDS;
			$meta[] = array(
				'key'     => '_bdc_sent_at',
				'value'   => array( $from, $to ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		}

		if ( '' !== $filters['s'] ) {
			$term   = ltrim( $filters['s'], '#' );
			$phone  = preg_replace( '/\D+/', '', $term );
			$search = array(
				'relation' => 'OR',
				array(
					'key'     => '_bdc_consignment_id',
					'value'   => $term,
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_bdc_name',
					'value'   => $term,
					'compare' => 'LIKE',
				),
			);
			if ( strlen( $phone ) >= 4 ) {
				$search[] = array(
					'key'     => '_bdc_phone',
					'value'   => BDC_Courier::clean_phone( $phone ),
					'compare' => 'LIKE',
				);
			}
			$meta[] = $search;
		}

		return $meta;
	}

	private static function query( array $meta, $page, $per_page ) {
		return BDC_Shipment::query_orders(
			$meta,
			array(
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);
	}

	private static function count( array $filters, $state ) {
		$filters['state'] = $state;
		$result           = self::query( self::conditions( $filters ), 1, 1 );
		return (int) $result->total;
	}

	/* ---- Page ---- */

	public static function render() {
		$filters = self::filters();
		$result  = self::query( self::conditions( $filters ), $filters['paged'], self::PER_PAGE );
		$orders  = $result->orders;
		$total   = (int) $result->total;

		// A plain order number in the search box also finds that order.
		$number = ltrim( $filters['s'], '#' );
		if ( 1 === $filters['paged'] && ctype_digit( $number ) ) {
			$match = wc_get_order( (int) $number );
			if ( $match instanceof WC_Order && ! $match instanceof WC_Order_Refund && $match->get_meta( '_bdc_courier' ) ) {
				$ids = array();
				foreach ( $orders as $order ) {
					$ids[] = $order->get_id();
				}
				if ( ! in_array( $match->get_id(), $ids, true ) ) {
					array_unshift( $orders, $match );
					++$total;
				}
			}
		}

		$base = array(
			'courier' => $filters['courier'],
			'state'   => $filters['state'],
			's'       => $filters['s'],
			'from'    => $filters['from'],
			'to'      => $filters['to'],
		);
		?>
		<div class="wrap bdc-page bdc-shipments">
			<?php echo BDC_View::header( 'shipments' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="bdc-titlebar">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Shipments', 'bd-courier' ); ?></h1>
				<?php if ( $orders ) : ?>
					<div class="bdc-shipments__tools">
						<span class="bdc-shipments__progress bdc-muted" role="status"></span>
						<button type="button" class="button bdc-print-page"><span class="dashicons dashicons-printer" aria-hidden="true"></span><?php esc_html_e( 'Print this page', 'bd-courier' ); ?></button>
						<button type="button" class="button bdc-refresh-all"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Refresh statuses', 'bd-courier' ); ?></button>
					</div>
				<?php endif; ?>
			</div>
			<hr class="wp-header-end">

			<?php if ( count( BDC_Couriers::shown() ) > 1 ) : ?>
			<nav class="bdc-tabs" aria-label="<?php esc_attr_e( 'Courier', 'bd-courier' ); ?>">
				<a class="<?php echo '' === $filters['courier'] ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( array_merge( $base, array( 'courier' => '' ) ) ) ); ?>"><?php esc_html_e( 'All couriers', 'bd-courier' ); ?></a>
				<?php foreach ( BDC_Couriers::shown() as $courier ) : ?>
					<a class="<?php echo $courier->id() === $filters['courier'] ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( array_merge( $base, array( 'courier' => $courier->id() ) ) ) ); ?>"><?php echo esc_html( $courier->label() ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>

			<div class="bdc-filterbox">
				<form method="get" class="bdc-shipments__filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="courier" value="<?php echo esc_attr( $filters['courier'] ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $filters['state'] ); ?>">

					<label class="bdc-search">
						<span class="screen-reader-text"><?php esc_html_e( 'Search shipments', 'bd-courier' ); ?></span>
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search order, consignment ID, name or phone', 'bd-courier' ); ?>">
					</label>

					<fieldset class="bdc-daterange">
						<legend class="screen-reader-text"><?php esc_html_e( 'Sent', 'bd-courier' ); ?></legend>
						<span class="bdc-daterange__icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
						<span class="bdc-daterange__caption" aria-hidden="true"><?php esc_html_e( 'Sent', 'bd-courier' ); ?></span>
						<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" aria-label="<?php esc_attr_e( 'Sent from', 'bd-courier' ); ?>">
						<span class="bdc-daterange__arrow" aria-hidden="true">&rarr;</span>
						<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" aria-label="<?php esc_attr_e( 'Sent until', 'bd-courier' ); ?>">
					</fieldset>

					<span class="bdc-filterbox__buttons">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'bd-courier' ); ?></button>
						<?php if ( $filters['s'] || $filters['from'] || $filters['to'] ) : ?>
							<a class="button" href="<?php echo esc_url( self::url( array( 'courier' => $filters['courier'], 'state' => $filters['state'] ) ) ); ?>"><?php esc_html_e( 'Clear', 'bd-courier' ); ?></a>
						<?php endif; ?>
					</span>
				</form>

				<div class="bdc-chips" role="group" aria-label="<?php esc_attr_e( 'Parcel status', 'bd-courier' ); ?>">
					<?php
					$views = array( '' => __( 'All', 'bd-courier' ) ) + BDC_Statuses::all();
					foreach ( $views as $state => $label ) {
						$number = self::count( $filters, $state );
						if ( ! $number && '' !== $state && $state !== $filters['state'] ) {
							continue;
						}
						printf(
							'<a href="%s" class="bdc-chip %s"%s>%s<span>%s</span></a>',
							esc_url( self::url( array_merge( $base, array( 'state' => $state ) ) ) ),
							$state === $filters['state'] ? 'is-current' : '',
							$state === $filters['state'] ? ' aria-current="true"' : '',
							esc_html( $label ),
							esc_html( number_format_i18n( $number ) )
						);
					}
					?>
				</div>
			</div>

			<div class="bdc-shipments__list">
			<table class="bdc-shipments__table">
				<thead>
					<tr>
						<th class="bdc-col-order"><?php esc_html_e( 'Order', 'bd-courier' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'bd-courier' ); ?></th>
						<th class="bdc-col-courier"><?php esc_html_e( 'Courier', 'bd-courier' ); ?></th>
						<th><?php esc_html_e( 'Parcel status', 'bd-courier' ); ?></th>
						<th class="bdc-col-money"><?php esc_html_e( 'Cash to collect', 'bd-courier' ); ?></th>
						<th class="bdc-col-money"><?php esc_html_e( 'Delivery fee', 'bd-courier' ); ?></th>
						<th class="bdc-col-sent"><?php esc_html_e( 'Sent', 'bd-courier' ); ?></th>
						<th class="bdc-col-actions"><?php esc_html_e( 'Actions', 'bd-courier' ); ?></th>
						<th class="bdc-col-toggle"><span class="screen-reader-text"><?php esc_html_e( 'Details', 'bd-courier' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $orders ) : ?>
						<tr class="no-items">
							<td colspan="9">
								<?php
								if ( $filters['s'] || $filters['from'] || $filters['to'] || $filters['state'] ) {
									esc_html_e( 'No shipments match these filters.', 'bd-courier' );
								} else {
									/* translators: %s: courier names */
									echo esc_html( sprintf( __( 'No shipments yet. Send an order with %s from the Orders screen and it will appear here.', 'bd-courier' ), BDC_Couriers::names() ) );
								}
								?>
							</td>
						</tr>
					<?php endif; ?>
					<?php
					foreach ( $orders as $order ) {
						echo self::row( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</tbody>
			</table>
			</div>

			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						/* translators: %s: number of shipments */
						echo esc_html( sprintf( _n( '%s shipment', '%s shipments', $total, 'bd-courier' ), number_format_i18n( $total ) ) );
						?>
					</span>
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', self::url( $base ) ),
								'format'    => '',
								'current'   => $filters['paged'],
								'total'     => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
								'prev_text' => '&lsaquo;',
								'next_text' => '&rsaquo;',
							)
						)
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/** One table row. Also sent back on its own after a refresh or an edit. */
	public static function row( WC_Order $order ) {
		$shipment = BDC_Shipment::get( $order );
		$parts    = $shipment ? BDC_View::parcel_parts( $order, $shipment ) : array();
		if ( ! $parts ) {
			return '';
		}

		$name   = '' !== (string) ( $shipment['details']['recipient_name'] ?? '' ) ? $shipment['details']['recipient_name'] : $order->get_formatted_billing_full_name();
		$phone  = '' !== (string) ( $shipment['details']['recipient_phone'] ?? '' ) ? $shipment['details']['recipient_phone'] : $order->get_billing_phone();
		$format = 'j M Y, g:i a';

		// The order status is only worth a line when it says something the parcel status does not.
		$order_status  = wc_get_order_status_name( $order->get_status() );
		$parcel_status = BDC_Statuses::label( $shipment['status'] );
		$show_order    = 0 !== strcasecmp( $order_status, $parcel_status );

		ob_start();
		?>
		<tr class="bdc-shipment" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-finished="<?php echo BDC_Shipment::is_open( $shipment ) ? '0' : '1'; ?>">
			<td class="bdc-col-order" data-label="<?php esc_attr_e( 'Order', 'bd-courier' ); ?>">
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a>
				<span class="bdc-muted"><?php echo esc_html( BDC_View::money( $order->get_total() ) ); ?></span>
			</td>
			<td class="bdc-col-customer" data-label="<?php esc_attr_e( 'Customer', 'bd-courier' ); ?>">
				<?php echo esc_html( $name ); ?>
				<span class="bdc-muted bdc-block"><span class="bdc-phone" data-phone="<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></span></span>
			</td>
			<td class="bdc-col-courier" data-label="<?php esc_attr_e( 'Courier', 'bd-courier' ); ?>"><div class="bdc-parcel__top"><?php echo $parts['badge'] . $parts['consignment']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div></td>
			<td class="bdc-col-status" data-label="<?php esc_attr_e( 'Parcel status', 'bd-courier' ); ?>">
				<?php echo $parts['status']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( $show_order ) : ?>
					<span class="bdc-muted bdc-block">
						<?php
						/* translators: %s: order status */
						echo esc_html( sprintf( __( 'Order: %s', 'bd-courier' ), $order_status ) );
						?>
					</span>
				<?php endif; ?>
				<?php echo $parts['flag']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</td>
			<td class="bdc-col-money" data-label="<?php esc_attr_e( 'Cash to collect', 'bd-courier' ); ?>"><?php echo '' !== (string) $shipment['cod'] ? esc_html( BDC_View::money( $shipment['cod'] ) ) : '&mdash;'; ?></td>
			<td class="bdc-col-money" data-label="<?php esc_attr_e( 'Delivery fee', 'bd-courier' ); ?>"><?php echo '' !== (string) $shipment['delivery_fee'] ? esc_html( BDC_View::money( $shipment['delivery_fee'] ) ) : '&mdash;'; ?></td>
			<td class="bdc-col-sent" data-label="<?php esc_attr_e( 'Sent', 'bd-courier' ); ?>" title="<?php echo esc_attr( $shipment['updated_at'] ? sprintf( /* translators: %s: time ago */ __( 'Last update %s ago', 'bd-courier' ), human_time_diff( (int) $shipment['updated_at'] ) ) : '' ); ?>">
				<?php echo $shipment['sent_at'] ? esc_html( wp_date( $format, (int) $shipment['sent_at'] ) ) : '&mdash;'; ?>
			</td>
			<td class="bdc-col-actions" data-label="<?php esc_attr_e( 'Actions', 'bd-courier' ); ?>"><div class="bdc-parcel__actions"><?php echo $parts['actions']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div></td>
			<td class="bdc-col-toggle">
				<button type="button" class="bdc-row-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Show all details', 'bd-courier' ); ?>" title="<?php esc_attr_e( 'Show all details', 'bd-courier' ); ?>">
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
				</button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public static function ajax_row() {
		BDC_Ajax::guard();

		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'This order could not be found. Please reload the page.', 'bd-courier' ) ), 404 );
		}

		wp_send_json_success( array( 'row' => self::row( $order ) ) );
	}
}

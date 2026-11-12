<?php
defined( 'ABSPATH' ) || exit;

/**
 * BD Courier → Reports: how each courier performed in a period.
 *
 * Everything is counted by the date the parcel was sent, and Pathao and
 * Steadfast are always shown side by side, never mixed.
 */
class BDC_Reports {

	const SLUG = 'bd-courier-reports';

	public static $hook = '';

	public static function init() {
		add_action( 'admin_post_bdc_report_csv', array( __CLASS__, 'csv' ) );
	}

	public static function is_current_screen() {
		$screen = get_current_screen();
		return $screen && self::$hook === $screen->id;
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ---- Period ---- */

	public static function presets() {
		return array(
			'today'      => __( 'Today', 'bd-courier' ),
			'7'          => __( 'Last 7 days', 'bd-courier' ),
			'30'         => __( 'Last 30 days', 'bd-courier' ),
			'month'      => __( 'This month', 'bd-courier' ),
			'last_month' => __( 'Last month', 'bd-courier' ),
		);
	}

	/**
	 * The chosen period.
	 *
	 * @return array range, from, to (Y-m-d), start, end (timestamps)
	 */
	private static function period() {
		// phpcs:disable WordPress.Security.NonceVerification
		$zone  = wp_timezone();
		$today = new DateTimeImmutable( 'today', $zone );
		$range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30';
		$valid = function ( $key ) {
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		// phpcs:enable

		$from = $today;
		$to   = $today;

		if ( 'custom' === $range && $valid( 'from' ) && $valid( 'to' ) ) {
			$from = new DateTimeImmutable( $valid( 'from' ), $zone );
			$to   = new DateTimeImmutable( $valid( 'to' ), $zone );
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
		} elseif ( 'today' === $range ) {
			$from = $today;
		} elseif ( '7' === $range ) {
			$from = $today->modify( '-6 days' );
		} elseif ( 'month' === $range ) {
			$from = $today->modify( 'first day of this month' );
		} elseif ( 'last_month' === $range ) {
			$from = $today->modify( 'first day of last month' );
			$to   = $today->modify( 'last day of last month' );
		} else {
			$range = '30';
			$from  = $today->modify( '-29 days' );
		}

		return array(
			'range' => $range,
			'from'  => $from->format( 'Y-m-d' ),
			'to'    => $to->format( 'Y-m-d' ),
			'start' => $from->getTimestamp(),
			'end'   => $to->modify( '+1 day' )->getTimestamp() - 1,
		);
	}

	/* ---- Numbers ---- */

	private static function blank() {
		return array(
			'sent'          => 0,
			'delivered'     => 0,
			'partial'       => 0,
			'returned'      => 0,
			'hold'          => 0,
			'on_the_way'    => 0,
			'cod_all'       => 0.0,
			'cod_collected' => 0.0,
			'cod_out'       => 0.0,
			'fees'          => 0.0,
			'fees_known'    => 0,
		);
	}

	/**
	 * Every parcel sent in the period, as plain rows.
	 *
	 * @return array[]
	 */
	private static function parcels( array $period ) {
		$rows = array();
		$page = 1;

		do {
			$orders = BDC_Shipment::query_orders(
				array(
					array(
						'key'     => '_bdc_sent_at',
						'value'   => array( $period['start'], $period['end'] ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
				array(
					'limit'   => 200,
					'page'    => $page,
					'orderby' => 'date',
					'order'   => 'ASC',
				)
			);

			foreach ( $orders as $order ) {
				$shipment = BDC_Shipment::get( $order );
				if ( ! $shipment || ! BDC_Couriers::get( $shipment['courier'] ) ) {
					continue;
				}

				$rows[] = array(
					'order'       => $order->get_order_number(),
					'courier'     => $shipment['courier'],
					'consignment' => $shipment['consignment_id'],
					'status'      => $shipment['status'] ? $shipment['status'] : 'sent',
					'raw_status'  => $shipment['courier_status'],
					'cod'         => (float) $shipment['cod'],
					'fee'         => '' === (string) $shipment['delivery_fee'] ? null : (float) $shipment['delivery_fee'],
					'sent_at'     => (int) $shipment['sent_at'],
					'name'        => (string) ( $shipment['details']['recipient_name'] ?? $order->get_formatted_billing_full_name() ),
					'phone'       => (string) ( $shipment['details']['recipient_phone'] ?? $order->get_billing_phone() ),
				);
			}

			++$page;
		} while ( 200 === count( $orders ) && $page <= 100 );

		return $rows;
	}

	/**
	 * Totals per courier, overall, and per day.
	 *
	 * @return array couriers => [id => totals], total => totals, days => [Y-m-d => [id => count]]
	 */
	private static function summarise( array $rows, array $period ) {
		$report = array(
			'couriers' => array(),
			'total'    => self::blank(),
			'days'     => array(),
		);
		foreach ( array_keys( BDC_Couriers::all() ) as $id ) {
			$report['couriers'][ $id ] = self::blank();
		}

		$zone = wp_timezone();
		$day  = new DateTimeImmutable( $period['from'], $zone );
		$last = new DateTimeImmutable( $period['to'], $zone );
		while ( $day <= $last && count( $report['days'] ) < 366 ) {
			$report['days'][ $day->format( 'Y-m-d' ) ] = array_fill_keys( array_keys( BDC_Couriers::all() ), 0 );
			$day                                      = $day->modify( '+1 day' );
		}

		foreach ( $rows as $row ) {
			foreach ( array( &$report['couriers'][ $row['courier'] ], &$report['total'] ) as &$sum ) {
				++$sum['sent'];
				$sum['cod_all'] += $row['cod'];

				if ( 'delivered' === $row['status'] ) {
					++$sum['delivered'];
					$sum['cod_collected'] += $row['cod'];
				} elseif ( 'partial' === $row['status'] ) {
					++$sum['partial'];
				} elseif ( in_array( $row['status'], array( 'returned', 'cancelled' ), true ) ) {
					++$sum['returned'];
				} else {
					if ( 'hold' === $row['status'] ) {
						++$sum['hold'];
					} else {
						++$sum['on_the_way'];
					}
					$sum['cod_out'] += $row['cod'];
				}

				if ( null !== $row['fee'] ) {
					$sum['fees'] += $row['fee'];
					++$sum['fees_known'];
				}
			}
			unset( $sum );

			$date = wp_date( 'Y-m-d', $row['sent_at'] );
			if ( isset( $report['days'][ $date ][ $row['courier'] ] ) ) {
				++$report['days'][ $date ][ $row['courier'] ];
			}
		}

		return $report;
	}

	/** Share of finished parcels that were delivered, or null when none has finished. */
	private static function success_rate( array $sum ) {
		$finished = $sum['delivered'] + $sum['partial'] + $sum['returned'];
		return $finished ? (int) round( ( $sum['delivered'] + $sum['partial'] ) / $finished * 100 ) : null;
	}

	/* ---- Page ---- */

	public static function render() {
		$period   = self::period();
		$report   = self::summarise( self::parcels( $period ), $period );
		$couriers = BDC_Couriers::all();
		$total    = $report['total'];
		$rate     = self::success_rate( $total );
		$format   = get_option( 'date_format' );
		$query    = array(
			'range' => $period['range'],
			'from'  => $period['from'],
			'to'    => $period['to'],
		);
		?>
		<div class="wrap bdc-page bdc-reports">
			<?php echo BDC_View::header( 'reports' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Courier reports', 'bd-courier' ); ?></h1>
			<hr class="wp-header-end">

			<form method="get" class="bdc-reports__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="range" value="custom">
				<span class="bdc-segment" role="group" aria-label="<?php esc_attr_e( 'Period', 'bd-courier' ); ?>">
					<?php foreach ( self::presets() as $key => $label ) : ?>
						<a class="<?php echo (string) $key === $period['range'] ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( array( 'range' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</span>
				<span class="bdc-reports__dates">
					<label>
						<span><?php esc_html_e( 'From', 'bd-courier' ); ?></span>
						<input type="date" name="from" value="<?php echo esc_attr( $period['from'] ); ?>">
					</label>
					<label>
						<span><?php esc_html_e( 'to', 'bd-courier' ); ?></span>
						<input type="date" name="to" value="<?php echo esc_attr( $period['to'] ); ?>">
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Apply', 'bd-courier' ); ?></button>
				</span>
				<a class="button bdc-reports__csv" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'bdc_report_csv' ), $query ), admin_url( 'admin-post.php' ) ), 'bdc_report_csv' ) ); ?>">
					<span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Download CSV', 'bd-courier' ); ?>
				</a>
			</form>

			<p class="bdc-reports__period">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: start date, 2: end date */
						__( 'Parcels sent from %1$s to %2$s.', 'bd-courier' ),
						wp_date( $format, $period['start'] ),
						wp_date( $format, $period['end'] )
					)
				);
				?>
			</p>

			<?php if ( ! $total['sent'] ) : ?>
				<div class="bdc-reports__empty">
					<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
					<p><strong><?php esc_html_e( 'No parcels in this period', 'bd-courier' ); ?></strong></p>
					<p><?php esc_html_e( 'Try a longer period, or send an order with Pathao or Steadfast from the Orders screen.', 'bd-courier' ); ?></p>
				</div>
			<?php else : ?>

				<div class="bdc-stats">
					<?php
					self::stat( 'archive', __( 'Parcels sent', 'bd-courier' ), number_format_i18n( $total['sent'] ), sprintf( /* translators: %d: parcels still on the way */ __( '%d still on the way', 'bd-courier' ), $total['on_the_way'] + $total['hold'] ) );
					self::stat( 'yes-alt', __( 'Delivery success', 'bd-courier' ), null === $rate ? '—' : $rate . '%', __( 'of parcels that have finished', 'bd-courier' ), 'good' );
					self::stat( 'money-alt', __( 'Cash collected', 'bd-courier' ), BDC_View::money( $total['cod_collected'] ), __( 'from delivered parcels', 'bd-courier' ), 'good' );
					self::stat( 'clock', __( 'Cash still out', 'bd-courier' ), BDC_View::money( $total['cod_out'] ), __( 'parcels on the way or on hold', 'bd-courier' ), 'warn' );
					self::stat( 'tag', __( 'Delivery fees', 'bd-courier' ), BDC_View::money( $total['fees'] ), __( 'as reported by the couriers', 'bd-courier' ) );
					?>
				</div>

				<h2><?php esc_html_e( 'By courier', 'bd-courier' ); ?></h2>
				<div class="bdc-courier-cards">
					<?php
					foreach ( $couriers as $id => $courier ) {
						self::courier_card( $courier, $report['couriers'][ $id ] );
					}
					?>
				</div>

				<h2><?php esc_html_e( 'Side by side', 'bd-courier' ); ?></h2>
				<div class="bdc-table-scroll">
					<table class="bdc-reports__table">
						<thead>
							<tr>
								<th></th>
								<?php foreach ( $couriers as $courier ) : ?>
									<th class="bdc-num"><?php echo BDC_View::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
								<?php endforeach; ?>
								<th class="bdc-num"><?php esc_html_e( 'Total', 'bd-courier' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$lines = array(
								'sent'          => array( __( 'Parcels sent', 'bd-courier' ), 'count' ),
								'delivered'     => array( __( 'Delivered', 'bd-courier' ), 'count' ),
								'partial'       => array( __( 'Partial delivery', 'bd-courier' ), 'count' ),
								'returned'      => array( __( 'Returned or cancelled', 'bd-courier' ), 'count' ),
								'hold'          => array( __( 'On hold at courier', 'bd-courier' ), 'count' ),
								'on_the_way'    => array( __( 'On the way', 'bd-courier' ), 'count' ),
								'rate'          => array( __( 'Delivery success', 'bd-courier' ), 'rate' ),
								'cod_all'       => array( __( 'Cash to collect (all parcels)', 'bd-courier' ), 'money' ),
								'cod_collected' => array( __( 'Cash collected (delivered)', 'bd-courier' ), 'money' ),
								'cod_out'       => array( __( 'Cash still out', 'bd-courier' ), 'money' ),
								'fees'          => array( __( 'Delivery fees', 'bd-courier' ), 'money' ),
							);
							foreach ( $lines as $key => $line ) :
								?>
								<tr class="<?php echo in_array( $key, array( 'rate', 'cod_all' ), true ) ? 'bdc-reports__divider' : ''; ?>">
									<th scope="row"><?php echo esc_html( $line[0] ); ?></th>
									<?php foreach ( array_merge( array_values( $report['couriers'] ), array( $total ) ) as $sum ) : ?>
										<td class="bdc-num">
											<?php
											if ( 'rate' === $line[1] ) {
												$value = self::success_rate( $sum );
												echo esc_html( null === $value ? '—' : $value . '%' );
											} elseif ( 'money' === $line[1] ) {
												echo esc_html( BDC_View::money( $sum[ $key ] ) );
											} else {
												echo esc_html( number_format_i18n( $sum[ $key ] ) );
											}
											?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php self::chart( $report['days'], $couriers ); ?>

				<p class="bdc-reports__notes">
					<?php esc_html_e( 'How these are counted: delivery success looks only at parcels that have finished (delivered, partly delivered, returned or cancelled). Partial deliveries are left out of the cash figures, because the amount actually collected is not known. Delivery fees are included only where the courier has reported the fee.', 'bd-courier' ); ?>
				</p>

			<?php endif; ?>
		</div>
		<?php
	}

	private static function stat( $icon, $label, $value, $hint = '', $tone = '' ) {
		printf(
			'<div class="bdc-stat %s"><span class="bdc-stat__icon dashicons dashicons-%s" aria-hidden="true"></span><span class="bdc-stat__label">%s</span><strong class="bdc-stat__value">%s</strong>%s</div>',
			$tone ? 'bdc-stat--' . esc_attr( $tone ) : '',
			esc_attr( $icon ),
			esc_html( $label ),
			esc_html( $value ),
			$hint ? '<span class="bdc-stat__hint">' . esc_html( $hint ) . '</span>' : ''
		);
	}

	/** One courier at a glance: volume, success, where its parcels are, and its cash. */
	private static function courier_card( BDC_Courier $courier, array $sum ) {
		$rate  = self::success_rate( $sum );
		$parts = array(
			'delivered'  => array( __( 'Delivered', 'bd-courier' ), $sum['delivered'] ),
			'partial'    => array( __( 'Partial', 'bd-courier' ), $sum['partial'] ),
			'on_the_way' => array( __( 'On the way', 'bd-courier' ), $sum['on_the_way'] ),
			'hold'       => array( __( 'On hold', 'bd-courier' ), $sum['hold'] ),
			'returned'   => array( __( 'Returned', 'bd-courier' ), $sum['returned'] ),
		);
		?>
		<section class="bdc-courier-card bdc-courier-card--<?php echo esc_attr( $courier->id() ); ?>">
			<header class="bdc-courier-card__head">
				<?php echo BDC_View::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="bdc-courier-card__count">
					<?php
					/* translators: %s: number of parcels */
					echo esc_html( sprintf( _n( '%s parcel', '%s parcels', $sum['sent'], 'bd-courier' ), number_format_i18n( $sum['sent'] ) ) );
					?>
				</span>
			</header>

			<?php if ( ! $sum['sent'] ) : ?>
				<p class="bdc-courier-card__empty">
					<?php
					/* translators: %s: courier name */
					echo esc_html( sprintf( __( 'Nothing sent with %s in this period.', 'bd-courier' ), $courier->label() ) );
					?>
				</p>
			<?php else : ?>
				<div class="bdc-courier-card__rate">
					<strong><?php echo esc_html( null === $rate ? '—' : $rate . '%' ); ?></strong>
					<span><?php echo null === $rate ? esc_html__( 'No parcel has finished yet', 'bd-courier' ) : esc_html__( 'delivery success', 'bd-courier' ); ?></span>
				</div>

				<div class="bdc-split" role="img" aria-label="<?php esc_attr_e( 'Where the parcels are now', 'bd-courier' ); ?>">
					<?php foreach ( $parts as $key => $part ) : ?>
						<?php if ( $part[1] ) : ?>
							<i class="bdc-split__part bdc-split__part--<?php echo esc_attr( $key ); ?>" style="flex-grow: <?php echo esc_attr( $part[1] ); ?>;" title="<?php echo esc_attr( $part[0] . ': ' . $part[1] ); ?>"></i>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<ul class="bdc-split__legend">
					<?php foreach ( $parts as $key => $part ) : ?>
						<li class="<?php echo $part[1] ? '' : 'is-zero'; ?>">
							<i class="bdc-split__part--<?php echo esc_attr( $key ); ?>"></i>
							<?php echo esc_html( $part[0] ); ?>
							<strong><?php echo esc_html( number_format_i18n( $part[1] ) ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="bdc-courier-card__cash">
					<div>
						<dt><?php esc_html_e( 'Cash collected', 'bd-courier' ); ?></dt>
						<dd><?php echo esc_html( BDC_View::money( $sum['cod_collected'] ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Cash still out', 'bd-courier' ); ?></dt>
						<dd><?php echo esc_html( BDC_View::money( $sum['cod_out'] ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Delivery fees', 'bd-courier' ); ?></dt>
						<dd><?php echo esc_html( BDC_View::money( $sum['fees'] ) ); ?></dd>
					</div>
				</dl>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Parcels sent per day, one bar per courier. */
	private static function chart( array $days, array $couriers ) {
		if ( count( $days ) < 2 || count( $days ) > 62 ) {
			return;
		}

		$max = 1;
		foreach ( $days as $counts ) {
			$max = max( $max, max( $counts ) );
		}
		// A round top line a little above the tallest bar.
		$top  = (int) ( ceil( $max / 4 ) * 4 );
		$step = max( 1, (int) ceil( count( $days ) / 16 ) );
		$i    = 0;
		?>
		<h2><?php esc_html_e( 'Parcels sent per day', 'bd-courier' ); ?></h2>
		<div class="bdc-chart" role="img" aria-label="<?php esc_attr_e( 'Bar chart of parcels sent per day by courier. The same numbers are in the CSV download.', 'bd-courier' ); ?>">
			<div class="bdc-chart__legend">
				<?php foreach ( $couriers as $courier ) : ?>
					<span><i class="bdc-chart__key bdc-chart__bar--<?php echo esc_attr( $courier->id() ); ?>"></i><?php echo esc_html( $courier->label() ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="bdc-chart__frame">
				<div class="bdc-chart__axis" aria-hidden="true">
					<span><?php echo esc_html( $top ); ?></span>
					<span><?php echo esc_html( $top / 2 ); ?></span>
					<span>0</span>
				</div>
				<div class="bdc-chart__plot" style="--bdc-days: <?php echo esc_attr( count( $days ) ); ?>;">
					<?php foreach ( $days as $date => $counts ) : ?>
						<?php
						$parts = array();
						foreach ( $counts as $id => $count ) {
							$parts[] = $couriers[ $id ]->label() . ' ' . $count;
						}
						$stamp = strtotime( $date . ' 12:00:00' );
						?>
						<div class="bdc-chart__day" title="<?php echo esc_attr( wp_date( 'j M', $stamp ) . ' — ' . implode( ', ', $parts ) ); ?>">
							<div class="bdc-chart__bars">
								<?php foreach ( $counts as $id => $count ) : ?>
									<i class="bdc-chart__bar bdc-chart__bar--<?php echo esc_attr( $id ); ?>" style="height: <?php echo esc_attr( round( $count / $top * 100, 1 ) ); ?>%;"></i>
								<?php endforeach; ?>
							</div>
							<span class="bdc-chart__date"><?php echo 0 === $i % $step ? esc_html( wp_date( 'j M', $stamp ) ) : '&nbsp;'; ?></span>
						</div>
						<?php ++$i; ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---- CSV ---- */

	public static function csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to download reports.', 'bd-courier' ) );
		}
		check_admin_referer( 'bdc_report_csv' );

		$period = self::period();
		$rows   = self::parcels( $period );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bd-courier-' . $period['from'] . '-to-' . $period['to'] . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads Bangla and the ৳ sign correctly.

		fputcsv(
			$out,
			array(
				__( 'Order', 'bd-courier' ),
				__( 'Courier', 'bd-courier' ),
				__( 'Consignment ID', 'bd-courier' ),
				__( 'Parcel status', 'bd-courier' ),
				__( 'Courier status', 'bd-courier' ),
				__( 'Cash to collect', 'bd-courier' ),
				__( 'Delivery fee', 'bd-courier' ),
				__( 'Sent', 'bd-courier' ),
				__( 'Customer', 'bd-courier' ),
				__( 'Phone', 'bd-courier' ),
			)
		);

		foreach ( $rows as $row ) {
			$courier = BDC_Couriers::get( $row['courier'] );
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array(
						$row['order'],
						$courier->label(),
						$row['consignment'],
						BDC_Statuses::label( $row['status'] ),
						$courier->status_label( $row['raw_status'] ),
						$row['cod'],
						null === $row['fee'] ? '' : $row['fee'],
						wp_date( 'Y-m-d H:i', $row['sent_at'] ),
						$row['name'],
						$row['phone'],
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Stops a cell that starts with = + - @ being run as a formula by a spreadsheet. Phone numbers stay text. */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}

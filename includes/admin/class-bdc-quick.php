<?php
defined( 'ABSPATH' ) || exit;

/**
 * Additions to WooCommerce's order quick view (the eye icon in the Orders
 * list): every status as a one-click button, the courier summary, and the
 * team's private notes with a box to add one.
 *
 * Also adds "Change status to…" for every BD Courier status to the bulk menu.
 */
class BDC_Quick {

	public static function init() {
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( __CLASS__, 'preview_data' ), 10, 2 );
		add_action( 'woocommerce_admin_order_preview_end', array( __CLASS__, 'preview_slot' ) );
		add_filter( 'woocommerce_admin_order_preview_actions', array( __CLASS__, 'preview_actions' ), 20 );

		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ), 25 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ), 25 );

		add_action( 'wp_ajax_bdc_set_status', array( __CLASS__, 'ajax_set_status' ) );
		add_action( 'wp_ajax_bdc_add_note', array( __CLASS__, 'ajax_add_note' ) );
	}

	/* ---- Bulk menu ---- */

	/**
	 * Statuses offered as "Change status to…" in the bulk menu.
	 *
	 * Only the ones chosen in settings: most parcel statuses are set by the
	 * courier, so listing them all would only clutter the menu.
	 *
	 * @return array key => label
	 */
	public static function bulk_statuses() {
		$chosen = array_filter( array_map( 'sanitize_key', explode( ',', (string) BDC_Settings::get( 'general', 'bulk_statuses', '' ) ) ) );
		return array_intersect_key( BDC_Statuses::extra() + BDC_Statuses::all(), array_flip( $chosen ) );
	}

	/** WooCommerce handles any "mark_{status}" action itself; these only add the menu entries. */
	public static function bulk_actions( $actions ) {
		$ours = array();
		foreach ( self::bulk_statuses() as $status => $label ) {
			/* translators: %s: order status */
			$ours[ 'mark_' . BDC_Statuses::slug( $status ) ] = sprintf( __( 'Change status to %s', 'bd-courier' ), strtolower( $label ) );
		}

		if ( ! $ours ) {
			return $actions;
		}

		// Right after WooCommerce's own "Change status to…" entries.
		$result = array();
		foreach ( $actions as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'mark_cancelled' === $key ) {
				$result += $ours;
			}
		}

		return $result + $ours;
	}

	/* ---- Quick view ---- */

	public static function preview_data( $data, $order ) {
		$data['bdc_html'] = $order instanceof WC_Order ? self::panel( $order ) : '';
		return $data;
	}

	/** Where the panel goes in WooCommerce's quick-view template. */
	public static function preview_slot() {
		echo '{{{ data.bdc_html }}}';
	}

	/** The status buttons in the panel replace WooCommerce's two or three footer buttons. */
	public static function preview_actions( $actions ) {
		unset( $actions['status'] );
		return $actions;
	}

	/**
	 * Statuses offered as buttons, in two groups.
	 *
	 * "Refunded" is left out on purpose: choosing it makes WooCommerce record
	 * a full refund, which should be done from the order page.
	 *
	 * @return array group label => array( slug without "wc-" => label )
	 */
	public static function status_groups() {
		$names = wc_get_order_statuses();
		$order = array();

		foreach ( array( 'pending', 'on-hold', 'processing', BDC_Statuses::slug( 'confirmed' ), 'completed', 'cancelled', 'failed' ) as $slug ) {
			if ( isset( $names[ 'wc-' . $slug ] ) ) {
				$order[ $slug ] = $names[ 'wc-' . $slug ];
			}
		}

		$parcel = array();
		foreach ( BDC_Statuses::all() as $key => $label ) {
			$parcel[ BDC_Statuses::slug( $key ) ] = $label;
		}

		return array(
			__( 'Order', 'bd-courier' )  => $order,
			__( 'Parcel', 'bd-courier' ) => $parcel,
		);
	}

	private static function allowed_statuses() {
		$allowed = array();
		foreach ( self::status_groups() as $statuses ) {
			$allowed = array_merge( $allowed, array_keys( $statuses ) );
		}
		return $allowed;
	}

	public static function panel( WC_Order $order ) {
		$id      = $order->get_id();
		$current = $order->get_status();

		ob_start();
		?>
		<div class="bdc-quick" data-order="<?php echo esc_attr( $id ); ?>">
			<?php $quick_logo = BDC_Brand::logo_url( 'thumbnail' ); ?>
			<?php if ( $quick_logo ) : ?>
				<div class="bdc-quick__brand">
					<img src="<?php echo esc_url( $quick_logo ); ?>" alt="">
					<span><?php echo esc_html( BDC_Print::business()['name'] ); ?></span>
				</div>
			<?php endif; ?>
			<section class="bdc-quick__section">
				<h3><?php esc_html_e( 'Status', 'bd-courier' ); ?></h3>
				<?php foreach ( self::status_groups() as $group => $statuses ) : ?>
					<div class="bdc-quick__group">
						<span class="bdc-quick__group-name"><?php echo esc_html( $group ); ?></span>
						<span class="bdc-quick__statuses">
							<?php foreach ( $statuses as $slug => $label ) : ?>
								<button
									type="button"
									class="bdc-status-button status-<?php echo esc_attr( $slug ); ?> <?php echo $slug === $current ? 'is-current' : ''; ?>"
									data-order="<?php echo esc_attr( $id ); ?>"
									data-status="<?php echo esc_attr( $slug ); ?>"
									aria-pressed="<?php echo $slug === $current ? 'true' : 'false'; ?>"
								><i class="bdc-status-button__dot" aria-hidden="true"></i><?php echo esc_html( $label ); ?></button>
							<?php endforeach; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</section>

			<section class="bdc-quick__section">
				<h3><?php esc_html_e( 'Courier', 'bd-courier' ); ?></h3>
				<?php echo BDC_View::cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>

			<section class="bdc-quick__section">
				<h3><?php esc_html_e( 'Customer history', 'bd-courier' ); ?></h3>
				<?php echo BDC_History::render( $order, 5 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>

			<section class="bdc-quick__section">
				<h3><?php esc_html_e( 'Private notes', 'bd-courier' ); ?></h3>
				<p class="bdc-quick__help"><?php
				/* translators: %s: courier names */
				echo esc_html( sprintf( __( 'Only your team sees these. The newest note is filled in as the note for the rider when you send the order with %s.', 'bd-courier' ), BDC_Couriers::names() ) );
				?></p>
				<div class="bdc-quick__add">
					<label class="screen-reader-text" for="bdc-note-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'New private note', 'bd-courier' ); ?></label>
					<textarea id="bdc-note-<?php echo esc_attr( $id ); ?>" rows="1" placeholder="<?php esc_attr_e( 'For example: deliver after 5 pm, call before coming', 'bd-courier' ); ?>"></textarea>
					<button type="button" class="button button-primary bdc-note-add" data-order="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Add note', 'bd-courier' ); ?></button>
				</div>
				<?php echo self::notes_list( $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function notes_list( WC_Order $order ) {
		$notes = BDC_Notes::staff_notes( $order, 10 );

		if ( ! $notes ) {
			return '<p class="bdc-notes bdc-notes--empty bdc-muted">' . esc_html__( 'No private notes yet.', 'bd-courier' ) . '</p>';
		}

		$items = '';
		foreach ( $notes as $note ) {
			$items .= sprintf(
				'<li><span class="bdc-notes__text">%s</span><span class="bdc-notes__meta">%s</span></li>',
				nl2br( esc_html( $note['text'] ) ),
				esc_html(
					sprintf(
						/* translators: 1: staff member, 2: date and time */
						__( '%1$s · %2$s', 'bd-courier' ),
						$note['by'],
						$note['date']
					)
				)
			);
		}

		return '<ul class="bdc-notes">' . $items . '</ul>';
	}

	/* ---- Actions ---- */

	private static function order() {
		BDC_Ajax::guard();

		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'This order could not be found. Please reload the page.', 'bd-courier' ) ), 404 );
		}

		return $order;
	}

	private static function respond( WC_Order $order, $message ) {
		$order = wc_get_order( $order->get_id() );

		wp_send_json_success(
			array(
				'message' => $message,
				'panel'   => self::panel( $order ),
				'cell'    => BDC_View::cell( $order ),
				'status'  => BDC_View::status_mark( $order ),
			)
		);
	}

	public static function ajax_set_status() {
		$order  = self::order();
		$status = sanitize_key( $_POST['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That status is not available.', 'bd-courier' ) ), 400 );
		}

		if ( ! $order->has_status( $status ) ) {
			$order->update_status( $status, '', true );
		}

		self::respond(
			$order,
			sprintf(
				/* translators: 1: order number, 2: status */
				__( 'Order #%1$s is now %2$s.', 'bd-courier' ),
				$order->get_order_number(),
				wc_get_order_status_name( $status )
			)
		);
	}

	public static function ajax_add_note() {
		$order = self::order();
		$note  = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $note ) {
			wp_send_json_error( array( 'message' => __( 'Please write the note first.', 'bd-courier' ) ), 400 );
		}

		// A normal WooCommerce private note: not sent to the customer, written by this staff member.
		$order->add_order_note( $note, 0, true );

		self::respond( $order, __( 'Private note added.', 'bd-courier' ) );
	}
}

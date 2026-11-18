<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order list column, order page box and assets.
 */
class BDC_Admin {

	public static function init() {
		// Order list: new (HPOS) and classic screens.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_classic' ), 10, 2 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_credit' ), 99 );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 99 );
	}

	/** True on BD Courier's own pages (Shipments, Reports, Settings). */
	private static function is_our_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return 0 === strpos( $page, 'bd-courier' );
	}

	/** No WordPress version or update link at the bottom right of our pages. */
	public static function footer_version( $text ) {
		return self::is_our_page() ? '' : $text;
	}

	/** Credit line at the bottom of BD Courier's own pages. */
	public static function footer_credit( $text ) {
		if ( ! self::is_our_page() ) {
			return $text;
		}
		return sprintf(
			'<span class="bdc-credit">%s</span>',
			sprintf(
				/* translators: %s: author name linked to the author's website */
				esc_html__( 'BD Courier, made by %s with help from Claude.', 'bd-courier' ),
				'<a href="https://jabedkhan.com/" target="_blank" rel="noopener">Jabed Khan</a>'
			)
		);
	}

	public static function order_screens() {
		$screens = array( 'shop_order', 'edit-shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		return $screens;
	}

	public static function add_column( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$result['bdc_courier'] = __( 'Courier', 'bd-courier' );
			}
		}
		if ( ! isset( $result['bdc_courier'] ) ) {
			$result['bdc_courier'] = __( 'Courier', 'bd-courier' );
		}
		return $result;
	}

	public static function render_column( $column, $order ) {
		if ( 'bdc_courier' === $column && $order instanceof WC_Order ) {
			echo BDC_View::cell( $order ) . self::history_seed( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** The customer's order count, moved under their name by the script. */
	private static function history_seed( WC_Order $order ) {
		$badge = BDC_History::badge( $order );
		return '' === $badge ? '' : '<template class="bdc-history-seed" data-order="' . esc_attr( $order->get_id() ) . '">' . $badge . '</template>';
	}

	public static function render_column_classic( $column, $post_id ) {
		if ( 'bdc_courier' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order instanceof WC_Order ) {
				echo BDC_View::cell( $order ) . self::history_seed( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	public static function meta_box() {
		foreach ( array( 'shop_order', function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '' ) as $screen ) {
			if ( $screen ) {
				add_meta_box( 'bdc-courier', __( 'Courier', 'bd-courier' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
				add_meta_box( 'bdc-history', __( 'Customer history', 'bd-courier' ), array( __CLASS__, 'render_history_box' ), $screen, 'normal', 'default' );
			}
		}
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		echo '<div class="bdc-box">';
		echo BDC_View::cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput

		$shipment = BDC_Shipment::get( $order );
		if ( $shipment ) {
			echo BDC_View::history( $shipment ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	public static function render_history_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( $order instanceof WC_Order ) {
			echo BDC_History::render( $order, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_orders   = in_array( $screen->id, self::order_screens(), true );
		$on_settings = BDC_Settings_Page::is_current_screen();

		if ( ! $on_orders && ! $on_settings && ! BDC_Shipments::is_current_screen() && ! BDC_Reports::is_current_screen() ) {
			return;
		}

		$script_needs = array( 'jquery' );
		if ( $on_settings ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			$script_needs[] = 'wp-color-picker';
		}

		wp_enqueue_style( 'bdc-admin', BDC_URL . 'assets/css/admin.css', array( 'dashicons' ), BDC_VERSION );
		wp_add_inline_style( 'bdc-admin', BDC_Brand::css() );
		wp_enqueue_script( 'bdc-admin', BDC_URL . 'assets/js/admin.js', $script_needs, BDC_VERSION, true );
		wp_localize_script(
			'bdc-admin',
			'bdcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bdc_admin' ),
				'printUrl' => BDC_Print::base_url(),
				'text'    => array(
					'loading'      => __( 'Loading…', 'bd-courier' ),
					'sending'      => __( 'Sending…', 'bd-courier' ),
					'waiting'      => __( 'Waiting…', 'bd-courier' ),
					/* translators: 1: number being refreshed, 2: total */
					'refreshMany'  => __( 'Refreshing %1$s of %2$s…', 'bd-courier' ),
					'refreshDone'  => __( 'Statuses are up to date.', 'bd-courier' ),
					/* translators: %s: number of parcels */
					'refreshFail'  => __( '%s parcels could not be refreshed. Please try again.', 'bd-courier' ),
					'sent'         => __( 'Sent', 'bd-courier' ),
					'close'        => __( 'Close', 'bd-courier' ),
					'pickOrders'   => __( 'Please tick the orders you want to send first.', 'bd-courier' ),
					/* translators: 1: number being sent, 2: total */
					'bulkProgress' => __( 'Sending %1$s of %2$s…', 'bd-courier' ),
					/* translators: %s: number of parcels */
					'bulkAllSent'  => __( 'All done. %s sent.', 'bd-courier' ),
					/* translators: 1: number sent, 2: number failed */
					'bulkSomeFail' => __( '%1$s sent, %2$s need attention. Correct them and try again.', 'bd-courier' ),
					/* translators: %s: number of parcels */
					'bulkRetry'    => __( 'Try again (%s)', 'bd-courier' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'bd-courier' ),
					'chooseCity'   => __( 'Choose a city first', 'bd-courier' ),
					'chooseZone'   => __( 'Choose a zone first', 'bd-courier' ),
					'selectZone'   => __( 'Select a zone', 'bd-courier' ),
					'selectArea'   => __( 'Select an area', 'bd-courier' ),
					'copied'       => __( 'Copied', 'bd-courier' ),
					'checking'     => __( 'Checking…', 'bd-courier' ),
					'checkAgain'   => __( 'Check again', 'bd-courier' ),
					'checkRecord'  => __( 'Check customer record', 'bd-courier' ),
					'showDetails'  => __( 'Show all details', 'bd-courier' ),
					'hideDetails'  => __( 'Hide details', 'bd-courier' ),
					'copyPhone'    => __( 'Copy phone number', 'bd-courier' ),
					'copyId'       => __( 'Copy courier ID', 'bd-courier' ),
					'customerRecord' => __( 'Customer record', 'bd-courier' ),
					'filters'      => __( 'Filters', 'bd-courier' ),
					'dateFrom'     => __( 'From', 'bd-courier' ),
					'dateTo'       => __( 'To', 'bd-courier' ),
					'testAccount'  => __( 'Pathao test account filled in. Click "Save and test connection".', 'bd-courier' ),
					'chooseLogo'   => __( 'Choose a logo', 'bd-courier' ),
					'useLogo'      => __( 'Use this logo', 'bd-courier' ),
				),
			)
		);
	}
}

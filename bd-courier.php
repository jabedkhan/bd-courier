<?php
/**
 * Plugin Name: BD Courier
 * Description: Send WooCommerce orders to Pathao and Steadfast from one place, and follow every parcel from your dashboard.
 * Version: 0.14.5
 * Author: BD Courier
 * Plugin URI: https://github.com/jabedkhan/bd-courier
 * Text Domain: bd-courier
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'BDC_VERSION', '0.14.5' );
define( 'BDC_FILE', __FILE__ );
define( 'BDC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDC_URL', plugin_dir_url( __FILE__ ) );

// Works with WooCommerce's current order storage (HPOS).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once BDC_DIR . 'includes/class-bdc-settings.php';
		require_once BDC_DIR . 'includes/class-bdc-brand.php';
		require_once BDC_DIR . 'includes/class-bdc-http.php';
		require_once BDC_DIR . 'includes/class-bdc-statuses.php';
		require_once BDC_DIR . 'includes/class-bdc-shipment.php';
		require_once BDC_DIR . 'includes/couriers/class-bdc-courier.php';
		require_once BDC_DIR . 'includes/couriers/class-bdc-pathao.php';
		require_once BDC_DIR . 'includes/couriers/class-bdc-steadfast.php';
		require_once BDC_DIR . 'includes/class-bdc-couriers.php';
		require_once BDC_DIR . 'includes/class-bdc-webhooks.php';
		require_once BDC_DIR . 'includes/class-bdc-sync.php';
		require_once BDC_DIR . 'includes/class-bdc-fraud.php';
		require_once BDC_DIR . 'includes/class-bdc-notes.php';
		require_once BDC_DIR . 'includes/class-bdc-history.php';
		require_once BDC_DIR . 'includes/class-bdc-customer.php';

		BDC_Statuses::init();
		BDC_Webhooks::init();
		BDC_Shipment::init();
		BDC_Sync::init();
		BDC_Customer::init();
		BDC_Notes::init();

		if ( is_admin() ) {
			require_once BDC_DIR . 'includes/admin/class-bdc-view.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-admin.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-settings-page.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-ajax.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-bulk.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-shipments.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-print.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-reports.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-quick.php';
			require_once BDC_DIR . 'includes/admin/class-bdc-orders-list.php';

			BDC_Admin::init();
			BDC_Settings_Page::init();
			BDC_Ajax::init();
			BDC_Bulk::init();
			BDC_Shipments::init();
			BDC_Print::init();
			BDC_Reports::init();
			BDC_Quick::init();
			BDC_Orders_List::init();
			BDC_History::init();
			add_action( 'admin_init', array( 'BDC_Shipment', 'maybe_upgrade' ) );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'BDC_Sync' ) ) {
			BDC_Sync::unschedule();
		}
	}
);

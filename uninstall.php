<?php
/**
 * Removes BD Courier's settings when the plugin is deleted from the Plugins screen.
 *
 * Parcel details saved on orders are kept on purpose: they are part of the
 * order history and are useful if the plugin is installed again.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'bdc_settings', 'bdc_pathao_token', 'bdc_sync_scheduled', 'bdc_sync_last', 'bdc_data_version', 'bdc_pathao_last_webhook', 'bdc_steadfast_last_webhook' ) as $bdc_option ) {
	delete_option( $bdc_option );
}

wp_clear_scheduled_hook( 'bdc_sync_parcels' );

// Cached courier lists, customer records and login notes.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bdc\_%' OR option_name LIKE '\_transient\_timeout\_bdc\_%'"
);

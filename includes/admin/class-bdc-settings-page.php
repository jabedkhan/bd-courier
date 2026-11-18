<?php
defined( 'ABSPATH' ) || exit;

/**
 * BD Courier → Settings. General, Branding, and one tab per courier so
 * Pathao and Steadfast stay separate.
 */
class BDC_Settings_Page {

	const SLUG = 'bd-courier-settings';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bdc_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_bdc_sync_now', array( __CLASS__, 'sync_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BDC_FILE ), array( __CLASS__, 'plugin_link' ) );
	}

	public static function url( $tab = 'general', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	public static function is_current_screen() {
		$screen = get_current_screen();
		return $screen && self::$hook === $screen->id;
	}

	public static function menu() {
		$name = BDC_Brand::name();

		add_menu_page( $name, $name, 'manage_woocommerce', BDC_Shipments::SLUG, array( 'BDC_Shipments', 'render' ), BDC_Brand::menu_icon(), 56 );

		BDC_Shipments::$hook = add_submenu_page(
			BDC_Shipments::SLUG,
			__( 'Shipments', 'bd-courier' ),
			__( 'Shipments', 'bd-courier' ),
			'manage_woocommerce',
			BDC_Shipments::SLUG,
			array( 'BDC_Shipments', 'render' )
		);

		BDC_Reports::$hook = add_submenu_page(
			BDC_Shipments::SLUG,
			__( 'Courier reports', 'bd-courier' ),
			__( 'Reports', 'bd-courier' ),
			'manage_woocommerce',
			BDC_Reports::SLUG,
			array( 'BDC_Reports', 'render' )
		);

		self::$hook = add_submenu_page(
			BDC_Shipments::SLUG,
			__( 'Settings', 'bd-courier' ),
			__( 'Settings', 'bd-courier' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function plugin_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'bd-courier' ) . '</a>' );
		return $links;
	}

	private static function tabs() {
		return array(
			'general'   => __( 'General', 'bd-courier' ),
			'branding'  => __( 'Branding', 'bd-courier' ),
			'checkout'  => __( 'Checkout', 'bd-courier' ),
			'chat'      => __( 'Chat buttons', 'bd-courier' ),
			'pathao'    => 'Pathao',
			'steadfast' => 'Steadfast',
		);
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::tabs()[ $tab ] ) ? $tab : 'general';
	}

	/* ---- Save ---- */

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'bd-courier' ) );
		}
		check_admin_referer( 'bdc_save_settings' );

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'general';
		$posted = isset( $_POST['bdc'] ) && is_array( $_POST['bdc'] ) ? wp_unslash( $_POST['bdc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$text   = function ( $key ) use ( $posted ) {
			return isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? trim( sanitize_text_field( (string) $posted[ $key ] ) ) : '';
		};

		if ( 'checkout' === $tab ) {
			$values = array(
				'enabled'     => isset( $posted['enabled'] ) ? 'yes' : 'no',
				'order_notes' => isset( $posted['order_notes'] ) ? 'yes' : 'no',
			);
			foreach ( array( 'heading_details', 'label_name', 'label_phone', 'label_address', 'label_area', 'label_notes', 'ph_name', 'ph_phone', 'ph_address', 'ph_notes', 'heading_order', 'button_text', 'area_inside', 'area_sub', 'area_outside', 'time_inside', 'time_sub', 'time_outside', 'help_text', 'thankyou_title' ) as $key ) {
				$values[ $key ] = $text( $key );
			}
			foreach ( array( 'trust_points', 'thankyou_text', 'thankyou_steps' ) as $key ) {
				$values[ $key ] = isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? trim( sanitize_textarea_field( (string) $posted[ $key ] ) ) : '';
			}
			foreach ( array( 'charge_inside', 'charge_sub', 'charge_outside' ) as $key ) {
				$values[ $key ] = (string) max( 0, (float) $text( $key ) );
			}
			BDC_Settings::update_section( 'checkout', $values );
		} elseif ( 'chat' === $tab ) {
			$message = isset( $posted['message'] ) && is_scalar( $posted['message'] ) ? trim( sanitize_textarea_field( (string) $posted['message'] ) ) : '';
			BDC_Settings::update_section(
				'chat',
				array(
					'enabled'   => isset( $posted['enabled'] ) ? 'yes' : 'no',
					'whatsapp'  => BDC_Chat::clean_whatsapp( $text( 'whatsapp' ) ),
					'messenger' => BDC_Chat::clean_messenger( $text( 'messenger' ) ),
					'messenger_id' => preg_replace( '/\D+/', '', $text( 'messenger_id' ) ),
					'message'   => $message,
					'message_page' => isset( $posted['message_page'] ) && is_scalar( $posted['message_page'] ) ? trim( sanitize_textarea_field( (string) $posted['message_page'] ) ) : '',
				)
			);
		} elseif ( 'branding' === $tab ) {
			$logo_id = absint( $text( 'logo_id' ) );

			BDC_Settings::update_section(
				'brand',
				array(
					'name'   => $text( 'name' ),
					'accent' => (string) sanitize_hex_color( $text( 'accent' ) ),
				)
			);
			BDC_Settings::update_section(
				'print',
				array(
					'business_name'    => $text( 'business_name' ),
					'business_phone'   => $text( 'business_phone' ),
					'business_address' => $text( 'business_address' ),
					'logo_id'          => $logo_id && wp_attachment_is_image( $logo_id ) ? (string) $logo_id : '',
					'footer_note'      => $text( 'footer_note' ),
				)
			);
		} elseif ( 'pathao' === $tab ) {
			$values = array(
				'enabled'       => isset( $posted['enabled'] ) ? 'yes' : 'no',
				'environment'   => 'sandbox' === $text( 'environment' ) ? 'sandbox' : 'live',
				'client_id'     => $text( 'client_id' ),
				'store_id'      => $text( 'store_id' ),
				'delivery_type' => isset( BDC_Pathao::delivery_types()[ $text( 'delivery_type' ) ] ) ? $text( 'delivery_type' ) : '48',
				'item_type'     => isset( BDC_Pathao::item_types()[ $text( 'item_type' ) ] ) ? $text( 'item_type' ) : '2',
				'username'      => $text( 'username' ),
				'default_note'  => isset( $posted['default_note'] ) && is_scalar( $posted['default_note'] ) ? trim( sanitize_textarea_field( (string) $posted['default_note'] ) ) : '',
			);
			// An empty secret field means "keep the one already saved".
			if ( '' !== $text( 'client_secret' ) ) {
				$values['client_secret'] = $text( 'client_secret' );
			}
			if ( '' !== $text( 'password' ) ) {
				$values['password'] = $text( 'password' );
			}
			// No username means the password is not used either.
			if ( '' === $values['username'] ) {
				$values['password'] = '';
			}
			delete_option( BDC_Pathao::TOKEN_OPTION );
			delete_transient( BDC_Pathao::LOGIN_FAILED );
			delete_transient( 'bdc_pathao_stores' );
			BDC_Settings::update_section( 'pathao', $values );
		} elseif ( 'steadfast' === $tab ) {
			$values = array(
				'enabled'       => isset( $posted['enabled'] ) ? 'yes' : 'no',
				'api_key'       => $text( 'api_key' ),
				'delivery_type' => '1' === $text( 'delivery_type' ) ? '1' : '0',
				'send_note'     => isset( $posted['send_note'] ) ? 'yes' : 'no',
				'default_note'  => isset( $posted['default_note'] ) && is_scalar( $posted['default_note'] ) ? trim( sanitize_textarea_field( (string) $posted['default_note'] ) ) : '',
			);
			if ( '' !== $text( 'secret_key' ) ) {
				$values['secret_key'] = $text( 'secret_key' );
			}
			BDC_Settings::update_section( 'steadfast', $values );
		} else {
			$tab    = 'general';
			$weight = (float) $text( 'default_weight' );

			BDC_Settings::update_section(
				'general',
				array(
					'update_order_status' => isset( $posted['update_order_status'] ) ? 'yes' : 'no',
					'customer_tracking'   => isset( $posted['customer_tracking'] ) ? 'yes' : 'no',
					'bulk_statuses'       => implode(
						',',
						array_intersect(
							array_keys( BDC_Statuses::extra() + BDC_Statuses::order_statuses() ),
							isset( $posted['bulk_statuses'] ) && is_array( $posted['bulk_statuses'] ) ? array_map( 'sanitize_key', $posted['bulk_statuses'] ) : array()
						)
					),
					'default_weight'      => (string) min( 10, max( 0.5, $weight ? $weight : 0.5 ) ),
					'sync_interval'       => isset( BDC_Sync::intervals()[ $text( 'sync_interval' ) ] ) ? $text( 'sync_interval' ) : '60',
				)
			);
		}

		$args = array( 'saved' => 1 );
		if ( isset( $_POST['bdc_test'] ) ) {
			$args['test'] = 1;
		}

		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/** "Check now" on the General tab. */
	public static function sync_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'bd-courier' ) );
		}
		check_admin_referer( 'bdc_sync_now' );

		$summary = BDC_Sync::run( 0 );

		wp_safe_redirect(
			self::url(
				'general',
				array(
					'checked' => $summary['checked'],
					'changed' => $summary['changed'],
					'failed'  => $summary['failed'],
				)
			)
		);
		exit;
	}

	/* ---- Page ---- */

	public static function render() {
		$tab = self::current_tab();
		?>
		<div class="wrap bdc-page bdc-settings">
			<?php echo BDC_View::header( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Settings', 'bd-courier' ); ?></h1>
			<hr class="wp-header-end">

			<?php self::notices( $tab ); ?>

			<nav class="bdc-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'bd-courier' ); ?>">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $key === $tab ? 'is-current' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
						<?php
						$tab_icons = array(
							'general'   => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
							'branding'  => '<circle cx="13.5" cy="6.5" r="1.2"/><circle cx="17.5" cy="10.5" r="1.2"/><circle cx="8.5" cy="7.5" r="1.2"/><circle cx="6.5" cy="12.5" r="1.2"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.56-2.5 5.56-5.55C21.97 6.01 17.46 2 12 2Z"/>',
							'checkout'  => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
							'chat'      => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
							'pathao'    => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
							'steadfast' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
						);
						if ( isset( $tab_icons[ $key ] ) ) {
							echo '<svg class="bdc-tab-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $tab_icons[ $key ] . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG paths above.
						}
						?>
						<?php echo esc_html( $label ); ?>
						<?php
						$courier = BDC_Couriers::get( $key );
						if ( $courier ) {
							printf( '<span class="bdc-dot %s" title="%s"></span>', $courier->is_active() ? 'bdc-dot--on' : '', esc_attr( self::state_label( $courier ) ) );
						}
						?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bdc-settings__form" autocomplete="off">
				<input type="hidden" name="action" value="bdc_save_settings">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( 'bdc_save_settings' ); ?>

				<?php
				if ( 'pathao' === $tab ) {
					self::pathao_tab();
				} elseif ( 'steadfast' === $tab ) {
					self::steadfast_tab();
				} elseif ( 'branding' === $tab ) {
					self::branding_tab();
				} elseif ( 'chat' === $tab ) {
					self::chat_tab();
				} elseif ( 'checkout' === $tab ) {
					self::checkout_tab();
				} else {
					self::general_tab();
				}
				?>
			</form>
		</div>
		<?php
	}

	private static function notices( $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) && ! isset( $_GET['test'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bd-courier' ) . '</p></div>';
		}

		if ( isset( $_GET['checked'] ) ) {
			$checked = absint( $_GET['checked'] );
			$changed = absint( $_GET['changed'] ?? 0 );
			$failed  = absint( $_GET['failed'] ?? 0 );

			if ( ! $checked ) {
				$message = __( 'There are no parcels on their way to check.', 'bd-courier' );
			} else {
				/* translators: %d: parcels checked */
				$message = sprintf( _n( 'Checked %d parcel.', 'Checked %d parcels.', $checked, 'bd-courier' ), $checked ) . ' ';
				$message .= $changed
					/* translators: %d: parcels with a new status */
					? sprintf( _n( '%d has a new status.', '%d have a new status.', $changed, 'bd-courier' ), $changed )
					: __( 'No status has changed.', 'bd-courier' );
				if ( $failed ) {
					/* translators: %d: number of parcels */
					$message .= ' ' . sprintf( __( '%d could not be checked; they will be tried again.', 'bd-courier' ), $failed );
				}
			}
			echo '<div class="notice ' . ( $failed ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		$courier = BDC_Couriers::get( $tab );
		if ( isset( $_GET['test'] ) && $courier ) {
			if ( ! $courier->is_enabled() ) {
				/* translators: %s: courier name */
				$message = sprintf( __( 'Settings saved. %s is turned off, so it is hidden from orders, shipments and reports.', 'bd-courier' ), $courier->label() );
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
				return;
			}
			if ( ! $courier->is_connected() ) {
				/* translators: %s: courier name */
				$message = sprintf( __( 'Settings saved. Enter both %s credentials to connect.', 'bd-courier' ), $courier->label() );
				echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
				return;
			}

			$result = $courier->test_connection();
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Settings saved, but the connection did not work.', 'bd-courier' ) . '</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				/* translators: %s: courier name */
				$message = sprintf( __( 'Settings saved. %s is connected and ready to use.', 'bd-courier' ), $courier->label() );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		// phpcs:enable
	}

	/** Short state of a courier for the tab dot and the General cards. */
	private static function state_label( BDC_Courier $courier ) {
		if ( ! $courier->is_enabled() ) {
			return __( 'Turned off', 'bd-courier' );
		}
		return $courier->is_connected() ? __( 'Credentials saved', 'bd-courier' ) : __( 'Not connected', 'bd-courier' );
	}

	/* ---- General ---- */

	private static function general_tab() {
		$settings = BDC_Settings::section( 'general' );
		$last     = get_option( 'bdc_sync_last' );
		?>
		<div class="bdc-cards">
			<?php foreach ( BDC_Couriers::all() as $courier ) : ?>
				<a class="bdc-card" href="<?php echo esc_url( self::url( $courier->id() ) ); ?>">
					<span class="bdc-card__head">
						<?php echo BDC_View::badge( $courier ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="bdc-pill <?php echo $courier->is_active() ? 'bdc-pill--ok' : ''; ?>">
							<?php echo esc_html( self::state_label( $courier ) ); ?>
						</span>
					</span>
					<span class="bdc-card__text">
						<?php
						echo esc_html(
							! $courier->is_enabled()
								/* translators: %s: courier name */
								? sprintf( __( 'Hidden from orders, shipments and reports. Open %s settings to turn it on', 'bd-courier' ), $courier->label() )
								: ( $courier->is_connected()
								/* translators: %s: courier name */
								? sprintf( __( 'Ready to send from the Orders screen. Open %s settings', 'bd-courier' ), $courier->label() )
								/* translators: %s: courier name */
								: sprintf( __( 'Add your %s credentials to start sending parcels', 'bd-courier' ), $courier->label() ) )
						);
						?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php self::panel_open( __( 'Orders', 'bd-courier' ), __( 'How orders behave once a parcel is with a courier. Orders are never sent automatically.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Order status', 'bd-courier' ) ); ?>
				<label class="bdc-check">
					<input type="checkbox" name="bdc[update_order_status]" value="yes" <?php checked( 'yes', $settings['update_order_status'] ); ?>>
					<span><?php esc_html_e( 'Follow the parcel status', 'bd-courier' ); ?></span>
				</label>
			<?php self::row_close( __( 'The order becomes "In Transit" when the courier moves the parcel, "Delivered" when it arrives, and so on.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Bulk status changes', 'bd-courier' ) ); ?>
				<fieldset class="bdc-checks">
					<legend class="screen-reader-text"><?php esc_html_e( 'Statuses in the Orders bulk-actions menu', 'bd-courier' ); ?></legend>
					<?php $bulk_chosen = array_keys( BDC_Quick::bulk_statuses() ); ?>
					<?php foreach ( BDC_Statuses::extra() + BDC_Statuses::order_statuses() as $status_key => $status_label ) : ?>
						<label class="bdc-check">
							<input type="checkbox" name="bdc[bulk_statuses][]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $bulk_chosen, true ) ); ?>>
							<span><?php echo esc_html( $status_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php self::row_close( __( 'Ticked statuses appear as "Change status to…" in the Orders bulk menu. Tick only the ones your team sets by hand.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Default weight', 'bd-courier' ), 'bdc-default-weight' ); ?>
				<span class="bdc-unit">
					<input type="number" id="bdc-default-weight" name="bdc[default_weight]" value="<?php echo esc_attr( $settings['default_weight'] ); ?>" min="0.5" max="10" step="0.1">
					<span>kg</span>
				</span>
			<?php self::row_close( __( 'Used when the products in an order have no weight. You can change it before sending.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Status updates', 'bd-courier' ), __( 'Couriers push updates to your shop. This check is the safety net for any update that was missed. It only reads statuses.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Check automatically', 'bd-courier' ), 'bdc-sync-interval' ); ?>
				<span class="bdc-inline">
					<?php self::select( 'bdc-sync-interval', 'sync_interval', BDC_Sync::intervals(), $settings['sync_interval'] ); ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdc_sync_now' ), 'bdc_sync_now' ) ); ?>"><?php esc_html_e( 'Check now', 'bd-courier' ); ?></a>
				</span>
			<?php
			self::row_close(
				is_array( $last ) && ! empty( $last['time'] )
					? sprintf(
						/* translators: 1: time ago, 2: parcels checked, 3: parcels changed */
						__( 'Last check %1$s ago: %2$d parcels checked, %3$d with a new status.', 'bd-courier' ),
						human_time_diff( (int) $last['time'] ),
						(int) $last['checked'],
						(int) $last['changed']
					)
					: __( 'No check has run yet.', 'bd-courier' )
			);
			?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Customers', 'bd-courier' ), __( 'What your customers see about their delivery.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Tracking', 'bd-courier' ) ); ?>
				<label class="bdc-check">
					<input type="checkbox" name="bdc[customer_tracking]" value="yes" <?php checked( 'yes', $settings['customer_tracking'] ); ?>>
					<span><?php esc_html_e( 'Show the courier, tracking ID, parcel status and a tracking link', 'bd-courier' ); ?></span>
				</label>
			<?php self::row_close( __( 'Shown on the order page in My Account and in the order emails WooCommerce already sends.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Email when sent', 'bd-courier' ) ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=bdc_email_shipped' ) ); ?>"><?php esc_html_e( 'Open email settings', 'bd-courier' ); ?></a>
			<?php self::row_close( __( 'The "Order sent with courier" email is switched on and edited in WooCommerce, with your other emails.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( false );
	}

	/* ---- Branding ---- */

	private static function branding_tab() {
		$brand    = BDC_Settings::section( 'brand' );
		$print    = BDC_Settings::section( 'print' );
		$business = BDC_Print::business();
		$logo     = BDC_Brand::logo_url();
		?>
		<?php self::panel_open( __( 'In your dashboard', 'bd-courier' ), __( 'How this plugin is named and coloured for your team.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Name', 'bd-courier' ), 'bdc-brand-name' ); ?>
				<input type="text" id="bdc-brand-name" name="bdc[name]" value="<?php echo esc_attr( $brand['name'] ); ?>" placeholder="<?php echo esc_attr( BDC_Brand::DEFAULT_NAME ); ?>" maxlength="30">
			<?php self::row_close( __( 'Shown in the dashboard menu and at the top of these pages.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Accent colour', 'bd-courier' ), 'bdc-brand-accent' ); ?>
				<input type="text" id="bdc-brand-accent" name="bdc[accent]" value="<?php echo esc_attr( BDC_Brand::accent() ); ?>" class="bdc-colour" data-default-color="<?php echo esc_attr( BDC_Brand::DEFAULT_ACCENT ); ?>">
			<?php self::row_close( __( 'Used for main buttons, the current page and highlights. Pathao and Steadfast keep their own colours.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Business logo', 'bd-courier' ) ); ?>
				<div class="bdc-logo">
					<input type="hidden" name="bdc[logo_id]" value="<?php echo esc_attr( $print['logo_id'] ); ?>">
					<div class="bdc-logo__preview">
						<?php if ( $logo ) : ?>
							<img src="<?php echo esc_url( $logo ); ?>" alt="">
						<?php endif; ?>
					</div>
					<button type="button" class="button bdc-logo__choose"><?php esc_html_e( 'Choose logo', 'bd-courier' ); ?></button>
					<button type="button" class="button-link bdc-logo__remove" <?php echo $logo ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'bd-courier' ); ?></button>
				</div>
			<?php self::row_close( __( 'Used everywhere: the top of these pages, the Orders quick view, invoices, parcel labels and customer emails. A square or wide PNG or JPG works best.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'On invoices and parcel labels', 'bd-courier' ), __( 'Your shop details as customers and riders see them on paper. Empty fields use the value shown in grey.', 'bd-courier' ), '', '', 'grid' ); ?>

			<?php self::row_open( __( 'Shop name', 'bd-courier' ), 'bdc-business-name' ); ?>
				<input type="text" id="bdc-business-name" name="bdc[business_name]" value="<?php echo esc_attr( $print['business_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Phone', 'bd-courier' ), 'bdc-business-phone' ); ?>
				<input type="text" id="bdc-business-phone" name="bdc[business_phone]" value="<?php echo esc_attr( $print['business_phone'] ); ?>">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Address', 'bd-courier' ), 'bdc-business-address', true ); ?>
				<input type="text" id="bdc-business-address" class="bdc-wide" name="bdc[business_address]" value="<?php echo esc_attr( $print['business_address'] ); ?>" placeholder="<?php echo esc_attr( '' === $print['business_address'] ? $business['address'] : '' ); ?>">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Invoice footer', 'bd-courier' ), 'bdc-footer-note', true ); ?>
				<input type="text" id="bdc-footer-note" class="bdc-wide" name="bdc[footer_note]" value="<?php echo esc_attr( $print['footer_note'] ); ?>">
			<?php self::row_close( __( 'For example a thank-you line or your return policy.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( false );
	}

	/* ---- Checkout ---- */

	private static function checkout_tab() {
		$c        = BDC_Settings::section( 'checkout' );
		$defaults = BDC_Settings::defaults()['checkout'];
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '৳';
		?>
		<?php self::panel_open( __( 'Simple checkout', 'bd-courier' ), __( 'A short checkout made for Bangladesh: name, mobile number and address, plus the delivery area with its charge. Country is set to Bangladesh and email is not asked.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Use simple checkout', 'bd-courier' ), 'bdc-checkout-enabled' ); ?>
				<label class="bdc-check">
					<input type="checkbox" id="bdc-checkout-enabled" name="bdc[enabled]" value="yes" <?php checked( 'yes', $c['enabled'] ); ?>>
					<?php esc_html_e( 'Replace the checkout fields and delivery charges with the ones below', 'bd-courier' ); ?>
				</label>
			<?php self::row_close( __( 'Turn off any other checkout-field plugin (such as Checkout Field Editor) after switching this on, so the two do not fight. Works with the classic WooCommerce checkout.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Order notes', 'bd-courier' ), 'bdc-checkout-notes' ); ?>
				<label class="bdc-check">
					<input type="checkbox" id="bdc-checkout-notes" name="bdc[order_notes]" value="yes" <?php checked( 'yes', $c['order_notes'] ); ?>>
					<?php esc_html_e( 'Keep the optional "Order notes" box', 'bd-courier' ); ?>
				</label>
			<?php self::row_close(); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Field labels', 'bd-courier' ), __( 'What customers read above each field.', 'bd-courier' ), '', '', 'grid' ); ?>
			<?php
			foreach ( array(
				'heading_details' => __( 'Details heading', 'bd-courier' ),
				'label_name'      => __( 'Name', 'bd-courier' ),
				'label_phone'     => __( 'Mobile number', 'bd-courier' ),
				'label_address'   => __( 'Address', 'bd-courier' ),
				'label_area'      => __( 'Delivery area', 'bd-courier' ),
				'label_notes'     => __( 'Order notes', 'bd-courier' ),
				'heading_order'   => __( 'Order summary heading', 'bd-courier' ),
				'button_text'     => __( 'Order button', 'bd-courier' ),
			) as $key => $title ) :
				?>
				<?php self::row_open( $title, 'bdc-checkout-' . $key ); ?>
					<input type="text" id="bdc-checkout-<?php echo esc_attr( $key ); ?>" name="bdc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ $key ] ); ?>" placeholder="<?php echo esc_attr( $defaults[ $key ] ); ?>">
				<?php self::row_close(); ?>
			<?php endforeach; ?>
		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Placeholders', 'bd-courier' ), __( 'Grey example text inside each empty field, so customers know what to write.', 'bd-courier' ), '', '', 'grid' ); ?>
			<?php
			foreach ( array(
				'ph_name'    => __( 'Name', 'bd-courier' ),
				'ph_phone'   => __( 'Mobile number', 'bd-courier' ),
				'ph_address' => __( 'Address', 'bd-courier' ),
				'ph_notes'   => __( 'Order notes', 'bd-courier' ),
			) as $key => $title ) :
				?>
				<?php self::row_open( $title, 'bdc-checkout-' . $key ); ?>
					<input type="text" id="bdc-checkout-<?php echo esc_attr( $key ); ?>" name="bdc[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ $key ] ); ?>" placeholder="<?php echo esc_attr( $defaults[ $key ] ); ?>">
				<?php self::row_close(); ?>
			<?php endforeach; ?>
		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Delivery areas and charges', 'bd-courier' ), __( 'Customers choose one; its charge is added to the order total. Each area shows its name, charge and delivery time.', 'bd-courier' ), '', '', 'grid' ); ?>
			<?php
			foreach ( array(
				'inside'  => __( 'Inside Dhaka', 'bd-courier' ),
				'sub'     => __( 'Sub Dhaka', 'bd-courier' ),
				'outside' => __( 'Outside Dhaka', 'bd-courier' ),
			) as $key => $title ) :
				?>
				<?php self::row_open( $title, 'bdc-checkout-area-' . $key ); ?>
					<span class="bdc-inline">
						<input type="text" id="bdc-checkout-area-<?php echo esc_attr( $key ); ?>" name="bdc[area_<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ 'area_' . $key ] ); ?>" placeholder="<?php echo esc_attr( $defaults[ 'area_' . $key ] ); ?>" aria-label="<?php esc_attr_e( 'Name shown to customers', 'bd-courier' ); ?>">
						<input type="number" name="bdc[charge_<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ 'charge_' . $key ] ); ?>" min="0" step="1" aria-label="<?php esc_attr_e( 'Delivery charge', 'bd-courier' ); ?>">
						<span class="bdc-muted"><?php echo esc_html( $currency ); ?></span>
						<input type="text" name="bdc[time_<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ 'time_' . $key ] ); ?>" placeholder="<?php echo esc_attr( $defaults[ 'time_' . $key ] ); ?>" style="width:120px" aria-label="<?php esc_attr_e( 'Delivery time', 'bd-courier' ); ?>">
					</span>
				<?php self::row_close(); ?>
			<?php endforeach; ?>
		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Beside the delivery areas', 'bd-courier' ), __( 'Short reassurance under the delivery areas on the checkout page.', 'bd-courier' ), '', '', 'grid' ); ?>

			<?php self::row_open( __( 'Trust points', 'bd-courier' ), 'bdc-checkout-trust', true ); ?>
				<textarea id="bdc-checkout-trust" name="bdc[trust_points]" rows="3" class="bdc-note bdc-wide" placeholder="<?php echo esc_attr( $defaults['trust_points'] ); ?>"><?php echo esc_textarea( $c['trust_points'] ); ?></textarea>
			<?php self::row_close( __( 'One point per line. Leave empty to use the points shown in grey.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Help line', 'bd-courier' ), 'bdc-checkout-help', true ); ?>
				<input type="text" id="bdc-checkout-help" class="bdc-wide" name="bdc[help_text]" value="<?php echo esc_attr( $c['help_text'] ); ?>" placeholder="<?php echo esc_attr( $defaults['help_text'] ); ?>">
			<?php self::row_close( __( 'Shown with WhatsApp and Messenger buttons when they are filled in under Chat buttons.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Thank-you page', 'bd-courier' ), __( 'What customers see right after placing the order.', 'bd-courier' ), '', '', 'grid' ); ?>

			<?php self::row_open( __( 'Title', 'bd-courier' ), 'bdc-thankyou-title', true ); ?>
				<input type="text" id="bdc-thankyou-title" class="bdc-wide" name="bdc[thankyou_title]" value="<?php echo esc_attr( $c['thankyou_title'] ); ?>" placeholder="<?php echo esc_attr( $defaults['thankyou_title'] ); ?>">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Message', 'bd-courier' ), 'bdc-thankyou-text', true ); ?>
				<textarea id="bdc-thankyou-text" name="bdc[thankyou_text]" rows="2" class="bdc-note bdc-wide" placeholder="<?php echo esc_attr( $defaults['thankyou_text'] ); ?>"><?php echo esc_textarea( $c['thankyou_text'] ); ?></textarea>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'What happens next', 'bd-courier' ), 'bdc-thankyou-steps', true ); ?>
				<textarea id="bdc-thankyou-steps" name="bdc[thankyou_steps]" rows="3" class="bdc-note bdc-wide" placeholder="<?php echo esc_attr( $defaults['thankyou_steps'] ); ?>"><?php echo esc_textarea( $c['thankyou_steps'] ); ?></textarea>
			<?php self::row_close( __( 'One step per line, shown as numbered steps.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( false );
	}

	/* ---- Chat buttons ---- */

	private static function chat_tab() {
		$chat = BDC_Settings::section( 'chat' );
		?>
		<?php self::panel_open( __( 'Chat buttons', 'bd-courier' ), __( 'A small chat button in the bottom-right corner of every page of your shop except checkout. Customers pick Messenger or WhatsApp, and their message already says which product or page they are looking at.', 'bd-courier' ), '', '', 'grid' ); ?>

			<?php self::row_open( __( 'Show chat buttons', 'bd-courier' ), 'bdc-chat-enabled', true ); ?>
				<label class="bdc-check">
					<input type="checkbox" id="bdc-chat-enabled" name="bdc[enabled]" value="yes" <?php checked( 'yes', $chat['enabled'] ); ?>>
					<?php esc_html_e( 'Show the chat button on every page except checkout', 'bd-courier' ); ?>
				</label>
			<?php self::row_close( __( 'Only the channels you fill in below are shown.', 'bd-courier' ) ); ?>

			<?php self::row_open( 'WhatsApp', 'bdc-chat-whatsapp' ); ?>
				<input type="text" id="bdc-chat-whatsapp" name="bdc[whatsapp]" value="<?php echo esc_attr( $chat['whatsapp'] ); ?>" placeholder="8801XXXXXXXXX" inputmode="tel" autocomplete="off">
			<?php self::row_close( __( 'Your WhatsApp number with the country code, for example 8801712345678. A number starting with 01 is changed to 8801 for you.', 'bd-courier' ) ); ?>

			<?php self::row_open( 'Messenger', 'bdc-chat-messenger' ); ?>
				<input type="text" id="bdc-chat-messenger" name="bdc[messenger]" value="<?php echo esc_attr( $chat['messenger'] ); ?>" placeholder="yourshop" autocomplete="off">
			<?php self::row_close( __( 'Your Facebook Page username, as in facebook.com/yourshop. You can also paste the full Page or m.me link.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Facebook Page ID', 'bd-courier' ), 'bdc-chat-messenger-id' ); ?>
				<input type="text" id="bdc-chat-messenger-id" name="bdc[messenger_id]" value="<?php echo esc_attr( $chat['messenger_id'] ); ?>" placeholder="1234567890" inputmode="numeric" autocomplete="off">
			<?php self::row_close( __( 'Optional, but needed on iPhone: lets customers who are browsing inside the Facebook app jump straight to the Messenger app. Find it on your Facebook Page under About → Page transparency → Page ID.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Message on product pages', 'bd-courier' ), 'bdc-chat-message', true ); ?>
				<textarea id="bdc-chat-message" name="bdc[message]" rows="3" class="bdc-note bdc-wide" placeholder="<?php echo esc_attr( BDC_Chat::default_message() ); ?>"><?php echo esc_textarea( $chat['message'] ); ?></textarea>
			<?php self::row_close( __( 'The message customers start with. {product} becomes the product name and {link} the page link. Leave empty to use the text shown in grey.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Message on other pages', 'bd-courier' ), 'bdc-chat-message-page', true ); ?>
				<textarea id="bdc-chat-message-page" name="bdc[message_page]" rows="3" class="bdc-note bdc-wide" placeholder="<?php echo esc_attr( BDC_Chat::default_page_message() ); ?>"><?php echo esc_textarea( $chat['message_page'] ); ?></textarea>
			<?php self::row_close( __( 'Used on the home page, shop, categories and other pages. {product} becomes the page title and {link} the page link.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( false );
	}

	/* ---- Pathao ---- */

	private static function pathao_tab() {
		$courier  = BDC_Couriers::get( 'pathao' );
		$settings = $courier->settings();
		$stores   = $courier->is_connected() ? $courier->stores() : array();
		$stores   = is_wp_error( $stores ) ? array() : $stores;
		?>
		<?php self::panel_open( __( 'Connect Pathao', 'bd-courier' ), __( 'Find these in the Pathao merchant panel under Developer API.', 'bd-courier' ), 'https://merchant.pathao.com/courier/developer-api', __( 'Open Pathao merchant panel', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Use Pathao', 'bd-courier' ), 'bdc-pathao-enabled' ); ?>
				<label class="bdc-check">
					<input type="checkbox" id="bdc-pathao-enabled" name="bdc[enabled]" value="yes" <?php checked( 'yes', $settings['enabled'] ); ?>>
					<?php esc_html_e( 'Use Pathao in my shop', 'bd-courier' ); ?>
				</label>
			<?php self::row_close( __( 'When this is off, Pathao is hidden everywhere: no send buttons, bulk actions, reports or shipment tabs for it. Parcels already sent keep updating.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Environment', 'bd-courier' ), 'bdc-pathao-env' ); ?>
				<select id="bdc-pathao-env" name="bdc[environment]">
					<option value="live" <?php selected( 'live', $settings['environment'] ); ?>><?php esc_html_e( 'Live (real parcels)', 'bd-courier' ); ?></option>
					<option value="sandbox" <?php selected( 'sandbox', $settings['environment'] ); ?>><?php esc_html_e( 'Sandbox (testing only)', 'bd-courier' ); ?></option>
				</select>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Client ID', 'bd-courier' ), 'bdc-pathao-id' ); ?>
				<input type="text" id="bdc-pathao-id" name="bdc[client_id]" value="<?php echo esc_attr( $settings['client_id'] ); ?>" autocomplete="off">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Client Secret', 'bd-courier' ), 'bdc-pathao-secret' ); ?>
				<?php self::secret_input( 'bdc-pathao-secret', 'client_secret', '' !== $settings['client_secret'] ); ?>
			<?php self::row_close( '' !== $settings['client_secret'] ? __( 'A secret is saved. Type a new one only to replace it.', 'bd-courier' ) : '' ); ?>

			<?php self::row_open( __( 'Username', 'bd-courier' ), 'bdc-pathao-username' ); ?>
				<input type="text" id="bdc-pathao-username" name="bdc[username]" value="<?php echo esc_attr( $settings['username'] ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Your Pathao merchant login email', 'bd-courier' ); ?>">
			<?php self::row_close( __( 'Needed for the Sandbox, and for live accounts that do not log in with the Client ID and Secret alone.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Password', 'bd-courier' ), 'bdc-pathao-password' ); ?>
				<?php self::secret_input( 'bdc-pathao-password', 'password', '' !== $settings['password'] ); ?>
			<?php self::row_close( '' !== $settings['password'] ? __( 'A password is saved. Type a new one only to replace it.', 'bd-courier' ) : '' ); ?>

			<?php self::row_open( __( 'Testing', 'bd-courier' ) ); ?>
				<button type="button" class="button bdc-pathao-test-account"
					data-client-id="7N1aMJQbWm"
					data-client-secret="wRcaibZkUdSNz2EI9ZyuXLlNrnAv0TdPUPXMnD39"
					data-username="test@pathao.com"
					data-password="lovePathao"><?php esc_html_e( 'Use Pathao test account', 'bd-courier' ); ?></button>
			<?php self::row_close( __( 'Fills in Pathao\'s public sandbox account and switches to Sandbox, so you can try sending without a real parcel. Remember to switch back to Live with your own details afterwards.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Automatic status updates', 'bd-courier' ), __( 'Add these two values under Webhook in the Pathao merchant panel, so Pathao can tell your shop when a parcel moves.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Callback URL', 'bd-courier' ) ); ?>
				<?php self::copy_field( BDC_Webhooks::url( 'pathao' ) ); ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Secret', 'bd-courier' ) ); ?>
				<?php self::copy_field( BDC_Settings::webhook_secret( 'pathao' ) ); ?>
			<?php self::row_close( self::last_webhook( 'pathao' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Starting values in the send form', 'bd-courier' ), __( 'You can change these for each parcel before sending.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Pickup store', 'bd-courier' ), 'bdc-pathao-store' ); ?>
				<?php if ( $stores ) : ?>
					<?php self::select( 'bdc-pathao-store', 'store_id', $stores, $settings['store_id'] ); ?>
				<?php else : ?>
					<input type="hidden" name="bdc[store_id]" value="<?php echo esc_attr( $settings['store_id'] ); ?>">
					<span class="bdc-muted"><?php esc_html_e( 'Your stores appear here once Pathao is connected.', 'bd-courier' ); ?></span>
				<?php endif; ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Delivery type', 'bd-courier' ), 'bdc-pathao-delivery' ); ?>
				<?php self::select( 'bdc-pathao-delivery', 'delivery_type', BDC_Pathao::delivery_types(), $settings['delivery_type'] ); ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Item type', 'bd-courier' ), 'bdc-pathao-item' ); ?>
				<?php self::select( 'bdc-pathao-item', 'item_type', BDC_Pathao::item_types(), $settings['item_type'] ); ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Default note for the rider', 'bd-courier' ), 'bdc-pathao-note' ); ?>
				<textarea id="bdc-pathao-note" name="bdc[default_note]" rows="2" class="bdc-note" placeholder="<?php esc_attr_e( 'For example: Call the customer before delivery. Handle with care.', 'bd-courier' ); ?>"><?php echo esc_textarea( $settings['default_note'] ); ?></textarea>
			<?php self::row_close( __( 'Added to every Pathao parcel. If the order has a private note, it is added after this one. You can still edit it before sending.', 'bd-courier' ) ); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( true );
	}

	/* ---- Steadfast ---- */

	private static function steadfast_tab() {
		$courier  = BDC_Couriers::get( 'steadfast' );
		$settings = $courier->settings();
		?>
		<?php self::panel_open( __( 'Connect Steadfast', 'bd-courier' ), __( 'Find these in your Steadfast merchant panel under API, or ask Steadfast support for them.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Use Steadfast', 'bd-courier' ), 'bdc-steadfast-enabled' ); ?>
				<label class="bdc-check">
					<input type="checkbox" id="bdc-steadfast-enabled" name="bdc[enabled]" value="yes" <?php checked( 'yes', $settings['enabled'] ); ?>>
					<?php esc_html_e( 'Use Steadfast in my shop', 'bd-courier' ); ?>
				</label>
			<?php self::row_close( __( 'When this is off, Steadfast is hidden everywhere: no send buttons, bulk actions, reports or shipment tabs for it. Parcels already sent keep updating.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'API Key', 'bd-courier' ), 'bdc-steadfast-key' ); ?>
				<input type="text" id="bdc-steadfast-key" name="bdc[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off">
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Secret Key', 'bd-courier' ), 'bdc-steadfast-secret' ); ?>
				<?php self::secret_input( 'bdc-steadfast-secret', 'secret_key', '' !== $settings['secret_key'] ); ?>
			<?php self::row_close( '' !== $settings['secret_key'] ? __( 'A secret is saved. Type a new one only to replace it.', 'bd-courier' ) : '' ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Automatic status updates', 'bd-courier' ), __( 'Add these two values under Webhook in the Steadfast merchant panel, so Steadfast can tell your shop when a parcel moves.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Callback URL', 'bd-courier' ) ); ?>
				<?php self::copy_field( BDC_Webhooks::url( 'steadfast' ) ); ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Auth token (Bearer)', 'bd-courier' ) ); ?>
				<?php self::copy_field( BDC_Settings::webhook_secret( 'steadfast' ) ); ?>
			<?php self::row_close( self::last_webhook( 'steadfast' ) ); ?>

		<?php self::panel_close(); ?>

		<?php self::panel_open( __( 'Starting values in the send form', 'bd-courier' ), __( 'You can change these for each parcel before sending.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Delivery type', 'bd-courier' ), 'bdc-steadfast-delivery' ); ?>
				<?php self::select( 'bdc-steadfast-delivery', 'delivery_type', BDC_Steadfast::delivery_types(), $settings['delivery_type'] ); ?>
			<?php self::row_close(); ?>

			<?php self::row_open( __( 'Default note for the rider', 'bd-courier' ), 'bdc-steadfast-note' ); ?>
				<textarea id="bdc-steadfast-note" name="bdc[default_note]" rows="2" class="bdc-note" placeholder="<?php esc_attr_e( 'For example: Call the customer before delivery. Handle with care.', 'bd-courier' ); ?>"><?php echo esc_textarea( $settings['default_note'] ); ?></textarea>
			<?php self::row_close( __( 'Added to every Steadfast parcel. If the order has a private note, it is added after this one. You can still edit it before sending.', 'bd-courier' ) ); ?>

			<?php self::row_open( __( 'Customer\'s checkout note', 'bd-courier' ) ); ?>
				<label class="bdc-check">
					<input type="checkbox" name="bdc[send_note]" value="yes" <?php checked( 'yes', $settings['send_note'] ); ?>>
					<span><?php esc_html_e( 'Use the customer\'s checkout note when the order has no private note', 'bd-courier' ); ?></span>
				</label>
			<?php self::row_close(); ?>

		<?php self::panel_close(); ?>

		<?php
		self::buttons( true );
	}

	/* ---- Building blocks ---- */

	/**
	 * @param string $layout '' for label-left rows, 'grid' for fields with their label on top, side by side.
	 */
	private static function panel_open( $title, $intro = '', $link = '', $link_text = '', $layout = '' ) {
		echo '<section class="bdc-panel' . ( 'grid' === $layout ? ' bdc-panel--grid' : '' ) . '"><header class="bdc-panel__head"><h2>' . esc_html( $title ) . '</h2>';
		if ( $intro ) {
			echo '<p>' . esc_html( $intro );
			if ( $link ) {
				echo ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link_text ) . '</a>';
			}
			echo '</p>';
		}
		echo '</header><div class="bdc-panel__body">';
	}

	private static function panel_close() {
		echo '</div></section>';
	}

	/** @param bool $wide In a grid panel, take the full row (for long text). */
	private static function row_open( $label, $for = '', $wide = false ) {
		echo '<div class="bdc-row' . ( $wide ? ' bdc-row--wide' : '' ) . '"><div class="bdc-row__label">';
		echo $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</div><div class="bdc-row__field">';
	}

	private static function row_close( $help = '' ) {
		if ( $help ) {
			echo '<p class="bdc-row__help">' . esc_html( $help ) . '</p>';
		}
		echo '</div></div>';
	}

	private static function buttons( $with_test ) {
		echo '<div class="bdc-actions">';
		if ( $with_test ) {
			echo '<button type="submit" name="bdc_test" value="1" class="button button-primary">' . esc_html__( 'Save and test connection', 'bd-courier' ) . '</button>';
			echo '<button type="submit" class="button">' . esc_html__( 'Save only', 'bd-courier' ) . '</button>';
		} else {
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'bd-courier' ) . '</button>';
		}
		echo '</div>';
	}

	/** Secrets are never printed back into the page. */
	private static function secret_input( $id, $key, $is_saved ) {
		printf(
			'<input type="password" id="%1$s" name="bdc[%2$s]" value="" autocomplete="new-password" placeholder="%3$s">',
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( $is_saved ? '••••••••••••' : '' )
		);
	}

	private static function select( $id, $key, array $options, $current ) {
		printf( '<select id="%s" name="bdc[%s]">', esc_attr( $id ), esc_attr( $key ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( (string) $value, (string) $current, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private static function copy_field( $value ) {
		printf(
			'<span class="bdc-copy"><input type="text" readonly value="%s" class="code" onfocus="this.select()"><button type="button" class="button bdc-copy__button">%s</button></span>',
			esc_attr( $value ),
			esc_html__( 'Copy', 'bd-courier' )
		);
	}

	private static function last_webhook( $courier ) {
		$time = (int) get_option( 'bdc_' . $courier . '_last_webhook', 0 );

		return $time
			/* translators: %s: time ago, e.g. "5 minutes" */
			? sprintf( __( 'Last update received %s ago.', 'bd-courier' ), human_time_diff( $time ) )
			: __( 'No update received yet.', 'bd-courier' );
	}
}

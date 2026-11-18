<?php
defined( 'ABSPATH' ) || exit;

/**
 * Chat buttons on product pages: one floating button that opens Messenger
 * and WhatsApp, with a message that already names the product and its link.
 */
class BDC_Chat {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/** Shown on every shop page except checkout (and the thank-you page), when switched on and a channel is filled in. */
	private static function should_show() {
		if ( is_admin() || wp_doing_ajax() || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return false;
		}
		$chat = BDC_Settings::section( 'chat' );
		return 'yes' === $chat['enabled'] && ( '' !== $chat['whatsapp'] || '' !== $chat['messenger'] );
	}

	public static function assets() {
		if ( ! self::should_show() ) {
			return;
		}
		wp_enqueue_style( 'bdc-chat', BDC_URL . 'assets/css/chat.css', array(), BDC_VERSION );
		wp_add_inline_style( 'bdc-chat', '.bdc-chat{--bdc-chat-accent:' . BDC_Brand::accent() . ';}' );
		wp_enqueue_script( 'bdc-chat', BDC_URL . 'assets/js/chat.js', array(), BDC_VERSION, true );
		if ( '' !== BDC_Settings::get( 'chat', 'messenger' ) ) {
			wp_enqueue_script( 'bdc-messenger', BDC_URL . 'assets/js/messenger.js', array(), BDC_VERSION, true );
		}
	}

	public static function default_message() {
		return __( "Hi! I'm interested in this product: {product}\n{link}", 'bd-courier' );
	}

	/** Message on pages that are not a product: {product} becomes the page title. */
	public static function default_page_message() {
		return __( "Hi! I have a question about: {product}\n{link}", 'bd-courier' );
	}

	/** WhatsApp wants the full number with country code, digits only. A local 01… number gets 880 in front. */
	public static function clean_whatsapp( $number ) {
		$digits = preg_replace( '/\D+/', '', (string) $number );
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '01' ) ) {
			$digits = '88' . $digits;
		}
		return $digits;
	}

	/** The Page username (or ID) from a username, a facebook.com link or an m.me link. */
	public static function clean_messenger( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#(?:facebook\.com|fb\.com|m\.me)/(?:profile\.php\?id=)?([A-Za-z0-9.\-]+)#i', $value, $match ) ) {
			$value = $match[1];
		}
		return preg_replace( '/[^A-Za-z0-9.\-]/', '', $value );
	}

	/**
	 * Messenger addresses for a Page. Phones use m.me, which opens the Messenger
	 * app with the message typed in. Computers use messenger.com, so the chat
	 * opens in Messenger rather than on Facebook.
	 *
	 * @return array mobile, desktop
	 */
	public static function messenger_urls( $page, $message = '' ) {
		$page = rawurlencode( self::clean_messenger( $page ) );
		return array(
			'mobile'  => 'https://m.me/' . $page . ( '' !== $message ? '?text=' . rawurlencode( $message ) : '' ),
			'desktop' => 'https://www.messenger.com/t/' . $page,
		);
	}

	/** Extra attributes for a Messenger link: the computer address and the message to copy. */
	public static function messenger_attrs( array $urls, $message = '' ) {
		return sprintf( ' data-bdc-desktop="%s" data-bdc-text="%s"', esc_url( $urls['desktop'] ), esc_attr( $message ) );
	}

	/** The message with this page's product (or page) name and link filled in. */
	private static function message() {
		$chat       = BDC_Settings::section( 'chat' );
		$on_product = function_exists( 'is_product' ) && is_product();

		if ( $on_product ) {
			$template = '' !== $chat['message'] ? $chat['message'] : self::default_message();
			$name     = wp_strip_all_tags( get_the_title() );
			$link     = get_permalink();
		} else {
			$template = '' !== $chat['message_page'] ? $chat['message_page'] : self::default_page_message();
			$name     = self::page_title();
			$link     = self::current_url();
		}

		return strtr(
			$template,
			array(
				'{product}' => $name,
				'{link}'    => esc_url_raw( $link ),
			)
		);
	}

	/** A clean page title: "Shop", "Cart" or a category name, without the site name after it. */
	private static function page_title() {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = wp_specialchars_decode( wp_strip_all_tags( wp_get_document_title() ), ENT_QUOTES );
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
		if ( '' !== $site && $title !== $site ) {
			$title = preg_replace( '/\s*[\-–—|·]\s*' . preg_quote( $site, '/' ) . '\s*$/u', '', $title );
		}
		return '' !== trim( $title ) ? trim( $title ) : $site;
	}

	/** This page's address: the site's own scheme and host, plus the path being viewed. */
	private static function current_url() {
		if ( is_singular() ) {
			return get_permalink();
		}
		$home = wp_parse_url( home_url() );
		$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = '/' . ltrim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
		return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;
	}

	public static function render() {
		if ( ! self::should_show() ) {
			return;
		}

		$chat    = BDC_Settings::section( 'chat' );
		$text    = self::message();
		$message = rawurlencode( $text );
		$links   = array();

		if ( '' !== $chat['messenger'] ) {
			$urls               = self::messenger_urls( $chat['messenger'], $text );
			$links['messenger'] = array(
				'label' => 'Messenger',
				'url'   => $urls['mobile'],
				'attrs' => self::messenger_attrs( $urls, $text ),
				'icon'  => '<path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.15.26.35.27.57l.05 1.78a.8.8 0 0 0 1.12.71l1.98-.87a.8.8 0 0 1 .53-.04c.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2Zm6 7.46-2.94 4.66a1.5 1.5 0 0 1-2.17.4l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.66a1.5 1.5 0 0 1 2.17-.4l2.34 1.75a.6.6 0 0 0 .72 0l3.16-2.4c.42-.32.97.18.69.63Z"/>',
			);
		}
		if ( '' !== $chat['whatsapp'] ) {
			$links['whatsapp'] = array(
				'label' => 'WhatsApp',
				'attrs' => '',
				'url'   => 'https://wa.me/' . rawurlencode( $chat['whatsapp'] ) . '?text=' . $message,
				'icon'  => '<path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2Zm0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 4.54 0 8.24 3.7 8.24 8.24 0 4.55-3.7 8.24-8.24 8.24Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.42.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.29Z"/>',
			);
		}
		?>
		<div class="bdc-chat" data-bdc-chat>
			<div class="bdc-chat__menu" id="bdc-chat-menu" role="menu" aria-label="<?php esc_attr_e( 'Chat with us', 'bd-courier' ); ?>">
				<?php foreach ( $links as $key => $link ) : ?>
					<a class="bdc-chat__link bdc-chat__link--<?php echo esc_attr( $key ); ?>" href="<?php echo esc_attr( $link['url'] ); // esc_url() would drop the encoded line break; the URL is built here from encoded parts on a fixed https host. ?>" target="_blank" rel="noopener noreferrer" role="menuitem"<?php echo $link['attrs']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in messenger_attrs(). ?>>
						<span class="bdc-chat__icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false"><?php echo $link['icon']; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed SVG paths above. ?></svg></span>
						<span class="bdc-chat__label"><?php echo esc_html( $link['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<button type="button" class="bdc-chat__toggle" aria-expanded="false" aria-controls="bdc-chat-menu" aria-label="<?php esc_attr_e( 'Chat with us', 'bd-courier' ); ?>">
				<svg class="bdc-chat__open" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20l1.2-4.2A8.2 8.2 0 0 1 3 11.5 8.6 8.6 0 0 1 12 3a8.6 8.6 0 0 1 9 8.5Z"/><path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01" stroke-width="2.6"/></svg>
				<svg class="bdc-chat__close" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
			</button>
		</div>
		<?php
	}
}

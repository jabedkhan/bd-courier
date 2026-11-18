<?php
defined( 'ABSPATH' ) || exit;

/**
 * Branding: the name the plugin goes by in the dashboard, its accent
 * colour and the logo. Set under BD Courier → Settings → Branding.
 */
class BDC_Brand {

	const DEFAULT_NAME   = 'BD Courier';
	const DEFAULT_ACCENT = '#2563eb';

	public static function name() {
		$name = trim( (string) BDC_Settings::get( 'brand', 'name', '' ) );
		return '' !== $name ? $name : self::DEFAULT_NAME;
	}

	public static function accent() {
		$colour = sanitize_hex_color( (string) BDC_Settings::get( 'brand', 'accent', '' ) );
		return $colour ? $colour : self::DEFAULT_ACCENT;
	}

	/** The logo chosen in settings, or '' when there is none. */
	public static function logo_url( $size = 'medium' ) {
		$id = (int) BDC_Settings::get( 'print', 'logo_id', 0 );
		return $id ? (string) wp_get_attachment_image_url( $id, $size ) : '';
	}

	/** Shapes of the plugin icon: a parcel box on the move. */
	const ICON_PATHS = '<path d="M12 2.6l6.2 3.4-6.2 3.2-6.2-3.2z"/><path d="M5.5 7l5.9 3.1v7.1l-5.9-3.6z"/><path d="M12.6 10.1l5.9-3.1v6.6l-5.9 3.6z"/><rect x="0.6" y="8.2" width="3.6" height="1.3" rx="0.65"/><rect x="1.6" y="11" width="2.6" height="1.3" rx="0.65"/><rect x="0.6" y="13.8" width="3.6" height="1.3" rx="0.65"/>';

	/** The plugin icon as inline SVG in the current text colour. */
	public static function icon_svg( $size = 20, $class = '' ) {
		return sprintf(
			'<svg class="%1$s" width="%2$d" height="%2$d" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" focusable="false">%3$s</svg>',
			esc_attr( $class ),
			(int) $size,
			self::ICON_PATHS
		);
	}

	/** The plugin icon for the admin menu; WordPress recolours it to match the menu. */
	public static function menu_icon() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><g fill="black">' . self::ICON_PATHS . '</g></svg>' );
	}

	/**
	 * Mix a hex colour with black (negative amount) or white (positive), -1 to 1.
	 */
	public static function shade( $hex, $amount ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$target = $amount < 0 ? 0 : 255;
		$amount = min( 1, abs( $amount ) );
		$result = '#';

		foreach ( str_split( $hex, 2 ) as $part ) {
			$value   = hexdec( $part );
			$result .= str_pad( dechex( (int) round( $value + ( $target - $value ) * $amount ) ), 2, '0', STR_PAD_LEFT );
		}

		return $result;
	}

	/** True when white text is readable on the accent colour. */
	private static function is_dark( $hex ) {
		$hex = ltrim( $hex, '#' );
		$r   = hexdec( substr( $hex, 0, 2 ) );
		$g   = hexdec( substr( $hex, 2, 2 ) );
		$b   = hexdec( substr( $hex, 4, 2 ) );

		return ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) < 160;
	}

	/** CSS custom properties that carry the accent colour into the stylesheet. */
	public static function css() {
		$accent = self::accent();

		return sprintf(
			':root{--bdc-accent:%1$s;--bdc-accent-dark:%2$s;--bdc-accent-soft:%3$s;--bdc-on-accent:%4$s;}',
			$accent,
			self::shade( $accent, -0.18 ),
			self::shade( $accent, 0.9 ),
			self::is_dark( $accent ) ? '#ffffff' : '#111827'
		);
	}
}

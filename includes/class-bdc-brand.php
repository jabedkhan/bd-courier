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

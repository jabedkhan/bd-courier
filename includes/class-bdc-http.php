<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small JSON HTTP helper shared by both couriers.
 */
class BDC_Http {

	/**
	 * @return array|WP_Error array( 'code' => int, 'body' => array )
	 */
	public static function request( $method, $url, array $headers = array(), $body = null, $timeout = 30 ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'bdc_network',
				sprintf(
					/* translators: %s: technical error message */
					__( 'Could not reach the courier. Please check your internet connection and try again. (%s)', 'bd-courier' ),
					$response->get_error_message()
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : array(),
		);
	}
}

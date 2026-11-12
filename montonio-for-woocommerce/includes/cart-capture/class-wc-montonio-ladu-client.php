<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Ladu_Client
 *
 * Sends signed requests to Ladu's cart rescue API at the capture endpoint: the claims are signed as an
 * HS256 JWT with the store's Montonio secret and posted as {"data": "<jwt>"}.
 *
 * @since 10.5.2
 */
class WC_Montonio_Ladu_Client {
    /**
     * Posts $claims to $route under the capture endpoint, or to the endpoint itself when $route is empty.
     *
     * @since 10.5.2
     * @param string $route   The path below the endpoint, without a leading slash; '' for the endpoint.
     * @param array  $claims  The claims to sign.
     * @param int    $exp     The token's expiry, a Unix time.
     * @param int    $timeout Seconds to wait for the answer.
     * @return array 'status': the HTTP status, 0 for a transport error; 'body': the response body; 'error': the WP_Error of a transport error, else null.
     */
    public static function post( $route, $claims, $exp, $timeout ) {
        $endpoint = (string) get_option( WC_Montonio_Cart_Capture::OPTION_ENDPOINT, '' );
        $url      = '' === $route ? $endpoint : untrailingslashit( $endpoint ) . '/' . $route;
        $jwt      = WC_Montonio_Helper::create_jwt_token( array_merge( $claims, array( 'exp' => $exp ) ) );

        $response = wp_remote_post( $url, array(
            'timeout' => $timeout,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'data' => $jwt ) )
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 0, 'body' => '', 'error' => $response );
        }

        return array(
            'status' => (int) wp_remote_retrieve_response_code( $response ),
            'body'   => (string) wp_remote_retrieve_body( $response ),
            'error'  => null
        );
    }
}

<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class for managing Montonio Shipping webhooks
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Webhooks {
    /**
     * Handle incoming webhooks from Montonio Shipping V2
     * 
     * @since 7.0.0
     * @param WP_REST_Request $request The incoming request
     * @return WP_REST_Response|WP_Error The response object if everything went well, WP_Error if something went wrong
     */
    public static function  handle_webhook( $request ) {
        $body = sanitize_text_field( $request->get_body() );

        WC_Montonio_Logger::log( 'Montonio Shipping webhook received: ' . $body );

        // let's decode the JSON body
        $decoded_body = json_decode( $body );

        // if the body is not JSON, return an error
        if ( ! $decoded_body || ! isset( $decoded_body->payload ) ) {
            return new WP_Error( 'montonio_shipping_webhook_invalid_json', 'Invalid JSON body', ['status' => 400] );
        }

        $payload      = null;
        try {
            $payload = WC_Montonio_Helper::decode_jwt_token( $decoded_body->payload );
        } catch ( Exception $e ) {
            return new WP_Error( 'montonio_shipping_webhook_invalid_token', 'Invalid token', ['status' => 400] );
        }

        switch ( $payload->eventType ) {
        case 'shipment.registered':
            return WC_Montonio_Shipping_Order::add_tracking_codes( $payload );
        case 'shipment.registrationFailed':
            return WC_Montonio_Shipping_Order::handle_registration_failed_webhook( $payload );
        case 'labelFile.ready':
            return WC_Montonio_Shipping_Label_Printing::get_instance()->handle_label_ready_webhook( $payload );
        default:
            WC_Montonio_Logger::log( 'Received unknown webhook event type: ' . $payload->eventType );
            return new WP_REST_Response( ['message' => 'Not handling this event type'], 200 );
        }
    }
}

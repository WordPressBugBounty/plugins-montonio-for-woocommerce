<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class for managing Montonio Shipping V2 webhooks
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Webhooks extends Montonio_Singleton {
    /**
     * The constructor for the Montonio Shipping Webhooks class
     *
     * @since 7.0.0
     */
    protected function __construct() {
        add_action( 'wc_montonio_shipping_register_webhook', [ $this, 'ensure_store_shipping_webhook_is_registered' ] );
    }

    /**
     * Ensure that the store shipping webhook is registered in Montonio Shipping V2
     * 
     * @since 7.0.0
     * @return void
     */
    public function ensure_store_shipping_webhook_is_registered( $force = false ) {
        $rest_url    = esc_url_raw( rest_url( 'montonio/shipping/v2/webhook' ) );
        $api_keys    = WC_Montonio_Helper::get_api_keys();
        $hash        = md5( $api_keys['access_key'] . $rest_url );
        $stored_hash = get_option( 'montonio_shipping_webhook_url_hash' );

        // If the stored hash is different from the current hash, update the webhook
        if ( $hash !== $stored_hash || $force ) {
            try {
                $webhooks = WC_Montonio_Shipping_Webhooks::get_instance()->get_registered_webhooks();

                // Find if the $rest_url is already registered as a webhook [ { url: 'https://example.com/wp-json/montonio/shipping/v2/webhook' } ]
                $webhook = array_filter( $webhooks, function ( $webhook ) use ( $rest_url ) {
                    return $webhook->url === $rest_url;
                } );

                // If the webhook is not found, register it
                if ( empty( $webhook ) ) {
                    $result = WC_Montonio_Shipping_Webhooks::get_instance()->register_store_shipping_webhook();
                    WC_Montonio_Logger::log( 'Shipping Webhook registration result: ' . print_r( $result, true ) );
                } else {
                    WC_Montonio_Logger::log( 'Shipping Webhook already registered' );
                }

                // Update the stored hash
                update_option( 'montonio_shipping_webhook_url_hash', $hash, 'no' );
            } catch ( Exception $e ) {
                WC_Montonio_Logger::log( 'Error: ' . $e->getMessage() );
            }
        } else {
            WC_Montonio_Logger::log( 'Shipping Webhook already registered. Hashes match.' );
        }
    }

    /**
     * Get all registered webhooks from Montonio Shipping V2
     * 
     * @since 7.0.0
     * @return array The registered webhooks
     * @throws Exception If the webhooks could not be fetched
     */
    public function get_registered_webhooks() {
        $shipping_api = get_montonio_shipping_api();
        $response     = $shipping_api->get_webhooks();
        $response     = json_decode( $response );

        if ( isset( $response->data ) ) {
            return $response->data;
        } else {
            throw new Exception( 'Could not get webhooks from Montonio Shipping V2' );
        }
    }

    /**
     * Register the shipping webhook for the store in Montonio Shipping V2.
     * This is done on plugin activation and when something changes in the webhook settings.
     *
     * @since 7.0.0
     * @return object The response from the API
     */
    public function register_store_shipping_webhook() {
        $shipping_api = get_montonio_shipping_api();
        $args         = [
            'url'           => esc_url_raw( rest_url( 'montonio/shipping/v2/webhook' ) ),
            'enabledEvents' => [
                'shipment.registered',
                'shipment.registrationFailed',
                'labelFile.ready'
            ]
        ];

        $args     = apply_filters( 'montonio_shipping_webhook_args', $args );
        $response = $shipping_api->create_webhook( $args );

        return json_decode( $response );
    }

    /**
     * Handle incoming webhooks from Montonio Shipping V2
     * 
     * @since 7.0.0
     * @param WP_REST_Request $request The incoming request
     * @return WP_REST_Response|WP_Error The response object if everything went well, WP_Error if something went wrong
     */
    public function handle_webhook( $request ) {
        $body = sanitize_text_field( $request->get_body() );

        WC_Montonio_Logger::log( 'Montonio Shipping webhook received: ' . $body );

        // let's decode the JSON body
        $decoded_body = json_decode( $body );

        // if the body is not JSON, return an error
        if ( ! $decoded_body || ! isset( $decoded_body->payload ) ) {
            return new WP_Error( 'montonio_shipping_webhook_invalid_json', 'Invalid JSON body', ['status' => 400] );
        }

        $shipping_api = get_montonio_shipping_api();
        $payload      = null;
        try {
            $payload = $shipping_api->decode_webhook_token( $decoded_body->payload );
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
WC_Montonio_Shipping_Webhooks::get_instance();

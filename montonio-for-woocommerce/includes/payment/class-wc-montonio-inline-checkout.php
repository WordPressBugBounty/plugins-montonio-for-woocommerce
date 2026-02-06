<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Inline_Checkout {

    public function __construct() {
        add_action( 'wp_ajax_get_payment_intent', array( $this, 'get_payment_intent' ) );
        add_action( 'wp_ajax_nopriv_get_payment_intent', array( $this, 'get_payment_intent' ) );
        add_action( 'wp_ajax_get_session_uuid', array( $this, 'get_session_uuid' ) );
        add_action( 'wp_ajax_nopriv_get_session_uuid', array( $this, 'get_session_uuid' ) );
    }

    /**
     * Handles the creation and retrieval of a Montonio payment intent for inline checkout.
     *
     * @since 8.0.1
     * @return void
     * @throws Exception Internally for parameter validation and API errors, caught and handled within the function.
     * @package WooCommerce
     */
    public function get_payment_intent() {
        try {
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'montonio_embedded_checkout_nonce' ) ) {
                throw new Exception( 'Unable to verify your request. Please reload the page and try again.' );
            }

            $method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : null;

            if ( empty( $method ) ) {
                throw new Exception( 'Missing payment method parameter.' );
            }

            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->create_payment_intent( $method );

            wp_send_json_success( $response );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Montonio Embedded Checkout: ' . $e->getMessage() );

            try {
                $response = json_decode( $e->getMessage() );

                if ( $response->message === 'PAYMENT_METHOD_PROCESSOR_MISMATCH' ) {
                    WC_Montonio_Data_Sync::sync_data();

                    wp_send_json_error( array( 'message' => $e->getMessage(), 'reload' => true ) );
                }
            } catch ( Exception $e ) {
                WC_Montonio_Logger::log( 'Error parsing JSON response: ' . $e->getMessage() );
            }

            wp_send_json_error( $e->getMessage() );
        }
    }


    public function get_session_uuid() {
        try {
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'montonio_embedded_checkout_nonce' ) ) {
                throw new Exception( 'Unable to verify your request. Please reload the page and try again.' );
            }

            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->get_session_uuid();

            wp_send_json_success( json_decode( $response ) );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Montonio Embedded Checkout: ' . $e->getMessage() );

            wp_send_json_error( $e->getMessage() );
        }
    }
}

new WC_Montonio_Inline_Checkout();
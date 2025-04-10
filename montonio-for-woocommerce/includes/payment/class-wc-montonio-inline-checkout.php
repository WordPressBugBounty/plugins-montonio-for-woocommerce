<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Inline_Checkout {

    public function __construct() {
        add_action( 'wp_ajax_get_payment_intent', array( $this, 'get_payment_intent' ) );
        add_action( 'wp_ajax_nopriv_get_payment_intent', array( $this, 'get_payment_intent' ) );
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
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'montonio_embedded_payment_intent_nonce' ) ) {
                throw new Exception( 'Unable to verify your request. Please reload the page and try again.' );
            }

            $sandbox_mode = isset( $_POST['sandbox_mode'] ) ? sanitize_key( wp_unslash( $_POST['sandbox_mode'] ) ) : null;
            $method       = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : null;

            if ( empty( $sandbox_mode ) || empty( $method ) ) {
                throw new Exception( 'Missing required parameters.' );
            }

            $montonio_api = new WC_Montonio_API( $sandbox_mode );
            $response     = $montonio_api->create_payment_intent( $method );

            wp_send_json_success( $response );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Montonio Inline Checkout: ' . $e->getMessage() );

            try {
                $response = json_decode( $e->getMessage() );

                if ( $response->message === 'PAYMENT_METHOD_PROCESSOR_MISMATCH' ) {
                    $blik_gateway = new WC_Montonio_Blik();
                    $blik_gateway->sync_blik_settings();

                    wp_send_json_error( array( 'message' => $e->getMessage(), 'reload' => true ) );
                }
            } catch ( Exception $e ) {
                WC_Montonio_Logger::log( 'Error parsing JSON response: ' . $e->getMessage() );
            }

            wp_send_json_error( $e->getMessage() );
        }
    }
}

new WC_Montonio_Inline_Checkout();
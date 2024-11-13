<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Montonio_Inline_Checkout {
    
    public function __construct() {
        add_action('wp_ajax_get_payment_intent', array( $this, 'get_payment_intent' ) );
        add_action('wp_ajax_nopriv_get_payment_intent', array( $this, 'get_payment_intent' ) );
    }

    /**
     * Handles the creation and retrieval of a Montonio payment intent for inline checkout.
     *
     * @return void
     * @throws Exception Internally for parameter validation and API errors, caught and handled within the function.
     * @package WooCommerce
     */
    public function get_payment_intent() {
        try {
            $sandbox_mode = isset( $_POST['sandbox_mode'] ) ? sanitize_text_field( $_POST['sandbox_mode'] ) : null;
            $method = isset( $_POST['method'] ) ? sanitize_text_field( $_POST['method'] ) : null;
        
            if ( empty( $sandbox_mode ) || empty( $method ) ) {
                throw new Exception( 'Missing required parameters.' );
            }
    
            $montonio_api = new WC_Montonio_API( $sandbox_mode );
            $response = $montonio_api->create_payment_intent( $method );
        
            wp_send_json_success( $response );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Montonio Inline Checkout: ' . $e->getMessage() );
    
            wp_send_json_error( $e->getMessage() );
        }
    }
}
new WC_Montonio_Inline_Checkout();
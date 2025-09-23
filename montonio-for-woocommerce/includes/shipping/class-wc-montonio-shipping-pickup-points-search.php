<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX search functionality for Montonio shipping pickup points.
 *
 * @since 9.1.1
 */
class WC_Montonio_Shipping_Pickup_Points_Search {
    public function __construct() {
        add_action( 'wp_ajax_montonio_pickup_points_search', array( $this, 'search_pickup_points' ) );
        add_action( 'wp_ajax_nopriv_montonio_pickup_points_search', array( $this, 'search_pickup_points' ) );
    }

    /**
     * AJAX handler for searching pickup points.
     *
     * @since 9.1.1
     * @return void Sends JSON response via wp_send_json_success() or wp_send_json_error()
     * @throws Exception When API request fails or returns invalid JSON
     */
    public function search_pickup_points() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'], 'montonio_pickup_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token' ) );
        }
        
        // Validate and sanitize input
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        $country = sanitize_text_field( $_POST['country'] ?? '' );
        $carrier = sanitize_text_field( $_POST['carrier'] ?? '' );
        
        if ( strlen( $search ) < 3 ) {
            wp_send_json_error( array( 'message' => 'Search query must be at least 3 characters' ) );
        }
        
        try {
            $sandbox_mode = get_option( 'montonio_shipping_sandbox_mode', 'no' );
            $api = new WC_Montonio_Shipping_API( $sandbox_mode );
            
            // Make API request with search parameter
            $response = $api->get_pickup_points( $carrier, $country, $search );
            $response = sanitize_textarea_field( $response );
            
            $data = json_decode( $response, true );
            
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                throw new Exception( 'Invalid JSON response from API' );
            }

            wp_send_json_success( $data );
            
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );
            wp_send_json_error( array( 'message' => $message) );
        }
    }
}
new WC_Montonio_Shipping_Pickup_Points_Search();
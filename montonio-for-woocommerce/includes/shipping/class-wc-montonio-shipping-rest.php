<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_REST - REST API endpoints for Montonio Shipping V2
 * @since 7.0.0
 * @since 7.0.1 Removed mark_labels_as_downloaded method, refactored poll_labels to get_label
 */
class WC_Montonio_Shipping_REST {
    /**
     * Route namespace
     *
     * @since 7.0.0
     */
    const NAMESPACE_PREFIX = 'montonio/shipping/v2';

    /**
     * Initialize the class
     *
     * @since 7.0.0
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     *
     * @since 7.0.0
     */
    public static function register_routes() {
        register_rest_route( self::NAMESPACE_PREFIX, '/labels/create',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_label' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/labels',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_label_file' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/shipment/create',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_shipment' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/shipment/update',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'update_shipment' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/shipment/update-panel',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'update_shipping_panel' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/shipment/sync',
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'sync_shipment_details' ),
                'permission_callback' => array( __CLASS__, 'permissions_check' )
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle_webhook' ),
                'permission_callback' => '__return_true'
            )
        );

        register_rest_route( self::NAMESPACE_PREFIX, '/sync-shipping-method-items',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'sync_shipping_method_items' ),
                'permission_callback' => array( __CLASS__, 'check_sync_shipping_method_items_permissions' )
            )
        );
    }

    /**
     * Validate the sync shipping method items request
     *
     * @param $request
     * @return mixed
     */
    public static function check_sync_shipping_method_items_permissions( $request ) {
        $token = $request->get_param( 'token' );

        try {
            $decoded = WC_Montonio_Helper::decode_jwt_token( $token );
            $url     = esc_url_raw( rest_url( 'montonio/shipping/v2/sync-shipping-method-items' ) );
            $hash    = md5( $url );

            return hash_equals( $hash, $decoded->hash );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /**
     * Check if the nonce is valid
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return bool True if the nonce is valid, false otherwise
     */
    public static function check_nonce_validity( $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Check if the user has the required permissions
     *
     * @since 7.0.0
     * @return bool True if the user has the required permissions, false otherwise
     */
    public static function permissions_check() {
        return current_user_can( 'view_woocommerce_reports' );
    }

    /**
     * Handle incoming webhooks from Montonio Shipping
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function handle_webhook( $request ) {
        return rest_ensure_response( WC_Montonio_Shipping_Webhooks::handle_webhook( $request ) );
    }

    /**
     * Create shipping label for the provided order IDs
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function create_label( $request ) {
        $order_ids = $request->get_param( 'order_ids' );

        // Make all order IDs integers
        $order_ids = array_map( 'intval', $order_ids );

        // Validate order IDs: must be an array of positive integers
        if ( empty( $order_ids ) || ! is_array( $order_ids ) || array_filter( $order_ids, function ( $id ) {return ! is_int( $id ) || $id <= 0;} ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_ids', 'Invalid or no order IDs provided.', array( 'status' => 400 ) );
        }

        try {
            $labels = WC_Montonio_Shipping_Label_Printing::create_label( $order_ids );

            return rest_ensure_response( array( 'message' => 'Labels created successfully.', 'data' => $labels ) );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Label creation failed. Response:' . $e->getMessage() );
            return new WP_Error( 'wc_montonio_shipping_label_creation_error', 'Error creating labels: ' . $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    /**
     * Poll for shipping labels that are ready to be downloaded (status = ready)
     *
     * @since 7.0.0
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function get_label_file( $request ) {
        $label_file_id = $request->get_param( 'label_file_id' );
        if ( ! WC_Montonio_Helper::is_valid_uuid( $label_file_id ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_label_file_id', 'Invalid or no label file ID provided.', array( 'status' => 400 ) );
        }

        $label = WC_Montonio_Shipping_Label_Printing::get_label_file_by_id( $label_file_id );

        return rest_ensure_response( array( 'message' => 'Label fetched successfully.', 'data' => $label ) );
    }

    /**
     * Create shipment for the provided order ID
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function create_shipment( $request ) {
        $order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', array( 'status' => 400 ) );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return new WP_Error( 'wc_montonio_shipping_unupported_method', 'Order doesn\'t have Montonio shipping method.', array( 'status' => 400 ) );
        }

        $shipment = WC_Montonio_Shipping_Shipment_Manager::create_shipment( $order );

        if ( empty( $shipment ) ) {
            return new WP_Error( 'wc_montonio_shipping_shipment_creation_error', 'Shipment creation failed.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'message' => 'Shipment created successfully.', 'shipment' => $shipment ) );
    }

    /**
     * Update shipment for the provided order ID
     *
     * @since 7.0.2
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function update_shipment( $request ) {
        $order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', array( 'status' => 400 ) );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return new WP_Error( 'wc_montonio_shipping_unupported_method', 'Order doesn\'t have Montonio shipping method.', array( 'status' => 400 ) );
        }

        $shipment = WC_Montonio_Shipping_Shipment_Manager::update_shipment( $order );

        if ( empty( $shipment ) ) {
            return new WP_Error( 'wc_montonio_shipping_shipment_update_error', 'Shipment update failed.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'message' => 'Shipment successfully updated.', 'shipment' => $shipment ) );
    }

    /**
     * Sync shipping method items immediately
     *
     * @since 7.0.1
     * @return WP_REST_Response The response object
     */
    public static function sync_shipping_method_items() {
        $result = WC_Montonio_Shipping_Sync::sync();

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array( 'message' => 'Shipping method items synced successfully.' ) );
    }

    /**
     * Update the shipping panel content (the view in single order page)
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function update_shipping_panel( $request ) {
        $order_id    = $request->get_param( 'order_id' );
        $last_status = $request->get_param( 'status' );
        $order       = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', array( 'status' => 400 ) );
        }

        $current_status = $order->get_meta( '_wc_montonio_shipping_shipment_status' );
        $panel_content  = '';

        if ( $current_status !== $last_status ) {
            $panel_content = WC_Montonio_Shipping_Order::get_order_shipping_panel_content( $order );
        }

        return rest_ensure_response( array(
            'status' => $current_status,
            'panel'  => $panel_content
        ) );
    }

    /**
     * Sync shipment details for the provided order ID
     *
     * @since 9.4.4
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public static function sync_shipment_details( $request ) {
        $order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', array( 'status' => 400 ) );
        }

        $shipment = WC_Montonio_Shipping_Shipment_Manager::sync_shipment_details( $order );

        if ( empty( $shipment ) ) {
            return new WP_Error( 'wc_montonio_shipping_sync_shipment_error', 'Shipment retrieval failed.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'message' => 'Shipment successfully retrieved.', 'shipment' => $shipment ) );
    }
}

<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_REST - REST API endpoints for Montonio Shipping V2
 * @since 7.0.0
 * @since 7.0.1 Removed mark_labels_as_downloaded method, refactored poll_labels to get_label
 */
class WC_Montonio_Shipping_REST extends Montonio_Singleton {
    /**
     * Route namespace
     *
     * @since 7.0.0
     * @var string
     */
    protected $namespace = 'montonio/shipping/v2';

    /**
     * Constructor
     *
     * @since 7.0.0
     */
    protected function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     *
     * @since 7.0.0
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/labels/create',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_label'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/labels',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_label_file'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/labels/mark-as-downloaded',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'mark_labels_as_downloaded'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/shipment/create',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_shipment'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/shipment/update',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_shipment'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/shipment/update-panel',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_shipping_panel'],
                'permission_callback' => [$this, 'permissions_check']
            ]
        );

        register_rest_route( $this->namespace, '/webhook',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_webhook'],
                'permission_callback' => '__return_true' // @TODO: Maybe handle webhook authentication here?
            ]
        );

        register_rest_route( $this->namespace, '/get-shipping-method-items',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_shipping_method_items'],
                'permission_callback' => [$this, 'check_nonce_validity']
            ]
        );

        register_rest_route( $this->namespace, '/sync-shipping-method-items',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'sync_shipping_method_items'],
                'permission_callback' => [$this, 'check_sync_shipping_method_items_permissions']
            ]
        );
    }

    /**
     * Validate the sync shipping method items request
     *
     * @param $request
     * @return mixed
     */
    public function check_sync_shipping_method_items_permissions( $request ) {
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
    public function check_nonce_validity( $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return wp_verify_nonce( $nonce, 'wp_rest' );
    }

    /**
     * Check if the user has the required permissions
     *
     * @since 7.0.0
     * @return bool True if the user has the required permissions, false otherwise
     */
    public function permissions_check() {
        return current_user_can( 'view_woocommerce_reports' );
    }

    /**
     * Get shipping method items
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function get_shipping_method_items( $request ) {
        $chosen_shipping_method = sanitize_text_field( $request->get_param( 'shipping_method' ) );
        $country_code           = sanitize_text_field( $request->get_param( 'country' ) );
        
        // Create an instance of the shipping method
        $montonio_shipping_method = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $chosen_shipping_method );

        if ( ! is_a( $montonio_shipping_method, 'Montonio_Shipping_Method' ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_shipping_method', 'Invalid or no shipping method provided.', ['status' => 400] );
        }

        $shipping_method_items = WC_Montonio_Shipping_Helper::get_items_for_montonio_shipping_method( $montonio_shipping_method, $country_code );
        if ( empty( $shipping_method_items ) ) {
            return new WP_Error( 'wc_montonio_shipping_no_items', 'No items found for the provided shipping method.', ['status' => 404] );
        }

        return rest_ensure_response( $shipping_method_items );
    }

    /**
     * Handle incoming webhooks from Montonio Shipping
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function handle_webhook( $request ) {
        return rest_ensure_response( WC_Montonio_Shipping_Webhooks::handle_webhook( $request ) );
    }

    /**
     * Create shipping label for the provided order IDs
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function create_label( $request ) {
        $order_ids = $request->get_param( 'order_ids' );

        // Make all order IDs integers
        $order_ids = array_map( 'intval', $order_ids );

        // Validate order IDs: must be an array of positive integers
        if ( empty( $order_ids ) || ! is_array( $order_ids ) || array_filter( $order_ids, function ( $id ) {return ! is_int( $id ) || $id <= 0;} ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_ids', 'Invalid or no order IDs provided.', ['status' => 400] );
        }

        try {
            $handler = WC_Montonio_Shipping_Label_Printing::get_instance();
            $labels  = $handler->create_label( $order_ids );
            return rest_ensure_response( ['message' => 'Labels created successfully.', 'data' => $labels] );
        } catch ( Exception $e ) {
            // TODO: Montonio Logger
            error_log( 'Error creating labels: ' . $e->getMessage() );
            WC_Montonio_Logger::log( 'Label creation failed. Response:' . $e->getMessage() );
            return new WP_Error( 'wc_montonio_shipping_label_creation_error', 'Error creating labels: ' . $e->getMessage(), ['status' => 500] );
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
    public function get_label_file( $request ) {
        $label_file_id = $request->get_param( 'label_file_id' );
        if ( ! WC_Montonio_Helper::is_valid_uuid( $label_file_id ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_label_file_id', 'Invalid or no label file ID provided.', ['status' => 400] );
        }

        $handler = WC_Montonio_Shipping_Label_Printing::get_instance();
        $label   = $handler->get_label_file_by_id( $label_file_id );

        return rest_ensure_response( ['message' => 'Label fetched successfully.', 'data' => $label] );
    }

    /**
     * Create shipment for the provided order ID
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function create_shipment( $request ) {
        $order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', ['status' => 400] );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return new WP_Error( 'wc_montonio_shipping_unupported_method', 'Order doesn\'t have Montonio shipping method.', ['status' => 400] );
        }

        $handler  = WC_Montonio_Shipping_Shipment_Manager::get_instance();
        $shipment = $handler->create_shipment( $order );

        if ( empty( $shipment ) ) {
            return new WP_Error( 'wc_montonio_shipping_shipment_creation_error', 'Shipment creation failed.', ['status' => 500] );
        }

        return rest_ensure_response( ['message' => 'Shipment created successfully.', 'shipment' => $shipment] );
    }

    /**
     * Update shipment for the provided order ID
     *
     * @since 7.0.2
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function update_shipment( $request ) {
        $order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', ['status' => 400] );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return new WP_Error( 'wc_montonio_shipping_unupported_method', 'Order doesn\'t have Montonio shipping method.', ['status' => 400] );
        }

        $handler  = WC_Montonio_Shipping_Shipment_Manager::get_instance();
        $shipment = $handler->update_shipment( $order );

        if ( empty( $shipment ) ) {
            return new WP_Error( 'wc_montonio_shipping_shipment_update_error', 'Shipment update failed.', ['status' => 500] );
        }

        return rest_ensure_response( ['message' => 'Shipment successfully updated.', 'shipment' => $shipment] );
    }

    /**
     * Sync shipping method items immediately
     *
     * @since 7.0.1
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function sync_shipping_method_items() {
        try {
            $lock_manager = new Montonio_Lock_Manager();

            // Attempt to acquire the lock to prevent multiple processes from running the sync simultaneously
            if ( ! $lock_manager->acquire_lock( 'montonio_shipping_method_items_sync' ) ) {
                return rest_ensure_response( ['message' => 'Another process is already syncing shipping method items.'] );
            }

            // Trigger the action to sync shipping method items
            do_action( 'wc_montonio_shipping_sync_shipping_method_items' );

            // Release the lock
            $lock_manager->release_lock( 'montonio_shipping_method_items_sync' );

            return rest_ensure_response( ['message' => 'Shipping method items synced successfully.'] );
        } catch ( Exception $e ) {
            // Log the error and return a WP_Error response
            error_log( 'Error syncing shipping method items: ' . $e->getMessage() );
            return new WP_Error( 'wc_montonio_shipping_sync_error', 'Error syncing shipping method items: ' . $e->getMessage(), ['status' => 500] );
        }
    }

    /**
     * Update the shipping panel content (the view in single order page)
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The response object or WP_Error if something went wrong
     */
    public function update_shipping_panel( $request ) {
        $order_id      = $request->get_param('order_id');
        $last_status   = $request->get_param('status');
        $order         = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            return new WP_Error( 'wc_montonio_shipping_invalid_order_id', 'Invalid or no order ID provided.', ['status' => 400] );
        }

        $current_status = $order->get_meta( '_wc_montonio_shipping_shipment_status' );
        $panel_content = '';

        if ( $current_status !== $last_status ) {
            $panel_content = WC_Montonio_Shipping_Order::get_order_shipping_panel_content( $order );
        }

        return new WP_REST_Response( [
            'status' => $current_status,
            'panel' => $panel_content
        ] );
    }
}

WC_Montonio_Shipping_REST::get_instance();

<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Label_Printing for handling actions related to label printing
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Label_Printing extends Montonio_Singleton {

    /**
     * The constructor for the WC_Montonio_Shipping_Label_Printing class
     *
     * @since 7.0.0
     */
    protected function __construct() {
        if ( WC_Montonio_Helper::is_hpos_enabled() ) {
            add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_label_printing_bulk_actions' ) );
            add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_mark_as_label_printed_bulk_action' ) );
            add_action( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_label_printed_bulk_action' ), 10, 3 );
        } else {
            add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_label_printing_bulk_actions' ) );
            add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_mark_as_label_printed_bulk_action' ) );
            add_action( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_label_printed_bulk_action' ), 10, 3 );
        }

        add_action( 'wc_montonio_shipping_labels_ready', array( $this, 'mark_orders_as_labels_printed' ) );
    }

    /**
     * Create labels for the given order IDs.
     *
     * @since 7.0.0
     * @since 7.0.1 Renamed to create_label. No longer saves label information to the label printing repository, just returns the response.
     * @param array $order_ids The order IDs to create labels for.
     * @return object The response object from the API.
     */
    public function create_label( $order_ids ) {
        $shipment_ids = $this->get_shipment_ids_from_order_ids( $order_ids );
        $shipping_api = get_montonio_shipping_api();

        // This throws if there is a HTTP error
        $response = $shipping_api->create_label( [
            'shipmentIds' => $shipment_ids,
            'metadata'    => [
                'platform'        => 'wordpress ' . get_bloginfo( 'version' ) . ' woocommerce ' . WC()->version,
                'platformVersion' => WC_MONTONIO_PLUGIN_VERSION
            ]
        ] );

        $response = json_decode( $response );

        return $response;
    }

    /**
     * Get label by label file ID.
     *
     * @since 7.0.1
     * @param string $label_file_id The label file ID
     * @return object The response object from the API.
     */
    public function get_label_file_by_id( $label_file_id ) {
        $shipping_api = get_montonio_shipping_api();
        $response     = $shipping_api->get_label_file_by_id( $label_file_id );
        $response     = json_decode( $response );

        return $response;
    }

    /**
     * Get shipment IDs from order IDs.
     *
     * @since 7.0.0
     * @param array $order_ids The order IDs to get shipment IDs from.
     * @return array The shipment IDs. If there are orders without shipment IDs, no error is thrown.
     */
    public function get_shipment_ids_from_order_ids( $order_ids ) {
        $shipment_ids = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            
            if ( 'registered' !== $order->get_meta( '_wc_montonio_shipping_shipment_status' ) ) {
                continue;
            }
            
            $shipment_id = $order->get_meta( '_wc_montonio_shipping_shipment_id' );

            if ( ! empty( $shipment_id ) ) {
                $shipment_ids[] = $shipment_id;
            }
        }

        return $shipment_ids;
    }

    /**
     * Get order IDs from shipment IDs.
     *
     * @since 7.0.0
     * @param array $shipment_ids The shipment IDs to get order IDs from.
     * @return array The order IDs. If there are shipments without order IDs, no error is thrown.
     */
    public function get_order_ids_from_shipment_ids( $shipment_ids ) {
        // Map shipment IDs to order IDs using the helper function, filtering out any null or false values
        $order_ids = array_filter( array_map( function ( $shipment_id ) {
            return WC_Montonio_Helper::get_order_id_by_meta_data( $shipment_id, '_wc_montonio_shipping_shipment_id' );
        }, $shipment_ids ) );

        // Remove duplicates and reindex the array
        return array_values( array_unique( $order_ids ) );
    }

    /**
     * Adds the label printing bulk actions to the WooCommerce orders list view.
     *
     * @since 7.0.0
     * @param array $actions The current bulk actions
     * @return array The modified bulk actions
     */
    public function add_label_printing_bulk_actions( $actions ) {
        $actions['wc_montonio_print_labels'] = __( 'Print V2 shipping labels', 'montonio-for-woocommerce' );

        wp_enqueue_script( 'wc-montonio-shipping-label-printing' );

        wp_localize_script(
            'wc-montonio-shipping-label-printing',
            'wcMontonioShippingLabelPrintingData',
            array(
                'getLabelFileUrl'           => esc_url_raw( rest_url( 'montonio/shipping/v2/labels' ) ),
                'createLabelsUrl'           => esc_url_raw( rest_url( 'montonio/shipping/v2/labels/create' ) ),
                'markLabelsAsDownloadedUrl' => esc_url_raw( rest_url( 'montonio/shipping/v2/labels/mark-as-downloaded' ) ),
                'nonce'                     => wp_create_nonce( 'wp_rest' )
            )
        );

        return $actions;
    }

    /**
     * Adds the mark as label printed bulk action to the WooCommerce orders list view.
     *
     * @since 7.1.2
     * @param array $actions The current bulk actions
     * @return array The modified bulk actions
     */
    public function add_mark_as_label_printed_bulk_action( $actions ) {
        $actions['mark_label-printed'] = __( 'Change status to label printed', 'montonio-for-woocommerce' );

        return $actions;
    }

    /**
     * Handle the 'Mark as label printed' bulk action and update order statuses.
     *
     * @since 7.1.2
     *
     * @param string $redirect_to The URL to redirect to after handling the bulk action.
     * @param string $action The bulk action being performed.
     * @param array $post_ids The array of order IDs selected for the bulk action.
     * @return string The URL to redirect to after handling the bulk action.
     */
    public function handle_label_printed_bulk_action( $redirect_to, $action, $post_ids ) {
        if ( $action !== 'mark_label-printed' ) {
            return $redirect_to; // If it's not the action we are handling, exit.
        }

        foreach ( $post_ids as $post_id ) {
            $order = wc_get_order( $post_id );
            if ( $order && 'wc-mon-label-printed' !== $order->get_status() ) {
                // Update order status to "Label Printed".
                $order->update_status( 'wc-mon-label-printed', __( 'Order marked as Label Printed.', 'montonio-for-woocommerce' ) );
            }
        }

        // Add a query parameter to the redirect URL to indicate how many orders were updated.
        $redirect_to = add_query_arg( 'bulk_label_printed_orders', count( $post_ids ), $redirect_to );

        return $redirect_to;
    }

    /**
     * Handles the label ready webhook from Montonio Shipping V2.
     *
     * @since 7.0.0
     * @since 7.0.1 No longer uses Label_Printing_Repository. Only fires the wc_montonio_shipping_labels_ready action.
     * @param object $payload The payload from the webhook
     * @return WP_REST_Response|WP_Error The response object if everything went well, WP_Error if something went wrong
     */
    public function handle_label_ready_webhook( $payload ) {
        if ( isset( $payload->data->shipmentIds ) ) {
            $order_ids = $this->get_order_ids_from_shipment_ids( $payload->data->shipmentIds );
            do_action( 'wc_montonio_shipping_labels_ready', $order_ids );
            return new WP_REST_Response( 'labelFile.ready event handled successfully', 200 );
        } else {
            return new WP_Error( 'montonio_shipping_label_ready_webhook_failed', 'No shipment IDs found in the payload', ['status' => 400] );
        }
    }

    /**
     * Mark orders as labels printed. This is used after the label file has been downloaded.
     *
     * @since 7.0.0
     * @param array $order_ids The order IDs to mark as labels printed.
     * @return void
     */
    public function mark_orders_as_labels_printed( $order_ids ) {
        $new_status = get_option( 'montonio_shipping_orderStatusWhenLabelPrinted', 'wc-mon-label-printed' );
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order->get_status() === 'processing' && 'no-change' !== $new_status ) {
                $order->update_status( $new_status );
                $current_time = current_time( 'timestamp' );
                WC_Montonio_Logger::log( 'Shipping V2 -> Label Printing -> Order ' . $order_id . ' status changed from processing to ' . $new_status, $current_time, $current_time );
                $order->add_order_note( 'Montonio shipping label printed' );
            }

            $order->update_meta_data( '_wc_montonio_shipping_label_printed', 'yes' );
            $order->save();
        }
    }
}

WC_Montonio_Shipping_Label_Printing::get_instance();

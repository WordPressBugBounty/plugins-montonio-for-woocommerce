<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class for managing Montonio Shipping webhooks
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Webhooks {
    /**
     * Handle incoming shipping webhooks
     *
     * @since 7.0.0
     * @param WP_REST_Request $request The incoming request
     * @return WP_REST_Response|WP_Error The response object if everything went well, WP_Error if something went wrong
     */
    public static function handle_webhook( $request ) {
        $body = sanitize_text_field( $request->get_body() );

        WC_Montonio_Logger::log( 'Montonio Shipping webhook received: ' . $body );

        // Let's decode the JSON body
        $decoded_body = json_decode( $body );

        // If the body is not JSON, return an error
        if ( ! $decoded_body || ! isset( $decoded_body->payload ) ) {
            return new WP_Error( 'montonio_shipping_webhook_invalid_json', 'Invalid JSON body', array( 'status' => 400 ) );
        }

        $payload = null;

        try {
            $payload = WC_Montonio_Helper::decode_jwt_token( $decoded_body->payload );
        } catch ( Exception $e ) {
            return new WP_Error( 'montonio_shipping_webhook_invalid_token', $e->getMessage(), array( 'status' => 400 ) );
        }

        switch ( $payload->eventType ) {
            case 'shipment.registered':
                return self::handle_shipment_registered( $payload );
            case 'shipment.registrationFailed':
                return self::handle_shipment_registration_failed( $payload );
            case 'shipment.statusUpdated':
                return self::handle_shipment_status_updated( $payload );
            case 'shipment.labelsCreated':
                return self::handle_shipment_labels_created( $payload );
            default:
                WC_Montonio_Logger::log( 'Received unhandled webhook event type: ' . $payload->eventType );
                return new WP_REST_Response( array( 'message' => 'Not handling this event type' ), 200 );
        }
    }

    /**
     * Add shipment tracking codes to order
     *
     * @since 7.0.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    private static function handle_shipment_registered( $payload ) {
        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( __( 'handle_shipment_registered: Order not found.', 'montonio-for-woocommerce' ) );
            return new WP_REST_Response( array( 'message' => 'Order not found' ), 400 );
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return new WP_REST_Response( array( 'message' => 'Order not using Montonio shipping method' ), 400 );
        }

        $tracking_links = '';

        foreach ( $payload->data->parcels as $parcel ) {
            $parcel_id    = sanitize_text_field( $parcel->carrierParcelId );
            $tracking_url = sanitize_text_field( $parcel->trackingLink );

            if ( ! empty( $tracking_url ) ) {
                $tracking_links .= '<a href="' . esc_url( $tracking_url ) . '" target="_blank">' . esc_html( $parcel_id ) . '</a><br>';
            }
        }

        if ( ! empty( $tracking_links ) ) {
            $order->add_order_note( __( '<strong>Shipment created.</strong><br>Tracking codes: ', 'montonio-for-woocommerce' ) . $tracking_links );
            $shipping_method->update_meta_data( 'tracking_codes', $tracking_links );
            $shipping_method->save_meta_data();

            $order->update_meta_data( '_montonio_tracking_info', $tracking_links );
        }

        $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'registered' );
        $order->save_meta_data();

        return new WP_REST_Response( array( 'message' => 'Tracking codes processed' ), 200 );
    }

    /**
     * Handle 'shipment.statusUpdate' webhook
     *
     * @since 8.1.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    private static function handle_shipment_status_updated( $payload ) {
        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( __( 'handle_shipment_status_update: Order not found.', 'montonio-for-woocommerce' ) );
            return new WP_REST_Response( array( 'message' => 'Order not found' ), 400 );
        }

        $status = sanitize_text_field( $payload->data->status );

        if ( ! empty( $status ) ) {
            do_action( 'wc_montonio_shipping_shipment_status_update', $status, $order );

            $order->update_meta_data( '_wc_montonio_shipping_shipment_status', $status );
            $order->save_meta_data();

            $new_order_status = get_option( 'montonio_shipping_order_status_when_delivered', 'wc-completed' );

            if ( 'delivered' === $status && 'no-change' !== $new_order_status ) {
                $order->update_status( $new_order_status );
            }
        }

        return new WP_REST_Response( array( 'message' => 'Shipment status update processed' ), 200 );
    }

    /**
     * Handle 'shipment.registrationFailed' webhook
     *
     * @since 7.0.0
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    private static function handle_shipment_registration_failed( $payload ) {
        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( 'handle_shipment_registration_failed: Order not found.' );
            return new WP_REST_Response( array( 'message' => 'Order not found' ), 400 );
        }

        // Recursive function to traverse the nested errors and collect messages and descriptions
        function collect_error_messages( $errors, &$messages, &$seen_messages, $depth = 0, $max_depth = 5 ) {
            if ( $depth > $max_depth ) {
                return;
            }

            foreach ( $errors as $error ) {
                if ( isset( $error->message ) && ! in_array( $error->message, $seen_messages ) ) {
                    $sanitized_message = sanitize_text_field( $error->message );
                    $messages[]        = $sanitized_message;
                    $seen_messages[]   = $sanitized_message;
                }
                if ( isset( $error->description ) && ! in_array( $error->description, $seen_messages ) ) {
                    $sanitized_description = sanitize_text_field( $error->description );
                    $messages[]            = $sanitized_description;
                    $seen_messages[]       = $sanitized_description;
                }
                if ( isset( $error->cause ) ) {
                    if ( is_array( $error->cause ) ) {
                        collect_error_messages( $error->cause, $messages, $seen_messages, $depth + 1, $max_depth );
                    } else {
                        collect_error_messages( array( $error->cause ), $messages, $seen_messages, $depth + 1, $max_depth );
                    }
                }
            }
        }

        $messages      = array();
        $seen_messages = array();

        if ( ! empty( $payload->data->errors ) ) {
            collect_error_messages( $payload->data->errors, $messages, $seen_messages );
        }

        $message = '<strong>' . __( 'Shipment registration failed.', 'montonio-for-woocommerce' ) . '</strong>';

        if ( ! empty( $messages ) ) {
            $message .= '<br>' . implode( '<br>', $messages );
        }

        $order->add_order_note( $message );
        $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'registrationFailed' );
        $order->update_meta_data( '_wc_montonio_shipping_shipment_status_reason', $message );
        $order->save_meta_data();

        return new WP_REST_Response( array( 'message' => 'Shipment registration failed message added to order' ), 200 );
    }

    /**
     * Handle 'shipment.labelsCreated' webhook
     *
     * @since 9.0.1
     * @param object $payload The payload data
     * @return WP_REST_Response The response object
     */
    private static function handle_shipment_labels_created( $payload ) {
        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $payload->shipmentId, '_wc_montonio_shipping_shipment_id' );
        $order    = wc_get_order( $order_id );

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) || $order->get_meta( '_wc_montonio_shipping_shipment_id', true ) !== $payload->shipmentId ) {
            WC_Montonio_Logger::log( 'handle_shipment_labels_created: Order not found.' );
            return new WP_REST_Response( array( 'message' => 'Order not found' ), 400 );
        }

        $new_order_status = get_option( 'montonio_shipping_orderStatusWhenLabelPrinted', 'wc-mon-label-printed' );

        if ( $order->get_status() === 'processing' && 'no-change' !== $new_order_status ) {
            $order->update_status( $new_order_status );
            $order->add_order_note( 'Montonio shipping label printed' );

            WC_Montonio_Logger::log( 'handle_shipment_labels_created: Order ' . $order_id . ' status changed to ' . $new_order_status );
        }

        $order->update_meta_data( '_wc_montonio_shipping_shipment_status', 'labelsCreated' );
        $order->update_meta_data( '_wc_montonio_shipping_label_printed', 'yes' );
        $order->save_meta_data();

        return new WP_REST_Response( array( 'message' => 'labelsCreated event handled successfully' ), 200 );
    }
}

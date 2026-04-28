<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles payment and refund callbacks (webhooks and customer returns) from Montonio.
 *
 * @since 8.1.0
 */
class WC_Montonio_Callbacks {
    /**
     * Handle customer return from Montonio payment page.
     *
     * @since 10.0.0
     * @return void
     */
    public static function handle_return() {
        self::route_callback( true );
    }

    /**
     * Handle webhook notification from Montonio.
     *
     * @since 10.0.0
     * @return void
     */
    public static function handle_notification() {
        self::route_callback( false );
    }

    /**
     * Route the incoming callback to the appropriate handler based on query parameters.
     *
     * @since 8.1.0
     * @param bool $is_customer_return Whether customer is returning from payment (true) or this is a webhook (false).
     * @return void
     */
    private static function route_callback( $is_customer_return = false ) {
        if ( ! empty( $_GET['refund-token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['refund-token'] ) );
            self::process_refund_callback( $token );

            return;
        }

        if ( ! empty( $_GET['order-token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['order-token'] ) );
            self::process_payment_callback( $token, $is_customer_return );

            return;
        }

        if ( ! empty( $_GET['error-message'] ) ) {
            $error_message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['error-message'] ) ) );

            WC_Montonio_Logger::log( 'Payment error: ' . $error_message );
            wc_add_notice( $error_message, 'error' );
        }

        if ( $is_customer_return ) {
            wp_redirect( wc_get_checkout_url() );
            exit;
        }

        self::send_json_error( 'Bad Request', 'Token is missing' );
    }

    /**
     * Process refund callback notifications from Montonio.
     *
     * @since 8.1.0
     * @param string $token JWT token containing refund data from Montonio.
     * @return void
     */
    private static function process_refund_callback( $token ) {
        try {
            $decoded_token = WC_Montonio_Helper::decode_jwt_token( $token );
        } catch ( Throwable $exception ) {
            self::send_json_error( 'Bad Request', $exception->getMessage() );

            return;
        }

        WC_Montonio_Logger::log( 'Refund webhook: ' . json_encode( $decoded_token ) );

        $order_uuid  = sanitize_text_field( $decoded_token->orderUuid );
        $refund_uuid = sanitize_text_field( $decoded_token->refundUuid );
        $status      = sanitize_text_field( $decoded_token->refundStatus );
        $amount      = sanitize_text_field( $decoded_token->refundAmount );

        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $order_uuid, '_montonio_uuid' );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Unable to locate an order with the specified UUID: ' . $order_uuid );
            self::send_json_error( 'Not Found', 'Unable to locate an order with the specified UUID' );

            return;
        }

        // Acquire a per-order lock to serialise concurrent refund callbacks for the same order..
        $lock_manager = new Montonio_Lock_Manager();
        $lock_name    = 'refund_callback_' . $order->get_id();

        if ( ! $lock_manager->acquire_lock( $lock_name ) ) {
            WC_Montonio_Logger::log( 'Refund callback for order #' . $order->get_id() . ' is already being processed by another request — skipping.' );
            self::send_json_error( 'Callback already processing', 'Refund callback for this order is currently blocked by another request', 409 );

            return;
        }

        $existing_refunds = WC_Montonio_Refund::get_order_refunds( $order );

        // Handle existing refund
        if ( isset( $existing_refunds[$refund_uuid] ) ) {
            $wc_refund_id   = $existing_refunds[$refund_uuid]['wc_refund_id'];
            $current_status = $existing_refunds[$refund_uuid]['status'];

            $refund = wc_get_order( $wc_refund_id );

            if ( empty( $refund ) ) {
                $lock_manager->release_lock( $lock_name );
                self::send_json_error( 'Not Found', 'Unable to locate a refund with the specified ID' );

                return;
            }

            if ( 'PENDING' !== $current_status ) {
                $lock_manager->release_lock( $lock_name );
                self::send_json_error( 'Already Processed', 'This refund has already been processed and is no longer in pending status' );

                return;
            }

            switch ( $status ) {
                case 'CANCELED':
                    self::delete_refund( $refund );

                    /* translators: refund UUID */
                    $message = sprintf( __( '<strong>Refund canceled.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ), $refund_uuid );
                    $order->add_order_note( $message );

                    break;
                case 'REJECTED':
                    self::delete_refund( $refund );

                    /* translators: refund UUID */
                    $message = sprintf( __( '<strong>Refund rejected.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ), $refund_uuid );
                    $order->add_order_note( $message );

                    break;
                case 'SUCCESSFUL':
                    /* translators: 1) refund amount 2) refund UUID */
                    $message = sprintf( __( '<strong>Refund of %1$s processed successfully.</strong><br>Refund ID: %2$s', 'montonio-for-woocommerce' ), wc_price( $amount ), $refund_uuid );
                    $order->add_order_note( $message );

                    break;
                default:
                    $lock_manager->release_lock( $lock_name );
                    self::send_json_error( 'Invalid Status', 'Unknown refund status received: ' . $status );

                    return;
            }

            $existing_refunds[$refund_uuid]['status'] = $status;

            $order->update_meta_data( '_montonio_refunds', $existing_refunds );
            $order->save();

            $lock_manager->release_lock( $lock_name );

            wp_send_json_success( array(
                'message' => 'Refund status updated'
            ) );

            return;
        }

        // Handle new refund that is already rejected/canceled
        if ( 'REJECTED' === $status || 'CANCELED' === $status ) {
            /* translators: refund UUID */
            $message = sprintf( __( '<strong>Refund rejected.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ), $refund_uuid );
            $order->add_order_note( $message );

            $lock_manager->release_lock( $lock_name );

            wp_send_json_success( array(
                'message' => 'Refund status updated'
            ) );

            return;
        }

        // Create new refund
        $refund = wc_create_refund( array(
            'amount'         => $amount,
            'order_id'       => $order->get_id(),
            'reason'         => 'Refund via Montonio (ID: ' . $refund_uuid . ')',
            'refund_payment' => false
        ) );

        if ( is_wp_error( $refund ) ) {
            $lock_manager->release_lock( $lock_name );
            self::send_json_error( 'Refund Creation Failed', $refund->get_error_message() );

            return;
        }

        // Store the external refund ID in order meta to prevent duplicates
        $existing_refunds[$refund_uuid] = array(
            'wc_refund_id' => $refund->get_id(),
            'status'       => $status
        );

        $order->update_meta_data( '_montonio_refunds', $existing_refunds );
        $order->save();

        if ( 'SUCCESSFUL' === $status ) {
            /* translators: 1) refund amount 2) refund UUID */
            $message = sprintf( __( '<strong>Refund of %1$s processed successfully.</strong><br>Refund ID: %2$s', 'montonio-for-woocommerce' ), wc_price( $amount ), $refund_uuid );
        } else {
            /* translators: 1) refund amount 2) refund UUID */
            $message = sprintf( __( '<strong>Refund of %1$s pending.</strong><br>Refund ID: %2$s', 'montonio-for-woocommerce' ), wc_price( $amount ), $refund_uuid );
        }

        $order->add_order_note( $message );

        $lock_manager->release_lock( $lock_name );

        wp_send_json_success( array(
            'message' => 'Refund created successfully'
        ) );
    }

    /**
     * Process order status callback notifications from Montonio.
     *
     * @param string $token JWT token containing order status data from Montonio.
     * @param bool   $is_customer_return Whether customer is returning from payment (true) or this is a webhook (false).
     * @return void
     */
    private static function process_payment_callback( $token, $is_customer_return = false ) {
        $return_url = wc_get_checkout_url();

        try {
            $decoded_token = WC_Montonio_Helper::decode_jwt_token( $token );
            $decoded_token = apply_filters( 'wc_montonio_decoded_payment_token', $decoded_token );
        } catch ( Throwable $exception ) {
            wc_add_notice( __( 'There was a problem with processing the order.', 'montonio-for-woocommerce' ), 'error' );
            WC_Montonio_Logger::log( 'Unable to decode payment token: ' . $exception->getMessage() );

            if ( $is_customer_return ) {
                wp_redirect( $return_url );
                exit;
            }

            self::send_json_error( 'Unauthorized', $exception->getMessage() );

            return;
        }

        if ( $is_customer_return ) {
            WC_Montonio_Logger::log( 'Payment (Return URL): ' . json_encode( $decoded_token ) );
        } else {
            sleep( 5 );
            WC_Montonio_Logger::log( 'Payment webhook: ' . json_encode( $decoded_token ) );
        }

        $payment_status        = sanitize_text_field( $decoded_token->paymentStatus );
        $uuid                  = sanitize_text_field( $decoded_token->uuid );
        $order_id              = sanitize_text_field( $decoded_token->merchantReference );
        $grand_total           = sanitize_text_field( $decoded_token->grandTotal );
        $currency              = sanitize_text_field( $decoded_token->currency );
        $payment_method        = sanitize_text_field( $decoded_token->paymentMethod );
        $payment_provider_name = self::resolve_payment_provider_name( $payment_method, $decoded_token );

        $merchant_reference_type = WC_Montonio_Helper::get_api_settings()['merchant_reference_type'];
        $is_custom_ref = in_array( $merchant_reference_type, array( 'order_number', 'add_prefix' ), true );

        // Resolve via UUID immediately for custom reference types
        if ( $is_custom_ref ) {
            $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $uuid, '_montonio_uuid' );
        }

        $order = wc_get_order( $order_id );

        if ( empty( $order ) && ! $is_custom_ref ) {
            $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $uuid, '_montonio_uuid' );
            $order    = wc_get_order( $order_id );
        }

        // If we got a refund object instead of a real order, climb up to the parent order
        if ( ! empty( $order ) && $order instanceof WC_Order_Refund ) {
            $order = wc_get_order( $order->get_parent_id() );
        }

        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Unable to locate an order with the specified UUID: ' . $uuid );

            if ( $is_customer_return ) {
                die( 'Unable to locate an order with the specified UUID. Please contact merchant support. Order ID: ' . esc_attr( $uuid ) );
            }

            self::send_json_error( 'Not Found', 'Unable to locate an order with the specified UUID' );

            return;
        }

        // Acquire a per-order lock to serialise concurrent payment callbacks for the same order.
        $lock_manager = new Montonio_Lock_Manager();
        $lock_name    = 'payment_callback_' . $order->get_id();

        if ( ! $lock_manager->acquire_lock( $lock_name ) ) {
            if ( $is_customer_return ) {
                $lock_acquired = false;
                $max_attempts  = 8;

                for ( $i = 0; $i < $max_attempts; $i++ ) {
                    if ( $lock_manager->acquire_lock( $lock_name ) ) {
                        $lock_acquired = true;
                        break;
                    }

                    usleep( 500000 ); // wait 0.5s between attempts
                }

                if ( ! $lock_acquired ) {
                    WC_Montonio_Logger::log( 'Lock timeout for order #' . $order->get_id() . ' — redirecting customer to order received page.' );

                    $return_url = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
                    wp_redirect( $return_url );

                    exit;
                }
            } else {
                WC_Montonio_Logger::log( 'Callback for order #' . $order->get_id() . ' is already being processed by another request — skipping.' );
                self::send_json_error( 'Callback already processing', 'Callback for this order is currently blocked by another request', 409 );

                return;
            }
        }

        if ( strpos( $order->get_payment_method(), 'wc_montonio_' ) === false ) {
            WC_Montonio_Logger::log( 'Invalid payment method detected. Order ID: ' . $order_id );
            $lock_manager->release_lock( $lock_name );

            if ( $is_customer_return ) {
                die( 'Order contains payment method that is not supported by Montonio' );
            }

            self::send_json_error( 'Invalid payment method', 'Order contains payment method that is not supported by Montonio' );

            return;
        }

        $response_message = 'The order has been processed successfully';

        if ( $order->has_status( apply_filters( 'wc_montonio_processed_order_statuses', array( 'processing', 'completed', 'wc-mon-part-refund' ) ) ) && ! in_array( $payment_status, array( 'VOIDED', 'PARTIALLY_REFUNDED' ) ) ) {
            $response_message = 'The order has already been processed, no status change applied';
            WC_Montonio_Logger::log( 'The order (#' . $order_id . ') has already been processed, no status change applied' );

            $return_url = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
        } else {
            switch ( $payment_status ) {
                case 'PAID':
                    $order->payment_complete();

                    /* translators: 1) order UUID 2) payment method 3) amount 4) currency */
                    $message = sprintf( __( '<strong>Payment via Montonio.</strong><br>Order ID: %1$s<br>Payment method: %2$s<br>Paid amount: %3$s%4$s', 'montonio-for-woocommerce' ), $uuid, $payment_provider_name, $grand_total, $currency );
                    $order->add_order_note( $message );

                    WC()->cart->empty_cart();
                    $return_url = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
                    break;
                case 'AUTHORIZED':
                    /* translators: order UUID */
                    $message = sprintf( __( 'Montonio: Payment is authorized but not yet processed by the bank, order ID: %s', 'montonio-for-woocommerce' ), $uuid );
                    $order->add_order_note( $message );

                    $order->update_status( apply_filters( 'wc_montonio_authorized_order_status', 'on-hold' ) );

                    WC()->cart->empty_cart();
                    $return_url = apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order );
                    break;
                case 'VOIDED':
                    /* translators: order UUID */
                    $message = sprintf( __( 'Montonio: Payment was rejected by the bank, order ID: %s', 'montonio-for-woocommerce' ), $uuid );
                    $order->add_order_note( $message );

                    $order->update_status( apply_filters( 'wc_montonio_voided_order_status', 'cancelled' ) );
                    break;
                case 'ABANDONED':
                    /* translators: order UUID */
                    $message = sprintf( __( 'Montonio: Payment session abandoned, order ID: %s', 'montonio-for-woocommerce' ), $uuid );
                    $order->add_order_note( $message );

                    $order->update_status( apply_filters( 'wc_montonio_abandoned_order_status', 'cancelled' ) );
                    break;
                case 'PARTIALLY_REFUNDED':
                    $order->update_status( apply_filters( 'wc_montonio_partially_refunded_order_status', 'wc-mon-part-refund' ) );
                    break;
                case 'PENDING':
                    wc_add_notice( __( 'Payment status "PENDING". Please wait for the payment to be processed.', 'montonio-for-woocommerce' ), 'notice' );
                    break;
                default:
                    wc_add_notice( __( 'Unable to finish the payment. Please try again or choose a different payment method.', 'montonio-for-woocommerce' ), 'notice' );
                    WC_Montonio_Logger::log( 'Not handling this payment status. Payment status: ' . $payment_status );
                    break;
            }
        }

        $lock_manager->release_lock( $lock_name );

        if ( $is_customer_return ) {
            wp_redirect( $return_url );
            exit;
        }

        wp_send_json_success( array(
            'message' => $response_message
        ) );
    }

    /**
     * Resolve a human-readable payment provider name from the payment method identifier.
     *
     * @param string $payment_method The payment method identifier from Montonio.
     * @param object $decoded_token  The decoded JWT token.
     * @return string
     */
    private static function resolve_payment_provider_name( $payment_method, $decoded_token ) {
        switch ( $payment_method ) {
            case 'paymentInitiation':
                return sanitize_text_field( $decoded_token->paymentProviderName );
            case 'cardPayments':
                return 'Card payment';
            case 'bnpl':
                return 'Pay in parts';
            case 'hirePurchase':
                return 'Financing';
            case 'blik':
                return 'BLIK';
            default:
                return 'N/A';
        }
    }

    /**
     * Delete a WooCommerce refund order, handling both HPOS and legacy post meta storage.
     *
     * @param WC_Order_Refund $refund The refund order to delete.
     * @return void
     */
    private static function delete_refund( $refund ) {
        if ( WC_Montonio_Helper::is_hpos_enabled() ) {
            $refund->delete( true );
        } else {
            wp_delete_post( $refund->get_id(), true );
        }
    }

    /**
     * Send a standardized JSON error response and terminate execution.
     *
     * @param string $message       Short error message.
     * @param string $error_details Detailed error description.
     * @param int    $status_code   HTTP status code (default 200 for backward compatibility).
     * @return void
     */
    private static function send_json_error( $message, $error_details, $status_code = 200 ) {
        wp_send_json( array(
            'success' => false,
            'data'    => array(
                'message'       => $message,
                'error_details' => $error_details
            )
        ), $status_code );
    }
}

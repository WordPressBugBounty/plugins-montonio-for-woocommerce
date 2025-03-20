<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class for handling callbacks
 */
class WC_Montonio_Callbacks extends WC_Payment_Gateway {

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $sandbox_mode;

    /**
     * Check if the response is a webhook notification.
     *
     * @var bool
     */
    public $is_webhook;

    /**
     * Constructor.
     *
     * @param $sandbox_mode
     * @param $is_webhook
     */
    public function __construct( $sandbox_mode, $is_webhook ) {
        $this->sandbox_mode = $sandbox_mode;
        $this->is_webhook   = $is_webhook;

        $this->process_webhook();
    }

    /**
     * Process webhook notifications from Montonio payment gateway.
     *
     * @since 8.1.0
     * @return void
     */
    public function process_webhook() {
        if ( ! empty( $_REQUEST['refund-token'] ) ) {
            $token = sanitize_text_field( $_REQUEST['refund-token'] );
            $this->process_refund_webhook( $token );

            return;
        }

        if ( ! empty( $_REQUEST['order-token'] ) ) {
            $token = sanitize_text_field( $_REQUEST['order-token'] );

            $this->process_order_webhook( $token );

            return;
        }

        if ( ! empty( $_REQUEST['error-message'] ) ) {
            $error_message = rawurldecode( $_REQUEST['error-message'] );

            WC_Montonio_Logger::log( 'Payment error: ' . $error_message );

            wc_add_notice( $error_message, 'error' );
        }

        if ( $this->is_webhook ) {
            wp_send_json( array(
                'success' => false,
                'data'    => array(
                    'message'       => 'Bad Request',
                    'error_details' => 'Token is missing'
                )
            ), 200 );
        } else {
            wp_redirect( wc_get_checkout_url() );
            exit;
        }
    }

    /**
     * Process refund webhook notifications from Montonio.
     *
     * @since 8.1.0
     * @param string $token JWT token containing refund data from Montonio
     * @return void
     */
    public function process_refund_webhook( $token ) {
        // Delay webhook to prevent racing conditions
        sleep( 10 );

        try {
            $decoded_token = WC_Montonio_Helper::decode_jwt_token( $token, $this->sandbox_mode );
        } catch ( Throwable $exception ) {
            wp_send_json( array(
                'success' => false,
                'data'    => array(
                    'message'       => 'Bad Request',
                    'error_details' => $exception->getMessage()
                )
            ), 200 );
        }

        $order_uuid  = sanitize_text_field( $decoded_token->orderUuid );
        $refund_uuid = sanitize_text_field( $decoded_token->refundUuid );
        $status      = sanitize_text_field( $decoded_token->refundStatus );
        $amount      = sanitize_text_field( $decoded_token->refundAmount );

        $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $order_uuid, '_montonio_uuid' );
        $order    = wc_get_order( $order_id );

        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Unable to locate an order with the specified UUID: ' . $order_uuid );

            wp_send_json( array(
                'success' => false,
                'data'    => array(
                    'message'       => 'Not Found',
                    'error_details' => 'Unable to locate an order with the specified UUID'
                )
            ), 200 );
        }

        $existing_refunds = WC_Montonio_Refund::get_order_refunds( $order );

        // Handle existing refund
        if ( isset( $existing_refunds[$refund_uuid] ) ) {
            $wc_refund_id   = $existing_refunds[$refund_uuid]['wc_refund_id'];
            $current_status = $existing_refunds[$refund_uuid]['status'];

            $refund = wc_get_order( $wc_refund_id );

            if ( empty( $refund ) ) {
                wp_send_json( array(
                    'success' => false,
                    'data'    => array(
                        'message'       => 'Not Found',
                        'error_details' => 'Unable to locate a refund with the specified ID'
                    )
                ), 200 );
            }

            if ( 'PENDING' !== $current_status ) {
                wp_send_json( array(
                    'success' => false,
                    'data'    => array(
                        'message'       => 'Already Processed',
                        'error_details' => 'This refund has already been processed and is no longer in pending status'
                    )
                ), 200 );
            }

            switch ( $status ) {
                case 'CANCELED':
                    if ( WC_Montonio_Helper::is_hpos_enabled() ) {
                        $refund->delete( true );
                    } else {
                        wp_delete_post( $refund->get_id(), true );
                    }

                    $order->add_order_note(
                        sprintf(
                            __( '<strong>Refund canceled.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                            $refund_uuid
                        )
                    );

                    break;
                case 'REJECTED':
                    if ( WC_Montonio_Helper::is_hpos_enabled() ) {
                        $refund->delete( true );
                    } else {
                        wp_delete_post( $refund->get_id(), true );
                    }

                    $order->add_order_note(
                        sprintf(
                            __( '<strong>Refund rejected.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                            $refund_uuid
                        )
                    );

                    break;
                case 'SUCCESSFUL':
                    $order->add_order_note(
                        sprintf(
                            __( '<strong>Refund of %s processed succesfully.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                            wc_price( $amount ),
                            $refund_uuid
                        )
                    );

                    break;
                default:
                    wp_send_json( array(
                        'success' => false,
                        'data'    => array(
                            'message'       => 'Invalid Status',
                            'error_details' => sprintf( 'Unknown refund status received: %s', $status )
                        )
                    ), 200 );
            }

            $existing_refunds[$refund_uuid]['status'] = $status;

            $order->update_meta_data( '_montonio_refunds', $existing_refunds );
            $order->save();

            wp_send_json_success( array(
                'message' => sprintf( 'Refund status updated' )
            ) );
        }

        // Handle new refund
        if ( 'REJECTED' === $status || 'CANCELED' === $status ) {
            $order->add_order_note(
                sprintf(
                    __( '<strong>Refund rejected.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                    $refund_uuid
                )
            );

            wp_send_json_success( array(
                'message' => sprintf( 'Refund status updated' )
            ) );
        }

        // Create new refund
        $refund = wc_create_refund( array(
            'amount'         => $amount,
            'order_id'       => $order->get_id(),
            'reason'         => 'Refund via Montonio (ID: ' . $refund_uuid . ')',
            'refund_payment' => false
        ) );

        if ( is_wp_error( $refund ) ) {
            wp_send_json( array(
                'success' => false,
                'data'    => array(
                    'message'       => 'Refund Creation Failed',
                    'error_details' => $refund->get_error_message()
                )
            ), 200 );
        }

        // Store the external refund ID in order meta to prevent duplicates
        $existing_refunds[$refund_uuid] = array(
            'wc_refund_id' => $refund->get_id(),
            'status'       => $status
        );

        $order->update_meta_data( '_montonio_refunds', $existing_refunds );
        $order->save();

        if ( 'SUCCESSFUL' === $status ) {
            $order->add_order_note(
                sprintf(
                    __( '<strong>Refund of %s processed succesfully.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                    wc_price( $amount ),
                    $refund_uuid
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __( '<strong>Refund of %s pending.</strong><br>Refund ID: %s', 'montonio-for-woocommerce' ),
                    wc_price( $amount ),
                    $refund_uuid
                )
            );
        }

        wp_send_json_success( array(
            'message' => sprintf( 'Refund created successfully' )
        ) );

    }

    /**
     * Process order status webhook notifications from Montonio.
     *
     * @param string $token JWT token containing order status data from Montonio
     * @return void
     */
    public function process_order_webhook( $token ) {
        // Define default return url
        $return_url = wc_get_checkout_url();

        if ( $this->is_webhook ) {
            sleep( 10 );
            WC_Montonio_Logger::log( '(Webhook) Order token: ' . $token );
        } else {
            WC_Montonio_Logger::log( 'Order token: ' . $token );
        }

        try {
            $response = WC_Montonio_Helper::decode_jwt_token( $token, $this->sandbox_mode );
            $response = apply_filters( 'wc_montonio_decoded_payment_token', $response );
        } catch ( Throwable $exception ) {
            wc_add_notice( __( 'There was a problem with processing the order.', 'montonio-for-woocommerce' ), 'error' );
            WC_Montonio_Logger::log( 'Unable to decode payment token: ' . $exception->getMessage() );

            if ( $this->is_webhook ) {
                wp_send_json( array(
                    'success' => false,
                    'data'    => array(
                        'message'       => 'Unauthorized',
                        'error_details' => $exception->getMessage()
                    )
                ), 200 );
            } else {
                wp_redirect( $return_url );
                exit;
            }
        }

        $payment_status = sanitize_text_field( $response->paymentStatus );
        $uuid           = sanitize_text_field( $response->uuid );
        $order_id       = sanitize_text_field( $response->merchantReference );
        $grand_total    = sanitize_text_field( $response->grandTotal );
        $currency       = sanitize_text_field( $response->currency );
        $payment_method = sanitize_text_field( $response->paymentMethod );

        switch ( $payment_method ) {
            case 'paymentInitiation':
                $payment_provider_name = sanitize_text_field( $response->paymentProviderName );
                break;
            case 'cardPayments':
                $payment_provider_name = 'Card payment';
                break;
            case 'bnpl':
                $payment_provider_name = 'Pay in parts';
                break;
            case 'hirePurchase':
                $payment_provider_name = 'Financing';
                break;
            case 'blik':
                $payment_provider_name = 'BLIK';
                break;
            default:
                $payment_provider_name = 'N/A';
                break;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            // We have an invalid $order_id, let's try to find order by UUID
            $order_id = WC_Montonio_Helper::get_order_id_by_meta_data( $uuid, '_montonio_uuid' );
            $order    = wc_get_order( $order_id );
        }

        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Unable to locate an order with the specified UUID: ' . $uuid );

            if ( $this->is_webhook ) {
                wp_send_json( array(
                    'success' => false,
                    'data'    => array(
                        'message'       => 'Not Found',
                        'error_details' => 'Unable to locate an order with the specified UUID'
                    )
                ), 200 );
            } else {
                die( 'Unable to locate an order with the specified UUID.' );
            }
        }

        $response_message = 'Order processed successfully';

        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            $return_url       = $this->get_return_url( $order );
            $response_message = 'The order already has the status \'Processing\' or \'Completed\'';
            WC_Montonio_Logger::log( 'The order (#' . $order_id . ') already has the status "Processing" or "Completed"' );
        } else {
            switch ( $payment_status ) {
                case 'PAID':
                    $order->payment_complete();
                    $order->add_order_note( __( 'Payment via Montonio, order ID: ', 'montonio-for-woocommerce' ) . $uuid );
                    $order->add_order_note(
                        __( 'Payment method: ', 'montonio-for-woocommerce' ) . $payment_provider_name . '<br>' .
                        __( 'Paid amount: ', 'montonio-for-woocommerce' ) . $grand_total . $currency
                    );

                    WC()->cart->empty_cart();
                    $return_url = $this->get_return_url( $order );
                    break;
                case 'AUTHORIZED':
                    $order->add_order_note( __( 'Montonio: Payment is authorized but not yet processed by the bank, order ID: ', 'montonio-for-woocommerce' ) . $uuid );
                    $order->update_status( apply_filters( 'wc_montonio_authorized_order_status', 'on-hold' ) );

                    WC()->cart->empty_cart();
                    $return_url = $this->get_return_url( $order );
                    break;
                case 'VOIDED':
                    if ( strpos( $order->get_payment_method(), 'wc_montonio_' ) !== false ) {
                        $order->add_order_note( __( 'Montonio: Payment was rejected by the bank, order ID: ', 'montonio-for-woocommerce' ) . $uuid );
                        $order->update_status( apply_filters( 'wc_montonio_voided_order_status', 'cancelled' ) );
                    }
                    break;
                case 'ABANDONED':
                    if ( strpos( $order->get_payment_method(), 'wc_montonio_' ) !== false && $order->has_status( 'pending' ) ) {
                        $order->add_order_note( __( 'Montonio: Payment session abandoned, order ID: ', 'montonio-for-woocommerce' ) . $uuid );
                        $order->update_status( apply_filters( 'wc_montonio_abandoned_order_status', 'cancelled' ) );
                    }
                    break;
                case 'PARTIALLY_REFUNDED':
                    $order->update_status( apply_filters( 'wc_montonio_partially_refunded_order_status', 'wc-mon-part-refund' ) );
                    break;
                default:
                    wc_add_notice( __( 'Unable to finish the payment. Please try again or choose a different payment method.', 'montonio-for-woocommerce' ), 'notice' );
                    WC_Montonio_Logger::log( 'Not handling this payment status. Payment status: ' . $payment_status );
                    break;
            }
        }

        if ( $this->is_webhook ) {
            wp_send_json_success( array(
                'message' => $response_message
            ) );
        } else {
            wp_redirect( $return_url );
            exit;
        }
    }
}
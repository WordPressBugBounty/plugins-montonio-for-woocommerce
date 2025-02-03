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

        $this->process_callback();
    }

    /**
     * Process response from Montonio.
     *
     * @return void
     */
    public function process_callback() {
        // Define default return url
        $return_url = wc_get_checkout_url();

        // Get Payment Token
        if ( empty( $_REQUEST['order-token'] ) ) {
            $error_message = isset( $_REQUEST['error-message'] ) ? 'Unable to finish the payment. ' . rawurldecode( $_REQUEST['error-message'] ) : __( 'Unable to finish the payment. "order-token" parameter is not set in order response.', 'montonio-for-woocommerce' );

            WC_Montonio_Logger::log( 'Payment error: ' . $error_message );

            wc_add_notice( $error_message, 'error' );

            if ( $this->is_webhook ) {
                wp_send_json( array(
                    'success' => false,
                    'data' => array (
                        'message' => 'Bad Request',
                        'error_details' => 'Order token is missing'
                    )
                ), 200 );
            } else {
                wp_redirect( $return_url );
                exit;
            }
        }

        $token = sanitize_text_field( $_REQUEST['order-token'] );

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
                    'data' => array (
                        'message' => 'Unauthorized',
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

        // Verify that the meta data is correct with what we just searched for
        if ( empty( $order ) ) {
            WC_Montonio_Logger::log( 'Unable to locate an order with the specified UUID: ' . $uuid );

            if ( $this->is_webhook ) {
                wp_send_json( array(
                    'success' => false,
                    'data' => array (
                        'message' => 'Not Found',
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
            default:
                wc_add_notice( __( 'Unable to finish the payment. Please try again or choose a different payment method.', 'montonio-for-woocommerce' ), 'notice' );
                WC_Montonio_Logger::log( 'Unsupported payment status. Payment status: ' . $payment_status );
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
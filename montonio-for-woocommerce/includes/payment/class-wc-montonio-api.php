<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API for Montonio Payments.
 */
class WC_Montonio_API {
    /**
     * Root URL for the Montonio application
     */
    const API_URL = 'https://stargate.montonio.com/api';

    /**
     * Root URL for the Montonio Sandbox application
     */
    const SANDBOX_API_URL = 'https://sandbox-stargate.montonio.com/api';

    /**
     * Get session UUID
     *
     * @return object
     */
    public function get_session_uuid() {
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'method'  => 'POST',
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token() ) )
        );

        return $this->request( '/sessions', $args );
    }

    /**
     * Create payment intent
     *
     * @return object
     */
    public function create_payment_intent( $method ) {
        $data = array(
            'method' => $method
        );

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'method'  => 'POST',
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $data ) ) )
        );

        $response = $this->request( '/payment-intents/draft', $args );

        return json_decode( $response );
    }

    /**
     * Create an order in Montonio
     *
     * @return object
     */
    public function create_order( $order, $payment_data ) {
        $data = $this->build_order_data( $order, $payment_data );

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'method'  => 'POST',
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $data ) ) )
        );

        $response = $this->request( '/orders', $args );

        return json_decode( $response );
    }

    /**
     * Structure order data for JWT token
     *
     * @return array
     */
    protected function build_order_data( $order, $payment_data ) {
        $order->add_order_note( __( 'Checkout via Montonio started.', 'montonio-for-woocommerce' ) );

        $payment_method_id = $payment_data['paymentMethodId'];

        // Parse Order Data to correct data types and add additional data
        $order_data = array(
            'accessKey'                => (string) WC_Montonio_Helper::get_api_keys()['access_key'],
            'merchantReference'        => (string) apply_filters( 'wc_montonio_merchant_reference', $order->get_id(), $order ),
            'merchantReferenceDisplay' => (string) apply_filters( 'wc_montonio_merchant_reference_display', $order->get_order_number(), $order ),
            'notificationUrl'          => (string) apply_filters( 'wc_montonio_notification_url', add_query_arg( 'wc-api', $payment_method_id . '_notification', trailingslashit( get_home_url() ) ), $payment_method_id ),
            'returnUrl'                => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $payment_method_id, trailingslashit( get_home_url() ) ), $payment_method_id ),
            'grandTotal'               => (float) wc_format_decimal( $order->get_total(), 2 ),
            'currency'                 => (string) $order->get_currency(),
            'locale'                   => (string) WC_Montonio_Helper::get_locale(),
            'billingAddress'           => array(
                'firstName'    => (string) $order->get_billing_first_name(),
                'lastName'     => (string) $order->get_billing_last_name(),
                'email'        => (string) $order->get_billing_email(),
                'phoneNumber'  => (string) $order->get_billing_phone(),
                'addressLine1' => (string) $order->get_billing_address_1(),
                'addressLine2' => (string) $order->get_billing_address_2(),
                'locality'     => (string) $order->get_billing_city(),
                'region'       => (string) $order->get_billing_state(),
                'postalCode'   => (string) $order->get_billing_postcode(),
                'country'      => (string) $order->get_billing_country()
            ),
            'shippingAddress'          => array(
                'firstName'    => (string) $order->get_shipping_first_name(),
                'lastName'     => (string) $order->get_shipping_last_name(),
                'email'        => (string) $order->get_billing_email(),
                'phoneNumber'  => (string) $order->get_billing_phone(),
                'addressLine1' => (string) $order->get_shipping_address_1(),
                'addressLine2' => (string) $order->get_shipping_address_2(),
                'locality'     => (string) $order->get_shipping_city(),
                'region'       => (string) $order->get_shipping_state(),
                'postalCode'   => (string) $order->get_shipping_postcode(),
                'country'      => (string) $order->get_shipping_country()
            ),
            'lineItems'                => $this->get_line_items( $order ),
            'payment'                  => array(
                'method'        => (string) $payment_data['payment']['method'],
                'methodDisplay' => (string) $payment_data['payment']['methodDisplay'],
                'amount'        => (float) $order->get_total(),
                'currency'      => (string) $order->get_currency(),
                'methodOptions' => $payment_data['payment']['methodOptions']
            )
        );

        if ( ! empty( $payment_data['paymentIntentUuid'] ) ) {
            $order_data['paymentIntentUuid'] = (string) $payment_data['paymentIntentUuid'];
        }

        if ( ! empty( $payment_data['sessionUuid'] ) ) {
            $order_data['sessionUuid'] = (string) $payment_data['sessionUuid'];
        }

        $order_data = apply_filters( 'wc_montonio_before_order_data_submission', $order_data, $order );
        $order_data = array_filter( $order_data );

        // Add expiration time of the token for JWT validation
        $order_data['exp'] = time() + 600;

        return $order_data;
    }

    /**
     * Get line items from order.
     *
     * @since 9.3.0
     * @param WC_Order $order
     * @return array
     */
    protected function get_line_items( $order ) {
        $items = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            if ( $product ) {
                $items[] = array(
                    'name'       => $product->get_name(),
                    'finalPrice' => (float) wc_format_decimal( $item->get_total() + $item->get_total_tax(), 2 ),
                    'quantity'   => (int) $item->get_quantity()
                );
            }
        }

        $shipping = $order->get_shipping_total() + $order->get_shipping_tax();
        if ( $shipping > 0 ) {
            $items[] = array(
                'name'       => 'SHIPPING',
                'finalPrice' => (float) wc_format_decimal( $shipping, 2 ),
                'quantity'   => 1
            );
        }

        return $items;
    }

    /**
     * Get all enabled payment methods for your store
     *
     * @return string String containing the enabled payment methods
     */
    public function get_payment_methods() {
        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->request( '/stores/payment-methods', $args );
    }

    /**
     * Create a refund request
     *
     * @return string
     */
    public function create_refund_request( $order_uuid, $amount, $idempotency_key ) {
        $data = array(
            'orderUuid'      => $order_uuid,
            'amount'         => $amount,
            'idempotencyKey' => $idempotency_key,
            'exp'            => time() + ( 10 * 60 )
        );

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'method'  => 'POST',
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $data ) ) )
        );

        return $this->request( '/refunds', $args );
    }

    /**
     * General request method.
     *
     * @param string $path path for request.
     * @param array  $args request parameters.
     *
     * @return string
     */
    public function request( $path, $args ) {
        $url = apply_filters( 'wc_montonio_request_url', $this->get_api_url() );
        $url = trailingslashit( $url ) . ltrim( $path, '/' );

        $args          = apply_filters( 'wc_montonio_remote_request_args', $args );
        $response      = wp_remote_request( $url, $args );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( is_wp_error( $response ) ) {
            throw new Exception( json_encode( $response->errors ) );
        }

        if ( ! in_array( $response_code, array( 200, 201 ) ) ) {
            throw new Exception( wp_remote_retrieve_body( $response ) );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Get the API URL based on mode.
     *
     * @return string
     */
    protected function get_api_url() {
        return WC_Montonio_Helper::is_test_mode() ? self::SANDBOX_API_URL : self::API_URL;
    }
}
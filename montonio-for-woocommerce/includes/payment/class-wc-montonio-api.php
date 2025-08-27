<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API for Montonio Payments.
 */
class WC_Montonio_API {

    /**
     * Instance of an WC_Order object
     *
     * @var object
     */
    public $order;

    /**
     * Payment Data for Montonio Payment Token generation
     * @see https://docs.montonio.com/api/stargate/guides/orders#3-generating-the-jwt
     *
     * @var array
     */
    public $payment_data;

    /**
     * API access key
     *
     * @var string
     */
    public $access_key;

    /**
     * API secret key
     *
     * @var string
     */
    public $secret_key;

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $sandbox_mode;

    /**
     * Root URL for the Montonio Sandbox application
     */
    const MONTONIO_SANDBOX_API_URL = 'https://sandbox-stargate.montonio.com/api';

    /**
     * Root URL for the Montonio application
     */
    const MONTONIO_API_URL = 'https://stargate.montonio.com/api';

    public function __construct( $sandbox_mode = 'no' ) {
        $this->sandbox_mode = $sandbox_mode;

        $api_keys = WC_Montonio_Helper::get_api_keys( $this->sandbox_mode );

        $this->access_key = $api_keys['access_key'];
        $this->secret_key = $api_keys['secret_key'];
    }

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
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode ) ) )
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
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode, $data ) ) )
        );

        $response = $this->request( '/payment-intents/draft', $args );

        return json_decode( $response );
    }

    /**
     * Create an order in Montonio
     *
     * @return object
     */
    public function create_order() {
        $data = $this->get_order_data();

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'method'  => 'POST',
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode, $data ) ) )
        );

        $response = $this->request( '/orders', $args );

        return json_decode( $response );
    }

    /**
     * Structure order data for JWT token
     *
     * @return array
     */
    protected function get_order_data() {

        $order = $this->order;
        $order->add_order_note( __( 'Checkout via Montonio started.', 'montonio-for-woocommerce' ) );

        $payment_method_id = $this->payment_data['paymentMethodId'];

        $locale                 = '';
        $wpml_customer_language = apply_filters( 'wpml_current_language', null );
        if ( $wpml_customer_language ) {
            $locale = WC_Montonio_Helper::get_locale( $wpml_customer_language );
        } else {
            $locale = WC_Montonio_Helper::get_locale( get_locale() );
        }

        // Parse Order Data to correct data types and add additional data
        $order_data = array(
            'accessKey'                => (string) $this->access_key,
            'merchantReference'        => (string) apply_filters( 'wc_montonio_merchant_reference', $order->get_id(), $order ),
            'merchantReferenceDisplay' => (string) apply_filters( 'wc_montonio_merchant_reference_display', $order->get_order_number(), $order ),
            'notificationUrl'          => (string) apply_filters( 'wc_montonio_notification_url', add_query_arg( 'wc-api', $payment_method_id . '_notification', trailingslashit( get_home_url() ) ), $payment_method_id ),
            'returnUrl'                => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $payment_method_id, trailingslashit( get_home_url() ) ), $payment_method_id ),
            'grandTotal'               => (float) wc_format_decimal( $order->get_total(), 2 ),
            'currency'                 => (string) $order->get_currency(),
            'locale'                   => (string) $locale,
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
            'lineItems'                => array(),
            'payment'                  => array(
                'method'        => (string) $this->payment_data['payment']['method'],
                'methodDisplay' => (string) $this->payment_data['payment']['methodDisplay'],
                'amount'        => (float) $order->get_total(),
                'currency'      => (string) $order->get_currency(),
                'methodOptions' => $this->payment_data['payment']['methodOptions']
            )
        );

        if ( ! empty( $this->payment_data['paymentIntentUuid'] ) ) {
            $order_data['paymentIntentUuid'] = (string) $this->payment_data['paymentIntentUuid'];
        }

        if ( ! empty( $this->payment_data['sessionUuid'] ) ) {
            $order_data['sessionUuid'] = (string) $this->payment_data['sessionUuid'];
        }

        // Add products & shipping to Payment Data
        if ( ! empty( $order->get_items() ) ) {
            foreach ( $order->get_items() as $item ) {
                $product       = $item->get_product();
                $product_price = $item->get_total() + $item->get_total_tax();

                if ( ! empty( $product ) ) {
                    $order_data['lineItems'][] = array(
                        'name'       => $product->get_name(),
                        'finalPrice' => (float) wc_format_decimal( $product_price, 2 ),
                        'quantity'   => (int) $item->get_quantity()
                    );
                }
            }
        }

        $shipping_price = $order->get_shipping_total() + $order->get_shipping_tax();
        if ( $shipping_price && $shipping_price > 0 ) {
            $order_data['lineItems'][] = array(
                'name'       => 'SHIPPING',
                'finalPrice' => (float) wc_format_decimal( $shipping_price, 2 ),
                'quantity'   => 1
            );
        }

        $order_data = apply_filters( 'wc_montonio_before_order_data_submission', $order_data, $order );

        foreach ( $order_data as $key => $value ) {
            if ( empty( $value ) || $value == '' ) {
                unset( $order_data[$key] );
            }
        }

        // Add expiration time of the token for JWT validation
        $exp               = time() + ( 10 * 60 );
        $order_data['exp'] = $exp;

        return $order_data;
    }

    /**
     * Fetch info about banks and card processors that
     * can be shown to the customer at checkout.
     *
     * @return string String containing the banklist
     */
    public function fetch_payment_methods() {
        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
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
            'body'    => json_encode( array( 'data' => WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode, $data ) ) )
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
        $url = apply_filters( 'wc_montonio_request_url', $this->get_request_url() );
        $url = trailingslashit( $url ) . ltrim( $path, '/' );

        $args          = apply_filters( 'wc_montonio_remote_request_args', $args );
        $response      = wp_remote_request( $url, $args );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( is_wp_error( $response ) ) {
            throw new Exception( json_encode( $response->errors ) );
        }

        if ( $response_code !== 200 && $response_code !== 201 ) {
            throw new Exception( wp_remote_retrieve_body( $response ) );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Get the API URL for the request.
     *
     * @return string
     */
    protected function get_request_url() {
        $url = self::MONTONIO_API_URL;

        if ( $this->sandbox_mode === 'yes' ) {
            $url = self::MONTONIO_SANDBOX_API_URL;
        }

        return $url;
    }
}
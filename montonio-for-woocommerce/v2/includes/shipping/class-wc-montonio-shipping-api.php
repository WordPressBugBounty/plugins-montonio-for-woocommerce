<?php
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Shipping_API for handling Montonio Shipping V2 API requests
 * @since 7.0.0
 */
class WC_Montonio_Shipping_API {
    /**
     * @since 7.0.0
     * @var string The API access key
     */
    public $access_key;

    /**
     * @since 7.0.0
     * @var string The API secret key
     */
    public $secret_key;

    /**
     * @since 7.0.0
     * @var string 'yes' if the API is in sandbox mode, 'no' otherwise
     */
    public $sandbox_mode;

    /**
     * @since 7.0.0
     * @var string Root URL for the Montonio shipping sandbox application
     */
    const MONTONIO_SHIPPING_SANDBOX_API_URL = 'https://shipping.montonio.com/api/v2';

    /**
     * @since 7.0.0
     * @var string Root URL for the Montonio shipping application
     */
    const MONTONIO_SHIPPING_API_URL = 'https://shipping.montonio.com/api/v2';

    /**
     * WC_Montonio_Shipping_API constructor.
     *
     * @since 7.0.0
     * @param string $sandbox_mode 'yes' if the API is in sandbox mode, 'no' otherwise
     */
    public function __construct( $sandbox_mode = 'no' ) {
        $this->sandbox_mode = $sandbox_mode;

        $api_keys = WC_Montonio_Helper::get_api_keys( $this->sandbox_mode );

        $this->access_key = $api_keys['access_key'];
        $this->secret_key = $api_keys['secret_key'];
    }

    /**
     * Get all store shipping methods
     *
     * @since 7.0.0
     * @return string
     */
    public function get_shipping_methods() {
        $path = '/shipping-methods';

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Get all store pickup points
     * 
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @return string The body of the response. Empty string if no body or incorrect parameter given. as a JSON string
     */
    public function get_pickup_points( $carrier, $country ) {
        $path = '/shipping-methods/pickup-points';
        $path = add_query_arg(
            [
                'carrierCode' => $carrier,
                'countryCode' => $country
            ],
            $path
        );

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Get all store courier services
     * 
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @return string The body of the response. Empty string if no body or incorrect parameter given. as a JSON string
     */
    public function get_courier_services( $carrier, $country ) {
        $path = '/shipping-methods/courier-services';
        $path = add_query_arg(
            [
                'carrierCode' => $carrier,
                'countryCode' => $country
            ],
            $path
        );

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Create shipment
     *
     * @since 7.0.0
     * @param array $data - The data to send to the API
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function create_shipment( $data ) {
        $path = '/shipments';

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'POST',
            'body'    => json_encode( $data )
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Update shipment
     *
     * @since 7.0.2
     * @param array $data - The data to send to the API
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function update_shipment( $id, $data ) {
        $path = '/shipments/' . $id;

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'PATCH',
            'body'    => json_encode( $data )
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Get shipment details
     *
     * @since 7.0.0
     * @param string $id - The shipment ID
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function get_shipment( $id ) {
        $path = '/shipments/' . $id;

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Create label file
     *
     * @since 7.0.0
     * @since 7.0.1 Rename to create_label
     * @param array $data - The data to send to the API
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function create_label( $data ) {
        $path = '/label-files';

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'POST',
            'body'    => json_encode( $data )
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Get a created label files
     *
     * @since 7.0.0
     * @since 7.0.1 Renamed from get_label to get_label_file_by_id
     * @param string $id - The label ID
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function get_label_file_by_id( $id ) {
        $path = '/label-files/' . $id;

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Registers a new URL where the Shipping V2 API will send webhooks about various events
     *
     * @since 7.0.0
     * @param array $data - The data to send to the API
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function create_webhook( $data ) {
        $path = '/webhooks';

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'POST',
            'body'    => json_encode( $data )
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Get all registered webhooks for the store
     *
     * @since 7.0.0
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    public function get_webhooks() {
        $path = '/webhooks';

        $options = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_bearer_token()
            ],
            'method'  => 'GET'
        ];

        return $this->api_request( $path, $options );
    }

    /**
     * Decode the Webhook Token
     * This is used to validate the integrity of a webhook sent from Montonio shipping API
     *
     * @param string $token - The Payment Token
     * @param string Your Secret Key for the environment
     * @return object The decoded Payment token
     * @throws Exception If the token is invalid
     */
    public function decode_webhook_token( $token ) {
        MontonioFirebaseV2\JWT\JWT::$leeway = 60 * 5; // 5 minutes
        return MontonioFirebaseV2\JWT\JWT::decode( $token, $this->secret_key, ['HS256'] );
    }

    /**
     * Function for making API calls to the Montonio Shipping API
     * 
     * @since 7.0.0
     * @param string $path The path to the API endpoint
     * @param array $options The options for the request
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    protected function api_request( $path, $options ) {
        $url     = $this->get_api_url() . $path;
        $options = wp_parse_args( $options, ['timeout' => 30] );

        $response      = wp_remote_request( $url, $options );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( is_wp_error( $response ) ) {
            throw new Exception( json_encode( $response->errors ) );
        }

        if ( 200 !== $response_code && 201 !== $response_code ) {
            throw new Exception( wp_remote_retrieve_body( $response ) );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Get the API URL
     * 
     * @since 7.0.0
     * @return string The API URL
     */
    protected function get_api_url() {
        $url = self::MONTONIO_SHIPPING_API_URL;

        if ( 'yes' === $this->sandbox_mode ) {
            $url = self::MONTONIO_SHIPPING_SANDBOX_API_URL;
        }

        return $url;
    }

    /**
     * Get the bearer token which is used for authentication
     * 
     * @since 7.0.0
     * @return string The bearer token
     */
    protected function get_bearer_token() {
        $data = [
            'accessKey' => $this->access_key,
            'iat'       => time(),
            'exp'       => time() + ( 60 * 60 )
        ];

        return MontonioFirebaseV2\JWT\JWT::encode( $data, $this->secret_key );
    }
}

/**
 * Get the Montonio shipping API instance
 * 
 * @since 7.0.0
 * @return WC_Montonio_Shipping_API The Montonio shipping API instance
 */
function get_montonio_shipping_api() {
    $sandbox_mode = 'no';
    return new WC_Montonio_Shipping_API( $sandbox_mode );
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Shipping_API for handling Montonio Shipping API requests
 * @since 7.0.0
 */
class WC_Montonio_Shipping_API {
    /**
     * @since 7.0.0
     * @var string 'yes' if the API is in sandbox mode, 'no' otherwise
     */
    public $sandbox_mode;

    /**
     * @since 7.0.0
     * @var string Root URL for the Montonio shipping sandbox application
     */
    const MONTONIO_SHIPPING_SANDBOX_API_URL = 'https://sandbox-shipping.montonio.com/api';

    /**
     * @since 7.0.0
     * @var string Root URL for the Montonio shipping application
     */
    const MONTONIO_SHIPPING_API_URL = 'https://shipping.montonio.com/api';

    /**
     * WC_Montonio_Shipping_API constructor.
     *
     * @since 7.0.0
     * @param string $sandbox_mode 'yes' if the API is in sandbox mode, 'no' otherwise
     */
    public function __construct( $sandbox_mode = 'no' ) {
        $this->sandbox_mode = $sandbox_mode;
    }

    /**
     * Get all store shipping methods
     *
     * @since 7.0.0
     * @return string
     */
    public function get_shipping_methods() {
        $path = '/v2/shipping-methods';

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'GET'
        );

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
    public function get_pickup_points( $carrier = null, $country = null, $search = null ) {
        $path = '/v3/shipping-methods/pickup-points';
        $query_params = array();

        if ( $carrier !== null ) {
            $query_params['carrierCode'] = $carrier;
        }
        
        if ( $country !== null ) {
            $query_params['countryCode'] = $country;
        }

        if ( $search !== null ) {
            $query_params['search'] = $search;
        }
        
        if ( ! empty( $query_params ) ) {
            $path = add_query_arg( $query_params, $path );
        }

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $options );
    }

    /**
     * Get courier services
     * 
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @return string The body of the response. Empty string if no body or incorrect parameter given. as a JSON string
     */
    public function get_courier_services( $carrier = null, $country = null ) {
        $path = '/v3/shipping-methods/courier-services';
        $query_params = array();
    
        if ( $carrier !== null ) {
            $query_params['carrierCode'] = $carrier;
        }
        
        if ( $country !== null ) {
            $query_params['countryCode'] = $country;
        }
        
        if ( ! empty( $query_params ) ) {
            $path = add_query_arg( $query_params, $path );
        }

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'GET'
        );

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
        $path = '/v2/shipments';

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'POST',
            'body'    => json_encode( $data )
        );

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
        $path = '/v2/shipments/' . $id;

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'PATCH',
            'body'    => json_encode( $data )
        );

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
        $path = '/v2/shipments/' . $id;

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'GET'
        );

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
        $path = '/v2/label-files';

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'POST',
            'body'    => json_encode( $data )
        );

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
        $path = '/v2/label-files/' . $id;

        $options = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token( $this->sandbox_mode )
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $options );
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
        $options = wp_parse_args( $options, array( 'timeout' => 30 ) );

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
}

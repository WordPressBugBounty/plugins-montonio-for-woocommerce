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
     * @var string Root URL for the Montonio shipping application
     */
    const SHIPPING_API_URL = 'https://shipping.montonio.com/api';

    /**
     * @since 7.0.0
     * @var string Root URL for the Montonio shipping sandbox application
     */
    const SHIPPING_SANDBOX_API_URL = 'https://sandbox-shipping.montonio.com/api';

    /**
     * Get all store shipping methods
     *
     * @since 7.0.0
     * @return string
     */
    public function get_shipping_methods() {
        $path = '/v2/shipping-methods';

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $args );
    }

    /**
     * Get all store pickup points
     *
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $type Pickup point type (parcelMachine, postOffice, parcelShop)
     * @param string $search Search term
     * @return string The body of the response. Empty string if no body or incorrect parameter given. as a JSON string
     */
    public function get_pickup_points( $carrier = null, $country = null, $type = null, $search = null ) {
        $path         = '/v3/shipping-methods/pickup-points';
        $query_params = array();

        if ( ! empty( $carrier ) ) {
            $query_params['carrierCode'] = $carrier;
        }

        if ( ! empty( $country ) ) {
            $query_params['countryCode'] = $country;
        }

        if ( ! empty( $type ) && in_array( $type, array( 'parcelMachine', 'postOffice', 'parcelShop' ), true ) ) {
            $query_params['type'] = $type;
        }

        if ( ! empty( $search ) ) {
            $query_params['search'] = $search;
        }

        if ( ! empty( $query_params ) ) {
            $path = add_query_arg( $query_params, $path );
        }

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $args );
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
        $path         = '/v3/shipping-methods/courier-services';
        $query_params = array();

        if ( ! empty( $carrier ) ) {
            $query_params['carrierCode'] = $carrier;
        }

        if ( ! empty( $country ) ) {
            $query_params['countryCode'] = $country;
        }

        if ( ! empty( $query_params ) ) {
            $path = add_query_arg( $query_params, $path );
        }

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $args );
    }

    /**
     * Get shipping rates for given destination and parcels
     *
     * @since 9.2.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $type shipping method type ('courier' or 'pickupPoint')
     * @param array $parcels Array of parcels with nested item dimensions (length, width, height)
     * @param string $carrier_code carrier code (e.g., 'novaPost')
     * @return string The body of the response as a JSON string
     */
    public function get_shipping_rates( $country, $type, $parcels, $carrier = null ) {
        $path = '/v2/shipping-methods/rates';
        $query_params = array();

        if ( ! empty( $carrier ) ) {
            $query_params['carrierCode'] = $carrier;
        }

        if ( ! empty( $type ) ) {
            $query_params['shippingMethodType'] = $type;
        }

        if ( ! empty( $query_params ) ) {
            $path = add_query_arg( $query_params, $path );
        }

        $data = array(
            'destination' => $country,
            'parcels'    => $parcels
        );

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'POST',
            'body'    => json_encode( $data )
        );

        return $this->api_request( $path, $args );
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

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'POST',
            'body'    => json_encode( $data )
        );

        return $this->api_request( $path, $args );
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

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'PATCH',
            'body'    => json_encode( $data )
        );

        return $this->api_request( $path, $args );
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

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $args );
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

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'POST',
            'body'    => json_encode( $data )
        );

        return $this->api_request( $path, $args );
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

        $args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . WC_Montonio_Helper::create_jwt_token()
            ),
            'method'  => 'GET'
        );

        return $this->api_request( $path, $args );
    }

    /**
     * Function for making API calls to the Montonio Shipping API
     *
     * @since 7.0.0
     * @param string $path The path to the API endpoint
     * @param array $args The options for the request
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     */
    protected function api_request( $path, $args ) {
        $url = apply_filters( 'wc_montonio_shipping_request_url', $this->get_api_url() );
        $url = trailingslashit( $url ) . ltrim( $path, '/' );

        $args          = wp_parse_args( $args, array( 'timeout' => 30 ) );
        $response      = wp_remote_request( $url, $args );
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
        return WC_Montonio_Helper::is_test_mode() ? self::SHIPPING_SANDBOX_API_URL : self::SHIPPING_API_URL;
    }
}

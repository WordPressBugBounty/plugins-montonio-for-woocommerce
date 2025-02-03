<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class Montonio_OTA_Updates
 * 
 * Handles Over-The-Air (OTA) updates from Montonio for configuration and other data.
 * Montonio Admins can send authenticated requests using the merchant's API keys to perform various actions,
 * including updating configuration settings, triggering syncs, and retrieving status information.
 * 
 * @package Montonio
 * @since 7.1.2
 */
class Montonio_OTA_Updates {
    /**
     * Route namespace
     *
     * @since 7.1.2
     * @var string
     */
    protected $namespace = 'montonio/ota';

    /**
     * Montonio_OTA_Updates constructor.
     *
     * @since 7.1.2
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register the OTA update endpoints.
     *
     * @since 7.1.2
     * @return void
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/sync', array(
            'methods'             => 'POST',
            'permission_callback' => array( $this, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( $this, 'trigger_ota_sync' )
        ) );

        register_rest_route( $this->namespace, '/config', array(
            'methods'             => 'GET',
            'permission_callback' => array( $this, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( $this, 'get_config' )
        ) );

        register_rest_route( $this->namespace, '/config', array(
            'methods'             => 'PATCH',
            'permission_callback' => array( $this, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( $this, 'update_config' ),
            'args'                => $this->get_config_update_args()
        ) );
    }

    /**
     * Gets the config for the Montonio plugin
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The sanitized Montonio plugin config
     */
    public function get_config( $request ) {
        $payment_method_ids = $this->get_payment_method_ids();
        $options_names = array_map( array( $this, 'get_payment_method_settings_option_name' ), $payment_method_ids );
        $data = array();
        
        foreach ( $options_names as $option_name ) {
            $settings = get_option( $option_name, false );

            if ( is_array( $settings ) ) {
                $settings = $this->filter_sensitive_data( $option_name, $settings );
            }

            $data[$option_name] = $settings;
        }

        $data['montonio_shipping_enabled'] = get_option( 'montonio_shipping_enabled', false );
        $data['montonio_shipping_enable_v2'] = get_option( 'montonio_shipping_enable_v2', false );
        $data['montonio_shipping_dropdown_type'] = get_option( 'montonio_shipping_dropdown_type', false );

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Get the arguments for the config update endpoint.
     * Only these properties will be included in the callback, to prevent any unexpected data from being passed.
     * So if you want to add a new property to the config, you need to add it here. 
     * 
     * @example when you pass in 'title' to 'woocommerce_montonio_payments_settings', 
     * it will normally be ignored, however if you add it here, you can update the title of the payment method.
     *
     * @since 7.1.2
     * @return array The schema for the config update endpoint. 
     */
    public function get_config_update_args() {
        return array(
            'woocommerce_montonio_payments_settings'      => array(
                'description' => __( 'Payment settings for Montonio Bank Payments V1', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => array(
                    'enabled' => array(
                        'description'       => __( 'Enable or disable Montonio Bank Payments V1', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    )
                )
            ),
            'woocommerce_montonio_card_payments_settings' => array(
                'description' => __( 'Payment settings for Montonio Card Payments V1', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => array(
                    'enabled' => array(
                        'description'       => __( 'Enable or disable Montonio Card Payments V1', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    )
                )
            ),
            'montonio_shipping_enable_v2'                 => array(
                'description'       => __( 'Enable or disable Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'enum'              => array( 'yes', 'no' ),
                'sanitize_callback' => 'sanitize_text_field',
                'required'          => false
            ),
            'montonio_shipping_dropdown_type'             => array(
                'description'       => __( 'Dropdown type for Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'enum'              => array( 'select2', 'choices' ),
                'sanitize_callback' => 'sanitize_text_field',
                'required'          => false
            ),
            'woocommerce_wc_montonio_payments_settings'   => array(
                'description' => __( 'Payment settings for Montonio Bank Payments', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => array(
                    'enabled'      => array(
                        'description'       => __( 'Enable or disable Montonio Bank Payments', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    ),
                    'sandbox_mode' => array(
                        'description'       => __( 'Enable or disable sandbox mode', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    ),
                    'title'  => array(
                        'description'       => __( 'Title of the payment method', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => false
                    )
                )
            ),
            'woocommerce_wc_montonio_card_settings'       => array(
                'description' => __( 'Payment settings for Montonio Card Payments', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => array(
                    'enabled'      => array(
                        'description'       => __( 'Enable or disable Montonio Card Payments', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    ),
                    'sandbox_mode' => array(
                        'description'       => __( 'Enable or disable sandbox mode', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field',
                        'required'          => true
                    )
                )
            )
        );
    }

    /**
     * Update the config for the Montonio plugin.
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public function update_config( $request ) {
        $allowed_params = $this->sanitize_request_params( $this->get_config_update_args(), $request );
        $payment_method_ids = $this->get_payment_method_ids();

        // option_name is the key of the allowed_params array, which corresponds to the option name in the database.
        // $new_values is whatever was passed in the request. It will either be an array of new values or a single value.
        foreach ( $allowed_params as $option_name => $new_values ) {
            $existing_settings = get_option( $option_name, false );

            // Check if the option name corresponds to a Montonio payment gateway. 
            // like 'woocommerce_montonio_payments_settings' will be 'montonio_payments'.
            $method_id = str_replace( 'woocommerce_', '', str_replace( '_settings', '', $option_name ) );

            // Handle if it's one of Montonio's payment gateways.
            if ( in_array( $method_id, $payment_method_ids ) ) {
                // Get the default form fields for the payment method.
                $payment_gateways = WC()->payment_gateways->payment_gateways();

                if ( isset( $payment_gateways[$method_id] ) ) {
                    $form_fields = $payment_gateways[$method_id]->form_fields;

                    // Map form fields to key => default value pairs.
                    $default_settings = array_map( function ( $field ) {
                        return isset( $field['default'] ) ? $field['default'] : '';
                    }, $form_fields );

                    // Merge the default settings with the new values.
                    $updated_settings = array_merge( $default_settings, is_array( $existing_settings ) ? $existing_settings : [], $new_values );

                    // Update the option with the new merged settings.
                    update_option( $option_name, $updated_settings );
                }
            } else {
                // If not a payment method, handle normally.
                if ( is_array( $existing_settings ) && is_array( $new_values ) ) {
                    $updated_settings = array_merge( $existing_settings, $new_values );
                    update_option( $option_name, $updated_settings );
                } else {
                    update_option( $option_name, $new_values );
                }
            }
        }

        return new WP_REST_Response( $allowed_params, 200 );
    }


    /**
     * Trigger an over-the-air sync
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response
     */
    public function trigger_ota_sync( $request ) {
        try {
            WC_Montonio_Logger::log( 'OTA Sync started by Montonio at ' . date( 'Y-m-d H:i:s' ) );

            /**
             * @hooked WC_Montonio_Payments::sync_banks_ota - 10
             * @hooked WC_Montonio_Shipping::sync_shipping_methods_ota - 20
             */
            $result = apply_filters( 'montonio_ota_sync', array(
                'started_at'   => date( 'Y-m-d H:i:s' ),
                'sync_results' => array(),
            ) );

            do_action( 'montonio_send_telemetry_data' );

            $result['finished_at'] = date( 'Y-m-d H:i:s' );

            WC_Montonio_Logger::log( 'OTA Sync finished at ' . $result['finished_at'] );

            return new WP_REST_Response( $result, 200 );
        } catch ( Exception $e ) {
            return new WP_Error( 'montonio_ota_sync_failed', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    /**
     * Check if the current user has the required permissions to access the endpoint
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object
     * @return bool
     */
    public function merchant_apikey_auth_permissions_check( $request ) {
        try {
            $headers = getallheaders();
            // Check for the Authorization header
            if ( empty( $headers['Authorization'] ) ) {
                return new WP_Error( 'unauthorized', 'Missing authorization header', array( 'status' => 401 ) );
            }

            $auth = sanitize_text_field( $headers['Authorization'] );
            $token = str_replace( 'Bearer ', '', $auth );

            if ( empty( $token ) ) {
                return new WP_Error( 'unauthorized', 'Token not parsed successfully', array( 'status' => 401 ) );
            }

            $target_audience = sanitize_text_field( $request->get_route() );
            $decoded = WC_Montonio_Helper::decode_jwt_token( $token );

            if ( empty( $decoded->aud ) || $decoded->aud !== $target_audience ) {
                return new WP_Error( 'unauthorized', 'Invalid token', array( 'status' => 401 ) );
            }

            return true;
        } catch ( Throwable $e ) {
            return new WP_Error( 'unauthorized', $e->getMessage(), array( 'status' => 401 ) );
        }
    }

    /**
     * Remove all parameters that are not allowed in the callback function, including nested properties.
     * This is to prevent any unexpected data from being passed to the callback function.
     *
     * @since 7.1.2
     * @param array $prepared_args The allowed parameters in the data.
     * @param WP_REST_Request $request The request object.
     *
     * @return array The sanitized parameters.
     */
    public function sanitize_request_params( $prepared_args, $request ) {
        // Get the raw request params (body, query, etc.)
        $body_params = $request->get_params();

        // Recursively sanitize the parameters
        return $this->sanitize_recursive( $prepared_args, $body_params );
    }

    /**
     * Recursive function to sanitize nested parameters.
     *
     * @param array $allowed_params The allowed parameters, which may include nested properties.
     * @param array $actual_params The actual parameters from the request to sanitize.
     * @return array The sanitized parameters.
     */
    private function sanitize_recursive( $allowed_params, $actual_params ) {
        $sanitized_params = array();

        foreach ( $allowed_params as $key => $value ) {
            if ( isset( $actual_params[$key] ) ) {
                // If the value is an array and there are nested properties, recurse into it.
                if ( is_array( $value ) && isset( $value['properties'] ) && is_array( $actual_params[$key] ) ) {
                    // Recursively sanitize the nested properties
                    $sanitized_params[$key] = $this->sanitize_recursive( $value['properties'], $actual_params[$key] );
                } else {
                    // Otherwise, it's a simple key, add it directly
                    $sanitized_params[$key] = $actual_params[$key];
                }
            }
        }

        return $sanitized_params;
    }

    /**
     * Filters out sensitive data from the settings.
     *
     * @since 7.1.2
     * @param string $method_id The method ID (option name).
     * @param array  $settings The settings array to filter.
     * @return array The filtered settings.
     */
    private function filter_sensitive_data( $method_id, $settings ) {
        $sensitive_keys = array(
            'woocommerce_montonio_card_payments_settings' => array( 'montonioCardPaymentsAccessKey', 'montonioCardPaymentsSecretKey' ),
            'woocommerce_montonio_payments_settings'      => array( 'montonioPaymentsAccessKey', 'montonioPaymentsSecretKey' )
        );

        if ( isset( $sensitive_keys[$method_id] ) ) {
            foreach ( $sensitive_keys[$method_id] as $sensitive_key ) {
                unset( $settings[$sensitive_key] );
            }
        }

        return $settings;
    }

    /**
     * Get the payment method IDs that are supported by Montonio
     *
     * @since 7.1.2
     * @return array
     */
    private function get_payment_method_ids() {
        return array(
            'wc_montonio_payments',
            'wc_montonio_card',
            'montonio_payments',
            'montonio_card_payments',
        );
    }

    /**
     * Get the option name for the payment method settings
     *
     * @since 7.1.2
     * @param string $method_id The id property of the payment gateway
     * 
     * @example 'montonio_payments' will be converted to 'woocommerce_montonio_payments_settings'
     * 
     * @return string The option name
     */
    private function get_payment_method_settings_option_name( $method_id ) {
        return 'woocommerce_' . $method_id . '_settings';
    }
}
new Montonio_OTA_Updates();
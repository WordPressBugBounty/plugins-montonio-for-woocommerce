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
     */
    const NAMESPACE_PREFIX = 'montonio/ota';

    /**
     * Initialize the class by registering hooks.
     *
     * @since 7.1.2
     * @return void
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register the OTA update endpoints.
     *
     * @since 7.1.2
     * @return void
     */
    public static function register_routes() {
        register_rest_route( self::NAMESPACE_PREFIX, '/sync', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( __CLASS__, 'trigger_ota_sync' )
        ) );

        register_rest_route( self::NAMESPACE_PREFIX, '/config', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( __CLASS__, 'get_config' )
        ) );

        register_rest_route( self::NAMESPACE_PREFIX, '/config', array(
            'methods'             => 'PATCH',
            'permission_callback' => array( __CLASS__, 'merchant_apikey_auth_permissions_check' ),
            'callback'            => array( __CLASS__, 'update_config' ),
            'args'                => self::get_config_update_args()
        ) );
    }

    /**
     * Gets the config for the Montonio plugin
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response The sanitized Montonio plugin config
     */
    public static function get_config( $request ) {
        $payment_method_ids = self::get_payment_method_ids();
        $options_names      = array_map( array( __CLASS__, 'get_payment_method_settings_option_name' ), $payment_method_ids );
        $data               = array();

        foreach ( $options_names as $option_name ) {
            $settings = get_option( $option_name, false );

            if ( is_array( $settings ) ) {
                $settings = self::filter_sensitive_data( $option_name, $settings );
            }

            $data[$option_name] = $settings;
        }

        $api_settings = get_option( 'woocommerce_wc_montonio_api_settings', false );

        if ( is_array( $api_settings ) ) {
            $api_settings = self::filter_sensitive_data( 'woocommerce_wc_montonio_api_settings', $api_settings );
        }

        $data['woocommerce_wc_montonio_api_settings'] = $api_settings;

        $data['montonio_shipping_enabled']                      = get_option( 'montonio_shipping_enabled', false );
        $data['montonio_shipping_dropdown_type']                = get_option( 'montonio_shipping_dropdown_type', false );
        $data['montonio_shipping_create_shipment_on_status']    = get_option( 'montonio_shipping_create_shipment_on_status', false );
        $data['montonio_shipping_orderStatusWhenLabelPrinted']  = get_option( 'montonio_shipping_orderStatusWhenLabelPrinted', false );
        $data['montonio_shipping_order_status_when_delivered']  = get_option( 'montonio_shipping_order_status_when_delivered', false );

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
    public static function get_config_update_args() {
        return array(
            'montonio_shipping_create_shipment_on_status'    => array(
                'description'       => __( 'Order status that triggers automatic shipment creation', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'montonio_shipping_orderStatusWhenLabelPrinted'  => array(
                'description'       => __( 'Order status when shipping label is printed', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'montonio_shipping_order_status_when_delivered'  => array(
                'description'       => __( 'Order status when shipment is delivered', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'montonio_shipping_enabled'                 => array(
                'description'       => __( 'Enable or disable Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'enum'              => array( 'yes', 'no' ),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'montonio_shipping_dropdown_type'           => array(
                'description'       => __( 'Dropdown type for Montonio Shipping', 'montonio-for-woocommerce' ),
                'type'              => 'string',
                'enum'              => array( 'default', 'select2', 'choices' ),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'woocommerce_wc_montonio_api_settings'      => array(
                'description' => __( 'API settings for Montonio', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'test_mode'               => array(
                        'description'       => __( 'Enable or disable test mode', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'merchant_reference_type' => array(
                        'description'       => __( 'Merchant reference type for orders', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'order_id', 'order_number', 'add_prefix' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'order_prefix'            => array(
                        'description'       => __( 'Custom prefix for order IDs', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            'woocommerce_wc_montonio_payments_settings' => array(
                'description' => __( 'Payment settings for Montonio Bank Payments', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'enabled' => array(
                        'description'       => __( 'Enable or disable Montonio Bank Payments', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'title'   => array(
                        'description'       => __( 'Title of the payment method', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            'woocommerce_wc_montonio_card_settings'     => array(
                'description' => __( 'Payment settings for Montonio Card Payments', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'enabled'         => array(
                        'description'       => __( 'Enable or disable Montonio Card Payments', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'inline_checkout' => array(
                        'description'       => __( 'Enable or disable embedded card fields in checkout', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            'woocommerce_wc_montonio_blik_settings'    => array(
                'description' => __( 'Payment settings for Montonio BLIK', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'enabled'          => array(
                        'description'       => __( 'Enable or disable Montonio BLIK', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    ),
                    'blik_in_checkout' => array(
                        'description'       => __( 'Enable or disable embedded BLIK fields in checkout', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            'woocommerce_wc_montonio_bnpl_settings'    => array(
                'description' => __( 'Payment settings for Montonio Buy Now Pay Later', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'enabled' => array(
                        'description'       => __( 'Enable or disable Montonio Buy Now Pay Later', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
                    )
                )
            ),
            'woocommerce_wc_montonio_hire_purchase_settings' => array(
                'description' => __( 'Payment settings for Montonio Hire Purchase', 'montonio-for-woocommerce' ),
                'type'        => 'object',
                'properties'  => array(
                    'enabled' => array(
                        'description'       => __( 'Enable or disable Montonio Hire Purchase', 'montonio-for-woocommerce' ),
                        'type'              => 'string',
                        'enum'              => array( 'yes', 'no' ),
                        'sanitize_callback' => 'sanitize_text_field'
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
    public static function update_config( $request ) {
        $allowed_params     = self::sanitize_request_params( self::get_config_update_args(), $request );
        $payment_method_ids = self::get_payment_method_ids();

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
                    $updated_settings = array_merge( $default_settings, is_array( $existing_settings ) ? $existing_settings : array(), $new_values );

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
    public static function trigger_ota_sync( $request ) {
        try {
            WC_Montonio_Logger::log( 'OTA Sync started by Montonio at ' . gmdate( 'Y-m-d H:i:s' ) );

            /**
             * @hooked WC_Montonio_Data_Sync::sync_payment_methods_ota - 10
             * @hooked WC_Montonio_Shipping_Sync::handle_ota_sync - 20
             */
            $result = apply_filters( 'montonio_ota_sync', array(
                'started_at'   => gmdate( 'Y-m-d H:i:s' ),
                'sync_results' => array()
            ) );

            do_action( 'montonio_send_telemetry_data' );

            $result['finished_at'] = gmdate( 'Y-m-d H:i:s' );

            WC_Montonio_Logger::log( 'OTA Sync finished at ' . $result['finished_at'] );

            return new WP_REST_Response( $result, 200 );
        } catch ( Exception $e ) {
            return new WP_Error( 'wc_montonio_ota_sync_error', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    /**
     * Check if the current user has the required permissions to access the endpoint
     *
     * @since 7.1.2
     * @param WP_REST_Request $request The request object
     * @return bool
     */
    public static function merchant_apikey_auth_permissions_check( $request ) {
        try {
            $headers = getallheaders();
            // Check for the Authorization header
            if ( empty( $headers['Authorization'] ) ) {
                return new WP_Error( 'unauthorized', 'Missing authorization header', array( 'status' => 401 ) );
            }

            $auth  = sanitize_text_field( $headers['Authorization'] );
            $token = str_replace( 'Bearer ', '', $auth );

            if ( empty( $token ) ) {
                return new WP_Error( 'unauthorized', 'Token not parsed successfully', array( 'status' => 401 ) );
            }

            $target_audience = sanitize_text_field( $request->get_route() );
            $decoded         = WC_Montonio_Helper::decode_jwt_token( $token );

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
    public static function sanitize_request_params( $prepared_args, $request ) {
        // Get the raw request params (body, query, etc.)
        $body_params = $request->get_params();

        // Recursively sanitize the parameters
        return self::sanitize_recursive( $prepared_args, $body_params );
    }

    /**
     * Recursive function to sanitize nested parameters.
     *
     * @param array $allowed_params The allowed parameters, which may include nested properties.
     * @param array $actual_params The actual parameters from the request to sanitize.
     * @return array The sanitized parameters.
     */
    private static function sanitize_recursive( $allowed_params, $actual_params ) {
        $sanitized_params = array();

        foreach ( $allowed_params as $key => $value ) {
            if ( isset( $actual_params[$key] ) ) {
                // If the value is an array and there are nested properties, recurse into it.
                if ( is_array( $value ) && isset( $value['properties'] ) && is_array( $actual_params[$key] ) ) {
                    // Recursively sanitize the nested properties
                    $sanitized_params[$key] = self::sanitize_recursive( $value['properties'], $actual_params[$key] );
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
    private static function filter_sensitive_data( $method_id, $settings ) {
        $sensitive_keys = array(
            'woocommerce_wc_montonio_api_settings' => array( 'access_key', 'secret_key', 'sandbox_access_key', 'sandbox_secret_key' )
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
    private static function get_payment_method_ids() {
        return array(
            'wc_montonio_payments',
            'wc_montonio_card',
            'wc_montonio_blik',
            'wc_montonio_bnpl',
            'wc_montonio_hire_purchase'
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
    private static function get_payment_method_settings_option_name( $method_id ) {
        return 'woocommerce_' . $method_id . '_settings';
    }
}

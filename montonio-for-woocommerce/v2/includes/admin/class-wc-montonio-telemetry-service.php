<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @since 7.0.0 - Polyfill for wp_timezone_string that was introduced in WordPress 5.3
 *
 * Uses the `timezone_string` option to get a proper timezone name if available,
 * otherwise falls back to a manual UTC ± offset.
 *
 * Example return values:
 *
 *  - 'Europe/Rome'
 *  - 'America/North_Dakota/New_Salem'
 *  - 'UTC'
 *  - '-06:30'
 *  - '+00:00'
 *  - '+08:45'
 *
 * @return string PHP timezone name or a ±HH:MM offset.
 */
if ( ! function_exists( 'wp_timezone_string' ) ) {
    function wp_timezone_string() {
        // This is a simplified alternative to wp_timezone_string
        $timezone = get_option( 'timezone_string' );
        if ( ! empty( $timezone ) ) {
            return $timezone;
        }

        $offset = get_option( 'gmt_offset' );
        if ( 0 == $offset ) {
            return 'UTC';
        }

        $hours   = (int) $offset;
        $minutes = ( $offset - $hours );

        $sign     = ( $offset < 0 ) ? '-' : '+';
        $abs_hour = abs( $hours );
        $abs_mins = abs( $minutes * 60 );
        return sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );
    }
}

/**
 * Class WC_Montonio_Telemetry_Service
 *
 * @since 7.0.0
 */
class WC_Montonio_Telemetry_Service {
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
     * @var string Root URL for the Montonio telemetry application
     */
    const MONTONIO_TELEMETRY_API_URL = 'https://plugin-telemetry.montonio.com/api';

    /**
     * @since 7.0.0
     * @var array Common setting keys for all services
     */
    private $common_setting_keys = [
        'enabled',
        'sandbox_mode',
        'title',
        'description'
    ];

    /**
     * @since 7.0.0
     * @var array Service-specific setting keys
     */
    private $service_specific_setting_keys = [
        'wc_montonio_payments'      => [
            'handle_style', 'default_country', 'hide_country_select',
            'preselect_country', 'custom_payment_description', 'payment_description',
            'script_mode'
        ],
        'wc_montonio_card'          => [
            'inline_checkout'
        ],
        'wc_montonio_bnpl'          => [
            'min_amount'
        ],
        'wc_montonio_hire_purchase' => [],
        'wc_montonio_blik'          => [
            'blik_in_checkout'
        ]
    ];

    /**
     * WC_Montonio_Telemetry_Service constructor.
     *
     * @since 7.0.0
     */
    public function __construct() {
        $api_keys = WC_Montonio_Helper::get_api_keys();

        $this->access_key = $api_keys['access_key'];
        $this->secret_key = $api_keys['secret_key'];

        add_action( 'wp_loaded', [$this, 'trigger_telemetry_sync'] );
        add_action( 'woocommerce_settings_saved', [$this, 'send_telemetry_data'] );
        add_action( 'montonio_send_telemetry_data', [$this, 'send_telemetry_data'] );

        register_deactivation_hook( WC_MONTONIO_PLUGIN_FILE, [$this, 'wc_montonio_deactivated'] );
    }

    /**
     * Send telemetry data upon plugin deactivation.
     *
     * @since 7.0.0
     */
    public function wc_montonio_deactivated() {
        $deactivated_at = gmdate( 'Y-m-d H:i:s' );

        do_action( 'montonio_send_telemetry_data', $deactivated_at );
        update_option( 'montonio_telemetry_sync_timestamp', null );
    }

    /**
     * Send telemetry data if needed.
     *
     * @since 7.0.0
     */
    public function trigger_telemetry_sync() {
        $last_sync = get_option( 'montonio_telemetry_sync_timestamp' );

        if ( empty( $last_sync ) || $last_sync < time() - 86400 ) {
            do_action( 'montonio_send_telemetry_data' );
        }
    }

    /**
     * Send telemetry data to the Montonio API.
     *
     * @since 7.0.0
     */
    public function send_telemetry_data( $deactivated_at = null ) {
        try {
            $path = '/store-telemetry-data';
            $data = $this->collect_telemetry_data( $deactivated_at );

            $options = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->get_bearer_token()
                ],
                'method'  => 'PATCH',
                'body'    => json_encode( $data ),
                'timeout' => 5
            ];

            $this->api_request( $path, $options );

        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Telemetry sync failed. Response: ' . $e->getMessage() );
        }

        update_option( 'montonio_telemetry_sync_timestamp', time() );
    }

    /**
     * Collect telemetry data from the store.
     *
     * @since 7.0.0
     * @return array The collected telemetry data
     */
    public function collect_telemetry_data( $deactivated_at ) {
        $data = [
            'storeUrl'  => get_site_url(),
            'platform'  => 'wordpress',
            'storeInfo' => [
                'phpVersion'            => phpversion(),
                'wordpressVersion'      => get_bloginfo( 'version' ),
                'woocommerceVersion'    => WC()->version,
                'montonioPluginVersion' => WC_MONTONIO_PLUGIN_VERSION,
                'deactivatedAt'         => $deactivated_at,
                'wordpressInfo'         => [
                    'restApiUrl'            => rest_url(),
                    'otaUpdatesUrl'         => rest_url( 'montonio/ota' ),
                    'defaultLanguage'       => get_bloginfo( 'language' ),
                    'supportedLanguages'    => get_available_languages(),
                    'currency'              => get_option( 'woocommerce_currency' ),
                    'timezone'              => wp_timezone_string(),
                    'siteName'              => get_bloginfo( 'name' ),
                    'siteDescription'       => get_bloginfo( 'description' ),
                    'hasBlocksInCheckout'   => WC_Montonio_Helper::is_checkout_block(),
                    'merchantReferenceType' => $this->get_api_setting( 'merchant_reference_type' ),
                    'services'              => [
                        'paymentInitiationV2' => $this->get_payment_service_data( 'wc_montonio_payments' ),
                        'cardPaymentsV2'      => $this->get_payment_service_data( 'wc_montonio_card' ),
                        'bnpl'                => $this->get_payment_service_data( 'wc_montonio_bnpl' ),
                        'hirePurchase'        => $this->get_payment_service_data( 'wc_montonio_hire_purchase' ),
                        'blik'                => $this->get_payment_service_data( 'wc_montonio_blik' ),
                        'paymentInitiationV1' => $this->get_payment_service_data_v1( 'montonio_payments', 'montonioPaymentsEnvironment' ),
                        'cardPaymentsV1'      => $this->get_payment_service_data_v1( 'montonio_card_payments', 'montonioCardPaymentsEnvironment' ),
                        'shipping'            => $this->get_shipping_service_data()
                    ]
                ]
            ]
        ];

        return $data;
    }

    /**
     * Get API Settings.
     *
     * @since 7.0.2
     */
    public function get_api_setting( $key ) {
        $settings = $this->get_settings( 'wc_montonio_api' );

        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return;
        }

        return isset( $settings[$key] ) ? sanitize_text_field( $settings[$key] ) : null;
    }

    /**
     * Get payment service data.
     *
     * @since 7.0.0
     * @param string $service_key The service key to get settings for
     * @return array The service data
     */
    public function get_payment_service_data( $service_key ) {
        $settings = $this->get_settings( $service_key );

        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return [];
        }

        $data = $this->get_status_and_environment( $settings );

        if ( $service_key === 'wc_montonio_blik' ) {
            $data['showFieldsInCheckout'] = ( isset( $settings['blik_in_checkout'] ) && $settings['blik_in_checkout'] === 'yes' ) ? true : false;
        }

        if ( $service_key === 'wc_montonio_card' ) {
            $data['showFieldsInCheckout'] = ( isset( $settings['inline_checkout'] ) && $settings['inline_checkout'] === 'yes' ) ? true : false;
        }

        $data['settings'] = [];

        $setting_keys = array_merge(
            $this->common_setting_keys,
            $this->service_specific_setting_keys[$service_key] ?? []
        );

        foreach ( $setting_keys as $key ) {
            $data['settings'][$key] = isset( $settings[$key] ) ? sanitize_text_field( $settings[$key] ) : null;
        }

        return $data;
    }

    /**
     * Get payment service data for version 1.
     *
     * @since 7.0.0
     * @param string $method_id The method ID to get settings for
     * @param string $environment_key The environment key in settings
     * @return array The service data
     */
    public function get_payment_service_data_v1( $method_id, $environment_key ) {
        $settings = $this->get_settings( $method_id );

        if ( empty( $settings ) ) {
            return [];
        }

        return [
            'status'      => ( $settings['enabled'] === 'yes' ) ? 'enabled' : 'disabled',
            'environment' => $settings[$environment_key]
        ];
    }

    /**
     * Get shipping service data.
     *
     * @since 7.0.0
     * @return array The shipping service data
     */
    public function get_shipping_service_data() {
        $data = [
            'status'   => ( get_option( 'montonio_shipping_enabled' ) === 'yes' ) ? 'enabled' : 'disabled',
            'version'  => ( get_option( 'montonio_shipping_enable_v2' ) === 'yes' ) ? '2' : '1',
            'settings' => [
                'montonio_shipping_enabled'                     => get_option( 'montonio_shipping_enabled' ),
                'montonio_shipping_enable_v2'                   => get_option( 'montonio_shipping_enable_v2' ),
                'montonio_shipping_orderStatusWhenLabelPrinted' => get_option( 'montonio_shipping_orderStatusWhenLabelPrinted' ),
                'montonio_email_tracking_code_text'             => get_option( 'montonio_email_tracking_code_text' ),
                'montonio_shipping_order_prefix'                => get_option( 'montonio_shipping_order_prefix' ),
                'montonio_shipping_show_address'                => get_option( 'montonio_shipping_show_address' ),
                'montonio_shipping_show_provider_logos'         => get_option( 'montonio_shipping_show_provider_logos' ),
                'montonio_shipping_senderName'                  => get_option( 'montonio_shipping_senderName' ),
                'montonio_shipping_senderPhone'                 => get_option( 'montonio_shipping_senderPhone' ),
                'montonio_shipping_senderStreetAddress'         => get_option( 'montonio_shipping_senderStreetAddress' ),
                'montonio_shipping_senderLocality'              => get_option( 'montonio_shipping_senderLocality' ),
                'montonio_shipping_senderRegion'                => get_option( 'montonio_shipping_senderRegion' ),
                'montonio_shipping_senderPostalCode'            => get_option( 'montonio_shipping_senderPostalCode' ),
                'montonio_shipping_senderCountry'               => get_option( 'montonio_shipping_senderCountry' ),
                'montonio_shipping_dropdown_type'               => get_option( 'montonio_shipping_dropdown_type' ),
                'montonio_shipping_css'                         => strlen( get_option( 'montonio_shipping_css' ) ) ?: 0,
                'montonio_shipping_enqueue_mode'                => get_option( 'montonio_shipping_enqueue_mode' ),
                'montonio_shipping_register_selectWoo'          => get_option( 'montonio_shipping_register_selectWoo' )
            ]
        ];

        $shipping_zones    = array_reverse( WC_Shipping_Zones::get_zones(), true );
        $default_zone      = new WC_Shipping_Zone( 0 );
        $shipping_zones[0] = [
            'id'               => $default_zone->get_id(),
            'zone_name'        => $default_zone->get_zone_name(),
            'zone_order'       => $default_zone->get_zone_order(),
            'zone_locations'   => $default_zone->get_zone_locations(),
            'shipping_methods' => $default_zone->get_shipping_methods()
        ];

        foreach ( $shipping_zones as $zone ) {
            $shipping_methods = $zone['shipping_methods'];

            foreach ( $shipping_methods as $method ) {
                if ( strpos( $method->id, 'montonio_' ) === false || 'yes' !== $method->enabled ) {
                    continue;
                }

                $data['shipping_methods'][$method->id] = [
                    'setting' => $method->instance_settings
                ];
            }
        }

        return $data;
    }

    /**
     * Get settings for a specific method ID.
     *
     * @since 7.0.0
     * @param string $method_id The method ID to get settings for
     * @return array|false The settings array or false if not found
     */
    public function get_settings( $method_id ) {
        return get_option( 'woocommerce_' . $method_id . '_settings', false );
    }

    /**
     * Get the status and environment from settings.
     *
     * @since 7.0.0
     * @param array $settings The settings array
     * @return array The status and environment data
     */
    public function get_status_and_environment( $settings ) {
        return [
            'status'      => ( isset( $settings['enabled'] ) && $settings['enabled'] === 'yes' ) ? 'enabled' : 'disabled',
            'environment' => ( isset( $settings['sandbox_mode'] ) && $settings['sandbox_mode'] === 'yes' ) ? 'sandbox' : 'production'
        ];
    }

    /**
     * Get the bearer token which is used for authentication.
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

    /**
     * Function for making API calls to the Montonio Shipping API.
     *
     * @since 7.0.0
     * @param string $path The path to the API endpoint
     * @param array $options The options for the request
     * @return string The body of the response. Empty string if no body or incorrect parameter given.
     * @throws Exception If the request fails
     */
    protected function api_request( $path, $options ) {
        $url     = self::MONTONIO_TELEMETRY_API_URL . $path;
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
}
new WC_Montonio_Telemetry_Service();

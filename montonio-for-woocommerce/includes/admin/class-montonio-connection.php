<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the Montonio Partner System connection callback flow.
 *
 * Receives the auto-submitted form POST from the Partner System after the
 * merchant authorises this store, exchanges the one-time code with the
 * Plugin Token Service (PTS) for live/sandbox API keys, and persists the
 * keys into the Montonio API settings option.
 *
 * @since 10.1.0
 */
class Montonio_Connection {
    /**
     * PTS host used to activate the connection and retrieve API keys.
     */
    const TELEMETRY_API_URL = 'https://plugin-telemetry.montonio.com/api';

    /**
     * Option name where Montonio API credentials are stored.
     */
    const SETTINGS_OPTION = 'woocommerce_wc_montonio_api_settings';

    /**
     * Register the callback and disconnect endpoints.
     *
     * @since 10.1.0
     * @return void
     */
    public static function init() {
        add_action( 'woocommerce_api_montonio_connection', array( __CLASS__, 'handle_callback' ) );
        add_action( 'admin_post_montonio_disconnect', array( __CLASS__, 'handle_disconnect' ) );
        add_action( 'admin_notices', array( __CLASS__, 'display_status_notice' ) );
    }

    /**
     * Display an admin notice based on the `montonio_connect` query param
     * set by self::redirect_to_settings().
     *
     * Restricted to the Montonio API settings page to avoid leaking the
     * notice onto unrelated admin screens.
     *
     * @since 10.1.0
     * @return void
     */
    public static function display_status_notice() {
        if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['section'], $_GET['montonio_connect'] ) ) {
            return;
        }

        $page    = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        $tab     = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
        $section = sanitize_text_field( wp_unslash( $_GET['section'] ) );

        if ( 'wc-settings' !== $page || 'checkout' !== $tab || 'wc_montonio_api' !== $section ) {
            return;
        }

        $status = sanitize_text_field( wp_unslash( $_GET['montonio_connect'] ) );

        $messages = array(
            'connected'         => array(
                'type'    => 'success',
                'message' => __( 'Your store has been connected to Montonio successfully.', 'montonio-for-woocommerce' )
            ),
            'connected_sandbox' => array(
                'type'    => 'success',
                'message' => __( 'Your store has been connected to Montonio in sandbox mode. Live API keys will be issued once Montonio approves your store.', 'montonio-for-woocommerce' )
            ),
            'disconnected'      => array(
                'type'    => 'success',
                'message' => __( 'Your Montonio account has been disconnected.', 'montonio-for-woocommerce' )
            ),
            'cancelled'         => array(
                'type'    => 'warning',
                'message' => __( 'Montonio connection was cancelled.', 'montonio-for-woocommerce' )
            ),
            'expired'           => array(
                'type'    => 'error',
                'message' => __( 'The Montonio connection link has expired. Please try connecting again.', 'montonio-for-woocommerce' )
            ),
            'activation_failed' => array(
                'type'    => 'error',
                'message' => __( 'Failed to activate the Montonio connection. Please try again or contact Montonio support.', 'montonio-for-woocommerce' )
            ),
            'invalid_request'   => array(
                'type'    => 'error',
                'message' => __( 'The Montonio connection request was invalid. Please try again.', 'montonio-for-woocommerce' )
            ),
            'keys_missing'      => array(
                'type'    => 'error',
                'message' => __( 'Montonio returned no usable API keys for this store. Please try connecting again or contact Montonio support.', 'montonio-for-woocommerce' )
            ),
            'sync_failed'       => array(
                'type'    => 'warning',
                'message' => __( 'Your store has been connected to Montonio, but we could not refresh the available payment methods.', 'montonio-for-woocommerce' )
            )
        );

        if ( ! isset( $messages[ $status ] ) ) {
            return;
        }

        printf(
            '<div class="montonio-notice montonio-notice--%1$s notice notice-%1$s"><p>%2$s</p></div>',
            esc_attr( $messages[ $status ]['type'] ),
            esc_html( $messages[ $status ]['message'] )
        );
    }

    /**
     * Build the Montonio Partner System connection URL.
     *
     * Used by the "Connect Montonio account" button on the API settings page.
     * Includes the store URL and a callback URL pointing back at the
     * `montonio_connection` wc-api endpoint handled by this class.
     *
     * @since 10.1.0
     * @return string Connection URL for Montonio Partner System.
     */
    public static function get_connect_url() {
        $redirect_uri = add_query_arg(
            array( 'wc-api' => 'montonio_connection' ),
            trailingslashit( get_home_url() )
        );

        $query = http_build_query( array(
            'action'      => 'connect-plugin',
            'platform'    => 'woocommerce',
            'storeUrl'    => wp_parse_url( get_home_url(), PHP_URL_HOST ),
            'callbackUrl' => $redirect_uri
        ) );

        return 'https://partner.montonio.com/?' . $query;
    }

    /**
     * Build the URL used by the Disconnect button on the API settings page.
     *
     * @since 10.1.0
     * @return string Nonced admin-post URL.
     */
    public static function get_disconnect_url() {
        return wp_nonce_url(
            add_query_arg( 'action', 'montonio_disconnect', admin_url( 'admin-post.php' ) ),
            'montonio_disconnect'
        );
    }

    /**
     * Handle the Disconnect action.
     *
     * Clears all live and sandbox API credentials and the connection UUID,
     * then redirects back to the API settings page.
     *
     * @since 10.1.0
     * @return void
     */
    public static function handle_disconnect() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to disconnect this Montonio account.', 'montonio-for-woocommerce' ),
                '',
                array( 'response' => 403 )
            );
        }

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'montonio_disconnect' ) ) {
            self::redirect_to_settings( 'invalid_request' );
            return;
        }

        $settings = get_option( self::SETTINGS_OPTION, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        foreach ( array( 'access_key', 'secret_key', 'sandbox_access_key', 'sandbox_secret_key', 'connection', 'live_keys_available', 'test_mode' ) as $key ) {
            $settings[$key] = '';
        }

        update_option( self::SETTINGS_OPTION, $settings );

        self::redirect_to_settings( 'disconnected' );
    }

    /**
     * Handle the POST callback from the Partner System.
     *
     * @since 10.1.0
     * @return void
     */
    public static function handle_callback() {
        if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            self::redirect_to_settings( 'invalid_request' );
            return;
        }

        $cancelled       = sanitize_text_field( wp_unslash( $_POST['cancelled'] ?? '' ) );
        $connection_uuid = sanitize_text_field( wp_unslash( $_POST['connectionUuid'] ?? '' ) );
        $one_time_code   = sanitize_text_field( wp_unslash( $_POST['oneTimeCode'] ?? '' ) );

        if ( 'true' === $cancelled ) {
            self::redirect_to_settings( 'cancelled' );
            return;
        }

        if ( empty( $connection_uuid ) || empty( $one_time_code ) ) {
            WC_Montonio_Logger::log( 'Connection callback missing connectionUuid or oneTimeCode.' );

            self::redirect_to_settings( 'invalid_request' );
            return;
        }

        if ( ! WC_Montonio_Helper::is_valid_uuid( $connection_uuid ) ) {
            WC_Montonio_Logger::log( 'Connection callback received malformed connectionUuid.' );

            self::redirect_to_settings( 'invalid_request' );
            return;
        }

        $activation = self::activate_connection( $connection_uuid, $one_time_code );

        if ( is_wp_error( $activation ) ) {
            WC_Montonio_Logger::log( 'Connection activation failed: ' . $activation->get_error_message() );

            $code = $activation->get_error_code() === 'INVALID_OR_EXPIRED_CODE' ? 'expired' : 'activation_failed';
            self::redirect_to_settings( $code );
            return;
        }

        $result = self::save_api_keys( $activation );

        if ( is_wp_error( $result ) ) {
            self::redirect_to_settings( $result->get_error_code() );
            return;
        }

        self::redirect_to_settings( $result );
    }

    /**
     * Exchange the one-time code with PTS for API keys.
     *
     * @since 10.1.0
     * @param string $connection_uuid
     * @param string $one_time_code
     * @return array|WP_Error Decoded PTS response on success.
     */
    private static function activate_connection( $connection_uuid, $one_time_code ) {
        $response = wp_remote_post(
            trailingslashit( self::TELEMETRY_API_URL ) . 'connections/activate',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json'
                ),
                'body'    => wp_json_encode(
                    array(
                        'connectionUuid' => $connection_uuid,
                        'oneTimeCode'    => $one_time_code
                    )
                )
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! in_array( $response_code, array( 200, 201 ) ) ) {
            return new WP_Error( 'activation_failed', 'Failed to activate connection.' );
        }

        if ( ! is_array( $body ) || empty( $body['connectionUuid'] ) ) {
            return new WP_Error( 'invalid_response', 'Partner System response missing expected fields.' );
        }

        return $body;
    }

    /**
     * Persist returned API keys into the Montonio API settings option.
     *
     * Only overwrites keys for environments PTS actually returned, leaving
     * existing values intact for the other environment.
     *
     * @since 10.1.0
     * @param array $activation
     * @return string|WP_Error Status code on success ('connected', 'connected_sandbox', 'sync_failed') or WP_Error on failure.
     */
    private static function save_api_keys( $activation ) {
        $settings = get_option( self::SETTINGS_OPTION, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $live_keys_saved    = false;
        $sandbox_keys_saved = false;

        if ( ! empty( $activation['liveKeysAvailable'] ) ) {
            if ( ! empty( $activation['productionApiKey']['accessKey'] ) && ! empty( $activation['productionApiKey']['secretKey'] ) ) {
                $settings['access_key']          = $activation['productionApiKey']['accessKey'];
                $settings['secret_key']          = $activation['productionApiKey']['secretKey'];
                $settings['test_mode']           = 'no';
                $settings['live_keys_available'] = 'yes';
                $live_keys_saved                 = true;
            }
        } else {
            $settings['access_key']          = '';
            $settings['secret_key']          = '';
            $settings['live_keys_available'] = 'no';
            $settings['test_mode']           = 'yes';
        }

        if ( ! empty( $activation['sandboxApiKey']['accessKey'] ) && ! empty( $activation['sandboxApiKey']['secretKey'] ) ) {
            $settings['sandbox_access_key'] = $activation['sandboxApiKey']['accessKey'];
            $settings['sandbox_secret_key'] = $activation['sandboxApiKey']['secretKey'];
            $sandbox_keys_saved             = true;
        }

        if ( ! $live_keys_saved && ! $sandbox_keys_saved ) {
            WC_Montonio_Logger::log( 'Connection activation succeeded but Partner System returned no usable API keys.' );
            return new WP_Error( 'keys_missing', 'No usable API keys returned from Partner System.' );
        }

        $settings['connection'] = $activation['connectionUuid'] ?? '';

        update_option( self::SETTINGS_OPTION, $settings );

        try {
            WC_Montonio_Data_Sync::sync_payment_methods();
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Payment method sync after connection failed: ' . $e->getMessage() );
            return 'sync_failed';
        }

        return $live_keys_saved ? 'connected' : 'connected_sandbox';
    }

    /**
     * Redirect back to the Montonio API settings page with a status flag.
     *
     * @since 10.1.0
     * @param string $status
     * @return void
     */
    private static function redirect_to_settings( $status ) {
        $url = add_query_arg(
            array(
                'page'             => 'wc-settings',
                'tab'              => 'checkout',
                'section'          => 'wc_montonio_api',
                'montonio_connect' => $status
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }
}

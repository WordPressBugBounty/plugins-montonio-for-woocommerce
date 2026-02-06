<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Montonio API Settings
 *
 * Handles the API settings page for Montonio payment gateway.
 * Provides configuration for live and sandbox API credentials.
 *
 * @extends WC_Settings_API
 */
class WC_Montonio_API_Settings extends WC_Settings_API {
    /**
     * WC_Montonio_API_Settings constructor.
     */
    public function __construct() {
        $this->id = 'wc_montonio_api';

        add_action( 'woocommerce_settings_checkout', array( $this, 'output_settings' ) );
        add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'on_setting_save' ), 20 );

        $this->init_form_fields();
    }

    /**
     * Initialize form fields
     * 
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'title'                   => array(
                'type'        => 'title',
                'title'       => __( 'Add API Keys', 'montonio-for-woocommerce' ),
                'description' => sprintf(
                    /* translators: 1) HTML anchor open tag 2) HTML anchor closing tag */
                    __( 'Live and Sandbox API keys can be obtained at %1$sMontonio Partner System%2$s', 'montonio-for-woocommerce' ),
                    '<a target="_blank" href="https://partner.montonio.com?utm_source=woo&utm_campaign=api">',
                    '</a>'
                )
            ),
            'live_title'              => array(
                'type'        => 'title',
                'title'       => __( 'Live keys', 'montonio-for-woocommerce' ),
                'description' => __( 'Use live keys to receive real payments from your customers.', 'montonio-for-woocommerce' )
            ),
            'access_key'              => array(
                'title'       => __( 'Access Key', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => '',
                'desc_tip'    => true
            ),
            'secret_key'              => array(
                'title'       => __( 'Secret Key', 'montonio-for-woocommerce' ),
                'type'        => 'password',
                'description' => '',
                'desc_tip'    => true
            ),
            'sandbox_title'           => array(
                'type'        => 'title',
                'title'       => __( 'Sandbox keys for testing', 'montonio-for-woocommerce' ),
                'description' => __( 'Use sandbox keys to test our services.', 'montonio-for-woocommerce' )
            ),
            'test_mode'               => array(
                'title'       => __( 'Test Mode', 'montonio-for-woocommerce' ),
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => __( 'Enable test mode for sandbox environment.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'sandbox_access_key'      => array(
                'title'       => __( 'Access Key', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => '',
                'desc_tip'    => true
            ),
            'sandbox_secret_key'      => array(
                'title'       => __( 'Secret Key', 'montonio-for-woocommerce' ),
                'type'        => 'password',
                'description' => '',
                'desc_tip'    => true
            ),
            'general_title'           => array(
                'type'  => 'title',
                'title' => __( 'General settings', 'montonio-for-woocommerce' )
            ),
            'merchant_reference_type' => array(
                'title'       => __( 'Merchant reference type', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( '<strong>Use order ID:</strong> Uses the default WooCommere order ID.<br><br><strong>Use order number:</strong> Allows you to use a custom order number. This option is useful if you have a custom order numbering system in place.<br><br><strong>Add prefix:</strong> Allows you to add a custom prefix to the default order ID.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'order_id'     => __( 'Use order ID', 'montonio-for-woocommerce' ),
                    'order_number' => __( 'Use order number', 'montonio-for-woocommerce' ),
                    'add_prefix'   => __( 'Add custom prefix', 'montonio-for-woocommerce' )
                )
            ),
            'order_prefix'            => array(
                'title'       => __( 'Order ID prefix', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'description' => ''
            )
        );
    }

    /**
     * Output settings page
     *
     * Renders the API settings page in WooCommerce admin.
     *
     * @since 9.3.0
     * @return void
     */
    public function output_settings() {
        global $current_section;

        if ( $current_section !== $this->id ) {
            return;
        }
        
        WC_Montonio_Admin_Settings_Page::render_options_page(
            __( 'API Settings', 'montonio-for-woocommerce' ),
            $this->generate_settings_html( array(), false ),
            $this->id
        );
    }

    /**
     * Handle settings save
     *
     * Validates API credentials after settings are saved.
     * Clears invalid keys and syncs payment methods on success.
     *
     * @since 9.3.0
     * @return void
     */
    public function on_setting_save() {
        $this->init_settings();

        $test_mode  = 'yes' === $this->get_option( 'test_mode' );
        $prefix     = $test_mode ? 'sandbox_' : '';
        $access_key = $this->get_option( $prefix . 'access_key' );
        $secret_key = $this->get_option( $prefix . 'secret_key' );

        $has_errors = false;

        // Validate access key for selected environment
        if ( ! empty( $access_key ) && ! WC_Montonio_Helper::is_valid_uuid( $access_key ) ) {
            $error_message = $test_mode 
                ? __( 'Sandbox Access Key is not valid.', 'montonio-for-woocommerce' )
                : __( 'Access Key is not valid.', 'montonio-for-woocommerce' );
            
            WC_Admin_Settings::add_error( $error_message );
            $this->update_option( $prefix . 'access_key', '' );
            $has_errors = true;
        }

        // Validate secret key for selected environment
        if ( ! empty( $secret_key ) && ! $this->is_valid_secret_key( $secret_key ) ) {
            $error_message = $test_mode 
                ? __( 'Sandbox Secret Key is not valid.', 'montonio-for-woocommerce' )
                : __( 'Secret Key is not valid.', 'montonio-for-woocommerce' );
            
            WC_Admin_Settings::add_error( $error_message );
            $this->update_option( $prefix . 'secret_key', '' );
            $has_errors = true;
        }

        // Stop if validation failed or keys are empty
        if ( $has_errors || empty( $access_key ) || empty( $secret_key ) ) {
            return;
        }

        $this->sync_payment_methods( $prefix );
    }

    /**
     * Sync payment methods with Montonio API
     *
     * Attempts to sync payment methods and handles API errors.
     * Clears credentials if authentication fails.
     *
     * @since 9.3.0
     * @param string $prefix Option prefix ('sandbox_' or '').
     * @return void
     */
    private function sync_payment_methods( $prefix ) {
        try {
            WC_Montonio_Data_Sync::sync_payment_methods();
        } catch ( Exception $e ) {
            $message = $e->getMessage();

            // Check if error indicates invalid credentials
            if ( strpos( $message, 'Unauthorized' ) !== false || strpos( $message, 'Forbidden' ) !== false ) {
                WC_Admin_Settings::add_error(
                    __( 'API credentials are invalid. Please check your Access Key and Secret Key.', 'montonio-for-woocommerce' )
                );                
                
                WC_Admin_Settings::add_error(
                    __( 'API response: ', 'montonio-for-woocommerce' ) . $message
                );
                
                $this->update_option( $prefix . 'access_key', '' );
                $this->update_option( $prefix . 'secret_key', '' );
            } else {
                WC_Admin_Settings::add_error(
                    __( 'Payment method sync failed: ', 'montonio-for-woocommerce' ) . $message
                );
            }
        }
    }

    /**
     * Validate secret key format
     *
     * Checks if the provided value is a valid base64-encoded string
     * with the expected length (44 characters, decoding to 33 bytes).
     *
     * @since 9.3.0
     * @param string $value The secret key to validate.
     * @return bool True if valid, false otherwise.
     */
    private function is_valid_secret_key( $value ) {
        if ( strlen( $value ) !== 44 ) {
            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $value ) ) {
            return false;
        }

        $decoded = base64_decode( $value, true );

        if ( $decoded === false || strlen( $decoded ) !== 33 ) {
            return false;
        }

        return true;
    }
}
new WC_Montonio_API_Settings();
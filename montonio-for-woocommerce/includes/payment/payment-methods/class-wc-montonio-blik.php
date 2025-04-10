<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Blik extends WC_Payment_Gateway {

    /**
     * Notices (array)
     *
     * @var array
     */
    protected $admin_notices = array();

    /**
     * Is test mode active?
     *
     * @var string
     */
    public $sandbox_mode;

    /**
     * Display BLIK fields in checkout?
     *
     * @var bool
     */
    public $blik_in_checkout;

    /**
     * Processor which handles the transaction in Montonio
     *
     * @var string
     */
    public $processor;

    public function __construct() {
        $this->id                 = 'wc_montonio_blik';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/blik.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Montonio BLIK', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Separate BLIK Payment option for checkout', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title            = $this->get_option( 'title', 'BLIK' );
        $this->description      = $this->get_option( 'description' );
        $this->enabled          = $this->get_option( 'enabled' );
        $this->sandbox_mode     = $this->get_option( 'sandbox_mode' );
        $this->blik_in_checkout = $this->get_option( 'blik_in_checkout' );
        $this->processor        = $this->get_option( 'processor', 'stripe' );

        if ( 'BLIK' === $this->title ) {
            $this->title = __( 'BLIK', 'montonio-for-woocommerce' );
        }

        if ( $this->blik_in_checkout === 'yes' ) {
            $this->has_fields = true;
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'validate_settings' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'get_order_response' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( $this, 'get_order_notification' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 3 );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );
        add_action( 'sync_blik_settings', array( $this, 'sync_blik_settings' ) );
    }

    /**
     * Edit gateway icon.
     */
    public function add_icon_class( $icon, $id ) {
        if ( $id == $this->id ) {
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-blik-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Plugin options, we deal with it in Step 3 too
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'          => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio BLIK', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'sandbox_mode'     => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => __( 'Use the Sandbox environment for testing only.', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => true
            ),
            'blik_in_checkout' => array(
                'title'       => 'BLIK fields in checkout',
                'label'       => 'Enable BLIK in checkout',
                'type'        => 'checkbox',
                'description' => __( 'Add BLIK fields to the checkout instead of redirecting to the gateway.', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => true
            ),
            'title'            => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'BLIK', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'      => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay with BLIK via Montonio.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            )
        );
    }

    /**
     * Check if Montonio BLIK should be available
     */
    public function is_available() {
        if ( $this->enabled !== 'yes' ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported( array( 'PLN' ) ) ) {
            return false;
        }

        if ( WC()->cart && $this->get_order_total() < 3 ) {
            return false;
        }

        $settings = get_option( 'woocommerce_wc_montonio_blik_settings' );
        if ( empty( $settings['processor'] ) ) {
            do_action( 'sync_blik_settings' );
        }

        return true;
    }

    /**
     * Perform validation on settings after saving them
     *
     * @since 8.0.1 - Will add processor to settings
     * @param array $settings The new settings to validate
     */
    public function validate_settings( $settings ) {
        if ( is_array( $settings ) ) {

            if ( $settings['enabled'] === 'no' ) {
                return $settings;
            }

            $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );

            // Disable the payment gateway if API keys are not provided
            if ( $settings['sandbox_mode'] === 'yes' ) {
                if ( empty( $api_settings['sandbox_access_key'] ) || empty( $api_settings['sandbox_secret_key'] ) ) {
                    /* translators: API Settings page url */
                    $message = sprintf( __( 'Sandbox API keys missing. The Montonio payment method has been automatically disabled. <a href="%s">Add API keys here</a>.', 'montonio-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) );
                    $this->add_admin_notice( $message, 'error' );

                    $settings['enabled'] = 'no';

                    return $settings;
                }
            } else {
                if ( empty( $api_settings['access_key'] ) || empty( $api_settings['secret_key'] ) ) {
                    /* translators: API Settings page url */
                    $message = sprintf( __( 'Live API keys missing. The Montonio payment method has been automatically disabled. <a href="%s">Add API keys here</a>.', 'montonio-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_montonio_api' ) );
                    $this->add_admin_notice( $message, 'error' );

                    $settings['enabled'] = 'no';

                    return $settings;
                }
            }

            try {
                $montonio_api = new WC_Montonio_API( $settings['sandbox_mode'] );
                $response     = json_decode( $montonio_api->fetch_payment_methods() );

                if ( ! isset( $response->paymentMethods->blik ) || ! isset( $response->paymentMethods->blik->processor ) ) {
                    throw new Exception( __( 'BLIK payment method is not enabled in Montonio partner system.', 'montonio-for-woocommerce' ) );
                }

                $settings['processor'] = $response->paymentMethods->blik->processor;
            } catch ( Exception $e ) {
                $settings['enabled'] = 'no';

                if ( ! empty( $e->getMessage() ) ) {
                    $this->add_admin_notice( __( 'Montonio API response: ', 'montonio-for-woocommerce' ) . $e->getMessage(), 'error' );
                    WC_Montonio_Logger::log( $e->getMessage() );
                }
            }
        }

        return $settings;
    }

    /**
     * Sync payment methods with Montonio API. This is called when PAYMENT_METHOD_PROCESSOR_MISMATCH error is received.
     *
     * @since 8.0.5
     * @return void
     */
    public function sync_blik_settings() {
        $settings = get_option( 'woocommerce_wc_montonio_blik_settings' );
        $settings = $this->validate_settings( $settings );
        update_option( 'woocommerce_wc_montonio_blik_settings', $settings );
    }

    /**
     * We're processing the payments here
     *
     * @param $order_id
     */
    public function process_payment( $order_id ) {

        $order = wc_get_order( $order_id );

        $payment_data = array(
            'paymentMethodId' => $this->id,
            'payment'         => array(
                'method'        => 'blik',
                'methodDisplay' => $this->get_title(),
                'methodOptions' => null
            )
        );

        if ( $this->blik_in_checkout === 'yes' ) {
            if ( $this->processor === 'stripe' ) {
                $payment_intent_uuid = isset( $_POST['montonio_blik_payment_intent_uuid'] ) ? sanitize_key( wp_unslash( $_POST['montonio_blik_payment_intent_uuid'] ) ) : null;

                if ( empty( $payment_intent_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $payment_intent_uuid ) ) {
                    wc_add_notice( __( 'There was a problem processing this payment. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
                    WC_Montonio_Logger::log( 'Failure - Order ID: ' . $order_id . ' Response: paymentIntentUuid is empty. ' . $this->id );

                    return array(
                        'result' => 'failure'
                    );
                }

                $payment_data['paymentIntentUuid'] = $payment_intent_uuid;
            } else {
                $blik_code = isset( $_POST['montonio_blik_code'] ) ? sanitize_key( wp_unslash( $_POST['montonio_blik_code'] ) ) : null;

                if ( empty( $blik_code ) || ! preg_match( '/^\d{6}$/', $blik_code ) ) {
                    wc_add_notice( __( 'Please enter a valid 6-digit BLIK code.', 'montonio-for-woocommerce' ), 'error' );

                    return array(
                        'result'  => 'failure',
                        'message' => __( 'Please enter a valid 6-digit BLIK code.', 'montonio-for-woocommerce' )
                    );
                }

                $payment_data['payment']['methodOptions'] = array(
                    'blikCode' => $blik_code
                );
            }
        }

        // Create new Montonio API instance
        $montonio_api               = new WC_Montonio_API( $this->sandbox_mode );
        $montonio_api->order        = $order;
        $montonio_api->payment_data = $payment_data;

        try {
            $response = $montonio_api->create_order();
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );

            wc_add_notice( $message, 'error' );

            $order->add_order_note( __( 'Montonio: There was a problem processing the payment. Response: ', 'montonio-for-woocommerce' ) . $e->getMessage() );

            WC_Montonio_Logger::log( 'Order creation failure - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() );

            return array(
                'result'  => 'failure',
                'message' => $message
            );
        }

        $order->update_meta_data( '_montonio_uuid', $response->uuid );
        $order->save();

        if ( $this->processor === 'blik' && $this->blik_in_checkout === 'yes' ) {
            // Find the latest payment intent UUID
            $payment_intent_uuid = null;

            if ( ! empty( $response->paymentIntents ) ) {
                $latest_payment_intent = null;

                foreach ( $response->paymentIntents as $intent ) {
                    if (
                        is_null( $latest_payment_intent ) ||
                        strtotime( $intent->createdAt ) > strtotime( $latest_payment_intent->createdAt )
                    ) {
                        $latest_payment_intent = $intent;
                    }
                }

                if ( $latest_payment_intent ) {
                    $payment_intent_uuid = $latest_payment_intent->uuid;
                }
            }

            // Validate the extracted UUID format
            if ( ! WC_Montonio_Helper::is_valid_uuid( $payment_intent_uuid ) ) {
                wc_add_notice( __( 'Invalid payment reference received. Please try again.', 'montonio-for-woocommerce' ), 'error' );

                return array(
                    'result'  => 'failure',
                    'message' => __( 'Invalid payment reference received. Please try again.', 'montonio-for-woocommerce' )
                );
            }
        }

        if ( $this->blik_in_checkout === 'yes' ) {
            return array(
                'result'              => 'success',
                'payment_intent_uuid' => $payment_intent_uuid,
                'redirect'            => '#confirm-pi-' . $payment_intent_uuid
            );
        } else {
            return array(
                'result'   => 'success',
                'redirect' => $response->paymentUrl
            );
        }
    }

    /**
     * Outputs payment description and fields on the checkout page
     *
     *  @return void
     */
    public function payment_fields() {
        $description = $this->get_description();

        do_action( 'wc_montonio_before_payment_desc', $this->id );

        if ( $this->sandbox_mode === 'yes' ) {
            /* translators: 1) notice that test mode is enabled 2) explanation of test mode */
            printf( '<strong>%1$s</strong><br>%2$s<br>', esc_html__( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ), esc_html__( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) );
        }

        if ( ! empty( $description ) ) {
            echo esc_html( apply_filters( 'wc_montonio_description', wp_kses_post( $description ), $this->id ) );
        }

        if ( $this->blik_in_checkout === 'yes' ) {
            echo '<div id="montonio-blik-form"></div>';
            echo '<input type="hidden" name="montonio_blik_payment_intent_uuid" value="">';
        }

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    /**
     * Register JS scripts for embedded BLIK in checkout
     *
     * @since 8.0.5 Now uses the processor to determine which script to load
     * @return void
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        if ( $this->blik_in_checkout === 'yes' && ! WC_Montonio_Helper::is_checkout_block() ) {
            $embedded_blik_params = array(
                'sandbox_mode' => $this->sandbox_mode,
                'return_url'   => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) ), $this->id ),
                'locale'       => WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) ),
                'nonce'        => wp_create_nonce( 'montonio_embedded_payment_intent_nonce' )
            );
            if ( $this->processor === 'blik' ) {
                wp_enqueue_script( 'montonio-embedded-blik' );
                wp_localize_script( 'montonio-embedded-blik', 'wc_montonio_embedded_blik', $embedded_blik_params );
            } else {
                wp_enqueue_script( 'montonio-inline-blik' );
                wp_localize_script( 'montonio-inline-blik', 'wc_montonio_inline_blik', $embedded_blik_params );
            }
        }
    }

    /**
     * Refunds amount from Montonio and return true/false as result
     *
     * @param string $order_id order id.
     * @param string $amount refund amount.
     * @param string $reason reason of refund.
     * @return bool
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return WC_Montonio_Refund::init_refund(
            $order_id,
            $this->sandbox_mode,
            $amount,
            $reason
        );
    }

    /**
     * Check webhook notfications from Montonio
     */
    public function get_order_notification() {
        new WC_Montonio_Callbacks(
            $this->sandbox_mode,
            true
        );
    }

    /**
     * Check callback from Montonio
     * and redirect user: thankyou page for success, checkout on declined/failure
     */
    public function get_order_response() {
        new WC_Montonio_Callbacks(
            $this->sandbox_mode,
            false
        );
    }

    /**
     * Edit settings page layout
     */
    public function admin_options() {
        WC_Montonio_Display_Admin_Options::display_options(
            $this->method_title,
            $this->generate_settings_html( array(), false ),
            $this->id,
            $this->sandbox_mode
        );
    }

    /**
     * Display admin notices
     */
    public function add_admin_notice( $message, $class ) {
        $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
    }

    public function display_admin_notices() {
        foreach ( $this->admin_notices as $notice ) {
            echo '<div id="message" class="' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
    }
}
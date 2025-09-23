<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_Card extends WC_Payment_Gateway {

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
    public $test_mode;

    /**
     * Display card fields in checkout?
     *
     * @var bool
     */
    public $inline_checkout;

    /**
     * Processor which handles the transaction in Montonio
     *
     * @var string
     */
    public $processor;

    public function __construct() {
        $this->id                 = 'wc_montonio_card';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc-ap-gp.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Montonio Card Payments', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Allows card payments via Montonio', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title           = $this->get_option( 'title', 'Card Payment' );
        $this->description     = $this->get_option( 'description' );
        $this->enabled         = $this->get_option( 'enabled' );
        $this->test_mode       = $this->get_option( 'test_mode' );
        $this->inline_checkout = $this->get_option( 'inline_checkout' );
        $this->processor       = $this->get_option( 'processor' );

        if ( 'Card Payment' === $this->title ) {
            $this->title = __( 'Card Payment', 'montonio-for-woocommerce' );
        }

        if ( isset( $_GET['pay_for_order'] ) ) {
            $this->inline_checkout = 'no';
        }

        if ( $this->inline_checkout === 'yes' ) {
            $this->has_fields = true;

            // Show all options for Adyen but hide wallets for other processors
            if ( 'adyen' !== $this->processor ) {
                $this->icon = WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc.png';
            }
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'validate_settings' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'get_order_response' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( $this, 'get_order_notification' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 3 );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );
    }

    /**
     * Edit gateway icon.
     */
    public function add_icon_class( $icon, $id ) {
        if ( $id == $this->id ) {
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-card-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Plugin options, we deal with it in Step 3 too
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Card Payments', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'test_mode'       => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => __( 'Whether the provider is in test mode (sandbox) for payments processing.', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => true
            ),
            'inline_checkout' => array(
                'title'       => 'Card fields in checkout',
                'label'       => 'Enable card fields in checkout',
                'type'        => 'checkbox',
                'description' => __( 'Add card fields to the checkout instead of redirecting to the gateway. (Apple Pay and Google Pay are not supported with this flow and will be turned off)', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => false
            ),
            'title'           => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Card Payment', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'     => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay with your credit or debit card via Montonio.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            )
        );
    }

    /**
     * Check if Montonio Card Payments should be available
     */
    public function is_available() {
        if ( $this->enabled !== 'yes' ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported() ) {
            return false;
        }

        if ( WC()->cart && $this->get_order_total() < 0.5 ) {
            return false;
        }

        $settings = get_option( 'woocommerce_wc_montonio_card_settings' );

        if ( empty( $settings['sync_timestamp'] ) || $settings['sync_timestamp'] < time() - 86400 || empty( $settings['processor'] ) ) {
            $settings                   = $this->validate_settings( $settings );
            $settings['sync_timestamp'] = time();
            update_option( 'woocommerce_wc_montonio_card_settings', $settings );
        }

        return true;
    }

    /**
     * Perform validation on settings after saving them
     */
    public function validate_settings( $settings ) {
        if ( is_array( $settings ) ) {

            if ( $settings['enabled'] === 'no' ) {
                return $settings;
            }

            $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );

            // Disable the payment gateway if API keys are not provided
            if ( isset( $settings['test_mode'] ) && $settings['test_mode'] === 'yes' ) {
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
                $montonio_api = new WC_Montonio_API( $settings['test_mode'] ?? 'no' );
                $response     = json_decode( $montonio_api->fetch_payment_methods() );

                if ( ! isset( $response->paymentMethods->cardPayments ) ) {
                    throw new Exception( __( 'Card payments method is not enabled in Montonio partner system.', 'montonio-for-woocommerce' ) );
                }

                $settings['processor'] = $response->paymentMethods->cardPayments->processor ?? null;
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

    /*
     * We're processing the payments here
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        try {
            // Prepare Payment Data for Montonio Payments
            $payment_data = array(
                'paymentMethodId' => $this->id,
                'payment'         => array(
                    'method'        => 'cardPayments',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( $this->inline_checkout === 'yes' ) {
                $session_uuid = null;

                if ( $this->processor === 'adyen' ) {
                    $session_uuid = isset( $_POST['montonio_card_payment_session_uuid'] ) ? sanitize_key( wp_unslash( $_POST['montonio_card_payment_session_uuid'] ) ) : null;

                    if ( empty( $session_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $session_uuid ) ) {
                        wc_add_notice( __( 'There was a problem processing this payment. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
                        WC_Montonio_Logger::log( 'Failure - Order ID: ' . $order_id . ' Response: sessionUuid is empty. ' . $this->id );

                        return array(
                            'result' => 'failure'
                        );
                    }

                    $payment_data['sessionUuid'] = $session_uuid;
                } else {
                    $session_uuid = isset( $_POST['montonio_card_payment_intent_uuid'] ) ? sanitize_key( wp_unslash( $_POST['montonio_card_payment_intent_uuid'] ) ) : null;

                    if ( empty( $session_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $session_uuid ) ) {
                        wc_add_notice( __( 'There was a problem processing this payment. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
                        WC_Montonio_Logger::log( 'Failure - Order ID: ' . $order_id . ' Response: paymentIntentUuid is empty. ' . $this->id );

                        return array(
                            'result' => 'failure'
                        );
                    }

                    $payment_data['paymentIntentUuid'] = $session_uuid;
                }
            }

            // Create new Montonio API instance
            $montonio_api               = new WC_Montonio_API( $this->test_mode );
            $montonio_api->order        = $order;
            $montonio_api->payment_data = $payment_data;

            $response = $montonio_api->create_order();

            $order->update_meta_data( '_montonio_uuid', $response->uuid );

            if ( is_callable( array( $order, 'save' ) ) ) {
                $order->save();
            }

            if ( $this->inline_checkout === 'yes' ) {
                return array(
                    'result'   => 'success',
                    'redirect' => '#confirm-session-' . $session_uuid
                );
            } else {
                return array(
                    'result'   => 'success',
                    'redirect' => $response->paymentUrl
                );
            }
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );

            wc_add_notice( $message, 'error' );

            $order->add_order_note( __( 'Montonio: There was a problem processing the payment. Response: ', 'montonio-for-woocommerce' ) . $e->getMessage() );

            WC_Montonio_Logger::log( 'Order creation failure - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() );
        }
    }

    public function payment_fields() {
        $description = $this->get_description();

        do_action( 'wc_montonio_before_payment_desc', $this->id );

        if ( $this->test_mode === 'yes' ) {
            /* translators: 1) notice that test mode is enabled 2) explanation of test mode */
            printf( '<strong>%1$s</strong><br>%2$s<br>', esc_html__( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ), esc_html__( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) );
        }

        if ( ! empty( $description ) ) {
            echo esc_html( apply_filters( 'wc_montonio_description', wp_kses_post( $description ), $this->id ) );
        }

        if ( $this->inline_checkout === 'yes' ) {
            echo '<div id="montonio-card-form"></div>';

            if ( $this->processor === 'adyen' ) {
                echo '<input type="hidden" name="montonio_card_payment_session_uuid" value="">';
            } else {
                echo '<input type="hidden" name="montonio_card_payment_intent_uuid" value="">';
            }
        }

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() || $this->inline_checkout !== 'yes' || WC_Montonio_Helper::is_checkout_block() ) {
            return;
        }

        $locale = WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) );

        if ( ! in_array( $locale, array( 'en', 'et', 'fi', 'lt', 'lv', 'pl', 'ru' ) ) ) {
            $locale = 'en';
        }

        $script_data = array(
            'test_mode'  => $this->test_mode,
            'return_url' => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) ), $this->id ),
            'locale'     => $locale,
            'nonce'      => wp_create_nonce( 'montonio_embedded_checkout_nonce' )
        );

        if ( $this->processor === 'adyen' ) {
            wp_enqueue_script( 'montonio-embedded-card' );
            wp_localize_script( 'montonio-embedded-card', 'wc_montonio_embedded_card', $script_data );
        } else {
            wp_enqueue_script( 'montonio-embedded-card-legacy' );
            wp_localize_script( 'montonio-embedded-card-legacy', 'wc_montonio_embedded_card', $script_data );
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
            $this->test_mode,
            $amount,
            $reason
        );
    }

    /**
     * Check webhook notfications from Montonio
     */
    public function get_order_notification() {
        new WC_Montonio_Callbacks(
            $this->test_mode,
            true
        );
    }

    /**
     * Check callback from Montonio
     * and redirect user: thankyou page for success, checkout on declined/failure
     */
    public function get_order_response() {
        new WC_Montonio_Callbacks(
            $this->test_mode,
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
            $this->test_mode
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

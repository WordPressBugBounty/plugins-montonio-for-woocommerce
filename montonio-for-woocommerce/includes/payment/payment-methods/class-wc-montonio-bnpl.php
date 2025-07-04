<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Montonio_BNPL extends WC_Payment_Gateway {

    /**
     * Notices (array)
     *
     * @var array
     */
    protected $admin_notices = array();

    /**
     * API access key
     *
     * @var string
     */
    public $access_key;

    /**
     * API secret key
     *
     * @var string
     */
    public $secret_key;

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $test_mode;

    public function __construct() {
        $this->id                 = 'wc_montonio_bnpl';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/inbank.svg';
        $this->has_fields         = true;
        $this->method_title       = __( 'Montonio Pay Later', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Pay in multiple parts provided in co-operation with Inbank', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title        = $this->get_option( 'title', 'Pay Later' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->test_mode = $this->get_option( 'test_mode' );

        if ( 'Pay Later' === $this->title ) {
            $this->title = __( 'Pay Later', 'montonio-for-woocommerce' );
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
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-bnpl-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Plugin options, we deal with it in Step 3 too
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'      => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Pay Later', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'test_mode' => array(
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'description' => __( 'Whether the provider is in test mode (sandbox) for payments processing.', 'montonio-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => true
            ),
            'title'        => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Pay Later', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'  => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay in multiple parts provided in co-operation with Inbank.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'min_amount'   => array(
                'title'       => __( 'Minimum cart amount', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '30',
                'description' => __( 'Display "Pay Later" payment method when the cart total is more than the specified amount. (Lowest allowed amount is 30 EUR).', 'montonio-for-woocommerce' ),
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

        if ( ! WC_Montonio_Helper::is_client_currency_supported( array( 'EUR' ) ) ) {
            return false;
        }

        if ( WC()->cart ) {
            $min_amount = $this->get_option( 'min_amount' );
            $min_amount = is_numeric( $min_amount ) && $min_amount > 30 ? (float) $min_amount : 30;
            $cart_total = $this->get_order_total();

            if ( $cart_total < $min_amount || $cart_total > 2500 ) {
                return false;
            }
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
            if ( $settings['test_mode'] === 'yes' ) {
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
                $montonio_api = new WC_Montonio_API( $settings['test_mode'] );
                $response     = json_decode( $montonio_api->fetch_payment_methods() );

                if ( ! isset( $response->paymentMethods->bnpl ) ) {
                    throw new Exception( __( 'Pay Later is not enabled in Montonio partner system.', 'montonio-for-woocommerce' ) );
                }
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
                    'method'        => 'bnpl',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( isset( $_POST['montonio_bnpl_period'] ) ) {
                $payment_data['payment']['methodOptions']['period'] = (float) sanitize_key( wp_unslash( $_POST['montonio_bnpl_period'] ) );
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

            // Return response after which redirect to Montonio Payments will happen
            return array(
                'result'   => 'success',
                'redirect' => $response->paymentUrl
            );
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

        $bnpl_periods = array(
            '1' => array(
                'title' => __( 'Pay next month', 'montonio-for-woocommerce' ),
                'min'   => 30,
                'max'   => 800
            ),
            '2' => array(
                'title' => __( 'Pay in two parts', 'montonio-for-woocommerce' ),
                'min'   => 75,
                'max'   => 2500
            ),
            '3' => array(
                'title' => __( 'Pay in three parts', 'montonio-for-woocommerce' ),
                'min'   => 75,
                'max'   => 2500
            )
        );

        $cart_total      = WC()->cart ? $this->get_order_total() : 0;
        $count           = 0;
        $selected_period = 1;

        echo '<div class="montonio-bnpl-items">';
        foreach ( $bnpl_periods as $key => $value ) {
            $class    = '';
            $subtitle = '';

            if ( $cart_total < $value['min'] ) {
                $class = ' montonio-bnpl-item--disabled';
                /* translators: additional amount needed */
                $subtitle = '<div class="montonio-bnpl-item-subtitle">' . sprintf( __( 'Add %s to the cart to use this payment method', 'montonio-for-woocommerce' ), wc_price( $value['min'] - $cart_total ) ) . '</div>';
            }

            if ( $value['max'] >= $cart_total ) {
                $count++;

                if ( $count == 1 ) {
                    $class           = ' active';
                    $selected_period = $key;
                }

                echo '<div class="montonio-bnpl-item montonio-bnpl-item-' . esc_attr( $key ) . esc_attr( $class ) . '" data-bnpl-period="' . esc_attr( $key ) . '">' . esc_html( $value['title'] ) . wp_kses_post( $subtitle ) . '</div>';
            }
        }

        echo '</div>';
        echo '<input type="hidden" name="montonio_bnpl_period" id="montonio_bnpl_period" value="' . esc_attr( $selected_period ) . '">';

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        wp_enqueue_script( 'montonio-bnpl' );
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

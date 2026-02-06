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
    public $test_mode;

    /**
     * Display BLIK fields in checkout?
     *
     * @var bool
     */
    public $embedded_fields;

    /**
     * Blik payment configuration
     *
     * @var array
     */
    public $method_config;

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
        $this->title           = $this->get_option( 'title', 'BLIK' );
        $this->description     = $this->get_option( 'description' );
        $this->enabled         = $this->get_option( 'enabled' );
        $this->test_mode       = WC_Montonio_Helper::is_test_mode();
        $this->embedded_fields = 'yes' === $this->get_option( 'blik_in_checkout' );
        $this->method_config   = WC_Montonio_Helper::get_payment_methods( 'blik' );
        $this->processor       = $this->method_config['processor'] ?? 'stripe';

        if ( 'BLIK' === $this->title ) {
            $this->title = __( 'BLIK', 'montonio-for-woocommerce' );
        }

        if ( isset( $_GET['pay_for_order'] ) ) {
            $this->embedded_fields = false;
        }

        if ( $this->embedded_fields ) {
            $this->has_fields = true;
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'get_order_response' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( $this, 'get_order_notification' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );
    }

    /**
     * Edit gateway icon.
     */
    public function add_icon_class( $icon, $id = '' ) {
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
        if ( 'yes' !== $this->enabled ) {
            return false;
        }

        if ( empty( $this->method_config ) ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported( array( 'PLN' ) ) ) {
            return false;
        }

        if ( WC()->cart && $this->get_order_total() < 3 ) {
            return false;
        }

        return true;
    }

    /**
     * Get the latest payment intent UUID from the API response
     *
     * @since 9.3.0
     * @param object $response The API response object
     * @return string|null The UUID of the latest payment intent, or null if not found
     */
    private function get_latest_payment_intent_uuid( $response ) {
        if ( empty( $response->paymentIntents ) ) {
            return;
        }

        $latest = null;

        foreach ( $response->paymentIntents as $intent ) {
            if ( is_null( $latest ) || strtotime( $intent->createdAt ) > strtotime( $latest->createdAt ) ) {
                $latest = $intent;
            }
        }

        return $latest ? $latest->uuid : null;
    }

    /**
     * We're processing the payments here
     *
     * @param $order_id
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        try {
            $payment_data = array(
                'paymentMethodId' => $this->id,
                'payment'         => array(
                    'method'        => 'blik',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( $this->embedded_fields ) {
                if ( 'blik' === $this->processor ) {
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
                } else {
                    $payment_intent_uuid = isset( $_POST['montonio_blik_payment_intent_uuid'] ) ? sanitize_key( wp_unslash( $_POST['montonio_blik_payment_intent_uuid'] ) ) : null;

                    if ( empty( $payment_intent_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $payment_intent_uuid ) ) {
                        throw new Exception( __( 'Invalid payment reference. Please refresh the page and try again.', 'montonio-for-woocommerce' ) );
                    }

                    $payment_data['paymentIntentUuid'] = $payment_intent_uuid;
                }
            }

            // Create new Montonio API instance
            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->create_order( $order, $payment_data );

            $order->update_meta_data( '_montonio_uuid', $response->uuid );
            $order->save();

            if ( $this->embedded_fields ) {
                if ( 'blik' === $this->processor ) {
                    $payment_intent_uuid = $this->get_latest_payment_intent_uuid( $response );

                    // Validate the extracted UUID format
                    if ( empty( $payment_intent_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $payment_intent_uuid ) ) {
                        throw new Exception( __( 'Invalid payment reference. Please refresh the page and try again.', 'montonio-for-woocommerce' ) );
                    }
                }

                return array(
                    'result'              => 'success',
                    'payment_intent_uuid' => $payment_intent_uuid,
                    'redirect'            => '#confirm-pi-' . $payment_intent_uuid
                );
            }

            return array(
                'result'   => 'success',
                'redirect' => $response->paymentUrl
            );
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );

            wc_add_notice( $message, 'error' );

            WC_Montonio_Logger::log( 'Error (' . $this->id . ') - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() );

            return array(
                'result'  => 'failure',
                'message' => $message
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

        if ( $this->test_mode ) {
            /* translators: 1) notice that test mode is enabled 2) explanation of test mode */
            printf( '<strong>%1$s</strong><br>%2$s<br>', esc_html__( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ), esc_html__( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) );
        }

        if ( ! empty( $description ) ) {
            echo esc_html( apply_filters( 'wc_montonio_description', wp_kses_post( $description ), $this->id ) );
        }

        if ( $this->embedded_fields ) {
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
        if ( ! is_cart() && ! is_checkout() || ! $this->embedded_fields || WC_Montonio_Helper::is_checkout_block() ) {
            return;
        }

        $embedded_blik_params = array(
            'test_mode'  => $this->test_mode,
            'return_url' => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) ), $this->id ),
            'locale'     => WC_Montonio_Helper::get_locale(),
            'nonce'      => wp_create_nonce( 'montonio_embedded_checkout_nonce' )
        );

        if ( 'blik' === $this->processor ) {
            wp_enqueue_script( 'montonio-embedded-blik' );
            wp_localize_script( 'montonio-embedded-blik', 'wc_montonio_embedded_blik', $embedded_blik_params );
        } else {
            wp_enqueue_script( 'montonio-embedded-blik-legacy' );
            wp_localize_script( 'montonio-embedded-blik-legacy', 'wc_montonio_inline_blik', $embedded_blik_params );
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
            $amount,
            $reason
        );
    }

    /**
     * Check webhook notfications from Montonio
     */
    public function get_order_notification() {
        new WC_Montonio_Callbacks();
    }

    /**
     * Check callback from Montonio
     * and redirect user: thankyou page for success, checkout on declined/failure
     */
    public function get_order_response() {
        new WC_Montonio_Callbacks( true );
    }

    /**
     * Edit settings page layout
     */
    public function admin_options() {
        WC_Montonio_Admin_Settings_Page::render_options_page(
            $this->method_title,
            $this->generate_settings_html( array(), false ),
            $this->id
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
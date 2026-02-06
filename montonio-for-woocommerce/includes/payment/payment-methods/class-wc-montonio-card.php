<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Montonio Card Payments Gateway
 *
 * @class       WC_Montonio_Card
 * @extends     WC_Payment_Gateway
 */
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
    public $embedded_fields;

    /**
     * Card payment configuration
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

    /**
     * Is the payment method required to be enabled
     *
     * @var string
     */
    public $is_required;

    /**
     * Constructor for the gateway.
     */
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
        $this->test_mode       = WC_Montonio_Helper::is_test_mode();
        $this->embedded_fields = 'yes' === $this->get_option( 'inline_checkout' );
        $this->method_config   = WC_Montonio_Helper::get_payment_methods( 'cardPayments' );
        $this->processor       = $this->method_config['processor'] ?? 'stripe';
        $this->is_required     = $this->method_config['requiredToBeEnabled'] ?? false;

        if ( 'Card Payment' === $this->title ) {
            $this->title = __( 'Card Payment', 'montonio-for-woocommerce' );
        }

        if ( isset( $_GET['pay_for_order'] ) ) {
            $this->embedded_fields = false;
        }

        if ( $this->embedded_fields ) {
            $this->has_fields = true;

            // Show all options for Adyen but hide wallets for other processors
            if ( 'adyen' !== $this->processor ) {
                $this->icon = WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc.png';
            }
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
     * Initialize gateway settings form fields.
     *
     * @return void
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
            'inline_checkout' => array(
                'title'       => 'Card fields in checkout',
                'label'       => 'Enable card fields in checkout',
                'type'        => 'checkbox',
                'description' => __( 'Add card fields to the checkout instead of redirecting to the gateway.', 'montonio-for-woocommerce' ),
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
     * Checks to see if all criteria is met before showing payment method.
     *
     * @return bool True if the gateway is available, false otherwise.
     */
    public function is_available() {
        if ( 'yes' !== $this->enabled ) {
            if ( ! $this->is_required || ! WC_Montonio_Helper::has_other_active_method( $this->id ) ) {
                return false;
            }
        }

        if ( empty( $this->method_config ) ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported() ) {
            return false;
        }

        if ( WC()->cart && $this->get_order_total() < 0.5 ) {
            return false;
        }

        return true;
    }

    /**
     * Add custom CSS class to the gateway icon.
     *
     * @param  string $icon The default icon HTML.
     * @param  string $id   The gateway ID.
     * @return string       Modified icon HTML with added classes.
     */
    public function add_icon_class( $icon, $id = '' ) {
        if ( $id == $this->id ) {
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-card-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Enqueue payment scripts and styles for the frontend.
     *
     * @return void
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() || ! $this->embedded_fields || WC_Montonio_Helper::is_checkout_block() ) {
            return;
        }

        $locale = WC_Montonio_Helper::get_locale();

        if ( ! in_array( $locale, array( 'en', 'et', 'fi', 'lt', 'lv', 'pl', 'ru' ) ) ) {
            $locale = 'en';
        }

        $script_data = array(
            'test_mode'  => $this->test_mode,
            'return_url' => (string) apply_filters( 'wc_montonio_return_url', add_query_arg( 'wc-api', $this->id, trailingslashit( get_home_url() ) ), $this->id ),
            'locale'     => $locale,
            'nonce'      => wp_create_nonce( 'montonio_embedded_checkout_nonce' )
        );

        if ( 'adyen' === $this->processor ) {
            wp_enqueue_script( 'montonio-embedded-card' );
            wp_localize_script( 'montonio-embedded-card', 'wc_montonio_embedded_card', $script_data );
        } else {
            wp_enqueue_script( 'montonio-embedded-card-legacy' );
            wp_localize_script( 'montonio-embedded-card-legacy', 'wc_montonio_embedded_card', $script_data );
        }
    }

    /**
     * Output the payment method fields on the checkout page.
     *
     * @return void
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
            echo '<div id="montonio-card-form"></div>';

            if ( 'adyen' === $this->processor ) {
                echo '<input type="hidden" name="montonio_card_payment_session_uuid" value="">';
            } else {
                echo '<input type="hidden" name="montonio_card_payment_intent_uuid" value="">';
            }
        }

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    /**
     * Process the payment for an order.
     *
     * @param  int $order_id The ID of the order being processed.
     * @return array         Result array with 'result' and 'redirect' keys.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        try {
            $payment_data = array(
                'paymentMethodId' => $this->id,
                'payment'         => array(
                    'method'        => 'cardPayments',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( $this->embedded_fields ) {
                $post_key     = 'adyen' === $this->processor ? 'montonio_card_payment_session_uuid' : 'montonio_card_payment_intent_uuid';
                $session_uuid = isset( $_POST[$post_key] ) ? sanitize_key( wp_unslash( $_POST[$post_key] ) ) : null;

                if ( empty( $session_uuid ) || ! WC_Montonio_Helper::is_valid_uuid( $session_uuid ) ) {
                    throw new Exception( __( 'Invalid payment reference. Please refresh the page and try again.', 'montonio-for-woocommerce' ) );
                }

                if ( 'adyen' === $this->processor ) {
                    $payment_data['sessionUuid'] = $session_uuid;
                } else {
                    $payment_data['paymentIntentUuid'] = $session_uuid;
                }
            }

            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->create_order( $order, $payment_data );

            $order->update_meta_data( '_montonio_uuid', $response->uuid );
            $order->save();

            $redirect = $this->embedded_fields ? '#confirm-session-' . $session_uuid : $response->paymentUrl;

            return array(
                'result'   => 'success',
                'redirect' => $redirect
            );
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );

            wc_add_notice( $message, 'error' );
            WC_Montonio_Logger::log( 'Error (' . $this->id . ') - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() );

            return array( 'result' => 'failure' );
        }
    }

    /**
     * Process a refund for an order.
     *
     * @param  int    $order_id The ID of the order to refund.
     * @param  float  $amount   The amount to refund (null for full refund).
     * @param  string $reason   The reason for the refund.
     * @return bool             True on success, false on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return WC_Montonio_Refund::init_refund(
            $order_id,
            $amount,
            $reason
        );
    }

    /**
     * Handle the payment callback/return from Montonio.
     *
     * @access public
     * @return void
     */
    public function get_order_response() {
        new WC_Montonio_Callbacks( true );
    }

    /**
     * Handle webhook notifications from Montonio.
     *
     * @return void
     */
    public function get_order_notification() {
        new WC_Montonio_Callbacks();
    }

    /**
     * Render the admin options/settings page.
     *
     * @return void
     */
    public function admin_options() {
        WC_Montonio_Admin_Settings_Page::render_options_page(
            $this->method_title,
            $this->generate_settings_html( array(), false ),
            $this->id
        );
    }

    /**
     * Add an admin notice to be displayed.
     *
     * @param  string $message The notice message content.
     * @param  string $class   The CSS class for the notice (e.g., 'notice-error', 'notice-success').
     * @return void
     */
    public function add_admin_notice( $message, $class ) {
        $this->admin_notices[] = array( 'message' => $message, 'class' => $class );
    }

    /**
     * Display all queued admin notices.
     *
     * @access public
     * @return void
     */
    public function display_admin_notices() {
        foreach ( $this->admin_notices as $notice ) {
            echo '<div id="message" class="' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
    }
}
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Montonio Pay Later (BNPL) Gateway
 *
 * @class       WC_Montonio_BNPL
 * @extends     WC_Payment_Gateway
 */
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

    /**
     * Constructor for the gateway.
     */
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
        $this->title       = $this->get_option( 'title', 'Pay Later' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->test_mode   = WC_Montonio_Helper::is_test_mode();
        $this->max_amount  = 2500;

        if ( 'Pay Later' === $this->title ) {
            $this->title = __( 'Pay Later', 'montonio-for-woocommerce' );
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
            'enabled'     => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Pay Later', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'       => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Pay Later', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description' => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay in multiple parts provided in co-operation with Inbank.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'min_amount'  => array(
                'title'       => __( 'Minimum cart amount', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '30',
                'description' => __( 'Display "Pay Later" payment method when the cart total is more than the specified amount.', 'montonio-for-woocommerce' ),
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
        if ( ! parent::is_available() ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported( array( 'EUR' ) ) ) {
            return false;
        }

        if ( WC()->cart ) {
            $min_amount = $this->get_option( 'min_amount' );
            $min_amount = is_numeric( $min_amount ) && $min_amount > 30 ? (float) $min_amount : 30;
            $cart_total = $this->get_order_total();

            if ( $cart_total < $min_amount ) {
                return false;
            }
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
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-bnpl-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Enqueue payment scripts and styles for the frontend.
     *
     * @return void
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        wp_enqueue_script( 'montonio-bnpl' );
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
                    'method'        => 'bnpl',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( isset( $_POST['montonio_bnpl_period'] ) ) {
                $payment_data['payment']['methodOptions']['period'] = (float) sanitize_key( wp_unslash( $_POST['montonio_bnpl_period'] ) );
            }

            // Create new Montonio API instance
            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->create_order( $order, $payment_data );

            $order->update_meta_data( '_montonio_uuid', $response->uuid );
            $order->save();

            return array(
                'result'   => 'success',
                'redirect' => $response->paymentUrl
            );
        } catch ( Exception $e ) {
            $message = WC_Montonio_Helper::get_error_message( $e->getMessage() );

            wc_add_notice( $message, 'error' );

            $order->add_order_note( __( 'Montonio: There was a problem processing the payment. Response: ', 'montonio-for-woocommerce' ) . $e->getMessage() );

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
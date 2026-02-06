<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Montonio Financing (Hire Purchase) Gateway
 *
 * @class       WC_Montonio_Hire_Purchase
 * @extends     WC_Payment_Gateway
 */
class WC_Montonio_Hire_Purchase extends WC_Payment_Gateway {

    /**
     * Notices (array)
     *
     * @var array
     */
    protected $admin_notices = array();

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $test_mode;

    /**
     * Minimum cart amount required for the payment method to be available
     *
     * @var bool
     */
    public $min_amount;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'wc_montonio_hire_purchase';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/inbank.svg';
        $this->has_fields         = false;
        $this->method_title       = __( 'Montonio Financing', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Hire purchase provided in co-operation with Inbank', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title       = $this->get_option( 'title', 'Financing' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->test_mode   = WC_Montonio_Helper::is_test_mode();
        $this->min_amount  = $this->get_option( 'min_amount', 100 );
        $this->max_amount  = 10000;

        if ( 'Financing' === $this->title ) {
            $this->title = __( 'Financing', 'montonio-for-woocommerce' );
        }

        if ( $this->test_mode ) {
            $this->description = '<strong>' . __( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ) . '</strong><br>' . __( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) . '<br>' . $this->description;
        }

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_' . $this->id, array( $this, 'get_order_response' ) );
        add_action( 'woocommerce_api_' . $this->id . '_notification', array( $this, 'get_order_notification' ) );
        add_filter( 'woocommerce_gateway_icon', array( $this, 'add_icon_class' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'display_admin_notices' ), 999 );
    }

    /**
     * Initialize gateway settings form fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'             => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Financing', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'               => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Financing', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'         => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay in 3-72 months.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'min_amount'          => array(
                'title'             => __( 'Min cart amount', 'montonio-for-woocommerce' ),
                'type'              => 'number',
                'default'           => 100,
                'description'       => __( 'The payment method will only be displayed if the cart total exceeds this amount. Minimum allowed value is 100.', 'montonio-for-woocommerce' ),
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'min'  => 100,
                    'step' => '1'
                )
            ),
            'calculator_title'    => array(
                'title'       => 'Financing calculator',
                'type'        => 'title',
                'description' => __( 'Display an interactive financing calculator that allows customers to see monthly payment breakdowns for their purchases.', 'montonio-for-woocommerce' )
            ),
            'calculator_enabled'  => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Financing Calculator', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'calculator_region'   => array(
                'title'       => __( 'Region', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'ee',
                'description' => __( 'Select the region for the calculator.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'ee' => 'Estonia',
                    'lv' => 'Latvia',
                    'lt' => 'Lithuania'
                )
            ),
            'calculator_mode'     => array(
                'title'       => __( 'Mode', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'lavender',
                'description' => __( 'The background color of the calculator.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'lavender' => 'Lavender',
                    'purple'   => 'Purple',
                    'white'    => 'White'
                )
            ),
            'calculator_template' => array(
                'title'       => __( 'Template', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'no_editable_amount',
                'description' => __( 'Whether the customer will be able to edit the loan amount in the calculator modal.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'editable_amount'    => 'Allow customers to edit loan amount',
                    'no_editable_amount' => 'Non-editable loan amount'
                )
            ),
            'calculator_hooks'    => array(
                'title'       => __( 'Hooks', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'description' => __(
                    'Set where the loan calculator appears by entering WooCommerce hooks.<br />Separate multiple hooks with commas (e.g., my_hook_1, my_hook_2).<br />See <a href="https://docs.woocommerce.com/wc-apidocs/hook-docs.html" target="_blank">WooCommerce hook docs</a> for reference.<br /><br />Or use the shortcode <code>[montonio_calculator]</code> with the optional attribute product_id="123" to display the calculator for a specific product.',
                    'montonio-for-woocommerce'
                ),
                'default'     => 'woocommerce_after_add_to_cart_button'
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
            $cart_total = $this->get_order_total();

            if ( $cart_total < (float) $this->min_amount ) {
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
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-hire-purchase-icon" src="', $icon );
        }

        return $icon;
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
                    'method'        => 'hirePurchase',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

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
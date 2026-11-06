<?php
defined( 'ABSPATH' ) || exit;

/**
 * Montonio Pay Later (BNPL) Gateway
 *
 * @class       WC_Montonio_BNPL
 * @extends     WC_Montonio_Payment_Gateway
 */
class WC_Montonio_BNPL extends WC_Montonio_Payment_Gateway {

    /**
     * Key of this gateway inside the synced `paymentMethods` response.
     *
     * @var string
     */
    protected $api_method_key = 'bnpl';

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
     * BNPL payment configuration
     *
     * @var array
     */
    public $method_config;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'wc_montonio_bnpl';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/payments/inbank.svg';
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
        $this->title         = $this->get_option( 'title', 'Pay Later' );
        $this->description   = $this->get_option( 'description' );
        $this->enabled       = $this->get_option( 'enabled' );
        $this->test_mode     = WC_Montonio_Helper::is_test_mode();
        $this->method_config = WC_Montonio_Helper::get_payment_methods( $this->api_method_key );
        $this->max_amount    = 2500;

        if ( 'Pay Later' === $this->title ) {
            $this->title = __( 'Pay Later', 'montonio-for-woocommerce' );
        }

        // Hooks
        $this->register_montonio_hooks();
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
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
                'label'       => __( 'Enable Montonio Pay Later', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'               => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Pay Later', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'         => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay in multiple parts provided in co-operation with Inbank.', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'min_amount'          => array(
                'title'       => __( 'Minimum cart amount', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '30',
                'description' => __( 'Display "Pay Later" payment method when the cart total is more than the specified amount.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'default_country'     => array(
                'title'       => __( 'Default Country', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => WC_Montonio_Helper::get_inbank_default_country(),
                'description' => __( 'The country selected first at checkout. The customer takes the loan in the selected country.', 'montonio-for-woocommerce' ),
                'options'     => WC_Montonio_Helper::get_inbank_countries()
            ),
            'hide_country_select' => array(
                'title'   => __( 'Hide Country Select', 'montonio-for-woocommerce' ),
                'label'   => __( 'Enable', 'montonio-for-woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'no'
            ),
            'preselect_country'   => array(
                'title'       => __( 'Preselect country by user data', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'disable',
                'description' => __( 'Automatically change the selected country based on customer\'s data? If unsuccessful, fallback to "Default Country".', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'disable' => __( 'Don\'t select automatically', 'montonio-for-woocommerce' ),
                    'billing' => __( 'Select based on user billing country', 'montonio-for-woocommerce' ),
                    'locale'  => __( 'Select based on site language', 'montonio-for-woocommerce' )
                )
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
            return false;
        }

        if ( empty( $this->method_config ) ) {
            return false;
        }

        if ( ! WC_Montonio_Helper::is_client_currency_supported( array( 'EUR' ) ) ) {
            return false;
        }

        if ( WC()->cart ) {
            $min_amount = $this->get_option( 'min_amount' );
            $min_amount = is_numeric( $min_amount ) && $min_amount > 30 ? (float) $min_amount : 30;
            $cart_total = $this->get_order_total();

            if ( $cart_total < $min_amount || $cart_total > $this->max_amount ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enqueue payment scripts and styles for the frontend.
     *
     * @return void
     */
    public function payment_scripts() {
        // The script loads the Montonio SDK, so only where classic checkout can show Pay Later. The Pay Later block
        // loads its own script.
        if ( 'yes' !== $this->enabled || empty( $this->method_config ) ) {
            return;
        }

        if ( ! is_checkout() || is_order_received_page() || WC_Montonio_Helper::is_checkout_block_rendering() ) {
            return;
        }

        wp_localize_script( 'montonio-bnpl', 'wc_montonio_bnpl', $this->get_widget_params() );
        wp_enqueue_script( 'montonio-bnpl' );
    }

    /**
     * Gets the Pay Later periods with the cart total each one needs.
     *
     * @since 10.5.1
     * @return array Periods keyed by the number of parts, each with a title, min and max.
     */
    public static function get_bnpl_periods() {
        return array(
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
    }

    /**
     * Gets the Pay Later periods for the Montonio SDK's widget, in classic and block checkout. The widget applies its
     * own limits for each period and country, so only the titles come from here.
     *
     * @since 10.5.1
     * @return array Periods, each with a period and a title.
     */
    public static function get_widget_periods() {
        $periods = array();

        foreach ( self::get_bnpl_periods() as $period => $bnpl_period ) {
            $periods[] = array(
                'period' => (string) $period,
                'title'  => $bnpl_period['title']
            );
        }

        return $periods;
    }

    /**
     * Gets the options for the Montonio SDK's Pay Later widget on classic checkout. The cart total and the country
     * selected first aren't here: they change with every checkout update, so payment_fields() renders them on the
     * widget container.
     *
     * @since 10.5.1
     * @return array Widget options.
     */
    private function get_widget_params() {
        $countries = WC_Montonio_Helper::get_inbank_countries();

        // The widget replaces #amount with the bare amount still needed, so the currency symbol goes around it where the
        // store's currency position puts it, like wc_price()
        $amount = sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), '#amount' );

        return array(
            'bnplOptions'          => self::get_widget_periods(),
            /* translators: %s: additional amount needed */
            'addMoreMessage'       => sprintf( __( 'Add %s more to your cart to use this payment method', 'montonio-for-woocommerce' ), $amount ),
            'overMaxAmountMessage' => __( 'Cart total exceeds the maximum limit for this payment method', 'montonio-for-woocommerce' ),
            'regionSelectLabel'    => __( 'Country', 'montonio-for-woocommerce' ),
            'regions'              => array_keys( $countries ),
            'regionNames'          => $countries,
            'hideCountrySelect'    => 'yes' === $this->get_option( 'hide_country_select' )
        );
    }

    /**
     * Gets the country the Pay Later widget selects first. WooCommerce saves the billing country the customer entered
     * before every checkout update renders the payment fields, so preselecting by billing country follows the address.
     *
     * @since 10.5.1
     * @return string The country code: EE, LV or LT.
     */
    private function get_checkout_country() {
        return WC_Montonio_Helper::get_inbank_checkout_country(
            $this->get_option( 'default_country' ),
            $this->get_option( 'preselect_country' ),
            WC()->customer ? WC()->customer->get_billing_country() : ''
        );
    }

    /**
     * Output the payment method fields on the checkout page. The Montonio SDK's Pay Later widget replaces the periods
     * rendered here, which stay as the fallback when the SDK doesn't load.
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

        $cart_total      = WC()->cart ? $this->get_order_total() : 0;
        $country         = $this->get_checkout_country();
        $count           = 0;
        $selected_period = 1;

        echo '<div id="montonio-bnpl-widget-container" class="montonio-bnpl-form" data-grand-total="' . esc_attr( wc_format_decimal( $cart_total, 2 ) ) . '" data-default-region="' . esc_attr( $country ) . '">';
        echo '<div class="montonio-bnpl-items">';
        foreach ( self::get_bnpl_periods() as $key => $value ) {
            $class    = '';
            $subtitle = '';

            if ( $cart_total < $value['min'] ) {
                $class = ' montonio-bnpl-item--disabled';
                /* translators: additional amount needed */
                $subtitle = '<div class="montonio-bnpl-item-subtitle">' . sprintf( __( 'Add %s more to your cart to use this payment method', 'montonio-for-woocommerce' ), wc_price( $value['min'] - $cart_total ) ) . '</div>';
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
        echo '<input type="hidden" name="montonio_bnpl_preferred_country" value="' . esc_attr( $country ) . '">';
        echo '</div>';

        do_action( 'wc_montonio_after_payment_desc', $this->id );
    }

    /**
     * Rejects the order when no period is selected. The widget leaves the period empty when none fits the cart total in
     * the selected country, and Montonio would refuse the order.
     *
     * @since 10.5.1
     * @return bool True when a period was chosen or none was posted.
     */
    public function validate_fields() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce first
        if ( isset( $_POST['montonio_bnpl_period'] ) && '' === sanitize_key( wp_unslash( $_POST['montonio_bnpl_period'] ) ) ) {
            // Same text as the Pay Later block shows for an empty period
            wc_add_notice( __( 'Pay Later is not available for this cart total. Please choose another payment method.', 'montonio-for-woocommerce' ), 'error' );

            return false;
        }

        return true;
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
                    'method'        => $this->api_method_key,
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => null
                )
            );

            if ( isset( $_POST['montonio_bnpl_period'] ) ) {
                $payment_data['payment']['methodOptions']['period'] = (float) sanitize_key( wp_unslash( $_POST['montonio_bnpl_period'] ) );
            }

            if ( ! empty( $_POST['montonio_bnpl_preferred_country'] ) ) {
                $payment_data['payment']['methodOptions']['preferredCountry'] = sanitize_text_field( wp_unslash( $_POST['montonio_bnpl_preferred_country'] ) );
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
}
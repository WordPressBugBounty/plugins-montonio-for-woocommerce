<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Montonio Bank Payments Gateway
 *
 * @class       WC_Montonio_Payments
 * @extends     WC_Payment_Gateway
 */
class WC_Montonio_Payments extends WC_Payment_Gateway {

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
     * Payment Handle Style
     *
     * @var string
     */
    public $handle_style;

    /**
     * Default Eurozone country
     *
     * @var string
     */
    public $default_country;

    /**
     * Should we hide country dropdown?
     *
     * @var bool
     */
    public $hide_country_select;

    /**
     * Should we preselect country by user data?
     *
     * @var bool
     */
    public $preselect_country;

    /**
     * Payment configuration
     *
     * @var array
     */
    public $method_config;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'wc_montonio_payments';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logomark.png';
        $this->has_fields         = true;
        $this->method_title       = __( 'Montonio Bank Payments', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Allows bank payments via the Montonio Payment Initiation Service.', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title               = $this->get_option( 'title', 'Pay with your bank' );
        $this->description         = $this->get_option( 'description' );
        $this->enabled             = $this->get_option( 'enabled' );
        $this->test_mode           = WC_Montonio_Helper::is_test_mode();
        $this->handle_style        = $this->get_option( 'handle_style' );
        $this->default_country     = $this->get_option( 'default_country', 'EE' );
        $this->hide_country_select = $this->get_option( 'hide_country_select' );
        $this->preselect_country   = $this->get_option( 'preselect_country' );
        $this->method_config       = WC_Montonio_Helper::get_payment_methods( 'paymentInitiation' );

        if ( 'Pay with your bank' === $this->title ) {
            $this->title = __( 'Pay with your bank', 'montonio-for-woocommerce' );
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
            'enabled'                    => array(
                'title'       => __( 'Enable/Disable', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable Montonio Bank Payments', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'                      => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Pay with your bank', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description'                => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => '',
                'description' => __( 'Payment method description which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'handle_style'               => array(
                'title'       => __( 'Payment Handle Style', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'grid',
                'description' => __( 'This controls how to display bank logos at checkout', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'grid'   => 'Display bank logos in grid',
                    'list'   => 'Display bank logos in list',
                    'hidden' => 'Hide bank logos'
                ),
                'desc_tip'    => true
            ),
            'default_country'            => array(
                'title'       => __( 'Default Eurozone Country', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'EE',
                'description' => __( 'The country whose banks to show first at checkout when using Euro (€) as currency.<br/>When using a different currency, e.g Polish Zloty (PLN), this option will be disregarded and the correct currency will be shown.', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'EE' => 'Estonia',
                    'FI' => 'Finland',
                    'LV' => 'Latvia',
                    'LT' => 'Lithuania',
                    'PL' => 'Poland'
                )
            ),
            'hide_country_select'        => array(
                'title'   => __( 'Hide Country Select', 'montonio-for-woocommerce' ),
                'label'   => __( 'Enable', 'montonio-for-woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'no'
            ),
            'preselect_country'          => array(
                'title'       => __( 'Preselect country by user data', 'montonio-for-woocommerce' ),
                'type'        => 'select',
                'class'       => 'wc-enhanced-select',
                'default'     => 'disable',
                'description' => __( 'Automatically change the selected bank country based on customer\'s data? If unsuccessful, fallback to "Default Eurozone Country".', 'montonio-for-woocommerce' ),
                'options'     => array(
                    'disable' => __( 'Don\'t select automatically', 'montonio-for-woocommerce' ),
                    'billing' => __( 'Select based on user billing country', 'montonio-for-woocommerce' ),
                    'locale'  => __( 'Select based on site language', 'montonio-for-woocommerce' )
                )
            ),
            'custom_payment_description' => array(
                'title'       => __( 'Use custom payment description', 'montonio-for-woocommerce' ),
                'label'       => __( 'Enable', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'default'     => 'no',
                'description' => __( 'This allows you to customize the payment description that will be relayed to the bank\'s payment order. If not enabled, defaults to order ID.', 'montonio-for-woocommerce' )
            ),
            'payment_description'        => array(
                'title'       => __( 'Custom payment description', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Payment for order {order_number}', 'montonio-for-woocommerce' ),
                'description' => __( 'Available placeholders: {order_number}', 'montonio-for-woocommerce' )
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

        if ( ! WC_Montonio_Helper::is_client_currency_supported() ) {
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
            return str_replace( 'src="', 'class="montonio-payment-method-icon montonio-pis-icon" src="', $icon );
        }

        return $icon;
    }

    /**
     * Enqueue payment scripts and styles for the frontend.
     *
     * @return void
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }

        wp_enqueue_script( 'montonio-pis' );
    }

    /**
     * Output the payment method fields on the checkout page.
     *
     * @return void
     */
    public function payment_fields() {
        $currency        = WC_Montonio_Helper::get_currency();
        $description     = $this->get_description();

        $available_countries = array(
            'EE' => __( 'Estonia', 'montonio-for-woocommerce' ),
            'FI' => __( 'Finland', 'montonio-for-woocommerce' ),
            'LV' => __( 'Latvia', 'montonio-for-woocommerce' ),
            'LT' => __( 'Lithuania', 'montonio-for-woocommerce' ),
            'PL' => __( 'Poland', 'montonio-for-woocommerce' )
        );

        if ( 'locale' === $this->preselect_country || 'billing' === $this->preselect_country ) {
            if ( 'billing' === $this->preselect_country && WC()->customer && WC()->customer->get_billing_country() ) {
                $user_country = WC()->customer->get_billing_country();
            } else {
                $user_country = strtoupper( WC_Montonio_Helper::get_locale() );
            }

            if ( 'EE' === $user_country ) {
                $this->default_country = 'EE';
            } else {
                foreach ( $available_countries as $locale => $country_name ) {
                    if ( $locale === $user_country ) {
                        $this->default_country = $user_country;
                    }
                }
            }
        }

        if ( 'PLN' === $currency ) {
            $this->default_country = 'PL';
        }

        do_action( 'wc_montonio_before_payment_desc', $this->id );

        if ( $this->test_mode ) {
            /* translators: 1) notice that test mode is enabled 2) explanation of test mode */
            printf( '<strong>%1$s</strong><br>%2$s<br>', esc_html__( 'TEST MODE ENABLED!', 'montonio-for-woocommerce' ), esc_html__( 'When test mode is enabled, payment providers do not process payments.', 'montonio-for-woocommerce' ) );
        }

        if ( ! empty( $description ) ) {
            echo esc_html( apply_filters( 'wc_montonio_description', wp_kses_post( $description ), $this->id ) );
        }

        do_action( 'wc_montonio_before_bank_list', $this->id );

        if ( ! empty( $this->method_config['setup'] ) && $this->handle_style != 'hidden' ) {
            echo '<div class="montonio-bank-payments-form">';

            $bank_list = apply_filters( 'wc_montonio_bank_list', $this->method_config['setup'] );

            // Filter out countries that doesn't support current currency
            $countries = array_filter( $bank_list, function ( $country ) use ( $currency ) {
                return array_search( $currency, $country['supportedCurrencies'] ) !== false;
            } );

            if ( 'yes' !== $this->hide_country_select && count( $countries ) > 1 ) {
                echo '<select class="montonio-payments-country-dropdown" name="montonio_payments_preferred_country">';
                foreach ( $countries as $r => $list ) {
                    echo '<option ' . ( $r === $this->default_country ? 'selected="selected"' : '' ) . ' value="' . esc_attr( $r ) . '">' . esc_html( $available_countries[$r] ) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="hidden" name="montonio_payments_preferred_country" value="' . esc_attr( $this->default_country ) . '">';
            }

            echo '<div id="montonio-payments-description" class="montonio-bank-items montonio-bank-items--' . esc_attr( $this->handle_style ) . '">';

            $default_country = array_keys( $countries )[0];
            foreach ( $countries as $country => $value ) {
                if ( $country === $this->default_country ) {
                    $default_country = $country;
                }
            }

            foreach ( $countries as $r => $list ) {
                foreach ( $list['paymentMethods'] as $key => $value ) {
                    if ( in_array( $currency, $value['supportedCurrencies'] ) ) {
                        echo '<div class="bank-region-' . esc_attr( $r ) . ' montonio-bank-item' . ( $r === $default_country ? '' : ' montonio-bank-item--hidden' ) . '" data-bank="' . esc_attr( $value['code'] ) . '"><img class="montonio-bank-item-img" src="' . esc_url( $value['logoUrl'] ) . '"  alt="' . esc_attr( $value['name'] ) . '"></div>';
                    }
                }
            }

            echo '</div>';
            echo '<input type="hidden" name="montonio_payments_preselected_bank" id="montonio_payments_preselected_bank">';
            echo '</div>';
        } else {
            echo '<input type="hidden" name="montonio_payments_preferred_country" value="' . esc_attr( $this->default_country ) . '">';
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
                    'method'        => 'paymentInitiation',
                    'methodDisplay' => $this->get_title(),
                    'methodOptions' => array(
                        'paymentReference'   => (string) apply_filters( 'wc_montonio_payment_reference', '', $this->id ),
                        'paymentDescription' => (string) apply_filters( 'wc_montonio_payment_description', $this->get_payment_description( $order ), $order->get_id() ),
                        'preferredCountry'   => (string) $this->default_country
                    )
                )
            );

            if ( isset( $_POST['montonio_payments_preselected_bank'] ) ) {
                $payment_data['payment']['methodOptions']['preferredProvider'] = sanitize_text_field( wp_unslash( $_POST['montonio_payments_preselected_bank'] ) );
            }

            if ( isset( $_POST['montonio_payments_preferred_country'] ) ) {
                $payment_data['payment']['methodOptions']['preferredCountry'] = sanitize_text_field( wp_unslash( $_POST['montonio_payments_preferred_country'] ) );
            }

            // Create new Montonio API instance
            $montonio_api = new WC_Montonio_API();
            $response     = $montonio_api->create_order( $order, $payment_data );

            $order->update_meta_data( '_montonio_uuid', $response->uuid );
            $order->save();

            // Return response after which redirect to Montonio Payments will happen
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
     * Retrieve the payment description for the order.
     *
     * @param  WC_Order $order The WooCommerce order object.
     * @return string          The payment description.
     */
    public function get_payment_description( $order ) {
        $order_number       = apply_filters( 'wc_montonio_merchant_reference_display', $order->get_order_number(), $order );
        $custom_description = $this->get_option( 'payment_description' );

        if ( 'yes' === $this->get_option( 'custom_payment_description' ) && ! empty( $custom_description ) ) {
            return str_replace( '{order_number}', $order_number, $custom_description );
        }

        return $order_number;
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
            echo '<div class="notice notice-' . esc_attr( $notice['class'] ) . '">';
            echo '	<p>' . wp_kses_post( $notice['message'] ) . '</p>';
            echo '</div>';
        }
    }
}
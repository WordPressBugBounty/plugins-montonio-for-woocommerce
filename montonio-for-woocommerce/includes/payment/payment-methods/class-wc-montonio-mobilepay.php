<?php
defined( 'ABSPATH' ) || exit;

/**
 * Montonio MobilePay Gateway
 *
 * A standalone payment method backed by the `mobilePay` entry in the synced
 * Montonio payment methods. It has its own settings and enabled state, always
 * redirects (no embedded fields), and is only available in Finland.
 *
 * @class       WC_Montonio_Mobilepay
 * @extends     WC_Payment_Gateway
 */
class WC_Montonio_Mobilepay extends WC_Montonio_Payment_Gateway {

    /**
     * Key of this gateway inside the synced `paymentMethods` response.
     *
     * @var string
     */
    protected $api_method_key = 'mobilePay';

    /**
     * MobilePay payment configuration
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
     * @var bool
     */
    public $is_required;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'wc_montonio_mobilepay';
        $this->icon               = WC_MONTONIO_PLUGIN_URL . '/assets/images/mobilepay.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Montonio MobilePay', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Allows MobilePay payments via Montonio', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get settings
        $this->title         = $this->get_option( 'title', 'MobilePay' );
        $this->description   = $this->get_option( 'description' );
        $this->enabled       = $this->get_option( 'enabled' );
        $this->test_mode     = WC_Montonio_Helper::is_test_mode();
        $this->method_config = WC_Montonio_Helper::get_payment_methods( $this->api_method_key );
        $this->processor     = $this->method_config['processor'] ?? 'adyen';
        $this->is_required   = $this->method_config['requiredToBeEnabled'] ?? false;

        if ( 'MobilePay' === $this->title ) {
            $this->title = __( 'MobilePay', 'montonio-for-woocommerce' );
        }

        // Hooks
        $this->register_montonio_hooks();
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
                'label'       => __( 'Enable Montonio MobilePay', 'montonio-for-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'       => array(
                'title'       => __( 'Title', 'montonio-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'MobilePay', 'montonio-for-woocommerce' ),
                'description' => __( 'Payment method title which the user sees during checkout.', 'montonio-for-woocommerce' ),
                'desc_tip'    => true
            ),
            'description' => array(
                'title'       => __( 'Description', 'montonio-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width: 400px;',
                'default'     => __( 'Pay with MobilePay via Montonio.', 'montonio-for-woocommerce' ),
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

        $billing_country  = ( WC()->customer ) ? WC()->customer->get_billing_country() : '';
        $shipping_country = ( WC()->customer ) ? WC()->customer->get_shipping_country() : '';

        $is_finnish = 'FI' === $billing_country
            || 'FI' === $shipping_country
            || $this->is_finnish_timezone()
            || $this->is_finnish_language()
            || $this->is_finnish_account_country()
            || $this->is_finnish_store()
            || $this->is_finnish_ip_country();

        if ( ! $is_finnish ) {
            return false;
        }

        return true;
    }

    /**
     * Whether the active language is Finnish.
     *
     * Considers, in order, the WPML/Polylang current language (via the
     * `wpml_current_language` filter, which Polylang also implements), the
     * WordPress locale (e.g. "fi" or "fi_FI"), and the browser's preferred
     * language from the Accept-Language header.
     *
     * @return bool
     */
    protected function is_finnish_language() {
        $current_language = apply_filters( 'wpml_current_language', null );

        if ( ! empty( $current_language ) && 'fi' === strtolower( $current_language ) ) {
            return true;
        }

        if ( 0 === strpos( strtolower( get_locale() ), 'fi' ) ) {
            return true;
        }

        return $this->is_finnish_browser_language();
    }

    /**
     * Whether the browser's preferred language (Accept-Language header) is Finnish.
     *
     * @return bool
     */
    protected function is_finnish_browser_language() {
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return false;
        }

        $accept_language = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) );

        // Match "fi" as a standalone language tag (e.g. "fi", "fi-FI"), not a substring of other tags.
        return 1 === preg_match( '/(^|,)\s*fi\b/', $accept_language );
    }

    /**
     * Whether the store is configured for Finland.
     *
     * Checks the WooCommerce base country and, as a fallback, the default
     * customer location country.
     *
     * @return bool
     */
    protected function is_finnish_store() {
        $base_country = ( WC()->countries ) ? WC()->countries->get_base_country() : '';

        if ( 'FI' === $base_country ) {
            return true;
        }

        $default_location = wc_get_customer_default_location();
        $default_country  = isset( $default_location['country'] ) ? $default_location['country'] : '';

        return 'FI' === $default_country;
    }

    /**
     * Whether the connected Montonio account is Finnish.
     *
     * Checks the synced store's `country` and `businessCountry` (from the
     * Montonio Partner System); either being Finland makes the method eligible.
     *
     * @return bool
     */
    protected function is_finnish_account_country() {
        $store_details = WC_Montonio_Helper::get_store_details();

        if ( empty( $store_details ) ) {
            return false;
        }

        return 'FI' === ( $store_details['country'] ?? '' ) || 'FI' === ( $store_details['businessCountry'] ?? '' );
    }

    /**
     * Whether the visitor's geo-IP country is Finland.
     *
     * Uses WooCommerce's geolocation, restricted to request headers only
     * (MM_COUNTRY_CODE, GEOIP_COUNTRY_CODE, CF-IPCountry, X-Country-Code) plus
     * the local MaxMind database when configured. The API and external-IP
     * fallbacks are disabled so this never makes a blocking remote request and
     * requires no MaxMind license key.
     *
     * @return bool
     */
    protected function is_finnish_ip_country() {
        if ( ! class_exists( 'WC_Geolocation' ) ) {
            return false;
        }

        $location = WC_Geolocation::geolocate_ip( '', false, false );

        return ! empty( $location['country'] ) && 'FI' === $location['country'];
    }

    /**
     * Whether the visitor's browser timezone is Finnish.
     *
     * The IANA timezone is only available client-side, so it is captured by
     * `assets/js/montonio-timezone.js` into the `montonio_tz` cookie. This will
     * be empty on the very first page view (before the script has run), in
     * which case the other Finnish signals govern.
     *
     * Finland uses Europe/Helsinki; the autonomous Åland Islands use the
     * Europe/Mariehamn zone (a link to Helsinki), so both are accepted.
     *
     * @return bool
     */
    protected function is_finnish_timezone() {
        if ( empty( $_COOKIE['montonio_tz'] ) ) {
            return false;
        }

        $timezone = sanitize_text_field( wp_unslash( $_COOKIE['montonio_tz'] ) );

        return in_array( $timezone, array( 'Europe/Helsinki', 'Europe/Mariehamn' ), true );
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
                    'method'        => $this->api_method_key,
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
            WC_Montonio_Logger::log( 'Error (' . $this->id . ') - Order ID: ' . $order_id . ' Response: ' . $e->getMessage() );

            return array( 'result' => 'failure' );
        }
    }
}

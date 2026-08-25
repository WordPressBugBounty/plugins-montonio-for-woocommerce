<?php
defined( 'ABSPATH' ) || exit;

/**
 * Montonio MobilePay Gateway
 *
 * A standalone payment method backed by the `mobilePay` entry in the synced
 * Montonio payment methods. It has its own settings and enabled state, always
 * redirects (no embedded fields), and is only available in the countries where
 * MobilePay is offered (Finland and Denmark).
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
     * Countries where MobilePay is offered.
     *
     * @var string[]
     */
    protected $supported_countries = array( 'FI', 'DK' );

    /**
     * Language subtags of the MobilePay countries.
     *
     * @var string[]
     */
    protected $supported_languages = array( 'fi', 'da' );

    /**
     * IANA timezones of the MobilePay countries.
     *
     * Finland uses Europe/Helsinki; the autonomous Åland Islands use the
     * Europe/Mariehamn zone (a link to Helsinki), so both are accepted.
     * Denmark uses Europe/Copenhagen.
     *
     * @var string[]
     */
    protected $supported_timezones = array( 'Europe/Helsinki', 'Europe/Mariehamn', 'Europe/Copenhagen' );

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

        $is_supported = $this->is_supported_country( $billing_country )
            || $this->is_supported_country( $shipping_country )
            || $this->is_supported_timezone()
            || $this->is_supported_language()
            || $this->is_supported_account_country()
            || $this->is_supported_store()
            || $this->is_supported_ip_country();

        if ( ! $is_supported ) {
            return false;
        }

        return true;
    }

    /**
     * Whether a country code is one where MobilePay is offered.
     *
     * @param  string $country Two-letter country code.
     * @return bool
     */
    protected function is_supported_country( $country ) {
        return in_array( strtoupper( (string) $country ), $this->supported_countries, true );
    }

    /**
     * Extract the language subtag from a locale or language tag.
     *
     * For example "fi", "fi_FI" and "da-DK" all yield the bare subtag ("fi",
     * "fi", "da"), so tags can be compared without matching unrelated locales
     * that merely share a prefix (e.g. "fil" for Filipino).
     *
     * @param  string $language Locale or language tag.
     * @return string Lowercased language subtag.
     */
    protected function get_language_subtag( $language ) {
        return strtolower( preg_replace( '/[_-].*$/', '', (string) $language ) );
    }

    /**
     * Whether the active language belongs to a MobilePay country.
     *
     * Considers, in order, the WPML/Polylang current language (via the
     * `wpml_current_language` filter, which Polylang also implements), the
     * WordPress locale (e.g. "fi", "fi_FI" or "da_DK"), and the browser's
     * preferred language from the Accept-Language header.
     *
     * @return bool
     */
    protected function is_supported_language() {
        $current_language = apply_filters( 'wpml_current_language', null );

        if ( in_array( $this->get_language_subtag( $current_language ), $this->supported_languages, true ) ) {
            return true;
        }

        if ( in_array( $this->get_language_subtag( get_locale() ), $this->supported_languages, true ) ) {
            return true;
        }

        return $this->is_supported_browser_language();
    }

    /**
     * Whether the browser's preferred language (Accept-Language header) belongs
     * to a MobilePay country.
     *
     * @return bool
     */
    protected function is_supported_browser_language() {
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return false;
        }

        $accept_language = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) );

        // Match a supported subtag as a standalone language tag (e.g. "da", "da-DK"), not a substring of other tags.
        $pattern = '/(^|,)\s*(' . implode( '|', array_map( 'preg_quote', $this->supported_languages ) ) . ')\b/';

        return 1 === preg_match( $pattern, $accept_language );
    }

    /**
     * Whether the store is configured for a MobilePay country.
     *
     * Checks the WooCommerce base country and, as a fallback, the default
     * customer location country.
     *
     * @return bool
     */
    protected function is_supported_store() {
        $base_country = ( WC()->countries ) ? WC()->countries->get_base_country() : '';

        if ( $this->is_supported_country( $base_country ) ) {
            return true;
        }

        $default_location = wc_get_customer_default_location();
        $default_country  = isset( $default_location['country'] ) ? $default_location['country'] : '';

        return $this->is_supported_country( $default_country );
    }

    /**
     * Whether the connected Montonio account belongs to a MobilePay country.
     *
     * Checks the synced store's `country` and `businessCountry` (from the
     * Montonio Partner System); either being a MobilePay country makes the
     * method eligible.
     *
     * @return bool
     */
    protected function is_supported_account_country() {
        $store_details = WC_Montonio_Helper::get_store_details();

        if ( empty( $store_details ) ) {
            return false;
        }

        return $this->is_supported_country( $store_details['country'] ?? '' ) || $this->is_supported_country( $store_details['businessCountry'] ?? '' );
    }

    /**
     * Whether the visitor's geo-IP country is a MobilePay country.
     *
     * Uses WooCommerce's geolocation, restricted to request headers only
     * (MM_COUNTRY_CODE, GEOIP_COUNTRY_CODE, CF-IPCountry, X-Country-Code) plus
     * the local MaxMind database when configured. The API and external-IP
     * fallbacks are disabled so this never makes a blocking remote request and
     * requires no MaxMind license key.
     *
     * @return bool
     */
    protected function is_supported_ip_country() {
        if ( ! class_exists( 'WC_Geolocation' ) ) {
            return false;
        }

        $location = WC_Geolocation::geolocate_ip( '', false, false );

        return ! empty( $location['country'] ) && $this->is_supported_country( $location['country'] );
    }

    /**
     * Whether the visitor's browser timezone belongs to a MobilePay country.
     *
     * The IANA timezone is only available client-side, so it is captured by
     * `assets/js/montonio-timezone.js` into the `montonio_tz` cookie. This will
     * be empty on the very first page view (before the script has run), in
     * which case the other country signals govern.
     *
     * @return bool
     */
    protected function is_supported_timezone() {
        if ( empty( $_COOKIE['montonio_tz'] ) ) {
            return false;
        }

        $timezone = sanitize_text_field( wp_unslash( $_COOKIE['montonio_tz'] ) );

        return in_array( $timezone, $this->supported_timezones, true );
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

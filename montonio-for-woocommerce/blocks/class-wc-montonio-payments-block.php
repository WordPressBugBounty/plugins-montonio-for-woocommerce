<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_Montonio_Payments_Block class.
 *
 * Handles the Payments method block for Montonio.
 *
 * @since 7.1.0
 */
class WC_Montonio_Payments_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_payments' );
    }

    /**
     * Get the default country based on available countries and settings.
     *
     * @since 7.1.0
     * @param array $available_countries List of available countries.
     * @return string The default country code.
     */
    public function get_default_country( $available_countries ) {
        $default_country   = $this->get_setting( 'default_country', 'EE' );
        $preselect_country = $this->get_setting( 'preselect_country' );

        if ( 'locale' === $preselect_country ) {
            $user_country = strtoupper( WC_Montonio_Helper::get_locale() );

            if ( 'ET' === $user_country ) {
                $user_country = 'EE';
            }

            if ( array_key_exists( $user_country, $available_countries ) ) {
                $default_country = $user_country;
            }
        }

        return $default_country;
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @since 7.1.0
     * @return array Payment method data including various settings and configurations.
     */
    public function get_payment_method_data() {
        $available_countries = array(
            'EE' => __( 'Estonia', 'montonio-for-woocommerce' ),
            'FI' => __( 'Finland', 'montonio-for-woocommerce' ),
            'LV' => __( 'Latvia', 'montonio-for-woocommerce' ),
            'LT' => __( 'Lithuania', 'montonio-for-woocommerce' ),
            'PL' => __( 'Poland', 'montonio-for-woocommerce' )
        );

        $hide_country_select = $this->get_setting( 'hide_country_select' );
        $currency            = WC_Montonio_Helper::get_currency();
        $default_country     = $this->get_default_country( $available_countries );
        $preselect_country   = $this->get_setting( 'preselect_country' );
        $payment_methods     = WC_Montonio_Helper::get_payment_methods( 'paymentInitiation' );
        $setup_data          = $payment_methods['setup'] ?? null;

        if ( $currency == 'PLN' ) {
            $default_country = 'PL';
        }

        $title = $this->get_setting( 'title' );

        if ( 'Pay with your bank' === $title ) {
            $title = __( 'Pay with your bank', 'montonio-for-woocommerce' );
        }

        return array(
            'title'              => $title,
            'description'        => $this->get_setting( 'description' ),
            'iconurl'            => apply_filters( 'wc_montonio_payments_block_logo', WC_MONTONIO_PLUGIN_URL . '/assets/images/montonio-logomark.png' ),
            'sandboxMode'        => WC_Montonio_Helper::is_test_mode(),
            'accessKey'          => WC_Montonio_Helper::get_api_keys()['access_key'],
            'storeSetupData'     => json_encode( $setup_data ),
            'currency'           => $currency,
            'preselectCountry'   => $preselect_country,
            'availableCountries' => array_keys( $available_countries ),
            'defaultRegion'      => $default_country,
            'regions'            => ( $hide_country_select == 'yes' ) ? array( $default_country ) : null,
            'regionNames'        => $available_countries,
            'handleStyle'        => $this->get_setting( 'handle_style' )
        );
    }
}
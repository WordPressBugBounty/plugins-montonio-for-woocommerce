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

        if ( $preselect_country == 'locale' ) {
            $user_country = strtoupper( WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) ) );

            if ( $user_country === 'ET' ) {
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
            'PL' => __( 'Poland', 'montonio-for-woocommerce' ),
            'DE' => __( 'Germany', 'montonio-for-woocommerce' )
        );

        $sandbox_mode        = $this->get_setting( 'sandbox_mode', 'no' );
        $api_keys            = WC_Montonio_Helper::get_api_keys( $sandbox_mode );
        $hide_country_select = $this->get_setting( 'hide_country_select' );
        $currency            = WC_Montonio_Helper::get_currency();
        $default_country     = $this->get_default_country( $available_countries );
        $preselect_country   = $this->get_setting( 'preselect_country' );

        if ( $this->get_setting( 'bank_list_fetch_datetime' ) < time() - 86400 ) {
            try {
                $montonio_api = new WC_Montonio_API( $sandbox_mode );
                $response     = json_decode( $montonio_api->fetch_payment_methods() );

                if ( ! isset( $response->paymentMethods->paymentInitiation ) ) {
                    throw new Exception( __( 'PIS not enabled in partner system', 'montonio-for-woocommerce' ) );
                }

                $this->settings['bank_list']                = json_encode( $response->paymentMethods->paymentInitiation->setup );
                $this->settings['bank_list_fetch_datetime'] = time();
                update_option( 'woocommerce_wc_montonio_payments_settings', $this->settings );
            } catch ( Exception $e ) {
                WC_Montonio_Logger::log( 'Bank list sync failed: ' . $e->getMessage() );
            }
        }

        $bank_list = $this->get_setting( 'bank_list' );

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
            'sandboxMode'        => $sandbox_mode,
            'accessKey'          => $api_keys['access_key'],
            'storeSetupData'     => $bank_list,
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
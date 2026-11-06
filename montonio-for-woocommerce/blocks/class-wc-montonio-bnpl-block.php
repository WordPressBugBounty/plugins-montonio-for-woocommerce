<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_Montonio_BNPL_Block class.
 *
 * Handles the Buy Now Pay Later payment method block for Montonio.
 *
 * @since 7.1.0
 */
class WC_Montonio_BNPL_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_bnpl' );
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @since 7.1.0
     * @return array Payment method data.
     */
    public function get_payment_method_data() {
        $available_countries = WC_Montonio_Helper::get_inbank_countries();
        $default_country     = WC_Montonio_Helper::get_inbank_checkout_country( $this->get_setting( 'default_country' ), $this->get_setting( 'preselect_country' ) );
        $title               = $this->get_setting( 'title' );

        if ( 'Pay Later' === $title ) {
            $title = __( 'Pay Later', 'montonio-for-woocommerce' );
        }

        return array(
            'title'              => $title,
            'description'        => $this->get_setting( 'description' ),
            'iconurl'            => WC_MONTONIO_PLUGIN_URL . '/assets/images/payments/inbank.svg',
            'sandboxMode'        => WC_Montonio_Helper::is_test_mode(),
            'preselectCountry'   => $this->get_setting( 'preselect_country' ),
            'availableCountries' => array_keys( $available_countries ),
            'defaultRegion'      => $default_country,
            'regions'            => array_keys( $available_countries ),
            'regionNames'        => $available_countries,
            'regionSelectLabel'  => __( 'Country', 'montonio-for-woocommerce' ),
            'bnplOptions'        => WC_Montonio_BNPL::get_widget_periods(),
            // The block narrows the regions down to the country it selects first, which can be the billing country
            'hideCountrySelect'  => 'yes' === $this->get_setting( 'hide_country_select' )
        );
    }
}
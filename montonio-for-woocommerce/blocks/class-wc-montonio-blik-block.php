<?php
defined('ABSPATH') || exit;

/**
 * WC_Montonio_Blik_Block class.
 *
 * Handles the Blik payment method block for Montonio.
 *
 * @since 7.1.0
 */
class WC_Montonio_Blik_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_blik' );
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @since 7.1.0
     * @return array Payment method data.
     */
    public function get_payment_method_data() {
        $sandbox_mode    = $this->get_setting( 'sandbox_mode', 'no' );
        $locale          = WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) );
        $inline_checkout = $this->get_setting( 'blik_in_checkout', 'no' );

        return array(
            'title'          => __( $this->get_setting( 'title' ), 'montonio-for-woocommerce' ),
            'description'    => $this->get_setting( 'description' ),
            'iconurl'        => apply_filters( 'wc_montonio_blik_block_logo', 'https://public.montonio.com/images/logos/blik-logo.png' ),
            'sandboxMode'    => $sandbox_mode,
            'locale'         => $locale,
            'inlineCheckout' => $inline_checkout
        );
    }
}
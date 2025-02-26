<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_Montonio_Card_Block class.
 *
 * Handles the Cards payment method block for Montonio.
 *
 * @since 7.1.0
 */
class WC_Montonio_Card_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_card' );
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
        $inline_checkout = $this->get_setting( 'inline_checkout', 'no' );
        $icon            = $inline_checkout === 'yes' ? 'https://public.montonio.com/images/logos/visa-mc.png' : 'https://public.montonio.com/images/logos/visa-mc-ap-gp.png';
        $icon            = apply_filters( 'wc_montonio_card_block_logo', $icon );

        return array(
            'title'          => __( $this->get_setting( 'title' ), 'montonio-for-woocommerce' ),
            'description'    => $this->get_setting( 'description' ),
            'iconurl'        => $icon,
            'sandboxMode'    => $sandbox_mode,
            'locale'         => $locale,
            'inlineCheckout' => $inline_checkout
        );
    }
}
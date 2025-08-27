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
        $test_mode       = $this->get_setting( 'test_mode', 'no' );
        $processor       = $this->get_setting( 'processor' );
        $inline_checkout = $this->get_setting( 'inline_checkout', 'no' );
        $icon            = $inline_checkout === 'yes' && $processor !== 'adyen' ? WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc.png' : WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc-ap-gp.png';
        $locale          = WC_Montonio_Helper::get_locale( apply_filters( 'wpml_current_language', get_locale() ) );

        if ( ! in_array( $locale, array( 'en', 'et', 'fi', 'lt', 'lv', 'pl', 'ru' ) ) ) {
            $locale = 'en';
        }

        $title = $this->get_setting( 'title' );

        if ( 'Card Payment' === $title ) {
            $title = __( 'Card Payment', 'montonio-for-woocommerce' );
        }

        return array(
            'title'          => $title,
            'description'    => $this->get_setting( 'description' ),
            'iconurl'        => $icon,
            'sandboxMode'    => $test_mode,
            'locale'         => $locale,
            'inlineCheckout' => $inline_checkout,
            'nonce'          => wp_create_nonce( 'montonio_embedded_checkout_nonce' )
        );
    }
}
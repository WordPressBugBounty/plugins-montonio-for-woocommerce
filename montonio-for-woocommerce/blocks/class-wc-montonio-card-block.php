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
     * Checks if the payment method is active or not.
     *
     * @since 9.3.3
     * @return boolean
     */
    public function is_active() {
        return 'yes' === $this->get_setting( 'enabled' ) || WC_Montonio_Helper::is_card_payment_required();
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @since 7.1.0
     * @return array Payment method data.
     */
    public function get_payment_method_data() {
        $config          = WC_Montonio_Helper::get_payment_methods( 'cardPayments' );
        $processor       = $config['processor'] ?? 'stripe';
        $inline_checkout = $this->get_setting( 'inline_checkout', 'no' );
        $icon            = 'yes' === $inline_checkout && 'adyen' !== $processor ? WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc.png' : WC_MONTONIO_PLUGIN_URL . '/assets/images/visa-mc-ap-gp.png';
        $locale          = WC_Montonio_Helper::get_locale();

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
            'sandboxMode'    => WC_Montonio_Helper::is_test_mode(),
            'locale'         => $locale,
            'inlineCheckout' => $inline_checkout,
            'nonce'          => wp_create_nonce( 'montonio_embedded_checkout_nonce' )
        );
    }
}
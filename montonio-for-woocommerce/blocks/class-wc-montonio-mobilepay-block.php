<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_Montonio_Mobilepay_Block class.
 *
 * Handles the MobilePay payment method block for Montonio. MobilePay is a
 * standalone payment method with its own settings and enabled state.
 */
class WC_Montonio_Mobilepay_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_mobilepay' );
    }

    /**
     * Checks if the payment method is active or not.
     *
     * @return boolean
     */
    public function is_active() {
        return 'yes' === $this->get_setting( 'enabled' ) || WC_Montonio_Helper::is_payment_method_required( 'mobilePay', 'wc_montonio_mobilepay' );
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @return array Payment method data.
     */
    public function get_payment_method_data() {
        $title = $this->get_setting( 'title' );

        if ( 'MobilePay' === $title ) {
            $title = __( 'MobilePay', 'montonio-for-woocommerce' );
        }

        return array(
            'title'       => $title,
            'description' => $this->get_setting( 'description' ),
            'iconurl'     => WC_MONTONIO_PLUGIN_URL . '/assets/images/mobilepay.png',
            'sandboxMode' => WC_Montonio_Helper::is_test_mode()
        );
    }
}

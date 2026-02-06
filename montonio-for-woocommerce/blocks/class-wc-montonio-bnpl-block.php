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
        $title = $this->get_setting( 'title' );

        if ( 'Pay Later' === $title ) {
            $title = __( 'Pay Later', 'montonio-for-woocommerce' );
        }

        return array(
            'title'       => $title,
            'description' => $this->get_setting( 'description' ),
            'iconurl'     => apply_filters( 'wc_montonio_bnpl_block_logo', WC_MONTONIO_PLUGIN_URL . '/assets/images/inbank.svg' ),
            'sandboxMode' => WC_Montonio_Helper::is_test_mode()
        );
    }
}
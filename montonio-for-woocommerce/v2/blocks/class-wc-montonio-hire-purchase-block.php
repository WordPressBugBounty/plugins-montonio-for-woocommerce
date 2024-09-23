<?php
defined('ABSPATH') || exit;

/**
 * WC_Montonio_Hire_Purchase_Block class.
 *
 * Handles the Hire Purchase payment method block for Montonio.
 *
 * @since 7.1.0
 */
class WC_Montonio_Hire_Purchase_Block extends AbstractMontonioPaymentMethodBlock {
    /**
     * Constructor.
     *
     * @since 7.1.0
     */
    public function __construct() {
        parent::__construct( 'wc_montonio_hire_purchase' );
    }

    /**
     * Gets the payment method data to load into the frontend.
     *
     * @since 7.1.0
     * @return array Payment method data including title, description, and icon URL.
     */
    public function get_payment_method_data() {
        return array(
            'title'       => __( $this->get_setting( 'title' ), 'montonio-for-woocommerce' ),
            'description' => $this->get_setting( 'description' ),
            'iconurl'     => apply_filters( 'wc_montonio_hire_purchase_block_logo', 'https://public.montonio.com/images/logos/inbank-general.svg' ),
        );
    }
}
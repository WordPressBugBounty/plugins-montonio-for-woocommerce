<?php
defined( 'ABSPATH' ) || exit;

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
        $title = $this->get_setting( 'title' );

        if ( 'Financing' === $title ) {
            $title = __( 'Financing', 'montonio-for-woocommerce' );
        }

        return array(
            'title'       => $title,
            'description' => $this->get_setting( 'description' ),
            'iconurl'     => apply_filters( 'wc_montonio_hire_purchase_block_logo', WC_MONTONIO_PLUGIN_URL . '/assets/images/inbank.svg' ),
            'sandboxMode' => $this->get_setting( 'test_mode', 'no' )
        );
    }
}
<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Unisend_Courier extends Montonio_Shipping_Method {

    public $default_title      = 'Unisend courier';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_unisend_courier';
        $this->method_title       = __( 'Montonio Unisend courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Unisend courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'unisend';
        $this->type_v2       = 'courier';
        $this->title         = $this->get_option( 'title', __( 'Unisend courier', 'montonio-for-woocommerce' ) );

        if ( 'Unisend courier' === $this->title ) {
            $this->title = __( 'Unisend courier', 'montonio-for-woocommerce' );
        }
    }
}

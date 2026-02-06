<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Omniva_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'Omniva courier';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_omniva_courier';
        $this->method_title       = __( 'Montonio Omniva courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Omniva courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'omniva';
        $this->type_v2       = 'courier';
        $this->title         = $this->get_option( 'title', __( 'Omniva courier', 'montonio-for-woocommerce' ) );

        if ( 'Omniva courier' === $this->title ) {
            $this->title = __( 'Omniva courier', 'montonio-for-woocommerce' );
        }
    }
}

<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Venipak_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'Venipak courier';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_venipak_courier';
        $this->method_title       = __( 'Montonio Venipak courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Venipak courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'venipak';
        $this->type_v2       = 'courier';
        $this->title         = $this->get_option( 'title', __( 'Venipak courier', 'montonio-for-woocommerce' ) );

        if ( 'Venipak courier' === $this->title ) {
            $this->title = __( 'Venipak courier', 'montonio-for-woocommerce' );
        }
    }
}

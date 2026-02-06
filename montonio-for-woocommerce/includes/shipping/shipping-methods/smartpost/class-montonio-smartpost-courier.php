<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Smartpost_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'SmartPosti courier';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_itella_courier';
        $this->method_title       = __( 'Montonio SmartPosti courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'SmartPosti courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'smartpost';
        $this->type_v2       = 'courier';
        $this->title         = $this->get_option( 'title', __( 'SmartPosti courier', 'montonio-for-woocommerce' ) );

        if ( 'SmartPosti courier' === $this->title ) {
            $this->title = __( 'SmartPosti courier', 'montonio-for-woocommerce' );
        }
    }
}

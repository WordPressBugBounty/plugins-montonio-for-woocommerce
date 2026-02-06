<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Latvian_Post_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'Latvijas Pasts courier';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_latvian_post_courier';
        $this->method_title       = __( 'Montonio Latvijas Pasts courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'latvian_post';
        $this->type_v2       = 'courier';
        $this->title         = $this->get_option( 'title', __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' ) );

        if ( 'Latvijas Pasts courier' === $this->title ) {
            $this->title = __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' );
        }
    }
}

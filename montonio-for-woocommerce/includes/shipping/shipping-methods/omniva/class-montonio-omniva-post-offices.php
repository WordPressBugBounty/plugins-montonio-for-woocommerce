<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Omniva_Post_Offices extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 38, 39, 64 ); // lowest to highest (cm)

    public $default_title      = 'Omniva post offices';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_omniva_post_offices';
        $this->method_title       = __( 'Montonio Omniva post offices', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Omniva post offices', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'omniva';
        $this->type_v2       = 'postOffice';
        $this->title         = $this->get_option( 'title', __( 'Omniva post offices', 'montonio-for-woocommerce' ) );

        if ( 'Omniva post offices' === $this->title ) {
            $this->title = __( 'Omniva post offices', 'montonio-for-woocommerce' );
        }
    }
}

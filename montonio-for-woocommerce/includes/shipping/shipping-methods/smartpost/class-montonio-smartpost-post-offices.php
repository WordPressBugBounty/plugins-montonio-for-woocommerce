<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Smartpost_Post_Offices extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 36, 60, 60 ); // lowest to highest (cm)

    public $default_title      = 'SmartPosti post offices';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_itella_post_offices';
        $this->method_title       = __( 'Montonio SmartPosti post offices', 'montonio-for-woocommerce' );
        $this->method_description = __( 'SmartPosti post offices', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'smartpost';
        $this->type_v2       = 'postOffice';
        $this->title         = $this->get_option( 'title', __( 'SmartPosti post offices', 'montonio-for-woocommerce' ) );

        if ( 'SmartPosti post offices' === $this->title ) {
            $this->title = __( 'SmartPosti post offices', 'montonio-for-woocommerce' );
        }
    }
}

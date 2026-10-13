<?php
defined( 'ABSPATH' ) || exit();

class Montonio_Posti_Courier extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 60, 60, 100 ); // lowest to highest (cm)

    public $default_title      = 'Posti courier';
    public $default_max_weight = 25; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_posti_courier';
        $this->method_title       = __( 'Montonio Posti courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Posti courier', 'montonio-for-woocommerce' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->carrier_code = 'posti';
        $this->type_v2      = 'courier';
        $this->title        = $this->get_option( 'title', __( 'Posti courier', 'montonio-for-woocommerce' ) );

        if ( 'Posti courier' === $this->title ) {
            $this->title = __( 'Posti courier', 'montonio-for-woocommerce' );
        }
    }
}

<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Unisend_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 35, 61, 74.5 ); // lowest to highest (cm)

    public $default_title      = 'Unisend parcel machines';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_unisend_parcel_machines';
        $this->method_title       = __( 'Montonio Unisend parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Unisend parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'unisend';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Unisend parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Unisend parcel machines' === $this->title ) {
            $this->title = __( 'Unisend parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

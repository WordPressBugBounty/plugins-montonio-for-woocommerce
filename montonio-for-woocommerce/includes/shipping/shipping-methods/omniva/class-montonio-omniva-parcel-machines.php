<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Omniva_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 38, 39, 64 ); // lowest to highest (cm)

    public $default_title      = 'Omniva parcel machines';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_omniva_parcel_machines';
        $this->method_title       = __( 'Montonio Omniva parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Omniva parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'omniva';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Omniva parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Omniva parcel machines' === $this->title ) {
            $this->title = __( 'Omniva parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

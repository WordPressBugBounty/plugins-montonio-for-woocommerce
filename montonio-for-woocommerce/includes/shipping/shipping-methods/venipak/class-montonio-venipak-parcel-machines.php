<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Venipak_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 39.5, 41, 61 ); // lowest to highest (cm)

    public $default_title      = 'Venipak parcel machines';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_venipak_parcel_machines';
        $this->method_title       = __( 'Montonio Venipak parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Venipak parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'venipak';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Venipak parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Venipak parcel machines' === $this->title ) {
            $this->title = __( 'Venipak parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

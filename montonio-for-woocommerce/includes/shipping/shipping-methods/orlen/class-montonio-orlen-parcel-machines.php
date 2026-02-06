<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Orlen_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 38, 41, 60 ); // lowest to highest (cm)

    public $default_title      = 'Orlen parcel machines';
    public $default_max_weight = 20; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_orlen_parcel_machines';
        $this->method_title       = __( 'Montonio Orlen parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Orlen parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'orlen';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Orlen parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Orlen parcel machines' === $this->title ) {
            $this->title = __( 'Orlen parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

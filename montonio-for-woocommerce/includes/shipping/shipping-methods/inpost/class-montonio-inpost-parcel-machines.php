<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Inpost_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 38, 41, 64 ); // lowest to highest (cm)

    public $default_title      = 'Inpost parcel machines';
    public $default_max_weight = 25; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_inpost_parcel_machines';
        $this->method_title       = __( 'Montonio Inpost parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Inpost parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'inpost';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Inpost parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Inpost parcel machines' === $this->title ) {
            $this->title = __( 'Inpost parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

<?php
defined( 'ABSPATH' ) || exit();

class Montonio_Matkahuolto_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 40, 60, 60 ); // lowest to highest (cm)

    public $default_title      = 'Matkahuolto parcel machines';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_matkahuolto_parcel_machines';
        $this->method_title       = __( 'Montonio Matkahuolto parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Matkahuolto parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->carrier_code = 'matkahuolto';
        $this->type_v2      = 'parcelMachine';
        $this->title        = $this->get_option( 'title', __( 'Matkahuolto parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Matkahuolto parcel machines' === $this->title ) {
            $this->title = __( 'Matkahuolto parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

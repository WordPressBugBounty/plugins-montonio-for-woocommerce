<?php
defined( 'ABSPATH' ) || exit();

class Montonio_Posti_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 36, 60, 60 ); // lowest to highest (cm)

    public $default_title      = 'Posti parcel machines';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_posti_parcel_machines';
        $this->method_title       = __( 'Montonio Posti parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Posti parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->carrier_code = 'posti';
        $this->type_v2      = 'parcelMachine';
        $this->title        = $this->get_option( 'title', __( 'Posti parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Posti parcel machines' === $this->title ) {
            $this->title = __( 'Posti parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

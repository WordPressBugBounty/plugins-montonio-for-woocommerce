<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Smartpost_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 60, 80, 100 ); // lowest to highest (cm)

    public $default_title      = 'SmartPosti parcel machines';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_itella_parcel_machines';
        $this->method_title       = __( 'Montonio SmartPosti parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'SmartPosti parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'smartpost';

        $this->type_v2 = 'parcelMachine';
        $this->title   = $this->get_option( 'title', __( 'SmartPosti parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'SmartPosti parcel machines' === $this->title ) {
            $this->title = __( 'SmartPosti parcel machines', 'montonio-for-woocommerce' );
        }
    }
}

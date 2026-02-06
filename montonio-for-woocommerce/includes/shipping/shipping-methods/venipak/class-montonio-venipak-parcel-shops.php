<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Venipak_Parcel_Shops extends Montonio_Shipping_Method {
    public $default_title      = 'Venipak parcel shops';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_venipak_post_offices';
        $this->method_title       = __( 'Montonio Venipak parcel shops', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Venipak parcel shops', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'venipak';
        $this->type_v2       = 'parcelShop';
        $this->title         = $this->get_option( 'title', __( 'Venipak parcel shops', 'montonio-for-woocommerce' ) );

        if ( 'Venipak parcel shops' === $this->title ) {
            $this->title = __( 'Venipak parcel shops', 'montonio-for-woocommerce' );
        }
    }
}

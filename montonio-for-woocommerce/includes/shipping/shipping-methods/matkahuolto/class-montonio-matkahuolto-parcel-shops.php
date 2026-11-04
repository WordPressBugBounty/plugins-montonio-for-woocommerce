<?php
defined( 'ABSPATH' ) || exit();

class Montonio_Matkahuolto_Parcel_Shops extends Montonio_Shipping_Method {
    protected $max_dimensions     = array( 240, 240, 240 ); // lowest to highest (cm)
    protected $max_dimensions_sum = 360; // length + width + height (cm)

    public $default_title      = 'Matkahuolto parcel shops';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_matkahuolto_parcel_shops';
        $this->method_title       = __( 'Montonio Matkahuolto parcel shops', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Matkahuolto parcel shops', 'montonio-for-woocommerce' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->carrier_code = 'matkahuolto';
        $this->type_v2      = 'parcelShop';
        $this->title        = $this->get_option( 'title', __( 'Matkahuolto parcel shops', 'montonio-for-woocommerce' ) );

        if ( 'Matkahuolto parcel shops' === $this->title ) {
            $this->title = __( 'Matkahuolto parcel shops', 'montonio-for-woocommerce' );
        }
    }
}

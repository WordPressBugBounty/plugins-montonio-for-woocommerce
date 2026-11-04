<?php
defined( 'ABSPATH' ) || exit();

class Montonio_Matkahuolto_Courier extends Montonio_Shipping_Method {
    protected $max_dimensions     = array( 240, 240, 240 ); // lowest to highest (cm)
    protected $max_dimensions_sum = 360; // length + width + height (cm)

    public $default_title      = 'Matkahuolto courier';
    public $default_max_weight = 35; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_matkahuolto_courier';
        $this->method_title       = __( 'Montonio Matkahuolto courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Matkahuolto courier', 'montonio-for-woocommerce' );
        $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

        $this->carrier_code = 'matkahuolto';
        $this->type_v2      = 'courier';
        $this->title        = $this->get_option( 'title', __( 'Matkahuolto courier', 'montonio-for-woocommerce' ) );

        if ( 'Matkahuolto courier' === $this->title ) {
            $this->title = __( 'Matkahuolto courier', 'montonio-for-woocommerce' );
        }
    }
}

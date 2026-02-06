<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Latvian_Post_Parcel_Machines extends Montonio_Shipping_Method {
    protected $max_dimensions = array( 38, 38, 58 );

    public $default_title      = 'Latvijas Pasts parcel machines';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_latvian_post_parcel_machines';
        $this->method_title       = __( 'Montonio Latvijas Pasts parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Latvijas Pasts parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'latvian_post';
        $this->type_v2       = 'parcelMachine';
        $this->title         = $this->get_option( 'title', __( 'Latvijas Pasts parcel machines', 'montonio-for-woocommerce' ) );

        if ( 'Latvijas Pasts parcel machines' === $this->title ) {
            $this->title = __( 'Latvijas Pasts parcel machines', 'montonio-for-woocommerce' );
        }

        // Adjust max dimensions for Lithuania
        if ( 'LT' === $this->country ) {
            $this->max_dimensions = array( 35, 36, 61 );
        }
    }
}

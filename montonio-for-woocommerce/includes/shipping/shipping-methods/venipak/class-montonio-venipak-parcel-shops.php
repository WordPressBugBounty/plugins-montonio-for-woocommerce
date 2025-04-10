<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Venipak_Parcel_Shops extends Montonio_Shipping_Method {
    const MAX_DIMENSIONS = array(80, 120, 170); // lowest to highest (cm)

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

        $this->provider_name = 'venipak';
        $this->type_v2       = 'parcelShop';
        $this->logo          = WC_MONTONIO_PLUGIN_URL . '/assets/images/venipak.svg';
        $this->title         = $this->get_option( 'title', __( 'Venipak parcel shops', 'montonio-for-woocommerce' ) );

        if ( 'Venipak parcel shops' === $this->title ) {
            $this->title = __( 'Venipak parcel shops', 'montonio-for-woocommerce' );
        }
    }

    /**
     * Validate the dimensions of a package against maximum allowed dimensions.
     *
     * @param array $package The package to validate, containing items to be shipped.
     * @return bool True if the package dimensions are valid, false otherwise.
     */
    protected function validate_package_dimensions( $package ) {
        $package_dimensions = $this->get_package_dimensions( $package );

        return ( $package_dimensions[0] <= self::MAX_DIMENSIONS[0] ) && ( $package_dimensions[1] <= self::MAX_DIMENSIONS[1] ) && ( $package_dimensions[2] <= self::MAX_DIMENSIONS[2] );
    }
}

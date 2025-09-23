<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Omniva_Post_Offices extends Montonio_Shipping_Method {
    const MAX_LENGHT                  = 150; // cm (longest side)
    const MAX_SUM_OF_LENGHT_AND_GIRTH = 300; // cm

    public $default_title      = 'Omniva post offices';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_omniva_post_offices';
        $this->method_title       = __( 'Montonio Omniva post offices', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Omniva post offices', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->provider_name = 'omniva';
        $this->type_v2       = 'postOffice';
        $this->logo          = WC_MONTONIO_PLUGIN_URL . '/assets/images/omniva-rect.svg';
        $this->title         = $this->get_option( 'title', __( 'Omniva post offices', 'montonio-for-woocommerce' ) );

        if ( 'Omniva post offices' === $this->title ) {
            $this->title = __( 'Omniva post offices', 'montonio-for-woocommerce' );
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

        if ( $package_dimensions[2] > self::MAX_LENGHT ) {
            return false;
        }

        $sum_of_lenght_and_girth = 2 * ( $package_dimensions[0] + $package_dimensions[1] ) + $package_dimensions[2];

        if ( $sum_of_lenght_and_girth > self::MAX_SUM_OF_LENGHT_AND_GIRTH ) {
            return false;
        }

        return true;
    }
}

<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Latvian_Post_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'Latvijas Pasts courier';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_latvian_post_courier';
        $this->method_title       = __( 'Montonio Latvijas Pasts courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->provider_name = 'latvian_post';
        $this->type_v2       = 'courier';
        $this->logo          = WC_MONTONIO_PLUGIN_URL . '/assets/images/latvian_post-rect.svg';
        $this->title         = $this->get_option( 'title', __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' ) );

        if ( 'Latvijas Pasts courier' === $this->title ) {
            $this->title = __( 'Latvijas Pasts courier', 'montonio-for-woocommerce' );
        }
    }

    /**
     * Validate the dimensions of a package against maximum allowed dimensions.
     *
     * @param array $package The package to validate, containing items to be shipped.
     * @return bool True if the package dimensions are valid, false otherwise.
     */
    protected function validate_package_dimensions( $package ) {
        return true;
    }
}

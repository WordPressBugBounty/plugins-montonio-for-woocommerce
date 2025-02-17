<?php
defined( 'ABSPATH' ) or exit;

class Montonio_Latvian_Post_Parcel_Machines extends Montonio_Shipping_Method {
    public $default_title = 'Latvijas Pasts parcel machines';
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

        $this->provider_name = 'latvian_post';
        $this->type_v2       = 'parcelMachine';
        $this->logo          = 'https://public.montonio.com/images/shipping_provider_logos/latvian-post.png';
        $this->title         = __( $this->get_option( 'title', __( 'Latvijas Pasts parcel machines', 'montonio-for-woocommerce' ) ), 'montonio-for-woocommerce' );
    }

    /**
     * Get maximum dimensions based on country
     *
     * @return array
     */
    protected function get_max_dimensions() {
        return ( 'LT' === $this->country ) ? array( 35, 36, 61 ) : array( 38, 38, 58 );
    }

    /**
     * Validate the dimensions of a package against maximum allowed dimensions.
     *
     * @param array $package The package to validate, containing items to be shipped.
     * @return bool True if the package dimensions are valid, false otherwise.
     */
    protected function validate_package_dimensions( $package ) {
        $package_dimensions = $this->get_package_dimensions( $package );
        $max_dimensions     = $this->get_max_dimensions();

        return ( $package_dimensions[0] <= $max_dimensions[0] ) && ( $package_dimensions[1] <= $max_dimensions[1] ) && ( $package_dimensions[2] <= $max_dimensions[2] );
    }

    /**
     * Check if the shipping method is available for the current package.
     *
     * @param array $package The package to check, containing items to be shipped.
     * @return bool True if the shipping method is available, false otherwise.
     */
    public function is_available( $package ) {
        foreach ( $package['contents'] as $item ) {
            if ( get_post_meta( $item['product_id'], '_montonio_no_parcel_machine', true ) === 'yes' ) {
                return false;
            }
        }

        return parent::is_available( $package );
    }
}

<?php
defined('ABSPATH') or exit;

require_once dirname( dirname(__FILE__) ) . '/class-montonio-shipping-method.php';

class Montonio_Unisend_Parcel_Machines extends Montonio_Shipping_Method {
    const MAX_DIMENSIONS = [35, 61, 74.5]; // lowest to highest (cm)

    public $default_title = 'Unisend parcel machine';
    public $default_max_weight = 30; // kg

    /**
     * Called from parent's constructor
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_unisend_parcel_machines';
        $this->method_title       = __( 'Montonio Unisend parcel machines', 'montonio-for-woocommerce' );
        $this->method_description = __( 'Unisend parcel machines', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->provider_name = 'unisend';
        $this->type = 'parcel_machine';
        $this->type_v2 = 'parcelMachine';
        $this->logo = 'https://public.montonio.com/images/shipping_provider_logos/unisend.png';
        $this->title = __( $this->get_option( 'title', __( 'Unisend parcel machine', 'montonio-for-woocommerce' ) ), 'montonio-for-woocommerce' );
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

    /**
	 * Check if the shipping method is available for use.
	 *
	 * @return bool
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

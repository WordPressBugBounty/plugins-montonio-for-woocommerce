<?php
defined( 'ABSPATH' ) or exit;

class Montonio_International_Shipping_Pickup_Points extends Montonio_Shipping_Method {
    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_international_shipping_pickup_points';
        $this->method_title       = __( 'Montonio International Shipping - Pickup Points', 'montonio-for-woocommerce' );
        $this->method_description = __( 'International shipping to parcel lockers and pickup points. Shipping costs calculated automatically based on real-time carrier rates.', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'novaPost';
        $this->type_v2      = 'parcelMachine';
        $this->title        = 'Montonio International Shipping - Pickup Points';
    }

    /**
     * Initialize form fields for the shipping method settings.
     */
    public function init_form_fields() {
        $this->instance_form_fields = require WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/montonio-international-shipping/montonio-international-shipping-settings.php';
    }

    /**
     * Check if the shipping method is available for the current order.
     *
     * @param array $package The package to be shipped, containing items and destination info.
     * @return bool True if the shipping method is available, false otherwise.
     */
    public function is_available( $package ) {
        if ( ! $this->is_enabled() ) {
            return false;
        }

        if ( $this->has_disabled_shipping_class( $package ) ) {
            return false;
        }

        foreach ( $package['contents'] as $item ) {
            if ( 'yes' === get_post_meta( $item['product_id'], '_montonio_no_parcel_machine', true ) ) {
                return false;
            }
        }

        return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true, $package );
    }

    /**
     * Calculate shipping costs and taxes for a package.
     *
     * @since 9.2.0
     * @param array $package The package to calculate shipping for.
     */
    public function calculate_shipping( $package = array() ) {
        $rate_template = array(
            'id'      => $this->get_rate_id(),
            'cost'    => 0,
            'package' => $package
        );

        $flat_rate_cost   = $this->get_option( 'flat_rate_cost' );
        $cart_total       = $this->get_cart_total( $package );
        $package_item_qty = $this->get_package_item_qty( $package );
        $parcels          = $this->get_parcels_with_item_dimensions( $package );
        $labels           = array(
            'parcelMachine' => __( 'Parcel lockers', 'montonio-for-woocommerce' ),
            'parcelShop'    => __( 'Parcel shops', 'montonio-for-woocommerce' )
        );

        $carrier_rates = WC_Montonio_Shipping_Rate::get_rates_for( 'novaPost', 'pickupPoint', $parcels, $this->country );

        if ( null === $carrier_rates ) {
            return;
        }

        foreach ( $carrier_rates as $carrier_rate ) {
            $rate = $rate_template;

            // Create unique ID
            $rate['id'] .= ':' . $this->carrier_code . '_' . $carrier_rate['code'];

            $rate['label'] = isset( $labels[$carrier_rate['code']] ) ? $labels[$carrier_rate['code']] : $carrier_rate['code'];

            // Set cost
            if ( 'flat_rate' === $this->get_option( 'pricing_type' ) && '' !== $flat_rate_cost ) {
                $rate['cost'] = $this->evaluate_cost(
                    $flat_rate_cost,
                    array(
                        'qty'  => $package_item_qty,
                        'cost' => $package['contents_cost']
                    )
                );
            } else {
                $rate['cost'] = $this->apply_dynamic_rate_markup( $carrier_rate['rate'] );
            }

            $rate['cost'] = $this->apply_free_shipping_rules( $rate['cost'], $cart_total, $package_item_qty, $package );

            // Update meta data
            $rate['meta_data'] = array(
                'carrier_code' => $this->carrier_code,
                'type_v2'      => $carrier_rate['code']
            );

            // Add operators if available
            if ( isset( $carrier_rate['operators'] ) && is_array( $carrier_rate['operators'] ) ) {
                $rate['meta_data']['operators'] = $carrier_rate['operators'];
            }

            // Add the shipping rate
            $this->add_rate( $rate );
        }
    }
}

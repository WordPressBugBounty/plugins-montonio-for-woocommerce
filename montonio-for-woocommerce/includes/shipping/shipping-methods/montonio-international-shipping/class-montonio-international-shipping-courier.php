<?php
defined( 'ABSPATH' ) or exit;

class Montonio_International_Shipping_Courier extends Montonio_Shipping_Method {
    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_international_shipping_courier';
        $this->method_title       = __( 'Montonio International Shipping - Courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'International courier delivery. Shipping costs calculated automatically based on real-time carrier rates.', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'novaPost';
        $this->type_v2      = 'courier';
        $this->title        = __( 'Montonio International Shipping - Courier', 'montonio-for-woocommerce' );
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
     * @param array $package The package to be shipped, containing parcels$parcels and destination info.
     * @return bool True if the shipping method is available, false otherwise.
     */
    public function is_available( $package ) {
        if ( ! $this->is_enabled() ) {
            return false;
        }

        if ( $this->has_disabled_shipping_class( $package ) ) {
            return false;
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

        try {
            $shipping_api = new WC_Montonio_Shipping_API();
            $response     = $shipping_api->get_shipping_rates( $this->country, $this->type_v2, $parcels, 'novaPost' );
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Shipping rate API request failed: ' . $e->getMessage() );
            return;
        }

        $rates = json_decode( $response, true );

        // Check if carriers exist and are valid
        if ( ! isset( $rates['carriers'] ) || ! is_array( $rates['carriers'] ) ) {
            WC_Montonio_Logger::log( 'No carriers found in API response' );
            return;
        }

        // Loop through each carrier and add rates
        foreach ( $rates['carriers'] as $carrier ) {
            $carrier_code = $carrier['carrierCode'];

            if ( ! isset( $carrier['shippingMethods'] ) || ! is_array( $carrier['shippingMethods'] ) ) {
                continue;
            }

            foreach ( $carrier['shippingMethods'] as $method ) {
                if ( ! isset( $method['subtypes'] ) || ! is_array( $method['subtypes'] ) ) {
                    continue;
                }

                foreach ( $method['subtypes'] as $subtype ) {
                    $rate = $rate_template;

                    // Create unique ID
                    $rate['id'] .= ':' . $carrier_code . '_' . $subtype['code'];

                    $labels = array(
                        'standard' => __( 'Courier', 'montonio-for-woocommerce' )
                    );

                    $rate['label'] = $labels[$subtype['code']];

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
                        $rate['cost'] = $subtype['rate'];

                        $margin = $this->get_option( 'dynamic_rate_markup' );

                        if ( ! empty( $margin ) && '0%' !== $margin ) {
                            if ( '%' === substr( $margin, -1 ) ) {
                                $rate['cost'] += ( $rate['cost'] * floatval( $margin ) / 100 );
                            } else {
                                $rate['cost'] += floatval( $margin );
                            }
                        }
                    }

                    $rate['cost'] = $this->apply_free_shipping_rules( $rate['cost'], $cart_total, $package_item_qty, $package );

                    // Update meta data
                    $rate['meta_data'] = array(
                        'carrier_code' => $carrier_code,
                        'type_v2'      => $this->type_v2
                    );

                    // Add operators if available
                    if ( isset( $subtype['operators'] ) && is_array( $subtype['operators'] ) ) {
                        $rate['meta_data']['operators'] = $subtype['operators'];
                    }

                    // Add the shipping rate
                    $this->add_rate( $rate );
                }
            }
        }
    }
}

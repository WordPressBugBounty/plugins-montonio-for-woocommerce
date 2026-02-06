<?php
defined( 'ABSPATH' ) or exit;

class Montonio_DPD_Courier extends Montonio_Shipping_Method {
    public $default_title      = 'DPD courier';
    public $default_max_weight = 31; // kg

    /**
     * Called from parent's constructor
     *
     * @return void
     */
    protected function init() {
        $this->id                 = 'montonio_dpd_courier';
        $this->method_title       = __( 'Montonio DPD courier', 'montonio-for-woocommerce' );
        $this->method_description = __( 'DPD courier', 'montonio-for-woocommerce' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal'
        );

        $this->carrier_code = 'dpd';
        $this->type_v2      = 'courier';
        $this->title        = $this->get_option( 'title', __( 'DPD courier', 'montonio-for-woocommerce' ) );

        if ( 'DPD courier' === $this->title ) {
            $this->title = __( 'DPD courier', 'montonio-for-woocommerce' );
        }
    }

    /**
     * Initialize form fields for the shipping method settings.
     */
    public function init_form_fields() {
        $this->instance_form_fields = require WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/dpd/dpd-settings.php';
    }

    /**
     * Calculate shipping costs and taxes for a package.
     *
     * @since 9.3.2
     * @param array $package The package to calculate shipping for.
     */
    public function calculate_shipping( $package = array() ) {
        $rate = array(
            'id'        => $this->get_rate_id(),
            'label'     => $this->title,
            'cost'      => 0,
            'package'   => $package,
            'meta_data' => array(
                'carrier_code'      => $this->carrier_code,
                'type_v2'           => $this->type_v2,
                'method_class_name' => get_class( $this )
            )
        );

        // Calculate the costs
        $flat_rate_cost   = $this->get_option( 'price' );
        $cart_total       = $this->get_cart_total( $package );
        $package_item_qty = $this->get_package_item_qty( $package );
        $parcels          = $this->get_parcels_with_item_dimensions( $package );

        if ( 'dynamic' === $this->get_option( 'pricing_type' ) ) {
            $parcels = $this->get_parcels_with_item_dimensions( $package );

            try {
                $shipping_api = new WC_Montonio_Shipping_API();

                $response = $shipping_api->get_shipping_rates( $this->country, 'courier', $parcels, 'dpd' );
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
                if ( ! isset( $carrier['shippingMethods'] ) || ! is_array( $carrier['shippingMethods'] ) ) {
                    continue;
                }

                foreach ( $carrier['shippingMethods'] as $method ) {
                    if ( ! isset( $method['subtypes'] ) || ! is_array( $method['subtypes'] ) ) {
                        continue;
                    }

                    foreach ( $method['subtypes'] as $subtype ) {
                        if ( 'standard' !== $subtype['code'] ) {
                            continue;
                        }

                        // Set cost
                        $rate['cost'] = $subtype['rate'];

                        $rate['cost'] = $this->apply_free_shipping_rules( $rate['cost'], $cart_total, $package_item_qty, $package );

                        // Add the shipping rate
                        $this->add_rate( $rate );
                    }
                }
            }

            return;
        }

        // Calculate the costs
        if ( '' !== $flat_rate_cost ) {
            $rate['cost'] = $this->evaluate_cost(
                $flat_rate_cost,
                array(
                    'qty'  => $package_item_qty,
                    'cost' => $package['contents_cost']
                )
            );
        }

        // Add shipping class costs
        $shipping_classes = WC()->shipping()->get_shipping_classes();

        if ( ! empty( $shipping_classes ) ) {
            $found_shipping_classes = $this->find_shipping_classes( $package );
            $highest_class_cost     = 0;

            foreach ( $found_shipping_classes as $shipping_class => $products ) {
                // Also handles BW compatibility when slugs were used instead of ids.
                $shipping_class_term = get_term_by( 'slug', $shipping_class, 'product_shipping_class' );
                $class_cost_string   = $shipping_class_term && $shipping_class_term->term_id ? $this->get_option( 'class_cost_' . $shipping_class_term->term_id, $this->get_option( 'class_cost_' . $shipping_class, '' ) ) : $this->get_option( 'no_class_cost', '' );

                if ( '' === $class_cost_string ) {
                    continue;
                }

                $class_cost = $this->evaluate_cost(
                    $class_cost_string,
                    array(
                        'qty'  => array_sum( wp_list_pluck( $products, 'quantity' ) ),
                        'cost' => array_sum( wp_list_pluck( $products, 'line_total' ) )
                    )
                );

                if ( 'class' === $this->calc_type ) {
                    $rate['cost'] += $class_cost;
                } else {
                    $highest_class_cost = $class_cost > $highest_class_cost ? $class_cost : $highest_class_cost;
                }
            }

            if ( 'order' === $this->calc_type && $highest_class_cost ) {
                $rate['cost'] += $highest_class_cost;
            }
        }

        $rate['cost'] = $this->apply_free_shipping_rules( $rate['cost'], $cart_total, $package_item_qty, $package );

        $this->add_rate( $rate );
    }
}

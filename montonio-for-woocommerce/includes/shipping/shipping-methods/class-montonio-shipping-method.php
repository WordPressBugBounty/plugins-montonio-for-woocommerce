<?php
defined( 'ABSPATH' ) or exit;

abstract class Montonio_Shipping_Method extends WC_Shipping_Method {

    /**
     * Shipping instance ID
     *
     * @param int
     */
    public $instance_id;

    /**
     * Shipping rate ID
     *
     * @var string
     */
    public $id;

    /**
     * Shipping method title
     *
     * @var string
     */
    public $title;

    /**
     * Shipping provider logo
     *
     * @var string
     */
    public $logo;

    /**
     * Customer's shipping country at checkout
     *
     * @var string
     */
    public $country;

    /**
     * Shipping method cost
     *
     * @var string
     */
    public $cost;

    /**
     * Maximum package dimensions for this shipping method in cm
     *
     * @var array|null
     */
    protected $max_dimensions = null;

    /**
     * Shipping method type for V2
     *
     * @var string
     */
    public $type_v2;

    /**
     * Shipping carrier name
     *
     * @var string
     */
    public $carrier_code;

    /**
     * Cost passed to [fee] shortcode.
     *
     * @var string Cost.
     */
    protected $fee_cost = '';

    /**
     * Shipping cost calculation type.
     *
     * @var string
     */
    public $calc_type;

    /**
     * Constructor for the shipping method.
     *
     * @param int $instance_id Optional. Instance ID.
     */
    public function __construct( $instance_id = 0 ) {
        $this->instance_id = absint( $instance_id );
        $this->country     = WC_Montonio_Shipping_Helper::get_customer_shipping_country();

        $this->init_form_fields();
        $this->init();
        $this->init_settings();

        $this->tax_status = $this->get_option( 'tax_status' );
        $this->calc_type  = $this->get_option( 'type', 'class' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Initialize form fields for the shipping method settings.
     */
    public function init_form_fields() {
        $this->instance_form_fields = require WC_MONTONIO_PLUGIN_PATH . '/includes/shipping/shipping-methods/shipping-method-settings.php';
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

        if ( ! WC_Montonio_Shipping_Item_Manager::shipping_method_items_exist( $this->country, $this->carrier_code, $this->type_v2 ) ) {
            return false;
        }

        if ( 'yes' === $this->get_option( 'enablePackageMeasurementsCheck' ) ) {
            if ( 'yes' === $this->get_option( 'hideWhenNoMeasurements' ) && $this->check_if_measurements_missing( $package ) ) {
                return false;
            }

            if ( WC_Montonio_Helper::convert_to_kg( WC()->cart->get_cart_contents_weight() ) > $this->get_option( 'maximumWeight', $this->default_max_weight ) ) {
                return false;
            }

            if ( ! $this->fits_within_dimensions( $package ) ) {
                return false;
            }
        }

        if ( 'parcelMachine' === $this->type_v2 ) {
            foreach ( $package['contents'] as $item ) {
                if ( 'yes' === get_post_meta( $item['product_id'], '_montonio_no_parcel_machine', true ) ) {
                    return false;
                }
            }
        }

        if ( $this->has_disabled_shipping_class( $package ) ) {
            return false;
        }

        return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true, $package );
    }

    /**
     * Check if any items in the package are missing required measurements.
     *
     * @param array $package The package data containing items to validate.
     * @return bool True if any item is missing measurements, false if all items are complete.
     */
    protected function check_if_measurements_missing( $package ) {
        foreach ( $package['contents'] as $item ) {
            if ( empty( $item['data']->get_length() ) || empty( $item['data']->get_width() ) || empty( $item['data']->get_height() ) || empty( $item['data']->get_weight() ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the minimum bounding box dimensions for a package.
     *
     * This method determines the smallest box that can contain all items in the package
     * by finding the maximum dimension across all items in each axis (length, width, height).
     *
     * @param array $package The package data containing items to be shipped.
     * @return array An array of three float values
     */
    protected function get_package_dimensions( $package ) {
        $package_dimensions = array( 0, 0, 0 );

        foreach ( $package['contents'] as $item ) {
            $length = $item['data']->get_length();
            $width  = $item['data']->get_width();
            $height = $item['data']->get_height();

            // Skip items without dimensions
            if ( empty( $length ) && empty( $width ) && empty( $height ) ) {
                continue;
            }

            $item_dimensions = array(
                (float) WC_Montonio_Helper::convert_to_cm( $length ),
                (float) WC_Montonio_Helper::convert_to_cm( $width ),
                (float) WC_Montonio_Helper::convert_to_cm( $height )
            );

            sort( $item_dimensions );

            for ( $i = 0; $i < 3; $i++ ) {
                $package_dimensions[$i] = max( $package_dimensions[$i], $item_dimensions[$i] );
            }
        }

        return $package_dimensions;
    }

    /**
     * Check if package dimensions fit within maximum dimension constraints.
     * Allows any orientation by comparing sorted dimensions.
     *
     * @param array $package_dimensions Array of package dimensions in cm.
     * @param array $max_dimensions Array of maximum allowed dimensions in cm.
     * @return bool True if package fits, false otherwise.
     */
    protected function fits_within_dimensions( $package ) {
        if ( empty( $this->max_dimensions ) ) {
            return true;
        }

        $package_dimensions    = $this->get_package_dimensions( $package );
        $max_dimensions_sorted = $this->max_dimensions;
        sort( $max_dimensions_sorted );

        // Each sorted package dimension must fit within corresponding max dimension
        foreach ( $package_dimensions as $index => $dimension ) {
            if ( $dimension > $max_dimensions_sorted[$index] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate shipping costs and taxes for a package.
     *
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
        $cost             = $this->get_option( 'price' );
        $cart_total       = $this->get_cart_total( $package );
        $package_item_qty = $this->get_package_item_qty( $package );

        if ( '' !== $cost ) {
            $rate['cost'] = $this->evaluate_cost(
                $cost,
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

    /**
     * Get the total cost of the cart including taxes.
     *
     * @param array $package Package of items from cart.
     * @return float The total cost of the cart.
     */
    protected function get_cart_total( $package ) {
        $total = 0;
        foreach ( $package['contents'] as $item_id => $values ) {
            $total += $values['line_total'] + $values['line_tax'];
        }

        return (float) wc_format_decimal( $total, 2 );
    }

    /**
     * Get the total quantity of items in the package that need shipping.
     *
     * @param array $package Package of items from cart.
     * @return int The total quantity of items needing shipping.
     */
    protected function get_package_item_qty( $package ) {
        $quantity = 0;
        foreach ( $package['contents'] as $item_id => $values ) {
            if ( $values['quantity'] > 0 && $values['data']->needs_shipping() ) {
                $quantity += $values['quantity'];
            }
        }

        return $quantity;
    }

    /**
     * Find and return shipping classes and the products with said class.
     *
     * @param array $package Package of items from cart.
     * @return array An array of shipping classes and their associated products.
     */
    public function find_shipping_classes( $package ) {
        $found_shipping_classes = array();

        foreach ( $package['contents'] as $item_id => $values ) {
            if ( $values['data']->needs_shipping() ) {
                $found_class = $values['data']->get_shipping_class();

                if ( ! isset( $found_shipping_classes[$found_class] ) ) {
                    $found_shipping_classes[$found_class] = array();
                }

                $found_shipping_classes[$found_class][$item_id] = $values;
            }
        }

        return $found_shipping_classes;
    }

    /**
     * Apply free shipping rules and return adjusted cost.
     *
     * @since 9.2.0
     * @param float $current_cost The current shipping cost.
     * @param float $cart_total The cart total.
     * @param int $package_item_qty The package item quantity.
     * @param array $package The shipping package (for coupon checking).
     * @return float The adjusted cost (0 if free shipping applies, original cost otherwise).
     */
    protected function apply_free_shipping_rules( $current_cost, $cart_total, $package_item_qty, $package ) {
        if ( 'yes' === $this->get_option( 'enableFreeShippingThreshold' ) ) {
            // Exclude virtual products from cart total for free shipping threshold calculation
            if ( 'yes' === $this->get_option( 'excludeVirtualFromThreshold' ) ) {
                $virtual_total = 0;

                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( $cart_item['data']->is_virtual() ) {
                        $virtual_total += $cart_item['line_total'] + $cart_item['line_tax'];
                    }
                }

                $cart_total -= $virtual_total;
            }

            // Exclude coupon discounts from free shipping threshold calculation
            if ( 'yes' === $this->get_option( 'excludeCouponsFromThreshold' ) ) {
                $discount_total = WC()->cart->get_discount_total();
                $cart_total += $discount_total;
            }

            // Check if cart total exceeds free shipping threshold
            $free_shipping_threshold = $this->get_option( 'freeShippingThreshold' );

            if ( ! empty( $free_shipping_threshold ) ) {
                $free_shipping_threshold = (float) wc_format_decimal( $free_shipping_threshold, 2 );

                if ( $cart_total > $free_shipping_threshold ) {
                    return 0;
                }
            }
        }

        // Check if cart quantity meets free shipping requirement
        if ( 'yes' === $this->get_option( 'enableFreeShippingQty' ) ) {
            $free_shipping_qty = (int) $this->get_option( 'freeShippingQty' );

            if ( $package_item_qty >= $free_shipping_qty ) {
                return 0;
            }
        }

        // Check for free shipping coupon
        if ( ! empty( $package['applied_coupons'] ) ) {
            foreach ( $package['applied_coupons'] as $applied_coupon ) {
                $coupon = new WC_Coupon( $applied_coupon );

                if ( $coupon->get_free_shipping() ) {
                    return 0;
                }
            }
        }

        // No free shipping rules applied, return original cost
        return $current_cost;
    }

    /**
     * Evaluate a cost from a sum/string.
     *
     * @param string $sum The cost string to evaluate.
     * @param array $args Arguments for evaluation, must contain 'cost' and 'qty' keys.
     * @return float The evaluated cost.
     */
    protected function evaluate_cost( $sum, $args = array() ) {
        // Add warning for subclasses.
        if ( ! is_array( $args ) || ! array_key_exists( 'qty', $args ) || ! array_key_exists( 'cost', $args ) ) {
            wc_doing_it_wrong( __FUNCTION__, '$args must contain `cost` and `qty` keys.', '4.0.1' );
        }

        include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';

        // Allow 3rd parties to process shipping cost arguments.
        $args           = apply_filters( 'wc_montonio_evaluate_shipping_cost_args', $args, $sum, $this );
        $locale         = localeconv();
        $decimals       = array( wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ',' );
        $this->fee_cost = $args['cost'];

        // Expand shortcodes.
        add_shortcode( 'fee', array( $this, 'fee' ) );

        $sum = do_shortcode(
            str_replace(
                array(
                    '[qty]',
                    '[cost]'
                ),
                array(
                    $args['qty'],
                    $args['cost']
                ),
                $sum
            )
        );

        remove_shortcode( 'fee', array( $this, 'fee' ) );

        // Remove whitespace from string.
        $sum = preg_replace( '/\s+/', '', $sum );

        // Remove locale from string.
        $sum = str_replace( $decimals, '.', $sum );

        // Trim invalid start/end characters.
        $sum = rtrim( ltrim( $sum, "\t\n\r\0\x0B+*/" ), "\t\n\r\0\x0B+-*/" );

        // Do the math.
        return $sum ? WC_Eval_Math::evaluate( $sum ) : 0;
    }

    /**
     * Check disabled_shipping_classes from the shipping method against product shipping classes in the cart
     * If a disabled shipping class is identified on a product, then it will return true, i.e the method should not be available
     *
     * @since 9.2.1
     * @param array $package Package of items from cart.
     * @return bool true if disabled shipping class match was identified. False in other cases.
     */
    protected function has_disabled_shipping_class( $package ) {
        // Check for disabled shipping classes
        $disabled_classes = $this->get_option( 'disabled_shipping_classes', array() );

        // Check if there are any disabled shipping classes configured
        if ( ! empty( $disabled_classes ) && is_array( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                $product                   = $item['data'];
                $product_shipping_class_id = $product->get_shipping_class_id();

                // No shipping class? Skip this item.
                if ( ! $product_shipping_class_id ) {
                    continue;
                }

                // Direct match check for performance
                if ( in_array( $product_shipping_class_id, $disabled_classes ) ) {
                    return true;
                }

                // If WPML is active, we need to check the canonical ID
                if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
                    $default_language = apply_filters( 'wpml_default_language', null );

                    // Get the "canonical" ID for this shipping class (in default language)
                    $product_canonical_id = apply_filters( 'wpml_object_id', $product_shipping_class_id, 'product_shipping_class', false, $default_language );

                    if ( ! $product_canonical_id ) {
                        continue; // Skip if we can't get a canonical ID
                    }

                    // Check each disabled class
                    foreach ( $disabled_classes as $disabled_id ) {
                        // Get the "canonical" ID for this disabled class
                        $disabled_canonical_id = apply_filters( 'wpml_object_id', $disabled_id, 'product_shipping_class', false, $default_language );

                        // If we can't get a canonical ID for the disabled class, skip it
                        // This can happen if the disabled class is not translated in the current language
                        if ( ! $disabled_canonical_id ) {
                            continue;
                        }

                        // If the canonical IDs match, this is essentially the same shipping class in different languages
                        if ( $product_canonical_id == $disabled_canonical_id ) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Calculate fee based on given attributes (used in shortcode).
     *
     * @param array $atts Attributes for fee calculation.
     * @return float The calculated fee.
     */
    public function fee( $atts ) {
        $atts = shortcode_atts(
            array(
                'percent' => '',
                'min_fee' => '',
                'max_fee' => ''
            ),
            $atts,
            'fee'
        );

        $calculated_fee = 0;

        if ( $atts['percent'] ) {
            $calculated_fee = $this->fee_cost * ( floatval( $atts['percent'] ) / 100 );
        }

        if ( $atts['min_fee'] && $calculated_fee < $atts['min_fee'] ) {
            $calculated_fee = $atts['min_fee'];
        }

        if ( $atts['max_fee'] && $calculated_fee > $atts['max_fee'] ) {
            $calculated_fee = $atts['max_fee'];
        }

        return $calculated_fee;
    }

    /**
     * Sanitize the cost field.
     *
     * @param string $value Unsanitized cost value.
     * @return string Sanitized cost value.
     * @throws Exception If the cost evaluation fails.
     */
    public function sanitize_cost( $value ) {
        $value = is_null( $value ) ? '' : $value;
        $value = wp_kses_post( trim( wp_unslash( $value ) ) );
        $value = str_replace( array( get_woocommerce_currency_symbol(), html_entity_decode( get_woocommerce_currency_symbol() ) ), '', $value );

        // Thrown an error on the front end if the evaluate_cost will fail.
        $dummy_cost = $this->evaluate_cost(
            $value,
            array(
                'cost' => 1,
                'qty'  => 1
            )
        );

        if ( false === $dummy_cost ) {
            throw new Exception( WC_Eval_Math::$last_error );
        }

        return $value;
    }

    /**
     * Sanitize and validate default length field.
     *
     * @param string $value The field value.
     * @return string Sanitized value.
     * @throws Exception If validation fails.
     */
    public function sanitize_default_length( $value ) {
        $value = is_null( $value ) ? '' : trim( wp_unslash( $value ) );

        if ( empty( $value ) || $value <= 0 ) {
            throw new Exception( __( 'Length must be a positive number.', 'montonio-for-woocommerce' ) );
        }

        return $value;
    }

    /**
     * Sanitize and validate default width field.
     *
     * @param string $value The field value.
     * @return string Sanitized value.
     * @throws Exception If validation fails.
     */
    public function sanitize_default_width( $value ) {
        $value = is_null( $value ) ? '' : trim( wp_unslash( $value ) );

        if ( empty( $value ) || $value <= 0 ) {
            throw new Exception( __( 'Width must be a positive number.', 'montonio-for-woocommerce' ) );
        }

        return $value;
    }

    /**
     * Sanitize and validate default height field.
     *
     * @param string $value The field value.
     * @return string Sanitized value.
     * @throws Exception If validation fails.
     */
    public function sanitize_default_height( $value ) {
        $value = is_null( $value ) ? '' : trim( wp_unslash( $value ) );

        if ( empty( $value ) || $value <= 0 ) {
            throw new Exception( __( 'Height must be a positive number.', 'montonio-for-woocommerce' ) );
        }

        return $value;
    }

    /**
     * Sanitize and validate default weight field.
     *
     * @param string $value The field value.
     * @return string Sanitized value.
     * @throws Exception If validation fails.
     */
    public function sanitize_default_weight( $value ) {
        $value = is_null( $value ) ? '' : trim( wp_unslash( $value ) );

        if ( empty( $value ) || $value <= 0 ) {
            throw new Exception( __( 'Weight must be a positive number.', 'montonio-for-woocommerce' ) );
        }

        return $value;
    }

    /**
     * Extract and normalize product dimensions from package items.
     *
     * Retrieves dimensions (length, width, height) and weight for all items in the package.
     * Falls back to default values when product dimensions are not set.
     *
     * @since 9.2.0
     * @param array $package The package data containing items with product information.
     * @return array Array of parcels for API request.
     *               Format: [{ items: [{ length, width, height, weight, quantity }] }]
     */
    protected function get_parcels_with_item_dimensions( $package ) {
        $parcels      = array();
        $shared_items = array();

        foreach ( $package['contents'] as $item ) {
            $product = $item['data'];

            // Get product dimensions
            $length = (float) $product->get_length();
            $width  = (float) $product->get_width();
            $height = (float) $product->get_height();
            $weight = (float) $product->get_weight();

            // Check if any dimension is missing and use defaults if needed
            if ( empty( $length ) || empty( $width ) || empty( $height ) ) {
                $length = $this->get_option( 'default_length', 0 );
                $width  = $this->get_option( 'default_width', 0 );
                $height = $this->get_option( 'default_height', 0 );
            }

            // Check if weight is missing and use default if needed
            if ( empty( $weight ) ) {
                $weight = $this->get_option( 'default_weight', 0 );
            }

            $item_data = array(
                'length'        => WC_Montonio_Helper::convert_to_cm( $length ),
                'width'         => WC_Montonio_Helper::convert_to_cm( $width ),
                'height'        => WC_Montonio_Helper::convert_to_cm( $height ),
                'weight'        => WC_Montonio_Helper::convert_to_kg( $weight ),
                'quantity'      => 1,
                'dimensionUnit' => 'cm',
                'weightUnit'    => 'kg'
            );

            // Check if product requires separate label
            if ( 'yes' === get_post_meta( $item['product_id'], '_montonio_separate_label', true ) ) {
                // Create separate parcel for each unit of this product
                for ( $i = 0; $i < $item['quantity']; $i++ ) {
                    $parcels[] = array(
                        'items' => array( $item_data )
                    );
                }
            } else {
                // Add to shared items parcel
                $item_data['quantity'] = $item['quantity'];
                $shared_items[]        = $item_data;
            }
        }

        // Add shared items as a single parcel if any exist
        if ( ! empty( $shared_items ) ) {
            $parcels[] = array(
                'items' => $shared_items
            );
        }

        return $parcels;
    }
}
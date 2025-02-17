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
     * Should we add free shipping rate text?
     *
     * @var bool
     */
    public $enable_free_shipping_text;

    /**
     * Free shipping rate text to include in title
     *
     * @var string
     */
    public $free_shipping_text;

    /**
     * Customer's shipping country at checkout
     *
     * @var string
     */
    public $country;

    /**
     * Shipping method cost.
     *
     * @var string
     */
    public $cost;

    /**
     * Shipping method type for V2.
     *
     * @var string
     */
    public $type_v2;

    /**
     * Shipping provider name
     *
     * @var string
     */
    public $provider_name;

    /**
     * Cost passed to [fee] shortcode.
     *
     * @var string Cost.
     */
    protected $fee_cost = '';

    /**
     * Pickup points for Montonio Shipping V2
     *
     * @var array
     * @since 7.0.0
     */
    public $shipping_method_items = [];

    /**
     * Constructor for the shipping method.
     *
     * @param int $instance_id Optional. Instance ID.
     */
    public function __construct( $instance_id = 0 ) {
        $this->instance_id = absint( $instance_id );

        $this->enable_free_shipping_text = $this->get_option( 'enable_free_shipping_text' );
        $this->free_shipping_text        = $this->get_option( 'free_shipping_text' );
        $this->country = WC_Montonio_Shipping_Helper::get_customer_shipping_country();

        $this->init_settings();
        $this->init_form_fields();

        add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options'] );

        $this->init();
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
        
        if ( ! WC_Montonio_Shipping_Item_Manager::shipping_method_items_exist( $this->country, $this->provider_name, $this->type_v2 ) ) {
            return false;
        }

        if ( $this->get_option( 'enablePackageMeasurementsCheck' ) === 'yes' ) {
            if ( $this->get_option( 'hideWhenNoMeasurements' ) === 'yes' && $this->check_if_measurements_missing( $package ) ) {
                return false;
            }

            if ( WC_Montonio_Helper::convert_to_kg( WC()->cart->get_cart_contents_weight() ) > $this->get_option( 'maximumWeight', $this->default_max_weight ) ) {
                return false;
            }

            if ( ! $this->validate_package_dimensions( $package ) ) {
                return false;
            }
        }

        // Check for disabled shipping classes
        $disabled_classes = $this->get_option( 'disabled_shipping_classes', [] );

        if ( ! empty( $disabled_classes ) && is_array( $package['contents'] ) ) {
            foreach ( $package['contents'] as $item ) {
                $product = $item['data'];
                $shipping_class_id = $product->get_shipping_class_id();
                
                if ( in_array( $shipping_class_id, $disabled_classes ) ) {
                    return false;
                }
            }
        }

        return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true, $package );
    }

    /**
     * Assemble the dimensions of the package in cm.
     *
     * @param array $package The package containing the items to be shipped.
     * @return array An array of three dimensions [length, width, height] in cm.
     */
    protected function get_package_dimensions( $package ) {
        $package_dimensions = [0, 0, 0];

        foreach( $package['contents'] as $item ) {
            $item_dimensions = [];
            $item_dimensions[] = (float) WC_Montonio_Helper::convert_to_cm( $item['data']->get_length() );
            $item_dimensions[] = (float) WC_Montonio_Helper::convert_to_cm( $item['data']->get_width() );
            $item_dimensions[] = (float) WC_Montonio_Helper::convert_to_cm( $item['data']->get_height() );

            // Sort from smallest to largest dimension
            sort( $item_dimensions );

            if ( $item_dimensions[0] > $package_dimensions[0] ) {
                $package_dimensions[0] = $item_dimensions[0];
            }

            if ( $item_dimensions[1] > $package_dimensions[1] ) {
                $package_dimensions[1] = $item_dimensions[1];
            }

            if ( $item_dimensions[2] > $package_dimensions[2] ) {
                $package_dimensions[2] = $item_dimensions[2];
            }
        }

        return $package_dimensions;
    }

    /**
     * Check if any product in the package is missing measurements.
     *
     * @param array $package The package containing the items to be shipped.
     * @return bool True if any product is missing measurements, false otherwise.
     */
    protected function check_if_measurements_missing( $package ) {
        foreach ( $package['contents'] as $item ) {
            if ( ! (float) $item['data']->get_length() || ! (float) $item['data']->get_width() || ! (float) $item['data']->get_height() || ! (float) $item['data']->get_weight() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate shipping costs and taxes for a package.
     *
     * @param array $package The package to calculate shipping for.
     */
    public function calculate_shipping( $package = [] ) {
        if ( get_option( 'montonio_shipping_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        $rate = [
            'id'        => $this->get_rate_id(),
            'label'     => $this->title,
            'taxes'     => $this->get_option( 'tax_status' ) == 'none' ? false : '',
            'calc_tax'  => 'per_order',
            'cost'      => 0,
            'package'   => $package,
            'meta_data' => [
                'provider_name'     => $this->provider_name,
                'type_v2'           => $this->type_v2,
                'method_class_name' => get_class( $this ),
            ]
        ];

        // Calculate the costs
        $cost             = $this->get_option( 'price' );
        $cart_total       = WC()->cart->get_cart_contents_total() + WC()->cart->get_taxes_total() - WC()->cart->get_shipping_tax();
        $package_item_qty = $this->get_package_item_qty( $package );

        if ( '' !== $cost ) {
            $rate['cost'] = $this->evaluate_cost(
                $cost,
                [
                    'qty'  => $this->get_package_item_qty( $package ),
                    'cost' => $package['contents_cost']
                ]
            );
        }

        // Add shipping class costs
        $shipping_classes = WC()->shipping()->get_shipping_classes();

        if ( ! empty( $shipping_classes ) ) {
            $found_shipping_classes = $this->find_shipping_classes( $package );
            $calculation_type       = $this->get_option( 'type', 'class' );
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
                    [
                        'qty'  => array_sum( wp_list_pluck( $products, 'quantity' ) ),
                        'cost' => array_sum( wp_list_pluck( $products, 'line_total' ) )
                    ]
                );

                if ( 'class' === $calculation_type ) {
                    $rate['cost'] += $class_cost;
                } else {
                    $highest_class_cost = $class_cost > $highest_class_cost ? $class_cost : $highest_class_cost;
                }
            }

            if ( 'order' === $calculation_type && $highest_class_cost ) {
                $rate['cost'] += $highest_class_cost;
            }
        }

        if ( $this->get_option( 'enableFreeShippingThreshold' ) === 'yes' && (float) $cart_total > (float) $this->get_option( 'freeShippingThreshold' ) ) {
            $rate['cost'] = 0;
        }

        if ( $this->get_option( 'enableFreeShippingQty' ) === 'yes' && (float) $package_item_qty >= (float) $this->get_option( 'freeShippingQty' ) ) {
            $rate['cost'] = 0;
        }

        // Check for free shipping coupon
        $applied_coupons = $package['applied_coupons'];
        foreach ( $applied_coupons as $applied_coupon ) {
            $coupon = new WC_Coupon( $applied_coupon );

            if ( $coupon->get_free_shipping() ) {
                $rate['cost'] = 0;
                break;
            }
        }

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
            $total += (float) $values['line_total'] + (float) $values['line_tax'];
        }

        return $total;
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
        $found_shipping_classes = [];

        foreach ( $package['contents'] as $item_id => $values ) {
            if ( $values['data']->needs_shipping() ) {
                $found_class = $values['data']->get_shipping_class();

                if ( ! isset( $found_shipping_classes[$found_class] ) ) {
                    $found_shipping_classes[$found_class] = [];
                }

                $found_shipping_classes[$found_class][$item_id] = $values;
            }
        }

        return $found_shipping_classes;
    }

    /**
     * Evaluate a cost from a sum/string.
     *
     * @param string $sum The cost string to evaluate.
     * @param array $args Arguments for evaluation, must contain 'cost' and 'qty' keys.
     * @return float The evaluated cost.
     */
    protected function evaluate_cost( $sum, $args = [] ) {
        // Add warning for subclasses.
        if ( ! is_array( $args ) || ! array_key_exists( 'qty', $args ) || ! array_key_exists( 'cost', $args ) ) {
            wc_doing_it_wrong( __FUNCTION__, '$args must contain `cost` and `qty` keys.', '4.0.1' );
        }

        include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';

        // Allow 3rd parties to process shipping cost arguments.
        $args           = apply_filters( 'wc_montonio_evaluate_shipping_cost_args', $args, $sum, $this );
        $locale         = localeconv();
        $decimals       = [wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ','];
        $this->fee_cost = $args['cost'];

        // Expand shortcodes.
        add_shortcode( 'fee', [$this, 'fee'] );

        $sum = do_shortcode(
            str_replace(
                [
                    '[qty]',
                    '[cost]'
                ],
                [
                    $args['qty'],
                    $args['cost']
                ],
                $sum
            )
        );

        remove_shortcode( 'fee', [$this, 'fee'] );

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
     * Calculate fee based on given attributes (used in shortcode).
     *
     * @param array $atts Attributes for fee calculation.
     * @return float The calculated fee.
     */
    public function fee( $atts ) {
        $atts = shortcode_atts(
            [
                'percent' => '',
                'min_fee' => '',
                'max_fee' => ''
            ],
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
        $value = str_replace( [get_woocommerce_currency_symbol(), html_entity_decode( get_woocommerce_currency_symbol() )], '', $value );

        // Thrown an error on the front end if the evaluate_cost will fail.
        $dummy_cost = $this->evaluate_cost(
            $value,
            [
                'cost' => 1,
                'qty'  => 1
            ]
        );

        if ( false === $dummy_cost ) {
            throw new Exception( WC_Eval_Math::$last_error );
        }

        return $value;
    }
}
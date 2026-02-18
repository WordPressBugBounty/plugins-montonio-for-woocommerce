<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cost_desc      = __( 'Enter a cost (excl. tax) or sum, e.g. <code>10.00 * [qty]</code>.', 'montonio-for-woocommerce' ) . '<br/><br/>' . __( 'Use <code>[qty]</code> for the number of items, <br/><code>[cost]</code> for the total cost of items, and <code>[fee percent="10" min_fee="20" max_fee=""]</code> for percentage based fees.', 'montonio-for-woocommerce' );
$dimension_unit = get_option( 'woocommerce_dimension_unit' );
$weight_unit    = get_option( 'woocommerce_weight_unit' );

$settings = array(
    'title'                    => array(
        'title'       => __( 'Title', 'montonio-for-woocommerce' ),
        'type'        => 'text',
        'description' => __( 'Shipping method title that the customer will see at checkout', 'montonio-for-woocommerce' ),
        'default'     => $this->default_title,
        'desc_tip'    => true
    ),
    'pricing_type'             => array(
        'title'       => __( 'Pricing Type', 'montonio-for-woocommerce' ),
        'type'        => 'select',
        'class'       => 'wc-montonio-pricing-type-select',
        'default'     => 'flat_rate',
        'options'     => array(
            'dynamic'   => __( 'Dynamic Pricing (API-based)', 'montonio-for-woocommerce' ),
            'flat_rate' => __( 'Flat Rate', 'montonio-for-woocommerce' )
        ),
        'description' =>
        __( '<strong>Dynamic Pricing</strong> - Shipping cost is calculated directly via the API and shown in the checkout to the buyer<br>
                <strong>Flat Rate Cost</strong> - Enter a fixed cost shown to the buyer. Actual shipping costs are calculated via API and charged to your account', 'montonio-for-woocommerce' ),
        'desc_tip'    => false
    ),
    'tax_status'               => array(
        'title'   => __( 'Tax status', 'montonio-for-woocommerce' ),
        'type'    => 'select',
        'class'   => 'wc-enhanced-select',
        'default' => 'taxable',
        'options' => array(
            'taxable' => __( 'Taxable', 'montonio-for-woocommerce' ),
            'none'    => _x( 'None', 'Tax status', 'montonio-for-woocommerce' )
        )
    ),
    'dynamic_rate_markup' => array(
        'title'             => __( 'Dynamic Price Markup', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-montonio-dynamic-rate-markup wc-montonio-dynamic-rate-only',
        'placeholder'       => '2.50 or 10%',
        'description'       => __( 'Add extra markup to shipping cost. Enter a fixed amount (e.g., <code>2.50</code>) or percentage (e.g., <code>10%</code>).', 'montonio-for-woocommerce' ),
        'default'           => '0%',
        'desc_tip'          => false,
        'sanitize_callback' => array( $this, 'sanitize_markup' )
    ),
    'price'                    => array(
        'title'             => __( 'Cost', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-shipping-modal-price wc-montonio-flat-rate-cost-field',
        'placeholder'       => '',
        'description'       => $cost_desc,
        'default'           => '0',
        'desc_tip'          => true,
        'sanitize_callback' => array( $this, 'sanitize_cost' )
    ),
    'default_dimensions_title' => array(
        'title'       => __( 'Default Package Dimensions', 'montonio-for-woocommerce' ),
        'type'        => 'title',
        'class'       => 'wc-montonio-default-dimensions-title wc-montonio-dynamic-rate-only',
        'description' => __( 'These dimensions are used when products in the cart do not have shipping dimensions defined. This ensures shipping rates can still be calculated via the API.', 'montonio-for-woocommerce' )
    ),
    'default_length'           => array(
        'title'             => sprintf( __( 'Length (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field wc-montonio-dynamic-rate-only',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        )
    ),
    'default_width'            => array(
        'title'             => sprintf( __( 'Width (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field wc-montonio-dynamic-rate-only',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        )
    ),
    'default_height'           => array(
        'title'             => sprintf( __( 'Height (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field wc-montonio-dynamic-rate-only',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        )
    ),
    'default_weight'           => array(
        'title'             => sprintf( __( 'Weight (%s)', 'montonio-for-woocommerce' ), $weight_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field wc-montonio-dynamic-rate-only',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        )
    )

);

$shipping_classes = WC()->shipping()->get_shipping_classes();

if ( ! empty( $shipping_classes ) ) {
    $settings['class_costs'] = array(
        'title'       => __( 'Shipping class costs', 'montonio-for-woocommerce' ),
        'type'        => 'title',
        'class'       => 'wc-montonio-flat-rate-only',
        'default'     => '',
        /* translators: %s: URL for link. */
        'description' => sprintf( __( 'These costs can optionally be added based on the <a href="%s">product shipping class</a>. Shipping class costs will be included in the default "Cost" value.', 'montonio-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=shipping&section=classes' ) )
    );

    $shipping_classes_options = array();
    foreach ( $shipping_classes as $shipping_class ) {
        if ( ! isset( $shipping_class->term_id ) ) {
            continue;
        }

        $shipping_classes_options[$shipping_class->term_id] = $shipping_class->name;

        $settings['class_cost_' . $shipping_class->term_id] = array(
            /* translators: %s: shipping class name */
            'title'             => sprintf( __( '"%s" shipping class cost', 'montonio-for-woocommerce' ), esc_html( $shipping_class->name ) ),
            'type'              => 'text',
            'class'             => 'wc-shipping-modal-price',
            'placeholder'       => __( 'N/A', 'montonio-for-woocommerce' ),
            'description'       => $cost_desc,
            'default'           => $this->get_option( 'class_cost_' . $shipping_class->slug ),
            'desc_tip'          => true,
            'sanitize_callback' => array( $this, 'sanitize_cost' )
        );
    }

    $settings['no_class_cost'] = array(
        'title'             => __( 'No shipping class cost', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-shipping-modal-price',
        'placeholder'       => __( 'N/A', 'montonio-for-woocommerce' ),
        'description'       => $cost_desc,
        'default'           => '',
        'desc_tip'          => true,
        'sanitize_callback' => array( $this, 'sanitize_cost' )
    );

    $settings['type'] = array(
        'title'   => __( 'Calculation type', 'montonio-for-woocommerce' ),
        'type'    => 'select',
        'class'   => 'wc-enhanced-select',
        'default' => 'class',
        'options' => array(
            'class' => __( 'Per class: Charge shipping for each shipping class individually', 'montonio-for-woocommerce' ),
            'order' => __( 'Per order: Charge shipping for the most expensive shipping class', 'montonio-for-woocommerce' )
        )
    );

    $settings['shipping_class_restrictions_title'] = array(
        'title' => __( 'Shipping class restrictions', 'montonio-for-woocommerce' ),
        'type'  => 'title'
    );

    $settings['disabled_shipping_classes'] = array(
        'title'    => __( 'Disable for shipping classes', 'montonio-for-woocommerce' ),
        'type'     => 'multiselect',
        'class'    => 'wc-enhanced-select',
        'default'  => '',
        'options'  => $shipping_classes_options,
        'desc_tip' => __( 'This method will be hidden if the cart contains products from any of the selected shipping classes.', 'montonio-for-woocommerce' )
    );
}

$settings['free_shipping_title'] = array(
    'title'       => __( 'Free shipping options', 'montonio-for-woocommerce' ),
    'type'        => 'title',
    'description' => '',
    'default'     => ''
);

$settings['enableFreeShippingThreshold'] = array(
    'title'       => '',
    'label'       => __( 'Enable free shipping based on cart total', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'Allow free shipping if the cart total exceeds the specified amount', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

$settings['excludeVirtualFromThreshold'] = array(
    'title'       => '',
    'label'       => __( 'Exclude virtual products from free shipping threshold', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'When calculating the cart total for free shipping, exclude the price of virtual products', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

$settings['excludeCouponsFromThreshold'] = array(
    'title'       => '',
    'label'       => __( 'Use full cart price (before coupons) for free shipping threshold', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'When enabled, the free shipping threshold will be calculated using the cart total before any coupon codes are applied at checkout', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

$settings['freeShippingThreshold'] = array(
    'title'             => __( 'Free shipping threshold', 'montonio-for-woocommerce' ),
    'type'              => 'text',
    'class'             => 'wc-shipping-modal-price',
    'description'       => __( 'Minimum cart total for free shipping', 'montonio-for-woocommerce' ),
    'default'           => 200,
    'sanitize_callback' => array( $this, 'sanitize_cost' )
);

$settings['enableFreeShippingQty'] = array(
    'title'       => '',
    'label'       => __( 'Enable quantity based free shipping', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'Allow free shipping if the product quantity in the cart equals or exceeds the specified amount', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

$settings['freeShippingQty'] = array(
    'title'       => __( 'Free shipping product quantity', 'montonio-for-woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Minimum amount of items in the cart for free shipping (excludes virtual products)', 'montonio-for-woocommerce' ),
    'default'     => 10
);

$settings['enable_free_shipping_text'] = array(
    'title'       => '',
    'label'       => __( 'Enable free shipping rate text', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'Display 0.00 amount or custom text for free shipping rate', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

$settings['free_shipping_text'] = array(
    'title'       => __( 'Free shipping rate text', 'montonio-for-woocommerce' ),
    'type'        => 'text',
    'description' => __( 'Leave empty to display formated price e.g €0.00, or add you custom text for free shipping rate.', 'montonio-for-woocommerce' ),
    'default'     => ''
);

$settings['measurement_check_title'] = array(
    'title'       => __( 'Measurement check options', 'montonio-for-woocommerce' ),
    'type'        => 'title',
    'description' => '',
    'default'     => ''
);

$settings['enablePackageMeasurementsCheck'] = array(
    'title'       => '',
    'label'       => __( 'Enable package measurements check', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'Hide this shipping method if package\'s weight or dimensions exceed limits', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'yes'
);

$settings['maximumWeight'] = array(
    'title'       => __( 'Maximum weight (kg)', 'montonio-for-woocommerce' ),
    'type'        => 'number',
    'description' => __( 'The total weight of items in the cart that is allowed for this option to be displayed', 'montonio-for-woocommerce' ),
    'default'     => $this->default_max_weight
);

$settings['hideWhenNoMeasurements'] = array(
    'title'       => '',
    'label'       => __( 'Hide when no measurements', 'montonio-for-woocommerce' ),
    'type'        => 'checkbox',
    'description' => __( 'Hide this shipping method when an item in cart has no set weight or dimensions', 'montonio-for-woocommerce' ),
    'desc_tip'    => true,
    'default'     => 'no'
);

return $settings;
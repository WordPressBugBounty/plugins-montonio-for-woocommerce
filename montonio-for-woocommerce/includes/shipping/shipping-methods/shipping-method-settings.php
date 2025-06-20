<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cost_desc = __( 'Enter a cost (excl. tax) or sum, e.g. <code>10.00 * [qty]</code>.', 'montonio-for-woocommerce' ) . '<br/><br/>' . __( 'Use <code>[qty]</code> for the number of items, <br/><code>[cost]</code> for the total cost of items, and <code>[fee percent="10" min_fee="20" max_fee=""]</code> for percentage based fees.', 'montonio-for-woocommerce' );

$settings = array(
    'title'      => array(
        'title'       => __( 'Title', 'montonio-for-woocommerce' ),
        'type'        => 'text',
        'description' => __( 'Shipping method title that the customer will see at checkout', 'montonio-for-woocommerce' ),
        'default'     => $this->default_title,
        'desc_tip'    => true
    ),
    'tax_status' => array(
        'title'   => __( 'Tax status', 'montonio-for-woocommerce' ),
        'type'    => 'select',
        'class'   => 'wc-enhanced-select',
        'default' => 'taxable',
        'options' => array(
            'taxable' => __( 'Taxable', 'montonio-for-woocommerce' ),
            'none'    => _x( 'None', 'Tax status', 'montonio-for-woocommerce' )
        )
    ),
    'price'      => array(
        'title'             => __( 'Cost', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-shipping-modal-price',
        'placeholder'       => '',
        'description'       => $cost_desc,
        'default'           => '0',
        'desc_tip'          => true,
        'sanitize_callback' => array( $this, 'sanitize_cost' )
    )
);

$shipping_classes = WC()->shipping()->get_shipping_classes();

if ( ! empty( $shipping_classes ) ) {
    $settings['class_costs'] = array(
        'title'       => __( 'Shipping class costs', 'montonio-for-woocommerce' ),
        'type'        => 'title',
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
        'default' => 'order',
        'options' => array(
            'class' => __( 'Per class: Charge shipping for each shipping class individually', 'montonio-for-woocommerce' ),
            'order' => __( 'Per order: Charge shipping for the most expensive shipping class', 'montonio-for-woocommerce' )
        )
    );

    $settings['disabled_shipping_classes'] = array(
        'title'    => __( 'Disable method for these shipping classes', 'montonio-for-woocommerce' ),
        'type'     => 'multiselect',
        'class'    => 'wc-enhanced-select',
        'default'  => '',
        'options'  => $shipping_classes_options,
        'desc_tip' => __( 'Select shipping classes for which this method should be disabled. If the cart contains any product from these selected shipping classes, this shipping method will not be available.', 'montonio-for-woocommerce' )
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
    'default'           => 50,
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
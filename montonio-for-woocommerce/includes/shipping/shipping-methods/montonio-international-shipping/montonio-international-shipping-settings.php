<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cost_desc      = __( 'Enter a cost (excl. tax) or sum, e.g. <code>10.00 * [qty]</code>.', 'montonio-for-woocommerce' ) . '<br/><br/>' . __( 'Use <code>[qty]</code> for the number of items, <br/><code>[cost]</code> for the total cost of items, and <code>[fee percent="10" min_fee="20" max_fee=""]</code> for percentage based fees.', 'montonio-for-woocommerce' );
$dimension_unit = get_option( 'woocommerce_dimension_unit' );
$weight_unit    = get_option( 'woocommerce_weight_unit' );

$settings = array(
    'international_shipping_courier_description' => array(
        'title'       => '',
        'type'        => 'title',
        'class'       => 'montonio-international-shipping-description-title',
        'description' => '<div class="montonio-international-shipping-description">' . sprintf(
            /* translators: %s: URL for link. */
            __( '<p><strong>Important:</strong> You must schedule a <a href="%s" target="_blank">courier pick-up in Partner System</a> for international orders. <em>Exception:</em> Cross-Baltic shipments can be dropped off at Venipak parcel machines.</p>
            <strong>Pricing Types:</strong>
            <ul>
                <li><strong>Dynamic Pricing</strong> - Shipping cost is calculated directly via the API and shown in the checkout to the buyer</li>
                <li><strong>Flat Rate Cost</strong> - Enter a fixed cost shown to the buyer. Actual shipping costs are calculated via API and charged to your account</li>
            </ul>', 'montonio-for-woocommerce' ),
            esc_url( 'https://partner.montonio.com/shipments/courier-pickups' )
        ) . '</div>'
    ),
    'pricing_type'                               => array(
        'title'       => __( 'Pricing Type', 'montonio-for-woocommerce' ),
        'type'        => 'select',
        'class'       => 'wc-montonio-pricing-type-select',
        'default'     => 'dynamic',
        'options'     => array(
            'dynamic'   => __( 'Dynamic Pricing (API-based)', 'montonio-for-woocommerce' ),
            'flat_rate' => __( 'Flat Rate', 'montonio-for-woocommerce' )
        ),
        'description' => __( 'Choose whether to use dynamic pricing from the API or set a flat rate.', 'montonio-for-woocommerce' ),
        'desc_tip'    => true
    ),
    'tax_status'                                 => array(
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
    'flat_rate_cost'                             => array(
        'title'             => __( 'Flat Rate Cost', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-shipping-modal-price wc-montonio-flat-rate-cost-field',
        'placeholder'       => '',
        'description'       => $cost_desc,
        'default'           => '0',
        'desc_tip'          => true,
        'sanitize_callback' => array( $this, 'sanitize_cost' )
    ),
    'default_dimensions_title'                   => array(
        'title'       => __( 'Default Package Dimensions', 'montonio-for-woocommerce' ),
        'type'        => 'title',
        'description' => __( 'These dimensions are used when products in the cart do not have shipping dimensions defined. This ensures shipping rates can still be calculated via the API.', 'montonio-for-woocommerce' )
    ),
    'default_length'                             => array(
        'title'             => sprintf( __( 'Length (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        ),
        'sanitize_callback' => array( $this, 'sanitize_default_length' )
    ),
    'default_width'                              => array(
        'title'             => sprintf( __( 'Width (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        ),
        'sanitize_callback' => array( $this, 'sanitize_default_width' )
    ),
    'default_height'                             => array(
        'title'             => sprintf( __( 'Height (%s)', 'montonio-for-woocommerce' ), $dimension_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        ),
        'sanitize_callback' => array( $this, 'sanitize_default_height' )
    ),
    'default_weight'                             => array(
        'title'             => sprintf( __( 'Weight (%s)', 'montonio-for-woocommerce' ), $weight_unit ) . '&nbsp;<span class="required">*</span>',
        'type'              => 'number',
        'class'             => 'wc-montonio-dimension-field',
        'default'           => '',
        'description'       => '',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'min'      => '0.01',
            'step'     => '0.01',
            'required' => null
        ),
        'sanitize_callback' => array( $this, 'sanitize_default_weight' )
    ),
    'free_shipping_title'                        => array(
        'title'       => __( 'Free shipping options', 'montonio-for-woocommerce' ),
        'type'        => 'title',
        'description' => '',
        'default'     => ''
    ),
    'enableFreeShippingThreshold'                => array(
        'title'       => '',
        'label'       => __( 'Enable free shipping based on cart total', 'montonio-for-woocommerce' ),
        'type'        => 'checkbox',
        'description' => __( 'Allow free shipping if the cart total exceeds the specified amount', 'montonio-for-woocommerce' ),
        'desc_tip'    => true,
        'default'     => 'no'
    ),
    'excludeVirtualFromThreshold'                => array(
        'title'       => '',
        'label'       => __( 'Exclude virtual products from free shipping threshold', 'montonio-for-woocommerce' ),
        'type'        => 'checkbox',
        'description' => __( 'When calculating the cart total for free shipping, exclude the price of virtual products', 'montonio-for-woocommerce' ),
        'desc_tip'    => true,
        'default'     => 'no'
    ),
    'excludeCouponsFromThreshold'                => array(
        'title'       => '',
        'label'       => __( 'Use full cart price (before coupons) for free shipping threshold', 'montonio-for-woocommerce' ),
        'type'        => 'checkbox',
        'description' => __( 'When enabled, the free shipping threshold will be calculated using the cart total before any coupon codes are applied at checkout', 'montonio-for-woocommerce' ),
        'desc_tip'    => true,
        'default'     => 'no'
    ),
    'freeShippingThreshold'                      => array(
        'title'             => __( 'Free shipping threshold', 'montonio-for-woocommerce' ),
        'type'              => 'text',
        'class'             => 'wc-shipping-modal-price',
        'description'       => __( 'Minimum cart total for free shipping', 'montonio-for-woocommerce' ),
        'default'           => 200,
        'sanitize_callback' => array( $this, 'sanitize_cost' )
    ),
    'enableFreeShippingQty'                      => array(
        'title'       => '',
        'label'       => __( 'Enable quantity based free shipping', 'montonio-for-woocommerce' ),
        'type'        => 'checkbox',
        'description' => __( 'Allow free shipping if the product quantity in the cart equals or exceeds the specified amount', 'montonio-for-woocommerce' ),
        'desc_tip'    => true,
        'default'     => 'no'
    ),
    'freeShippingQty'                            => array(
        'title'       => __( 'Free shipping product quantity', 'montonio-for-woocommerce' ),
        'type'        => 'text',
        'description' => __( 'Minimum amount of items in the cart for free shipping (excludes virtual products)', 'montonio-for-woocommerce' ),
        'default'     => 10
    ),
    'enable_free_shipping_text'                  => array(
        'title'       => '',
        'label'       => __( 'Enable free shipping rate text', 'montonio-for-woocommerce' ),
        'type'        => 'checkbox',
        'description' => __( 'Display 0.00 amount or custom text for free shipping rate', 'montonio-for-woocommerce' ),
        'desc_tip'    => true,
        'default'     => 'no'
    ),
    'free_shipping_text'                         => array(
        'title'       => __( 'Free shipping rate text', 'montonio-for-woocommerce' ),
        'type'        => 'text',
        'description' => __( 'Leave empty to display formated price e.g €0.00, or add you custom text for free shipping rate.', 'montonio-for-woocommerce' ),
        'default'     => ''
    )
);

$shipping_classes = WC()->shipping()->get_shipping_classes();

if ( ! empty( $shipping_classes ) ) {
    $shipping_classes_options = array();
    foreach ( $shipping_classes as $shipping_class ) {
        if ( ! isset( $shipping_class->term_id ) ) {
            continue;
        }

        $shipping_classes_options[$shipping_class->term_id] = $shipping_class->name;
    }

    $settings['shipping_class_restrictions_title'] = array(
        'title' => __( 'Shipping class restrictions', 'montonio-for-woocommerce' ),
        'type'  => 'title',
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

return $settings;
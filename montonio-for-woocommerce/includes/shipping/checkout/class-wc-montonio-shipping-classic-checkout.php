<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Classic_Checkout contains the logic for the Montonio shipping method items dropdown when using the classic checkout.
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Classic_Checkout extends Montonio_Singleton {

    /**
     * The constructor for the WC_Montonio_Shipping_Classic_Checkout class.
     *
     * @since 7.0.0
     */
    protected function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );
        add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'update_shipping_method_label' ), 10, 2 );
        add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render_shipping_method_items_dropdown' ) );
        add_filter( 'woocommerce_order_shipping_to_display', array( $this, 'add_details_to_shipping_label_ordered' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_pickup_point' ) );
    }

    /**
     * Enqueues the Montonio SDK script.
     *
     * @since 7.0.1 - Removed sync of shipping method items in here
     * @since 7.0.0
     * @return null
     */
    public function enqueue_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        $shipping_method_items_data = [
            'shippingDropdownType' => get_option( 'montonio_shipping_dropdown_type' )
        ];

        if ( get_option( 'montonio_shipping_dropdown_type' ) === 'select2' ) {
            wp_enqueue_style( 'montonio-pickup-points' );

            if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
                wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ) );
            }

            wp_enqueue_script( 'montonio-shipping-pickup-points-legacy' );
            wp_localize_script( 'montonio-shipping-pickup-points-legacy', 'wcMontonioShippingMethodItemsData', $shipping_method_items_data );
        } else {
            wp_enqueue_script( 'montonio-shipping-pickup-points' );
            wp_localize_script( 'montonio-shipping-pickup-points', 'wcMontonioShippingMethodItemsData', $shipping_method_items_data );
        }
    }

    /**
     * Update shipping method labels in checkout based on shipping method's settings.
     *
     * @since 7.0.0
     * @param string $label The shipping method label
     * @param WC_Shipping_Rate $method Shipping method rate data.
     * @return string
     */
    public function update_shipping_method_label( $label, $method ) {
        if ( strpos( $method->get_method_id(), 'montonio_' ) === false ) {
            return $label;
        }

        $method_instance = WC_Montonio_Shipping_Helper::create_shipping_method_instance( $method->get_method_id(), $method->get_instance_id() );
        $method_settings = $method_instance->instance_settings;

        if ( ! ( $method->get_cost() > 0 ) && isset( $method_settings['enable_free_shipping_text'] ) && 'yes' === $method_settings['enable_free_shipping_text'] ) {
            if ( isset( $method_settings['free_shipping_text'] ) && '' !== $method_settings['free_shipping_text'] ) {
                $label .= ': <span class="montonio-free-shipping-text">' . $method_settings['free_shipping_text'] . '</span>';
            } else {
                $label .= ': ' . wc_price( 0 );
            }
        }

        if ( get_option( 'montonio_shipping_show_provider_logos' ) === 'yes' && $method_instance->logo ) {
            $label = '<span class="montonio-shipping-label">' . $label . '</span><img class="montonio-shipping-provider-logo" id="' . $method->get_id() . '_logo" src="' . $method_instance->logo . '" width="80">';
        }

        return $label;
    }

    /**
     * Will render the shipping method items dropdown using the montonio-js sdk.
     *
     * @since 7.0.0
     * @return void
     */
    public function render_shipping_method_items_dropdown() {
        $montonio_shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( ! is_a( $montonio_shipping_method, 'Montonio_Shipping_Method' ) ) {
            return;
        }

        $shipping_method_items = WC_Montonio_Shipping_Helper::get_items_for_montonio_shipping_method( $montonio_shipping_method );

        if ( empty( $shipping_method_items ) ) {
            return;
        }

        wc_get_template(
            'shipping-pickup-points-dropdown.php',
            [
                'shipping_method'       => $montonio_shipping_method->id,
                'shipping_method_items' => $shipping_method_items
            ],
            '',
            WC_MONTONIO_PLUGIN_PATH . '/templates/'
        );
    }

    /**
     * Validates if a pickup point is selected in the dropdown during checkout.
     *
     * @since 7.0.0
     * @return void
     */
    public function validate_pickup_point() {
        if ( ! WC()->cart->needs_shipping() ) {
            return;
        }

        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( empty( $shipping_method ) ) {
            return;
        }

        $carrier = $shipping_method->provider_name;
        $method_type = $shipping_method->type_v2;

        if ( in_array( $method_type, ['parcelMachine', 'postOffice', 'parcelShop'] ) ) {
            $shipping_method_item_id = isset( $_POST['montonio_pickup_point'] ) ? sanitize_text_field( $_POST['montonio_pickup_point'] ) : null;

            if ( empty( $shipping_method_item_id ) ) {
                wc_add_notice( __( 'Please select a pickup point.', 'montonio-for-woocommerce' ), 'error' );
                return;
            }

            $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );
            
            if ( $carrier != $shipping_method_item->carrier_code ) {
                wc_add_notice( __( 'Selected pickup point carrier does not match the selected shipping method. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
            }
        }
    }

    /**
     * Add shipping method item details to shipping label in thank you page.
     *
     * @since 7.0.0
     * @param string $shipping_label The shipping label
     * @param WC_Order $order The order object
     * @return string
     */
    public function add_details_to_shipping_label_ordered( $shipping_label, $order ) {
        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_method ) ) {
            return $shipping_label;
        }

        $shipping_method_item_id = $order->get_meta( '_montonio_pickup_point_uuid' );

        if ( empty( $shipping_method_item_id ) ) {
            return $shipping_label;
        }

        $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

        if ( empty( $shipping_method_item ) || $shipping_method_item->method_type !== 'pickupPoint' ) {
            return $shipping_label;
        }

        $shipping_method_info = $shipping_method_item->item_name;

        if ( get_option( 'montonio_shipping_show_address' ) === 'yes' && ! empty( $shipping_method_item->street_address ) ) {
            $shipping_method_info .= ', ' . $shipping_method_item->street_address;
        }

        if ( 0 < abs( (float) $order->get_shipping_total() ) ) {
            $shipping_label .= '&nbsp;<small class="montonio-shipping-method-info">- ' . esc_html( $shipping_method_info ) . '</small>';
        } else {
            $shipping_label .= '&nbsp;- ' . esc_html( $shipping_method_info );
        }

        return $shipping_label;
    }
}

WC_Montonio_Shipping_Classic_Checkout::get_instance();
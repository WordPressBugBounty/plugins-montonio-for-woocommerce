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
        add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'update_shipping_method_label' ), 10, 2 );
        add_action( 'woocommerce_review_order_after_shipping', array( $this, 'render_shipping_method_items_dropdown' ) );
        add_filter( 'woocommerce_order_shipping_to_display', array( $this, 'add_details_to_shipping_label_ordered' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_pickup_point' ) );
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

        $shipping_method_instance = WC_Montonio_Shipping_Helper::get_shipping_method_instance( $method->get_instance_id() );

        if ( ! ( $method->get_cost() > 0 ) && 'yes' === $shipping_method_instance->get_option( 'enable_free_shipping_text' ) ) {
            if ( ! empty( $shipping_method_instance->get_option( 'free_shipping_text' ) ) ) {
                $label .= ': <span class="montonio-free-shipping-text">' . $shipping_method_instance->get_option( 'free_shipping_text' ) . '</span>';
            } else {
                $label .= ': ' . wc_price( 0 );
            }
        }

        if ( get_option( 'montonio_shipping_show_provider_logos' ) === 'yes' ) {
            $meta_data = $method->get_meta_data();

            if ( ! empty( $meta_data['operators'] ) && is_array( $meta_data['operators'] ) ) {
                $label = '<span class="montonio-shipping-label">' . $label . '</span>';
                $label .= '<div class="montonio-shipping-carrier-logos">';

                foreach ( $meta_data['operators'] as $operator ) {
                    $logo_path = WC_MONTONIO_PLUGIN_PATH . '/assets/images/' . strtolower( $operator ) . '-rect.svg';

                    if ( file_exists( $logo_path ) ) {
                        $label .= '<img class="montonio-shipping-carrier-logo" src="' . esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/' . strtolower( $operator ) . '-rect.svg' ) . '" width="50" alt="' . esc_attr( $operator ) . '">';
                    }
                }

                $label .= '</div>';
            } elseif ( ! empty( $meta_data['carrier_code'] ) ) {
                $label = '<span class="montonio-shipping-label">' . $label . '</span><img class="montonio-shipping-carrier-logo" id="' . $method->get_id() . '_logo" src="' . esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/' . $meta_data['carrier_code'] . '-rect.svg' ) . '" width="50">';
            }
        }

        return $label;
    }

    /**
     * Will render the shipping method items dropdown or search element.
     *
     * @since 7.0.0
     * @since 9.1.1 Added support for pickup point search element
     * @return void
     */
    public function render_shipping_method_items_dropdown() {
        $shipping_method = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( empty( $shipping_method ) ) {
            return;
        }

        $carrier_code = $shipping_method->carrier_code;
        $type         = $shipping_method->type;
        $operators    = $shipping_method->operators ?? null;

        if ( 'courier' === $type ) {
            return;
        }

        if ( in_array( $carrier_code, array( 'inpost', 'orlen', 'novaPost' ) ) ) {
            wc_get_template(
                'shipping-pickup-points-search.php',
                array(
                    'carrier'   => $carrier_code,
                    'type'      => $type,
                    'operators' => $operators,
                    'country'   => WC_Montonio_Shipping_Helper::get_customer_shipping_country()
                ),
                '',
                WC_MONTONIO_PLUGIN_PATH . '/templates/'
            );

            return;
        }

        $shipping_method_items = WC_Montonio_Shipping_Helper::get_items_for_montonio_shipping_method( $carrier_code, $type );

        if ( empty( $shipping_method_items ) ) {
            return;
        }

        wc_get_template(
            'shipping-pickup-points-dropdown.php',
            array(
                'shipping_method'       => $shipping_method->id,
                'shipping_method_items' => $shipping_method_items
            ),
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

        if ( ! in_array( $shipping_method->type, array( 'parcelMachine', 'postOffice', 'parcelShop' ) ) ) {
            return;
        }

        $shipping_method_item_id = isset( $_POST['montonio_pickup_point'] ) ? sanitize_text_field( wp_unslash( $_POST['montonio_pickup_point'] ) ) : null;

        if ( empty( $shipping_method_item_id ) ) {
            wc_add_notice( __( 'Please select a pickup point.', 'montonio-for-woocommerce' ), 'error' );
            return;
        }

        $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

        if ( $shipping_method->carrier_code != $shipping_method_item->carrier_code ) {
            wc_add_notice( __( 'Selected pickup point carrier does not match the selected shipping method. Please refresh the page and try again.', 'montonio-for-woocommerce' ), 'error' );
            return;
        }

        $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';

        if ( 'cod' !== $payment_method ) {
            return;
        }

        $additional_services = json_decode( $shipping_method_item->additional_services, true );
        $supports_cod        = is_array( $additional_services ) && in_array( 'cod', array_column( $additional_services, 'code' ), true );

        if ( ! $supports_cod ) {
            wc_add_notice( __( 'Cash on Delivery is not available for the selected pickup point. Please choose a different pickup point or payment method.', 'montonio-for-woocommerce' ), 'error' );
            return;
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

        if ( empty( $shipping_method_item ) || 'pickupPoints' !== $shipping_method_item->method_type ) {
            return $shipping_label;
        }

        $shipping_method_info = $shipping_method_item->item_name;

        if ( 'yes' === get_option( 'montonio_shipping_show_address' ) && ! empty( $shipping_method_item->street_address ) ) {
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
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Classic_Checkout contains the logic for the Montonio shipping method items dropdown when using the classic checkout.
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Classic_Checkout {

    /**
     * Initialize the class by registering WordPress hooks.
     *
     * @since 7.0.0
     */
    public static function init() {
        add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'update_shipping_method_label' ), 10, 2 );
        add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_shipping_method_items_dropdown' ) );
        add_filter( 'woocommerce_order_shipping_to_display', array( __CLASS__, 'add_details_to_shipping_label_ordered' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_pickup_point' ) );
    }

    /**
     * Update shipping method labels in checkout based on shipping method's settings.
     *
     * @since 7.0.0
     * @param string $label The shipping method label
     * @param WC_Shipping_Rate $rate The shipping rate whose label is shown.
     * @return string
     */
    public static function update_shipping_method_label( $label, $rate ) {
        if ( strpos( $rate->get_method_id(), 'montonio_' ) === false ) {
            return $label;
        }

        $shipping_method = WC_Shipping_Zones::get_shipping_method( $rate->get_instance_id() );

        if ( $shipping_method instanceof WC_Shipping_Method && ! ( $rate->get_cost() > 0 ) && 'yes' === $shipping_method->get_option( 'enable_free_shipping_text' ) ) {
            if ( ! empty( $shipping_method->get_option( 'free_shipping_text' ) ) ) {
                $label .= ': <span class="montonio-free-shipping-text">' . $shipping_method->get_option( 'free_shipping_text' ) . '</span>';
            } else {
                $label .= ': ' . wc_price( 0 );
            }
        }

        if ( get_option( 'montonio_shipping_show_provider_logos' ) === 'yes' ) {
            $meta_data = $rate->get_meta_data();

            if ( ! empty( $meta_data['operators'] ) && is_array( $meta_data['operators'] ) ) {
                $label = '<span class="montonio-shipping-label">' . $label . '</span>';
                $label .= '<div class="montonio-shipping-carrier-logos">';

                foreach ( $meta_data['operators'] as $operator ) {
                    $logo_path = WC_MONTONIO_PLUGIN_PATH . '/assets/images/carriers/' . strtolower( $operator ) . '.svg';

                    if ( file_exists( $logo_path ) ) {
                        $label .= '<img class="montonio-shipping-carrier-logo" src="' . esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/carriers/' . strtolower( $operator ) . '.svg' ) . '" width="50" alt="' . esc_attr( $operator ) . '">';
                    }
                }

                $label .= '</div>';
            } elseif ( ! empty( $meta_data['carrier_code'] ) ) {
                $label = '<span class="montonio-shipping-label">' . $label . '</span><img class="montonio-shipping-carrier-logo" id="' . $rate->get_id() . '_logo" src="' . esc_url( WC_MONTONIO_PLUGIN_URL . '/assets/images/carriers/' . $meta_data['carrier_code'] . '.svg' ) . '" width="50">';
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
    public static function render_shipping_method_items_dropdown() {
        $chosen_rate = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( empty( $chosen_rate ) ) {
            return;
        }

        $carrier_code = $chosen_rate->carrier_code;
        $type         = $chosen_rate->type;
        $operators    = $chosen_rate->operators ?? null;

        if ( 'courier' === $type ) {
            return;
        }

        $dropdown_type      = get_option( 'montonio_shipping_dropdown_type' );
        $is_legacy_dropdown = in_array( $dropdown_type, array( 'choices-js', 'select2' ), true );

        if ( $is_legacy_dropdown && ! in_array( $carrier_code, array( 'inpost', 'orlen', 'novaPost' ) ) ) {
            $shipping_method_items = WC_Montonio_Shipping_Helper::get_items_for_montonio_shipping_method( $carrier_code, $type );

            if ( empty( $shipping_method_items ) ) {
                return;
            }

            wc_get_template(
                'shipping-pickup-points-dropdown.php',
                array(
                    'shipping_method'       => $chosen_rate->id,
                    'shipping_method_items' => $shipping_method_items
                ),
                '',
                WC_MONTONIO_PLUGIN_PATH . '/templates/'
            );

            return;
        }

        wc_get_template(
            'shipping-pickup-points-search.php',
            array(
                'carrier'   => $carrier_code,
                'type'      => $type,
                'operators' => $operators,
                'country'   => WC_Montonio_Shipping_Helper::get_customer_shipping_country(),
                'city'      => WC_Montonio_Shipping_Helper::get_customer_shipping_city()
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
    public static function validate_pickup_point() {
        if ( ! WC()->cart->needs_shipping() ) {
            return;
        }

        $chosen_rate = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_at_checkout();

        if ( empty( $chosen_rate ) ) {
            return;
        }

        if ( ! in_array( $chosen_rate->type, array( 'parcelMachine', 'postOffice', 'parcelShop' ) ) ) {
            return;
        }

        $shipping_method_item_id = isset( $_POST['montonio_pickup_point'] ) ? sanitize_text_field( wp_unslash( $_POST['montonio_pickup_point'] ) ) : null;

        if ( empty( $shipping_method_item_id ) ) {
            wc_add_notice( __( 'Please select a pickup point.', 'montonio-for-woocommerce' ), 'error' );
            return;
        }

        $shipping_method_item = WC_Montonio_Shipping_Item_Manager::get_shipping_method_item( $shipping_method_item_id );

        // Removed by a pickup point sync since it was selected, e.g. a point restored from sessionStorage.
        // The notice ID tells the pickup point script to forget the point, so a reload does not restore it again.
        if ( empty( $shipping_method_item ) ) {
            wc_add_notice( __( 'The selected pickup point is no longer available. Please select another pickup point.', 'montonio-for-woocommerce' ), 'error', array( 'id' => 'montonio-pickup-point-unavailable' ) );
            return;
        }

        if ( $chosen_rate->carrier_code !== $shipping_method_item->carrier_code ) {
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
    public static function add_details_to_shipping_label_ordered( $shipping_label, $order ) {
        $shipping_item = WC_Montonio_Shipping_Helper::get_chosen_montonio_shipping_method_for_order( $order );

        if ( empty( $shipping_item ) ) {
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
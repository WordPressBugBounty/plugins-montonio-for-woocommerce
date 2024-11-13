<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Helper for handling Montonio Shipping V2 helper functions
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Helper {
    /**
     * Gets the chosen Montonio shipping method at checkout. If the chosen shipping method is not Montonio, returns null.
     *
     * @since 7.0.0
     * @return Montonio_Shipping_Method|null The chosen Montonio shipping method at checkout, or null if the chosen shipping method is not Montonio.
     */
    public static function get_chosen_montonio_shipping_method_at_checkout() {
        if ( ! is_checkout() ) {
            return;
        }

        $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

        if ( empty( $chosen_shipping_methods ) ) {
            return;
        }

        $chosen_shipping_method_id = null;
        WC()->shipping()->calculate_shipping( WC()->cart->get_shipping_packages() );
        
        foreach ( WC()->shipping()->get_packages() as $i => $package ) {
            if ( ! isset( $chosen_shipping_methods[$i], $package['rates'][$chosen_shipping_methods[$i]] ) ) {
                return;
            }

            if ( strpos( $chosen_shipping_methods[$i], 'montonio_' ) === false ) {
                return;
            }

            $chosen_shipping_method_id = $chosen_shipping_methods[$i];
        }

        if ( empty( $chosen_shipping_method_id ) ) {
            return;
        }

        list( $method_id, $instance_id ) = explode( ':', $chosen_shipping_method_id );

        return self::create_shipping_method_instance( $method_id, $instance_id );
    }

    /**
     * Gets the order's chosen Montonio shipping method. If the chosen shipping method is not Montonio, returns null.
     *
     * @since 7.0.1 - fixed calling get_shipping_methods() on $order that does not have the method. For example WC_Coupon.
     * @since 7.0.0
     * @param WC_Order $order The order to get the chosen Montonio shipping method for.
     * @return Montonio_Shipping_Method|null The chosen Montonio shipping method for the order, or null if the chosen shipping method is not Montonio.
     */
    public static function get_chosen_montonio_shipping_method_for_order( $order ) {
        if ( empty( $order ) ) {
            return;
        }

        if ( ! method_exists( (object) $order, 'get_shipping_methods' ) ) {
            return;
        }

        $shipping_methods = $order->get_shipping_methods();
        if ( empty( $shipping_methods ) ) {
            return;
        }

        foreach ( $shipping_methods as $shipping_method ) {
            $shipping_method_id = $shipping_method->get_method_id();

            if ( strpos( $shipping_method_id, 'montonio_' ) !== false ) {
                return $shipping_method;
            }
        }

        return;
    }

    /**
     * Create an instance of the shipping method class.
     *
     * @since 7.0.0
     * @param string $method_id The shipping method ID.
     * @param int $instance_id The shipping method instance ID.
     * @return Montonio_Shipping_Method The shipping method instance.
     */
    public static function create_shipping_method_instance( $method_id, $instance_id = 0 ) {
        $shipping_class_names = WC()->shipping->get_shipping_method_class_names();

        return new $shipping_class_names[$method_id]( $instance_id );
    }

    /**
     * Gets the Montonio shipping method items for the given shipping method ID.
     *
     * @since 7.0.0
     * @param Montonio_Shipping_Method $montonio_shipping_method The Montonio shipping method to get items for.
     * @return array The Montonio shipping method items for the given ID. Returns an empty array if the shipping method ID does not exist or has no items.
     */
    public static function get_items_for_montonio_shipping_method( $montonio_shipping_method, $country_code = null ) {
        $type = $montonio_shipping_method->type_v2;

        if ( ! in_array( $type, ['parcelMachine', 'parcelShop', 'postOffice'] ) ) {
            return [];
        }

        $carrier_code          = $montonio_shipping_method->provider_name;
        $country               = $country_code ? $country_code : self::get_customer_shipping_country();
        $shipping_method_items = WC_Montonio_Shipping_Item_Manager::fetch_and_group_pickup_points( $country, $carrier_code, $type );

        return is_array( $shipping_method_items ) ? $shipping_method_items : [];
    }

    /**
     * Gets the customer's shipping country at checkout.
     *
     * @since 7.0.0
     * @return string|null The chosen Montonio shipping method id at checkout, or null if the shipping country does not exist.
     */
    public static function get_customer_shipping_country() {
        $customer = WC()->customer;

        if ( empty( $customer ) ) {
            return;
        }

        $country = $customer->get_shipping_country();

        if ( empty( $country ) ) {
            return;
        }

        if ( ! self::validate_country( $country ) ) {
            return;
        }

        return $country;
    }

    /**
     * Validates a country code.
     *
     * @since 7.0.0
     * @param string $country The country code to validate.
     * @return bool True if the country code is valid, false otherwise.
     */
    public static function validate_country( $country ) {
        if ( ! ctype_upper( $country ) ) {
            return false;
        }

        if ( strlen( $country ) != 2 ) {
            return false;
        }

        return true;
    }

    /**
     * Is Montonio Shipping V2 enabled?
     *
     * @since 7.0.0
     * @return boolean True if Montonio Shipping V2 is enabled, false otherwise
     */
    public static function is_enabled() {
        $is_enabled  = get_option( 'montonio_shipping_enabled' ) === 'yes';
        $is_using_v2 = get_option( 'montonio_shipping_enable_v2' ) === 'yes';

        return $is_enabled && $is_using_v2;
    }

    /**
     * Is Montonio Shipping V2 being used?
     *
     * @since 7.0.0
     * @return boolean True if Montonio Shipping V2 is being used, false otherwise
     */
    public static function is_using_v2() {
        return get_option( 'montonio_shipping_enable_v2' ) === 'yes';
    }

    /**
     * Get the phone number for the order.
     *
     * @since 7.0.0
     * @param WC_Order $order The order to get the phone number for.
     * @return string The phone number for the order.
     */
    public static function get_order_phone_number( $order ) {
        // Check if the order object has a get_shipping_phone method and get the shipping phone number
        $shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';

        // If the shipping phone number is not empty, return it
        if ( ! empty( $shipping_phone ) ) {
            return $shipping_phone;
        }

        // Get the billing phone number and return it
        $billing_phone = (string) $order->get_billing_phone();
        return $billing_phone;
    }

    /**
     * Get the email address for the order.
     *
     * @since 7.0.0
     * @param WC_Order $order The order to get the email address for.
     * @return string The email address for the order.
     */
    public static function get_order_email( $order ) {
        return method_exists( $order, 'get_shipping_email' ) ? (string) $order->get_shipping_email() : (string) $order->get_billing_email();
    }

    /**
     * Check if it is time to sync the shipping method items
     *
     * @since 7.0.0
     * @return boolean True if it is time to sync the shipping method items, false otherwise.
     */
    public static function is_time_to_sync_shipping_method_items() {
        $last_synced_at = get_option( 'montonio_shipping_sync_timestamp' );
        $current_time   = time();

        // Return true if never synced or if more than 24 hours have passed since last sync
        return ! $last_synced_at || ( $current_time - $last_synced_at ) > 24 * 60 * 60;
    }
}
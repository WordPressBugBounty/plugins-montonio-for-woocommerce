<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Item_Manager for handling Montonio Shipping V2 shipping method items
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Item_Manager {
    /**
     * Sync shipping method items in the database
     *
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $type Shipping method type
     * @return void
     */
    public static function sync_method_items( $type, $carrier = null, $country = null ) {
        global $wpdb;

        // Check if the plugin is in sandbox mode
        $sandbox_mode = get_option( 'montonio_shipping_sandbox_mode', 'no' );

        $montonio_shipping_api = new WC_Montonio_Shipping_API( $sandbox_mode );

        if ( $type === 'courierServices' ) {
            $response = $montonio_shipping_api->get_courier_services( $carrier, $country );
        } else {
            $response = $montonio_shipping_api->get_pickup_points( $carrier, $country );
        }

        // Handle invalid or empty response
        if ( empty( $response ) ) {
            return;
        }

        $data = json_decode( $response, true );

        // Check if essential data is present in the data
        if ( empty( $data[$type] ) ) {
            return;
        }

        // Filter out B2B-only items only for courier services
        $items_to_process = $data[$type];
        if ( $type === 'courierServices' ) {
            $items_to_process = array_filter( $data[$type], function ( $item ) {
                return ! isset( $item['b2bOnly'] ) || $item['b2bOnly'] === false;
            } );
        }

        // Start transaction for data integrity
        $wpdb->query( 'START TRANSACTION' );

        // Prepare a list of IDs for deletion query
        $shipping_item_ids = array_column( $items_to_process, 'id' );

        // Delete pickup points not present in the updated list
        $conditions = array();
        $params     = array();

        $optional_filters = array(
            'country_code' => $country,
            'carrier_code' => $carrier,
            'method_type'  => $type
        );

        foreach ( $optional_filters as $field => $value ) {
            if ( $value !== null ) {
                $conditions[] = "$field = %s";
                $params[]     = $value;
            }
        }

        // Create placeholders for item_ids
        $placeholders     = implode( ',', array_fill( 0, count( $shipping_item_ids ), '%s' ) );
        $where_conditions = implode( ' AND ', $conditions );
        $sql              = "DELETE FROM {$wpdb->prefix}montonio_shipping_method_items WHERE item_id NOT IN ( $placeholders ) AND $where_conditions";
        $params           = array_merge( $shipping_item_ids, $params );

        $wpdb->query( $wpdb->prepare( $sql, $params ) );

        // Insert or update records
        foreach ( $items_to_process as $shipping_item_data ) {
            $mapped_data = array(
                'item_id'        => $shipping_item_data['id'] ?? null,
                'item_name'      => $shipping_item_data['name'] ?? null,
                'item_type'      => $shipping_item_data['type'] ?? null,
                'method_type'    => $type,
                'street_address' => $shipping_item_data['streetAddress'] ?? null,
                'locality'       => $shipping_item_data['locality'] ?? null,
                'postal_code'    => $shipping_item_data['postalCode'] ?? null,
                'carrier_code'   => $shipping_item_data['carrierCode'] ?? null,
                'country_code'   => $shipping_item_data['countryCode'] ?? null
            );

            // Use REPLACE INTO for insert or update operation
            $wpdb->replace( $wpdb->prefix . 'montonio_shipping_method_items', $mapped_data );
        }

        // Commit the transaction
        $wpdb->query( 'COMMIT' );
    }

    /**
     * Get single shipping method item by ID
     *
     * @since 7.0.0
     * @param int $id Shipping method item ID
     * @return array|object|null Database query results.
     */
    public static function get_shipping_method_item( $id ) {
        global $wpdb;

        $query = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}montonio_shipping_method_items WHERE item_id = %s", array( $id ) ) );

        return $query;
    }

    /**
     * Get shipping method items by country, carrier and type
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, parcelMachine, postOffice, courier)
     * @return array|object|null Database query results.
     */
    public static function get_shipping_method_items( $country, $carrier, $type ) {
        global $wpdb;

        $query = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND item_type = %s", array( $country, $carrier, $type ) ) );

        return $query;
    }

    /**
     * Get courier ID by country and carrier
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @return int|null Database query result.
     */
    public static function get_courier_id( $country, $carrier ) {
        global $wpdb;

        $query = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT item_id FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND item_type = 'courier'", array( $country, $carrier ) ) );

        return $query;
    }

    /**
     * Get shipping method type available countries by carrier code and item type
     *
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, parcelMachine, postOffice, courier)
     * @return array List of country codes.
     */
    public static function get_shipping_method_countries( $carrier, $type ) {
        global $wpdb;

        $query = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT country_code FROM {$wpdb->prefix}montonio_shipping_method_items WHERE carrier_code = %s AND item_type = %s", array( $carrier, $type ) ), ARRAY_A );

        $country_codes = array_column( $query, 'country_code' );

        return $country_codes;
    }

    /**
     * Check if shipping method items exist
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, parcelMachine, postOffice, courier)
     * @return bool True if items exist, false otherwise.
     */
    public static function shipping_method_items_exist( $country, $carrier, $type ) {
        global $wpdb;

        $query = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND item_type = %s LIMIT 1", array( $country, $carrier, $type ) ) );

        return $query !== null;
    }

    /**
     * Fetch and group pickup points by locality.
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, parcelMachine, postOffice)
     * @return array Grouped pickup points by locality
     */
    public static function fetch_and_group_pickup_points( $country, $carrier, $type ) {
        global $wpdb;

        $query = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND item_type = %s ORDER BY item_name ASC", array( $country, $carrier, $type ) ) );

        $grouped_localities = array();

        foreach ( $query as $pickup_point ) {
            $locality                        = $pickup_point->locality;
            $grouped_localities[$locality][] = array(
                'id'      => $pickup_point->item_id,
                'name'    => $pickup_point->item_name,
                'type'    => $pickup_point->item_type,
                'address' => $pickup_point->street_address
            );
        }

        // Optionally sort the locality arrays based on the number of pickup points
        uasort( $grouped_localities, function ( $a, $b ) {
            return count( $b ) - count( $a );
        } );

        // If "1. eelistus Omnivas" exists, move it to the beginning
        if ( isset( $grouped_localities['1. eelistus Omnivas'] ) ) {
            $omnivas_entry = array( '1. eelistus Omnivas' => $grouped_localities['1. eelistus Omnivas'] );

            unset( $grouped_localities['1. eelistus Omnivas'] );

            $grouped_localities = $omnivas_entry + $grouped_localities;
        }

        return $grouped_localities;
    }
}
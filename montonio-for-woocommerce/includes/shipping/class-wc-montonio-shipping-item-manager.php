<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Item_Manager for handling Montonio Shipping V2 shipping method items
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Item_Manager {
    /**
     * Create temporary table for sync operations
     *
     * @since 9.1.3
     * @return void
     */
    public static function initialize_temp_table() {
        global $wpdb;

        $table_main = "{$wpdb->prefix}montonio_shipping_method_items";
        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";

        $wpdb->query( "DROP TABLE IF EXISTS {$table_temp}" );
        $wpdb->query( "CREATE TABLE {$table_temp} LIKE {$table_main}" );
    }

    /**
     * Swap temp table with main table atomically
     *
     * @since 9.1.3
     * @return void
     */
    public static function replace_main_table_with_temp() {
        global $wpdb;

        $table_main = "{$wpdb->prefix}montonio_shipping_method_items";
        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";

        $wpdb->query( "RENAME TABLE {$table_main} TO {$table_main}_old, {$table_temp} TO {$table_main}" );
        $wpdb->query( "DROP TABLE IF EXISTS {$table_main}_old" );
    }

    /**
     * Clean up temporary table
     *
     * @since 9.1.3
     * @return void
     */
    public static function remove_temp_table() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}montonio_shipping_method_items_temp" );
    }

    /**
     * Sync shipping method items to temporary table
     *
     * @since 9.1.3
     * @param string $type Shipping method type
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @return void
     */
    public static function import_shipping_items_to_temp( $type, $carrier = null, $country = null ) {
        global $wpdb;

        $montonio_shipping_api = new WC_Montonio_Shipping_API();

        if ( $type === 'courier' ) {
            $response = $montonio_shipping_api->get_courier_services( $carrier, $country );
            $data_key = 'courierServices';
        } else {
            $response = $montonio_shipping_api->get_pickup_points( $carrier, $country );
            $data_key = 'pickupPoints';
        }

        // Handle invalid or empty response
        if ( empty( $response ) ) {
            return;
        }

        $data = json_decode( $response, true );

        // Check if essential data is present in the data
        if ( empty( $data[$data_key] ) ) {
            return;
        }

        // Filter out B2B-only items only for courier services
        $items_to_process = $data[$data_key];
        if ( $type === 'courier' ) {
            $items_to_process = array_filter( $data[$data_key], function ( $item ) {
                return ! isset( $item['b2bOnly'] ) || $item['b2bOnly'] === false;
            } );
        }

        // Batch insert for better performance with large datasets
        if ( ! empty( $items_to_process ) ) {
            self::bulk_insert_into_temp_table( $items_to_process, $type );
        }
    }

    /**
     * Batch insert items to temporary table for optimal performance
     *
     * @since 9.1.3
     * @param array $items Items to insert
     * @param string $type Method type
     * @return void
     */
    private static function bulk_insert_into_temp_table( $items, $type ) {
        global $wpdb;
        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";

        // Use smaller batches for wpdb->prepare() - 1000 records at a time
        $batches = array_chunk( $items, 1000 );

        foreach ( $batches as $batch ) {
            $placeholders = array();
            $values       = array();

            foreach ( $batch as $item ) {
                $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)';

                // Encode additionalServices as JSON
                $additional_services = isset( $item['additionalServices'] ) && is_array( $item['additionalServices'] )
                ? json_encode( $item['additionalServices'] )
                : null;

                $values = array_merge( $values, array(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    $item['type'] ?? '',
                    $type,
                    $item['streetAddress'] ?? '',
                    $item['locality'] ?? '',
                    $item['postalCode'] ?? '',
                    $item['carrierCode'] ?? '',
                    $item['countryCode'] ?? '',
                    $item['carrierAssignedId'] ?? '',
                    $additional_services
                ) );
            }

            $sql = "INSERT INTO {$table_temp}
                (item_id, item_name, item_type, method_type, street_address, locality, postal_code, carrier_code, country_code, carrier_assigned_id, additional_services)
                VALUES " . implode( ',', $placeholders );

            $wpdb->query( $wpdb->prepare( $sql, $values ) );
        }
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
     * Get additional services for a shipping method
     *
     * @since 9.3.1
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, parcelMachine, postOffice, courier)
     * @return array Array of additional services, each containing 'code' key
     */
    public static function get_additional_services( $country, $carrier, $type ) {
        global $wpdb;

        $query = $wpdb->get_var( $wpdb->prepare( "SELECT additional_services FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND item_type = %s", array( $country, $carrier, $type ) ) );

        if ( empty( $query ) ) {
            return array();
        }

        return json_decode( $query, true ) ?: array();
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

        $query = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT item_id FROM {$wpdb->prefix}montonio_shipping_method_items WHERE country_code = %s AND carrier_code = %s AND method_type = 'courier'", array( $country, $carrier ) ) );

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
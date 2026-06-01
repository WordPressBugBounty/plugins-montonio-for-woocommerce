<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Sync
 * Handles syncing of shipping method items from Montonio API to the local database.
 *
 * @since 9.4.1
 */
class WC_Montonio_Shipping_Sync {
    const CRON_HOOK = 'montonio_shipping_methods_sync_event';

    /**
     * Hook in methods.
     *
     * @return void
     */
    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'sync_async' ) );
        add_action( 'init', array( __CLASS__, 'setup_sync' ) );
        add_action( 'woocommerce_shipping_zone_method_added', array( __CLASS__, 'sync_async' ) );
        add_filter( 'montonio_ota_sync', array( __CLASS__, 'handle_ota_sync' ), 20, 1 );    
    }

    /**
     * Set up the shipping sync mechanism.
     *
     * Uses WP-Cron by default. Falls back to a throttled wp_loaded-based
     * sync when WP-Cron is disabled via the DISABLE_WP_CRON constant.
     *
     * @since 9.4.2
     */
    public static function setup_sync() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }

        // Fall back to wp_loaded-based throttled sync when WP-Cron is disabled.
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            add_action( 'wp_loaded', array( __CLASS__, 'run_fallback_sync' ) );
        }
    }

    /**
     * Sync if enough time has passed since the last sync.
     *
     * @since 7.0.1
     * @return void
     */
    public static function run_fallback_sync() {
        $last_sync = (int) get_option( 'montonio_shipping_sync_timestamp', 0 );

        if ( time() - $last_sync <= 24 * HOUR_IN_SECONDS ) {
            return;
        }

        update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

        self::sync_async();
    }

    /**
     * Trigger sync via async REST request.
     * Used to avoid blocking the main request.
     *
     * @since 7.0.1
     * @return void
     */
    public static function sync_async() {
        if ( 'yes' !== get_option( 'montonio_shipping_enabled' ) ) {
            return;
        }

        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            return;
        }

        $url   = esc_url_raw( rest_url( 'montonio/shipping/v2/sync-shipping-method-items' ) );
        $token = WC_Montonio_Helper::create_jwt_token( array(
            'hash' => md5( $url )
        ) );

        wp_remote_post( $url, array(
            'method'   => 'POST',
            'timeout'  => 0.01,
            'blocking' => false,
            'body'     => array( 'token' => $token )
        ) );
    }

    /**
     * Sync all shipping method items from the Montonio API.
     *
     * @since 7.0.0
     * @since 9.1.3 - Use new methods in WC_Montonio_Shipping_Item_Manager
     * @since 9.3.1 - Added concurrency lock
     * @return true|WP_Error
     */
    public static function sync() {
        $lock_manager = new Montonio_Lock_Manager();

        if ( ! $lock_manager->acquire_lock( 'montonio_shipping_method_items_sync' ) ) {
            return new WP_Error( 'wc_montonio_shipping_sync_locked', 'Sync already in progress.' );
        }

        update_option( 'montonio_shipping_sync_timestamp', time(), 'no' );

        try {
            self::initialize_temp_table();

            $courier_services_synced = false;
            $pickup_point_carriers   = array();

            $shipping_api     = new WC_Montonio_Shipping_API();
            $shipping_methods = json_decode( $shipping_api->get_shipping_methods(), true );

            foreach ( $shipping_methods['countries'] as $country ) {
                if ( empty( $country['carriers'] ) ) {
                    continue;
                }

                foreach ( $country['carriers'] as $carrier ) {
                    $carrier_code = $carrier['carrierCode'];

                    foreach ( $carrier['shippingMethods'] as $method ) {
                        if ( 'courier' === $method['type'] ) {
                            if ( false === $courier_services_synced ) {
                                $courier_services_synced = true;
                                self::import_courier_services_to_temp();
                            }
                            continue;
                        }

                        if ( in_array( $carrier_code, $pickup_point_carriers ) ) {
                            continue;
                        }

                        $pickup_point_carriers[] = $carrier_code;
                        self::import_pickup_points_to_temp( $carrier_code );
                    }
                }
            }

            self::replace_main_table_with_temp();

            return true;
        } catch ( Exception $e ) {
            self::remove_temp_table();
            WC_Montonio_Logger::log( 'Shipping method sync failed. Response: ' . $e->getMessage() );

            return new WP_Error( 'wc_montonio_shipping_sync_error', $e->getMessage(), array( 'status' => 500 ) );
        } finally {
            $lock_manager->release_lock( 'montonio_shipping_method_items_sync' );
        }
    }

    /**
     * Handle sync triggered by an over-the-air update.
     *
     * @since 7.1.2
     * @param array $status_report
     * @return array
     */
    public static function handle_ota_sync( $status_report ) {
        $result = self::sync();

        if ( is_wp_error( $result ) ) {
            $status_report['sync_results'][] = array(
                'status'  => 'error',
                'message' => 'Shipping method sync failed: ' . $result->get_error_message(),
            );
        } else {
            $status_report['sync_results'][] = array(
                'status'  => 'success',
                'message' => 'Shipping method sync successful',
            );
        }

        return $status_report;
    }

    /**
     * Create temporary table for sync operations.
     *
     * @return void
     */
    private static function initialize_temp_table() {
        global $wpdb;

        $table_main = "{$wpdb->prefix}montonio_shipping_method_items";
        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";

        $wpdb->query( "DROP TABLE IF EXISTS {$table_temp}" );
        $wpdb->query( "CREATE TABLE {$table_temp} LIKE {$table_main}" );
    }

    /**
     * Swap temp table with main table atomically.
     *
     * @return void
     */
    private static function replace_main_table_with_temp() {
        global $wpdb;

        $table_main = "{$wpdb->prefix}montonio_shipping_method_items";
        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";

        $wpdb->query( "RENAME TABLE {$table_main} TO {$table_main}_old, {$table_temp} TO {$table_main}" );
        $wpdb->query( "DROP TABLE IF EXISTS {$table_main}_old" );
    }

    /**
     * Clean up temporary table on failure.
     *
     * @return void
     */
    private static function remove_temp_table() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}montonio_shipping_method_items_temp" );
    }

    /**
     * Fetch courier services from the Montonio API and insert into the temp table.
     *
     * @param string|null $carrier Carrier code
     * @param string|null $country Country code (ISO 3166-1 alpha-2)
     * @return void
     */
    private static function import_courier_services_to_temp( $carrier = null, $country = null ) {
        $api      = new WC_Montonio_Shipping_API();
        $response = $api->get_courier_services( $carrier, $country );

        if ( empty( $response ) ) {
            return;
        }

        $data = json_decode( $response, true );
        unset( $response );

        if ( empty( $data['courierServices'] ) ) {
            return;
        }

        $items = array_filter( $data['courierServices'], function ( $item ) {
            return ! isset( $item['b2bOnly'] ) || $item['b2bOnly'] === false;
        } );
        unset( $data );

        if ( ! empty( $items ) ) {
            self::bulk_insert_into_temp_table( $items, 'courier' );
        }
    }

    /**
     * Stream pickup points from the Montonio API into the temp table.
     *
     * The response is downloaded to a temp file and parsed via json-machine so peak
     * memory stays a few MB regardless of carrier size. On malformed or empty 
     * payloads we swallow the parser exception so a single misbehaving carrier
     * doesn't poison the whole sync.
     *
     * @param string|null $carrier Carrier code
     * @param string|null $country Country code (ISO 3166-1 alpha-2)
     * @return void
     */
    private static function import_pickup_points_to_temp( $carrier = null, $country = null ) {
        $api = new WC_Montonio_Shipping_API();
        $tmp = $api->download_pickup_points( $carrier, $country );

        try {
            $items = \MontonioJsonMachine\Items::fromFile( $tmp, array(
                'pointer' => '/pickupPoints',
                'decoder' => new \MontonioJsonMachine\JsonDecoder\ExtJsonDecoder( true ),
            ) );
            self::bulk_insert_into_temp_table( $items, 'pickupPoints' );
        } catch ( \MontonioJsonMachine\Exception\PathNotFoundException $e ) {
            // Well-formed JSON but no `pickupPoints` key — anomalous (contract mismatch).
            WC_Montonio_Logger::log( sprintf(
                'Pickup points sync: response for carrier %s has no `pickupPoints` key, skipping.',
                $carrier ?? 'all'
            ) );
        } catch ( \MontonioJsonMachine\Exception\SyntaxErrorException $e ) {
            // Empty or non-JSON body — do nothing.
            WC_Montonio_Logger::log( sprintf(
                'Pickup points sync: unparseable response for carrier %s, skipping. %s',
                $carrier ?? 'all',
                $e->getMessage()
            ) );
        } finally {
            @unlink( $tmp );
        }
    }

    /**
     * Batch insert items into the temp table.
     *
     * Accepts any iterable so a streaming JSON parser can feed items lazily
     * without materializing the whole list in memory.
     *
     * @param iterable $items Items to insert
     * @param string   $type  Method type
     * @return void
     */
    private static function bulk_insert_into_temp_table( $items, $type ) {
        global $wpdb;

        $table_temp = "{$wpdb->prefix}montonio_shipping_method_items_temp";
        $batch_size = 1000;
        $batch      = array();

        foreach ( $items as $item ) {
            $batch[] = $item;

            if ( count( $batch ) >= $batch_size ) {
                self::insert_batch( $table_temp, $batch, $type );
                $batch = array();
            }
        }

        if ( ! empty( $batch ) ) {
            self::insert_batch( $table_temp, $batch, $type );
        }
    }

    /**
     * Insert a single batch of items.
     *
     * @param string $table Table name
     * @param array  $batch Items in this batch
     * @param string $type  Method type
     * @return void
     */
    private static function insert_batch( $table, $batch, $type ) {
        global $wpdb;

        $placeholders = array();
        $values       = array();

        foreach ( $batch as $item ) {
            $placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)';

            $additional_services = isset( $item['additionalServices'] ) && is_array( $item['additionalServices'] )
                ? wp_json_encode( $item['additionalServices'] )
                : null;

            $values[] = $item['id'] ?? '';
            $values[] = $item['name'] ?? '';
            $values[] = $item['type'] ?? '';
            $values[] = $type;
            $values[] = $item['streetAddress'] ?? '';
            $values[] = $item['locality'] ?? '';
            $values[] = $item['postalCode'] ?? '';
            $values[] = $item['carrierCode'] ?? '';
            $values[] = $item['countryCode'] ?? '';
            $values[] = $item['carrierAssignedId'] ?? '';
            $values[] = $additional_services;
        }

        $sql = "INSERT INTO {$table}
            (item_id, item_name, item_type, method_type, street_address, locality, postal_code, carrier_code, country_code, carrier_assigned_id, additional_services)
            VALUES " . implode( ',', $placeholders );

        $wpdb->query( $wpdb->prepare( $sql, $values ) );
    }
}
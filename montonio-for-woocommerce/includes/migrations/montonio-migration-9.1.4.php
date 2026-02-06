<?php
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

class Montonio_Migration_9_1_4 {

    public static function migrate_up() {
        self::ensure_montonio_shipping_method_items_table();
        self::create_montonio_locks_table();
        self::drop_montonio_shipping_labels_table();
    }

    /**
     * Ensure montonio_shipping_method_items table exists with all required columns
     * Creates table if missing, adds missing columns if table exists
     *
     * @return void
     */
    public static function ensure_montonio_shipping_method_items_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $collate    = $wpdb->get_charset_collate();

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $table_name
            )
        );

        if ( ! $table_exists ) {
            // Table doesn't exist - create it with all columns including additional_services
            $sql = "CREATE TABLE $table_name (
                item_id CHAR(36) PRIMARY KEY,
                item_name VARCHAR(255),
                item_type VARCHAR(100),
                method_type VARCHAR(100),
                street_address VARCHAR(255),
                locality VARCHAR(100),
                postal_code VARCHAR(20),
                carrier_code VARCHAR(50),
                country_code CHAR(2),
                carrier_assigned_id VARCHAR(50),
                additional_services TEXT NULL
            ) $collate;";

            dbDelta( $sql );
        } else {
            // Check if carrier_assigned_id column exists
            $carrier_assigned_id = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                    DB_NAME,
                    $table_name,
                    'carrier_assigned_id'
                )
            );

            if ( ! $carrier_assigned_id ) {
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN carrier_assigned_id VARCHAR(50) AFTER country_code"
                );
            }

            // Check if additional_services column exists
            $additional_services = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = %s
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s',
                    DB_NAME,
                    $table_name,
                    'additional_services'
                )
            );

            if ( ! $additional_services ) {
                $wpdb->query(
                    "ALTER TABLE {$table_name}
                    ADD COLUMN additional_services TEXT NULL AFTER carrier_assigned_id"
                );
            }
        }

        update_option( 'montonio_shipping_sync_timestamp', null, 'no' );
    }

    /**
     * Create montonio_locks table if it doesn't exist
     *
     * @return void
     */
    public static function create_montonio_locks_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'montonio_locks';
        $collate    = $wpdb->get_charset_collate();
        $sql        = "CREATE TABLE IF NOT EXISTS $table_name (
            lock_name VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (lock_name)
        ) $collate;";

        dbDelta( $sql );
    }

    /**
     * Remove montonio_shipping_labels table if it exists
     *
     * @return void
     */
    public static function drop_montonio_shipping_labels_table() {
        global $wpdb;

        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}montonio_shipping_labels`;" );
    }
}

Montonio_Migration_9_1_4::migrate_up();
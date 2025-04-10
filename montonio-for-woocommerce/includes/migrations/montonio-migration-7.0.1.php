<?php
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

class Montonio_Migration_7_0_1 {

    public static function migrate_up() {
        self::create_montonio_shipping_method_items_table();
        self::create_montonio_locks_table();
        self::drop_montonio_shipping_labels_table();
    }

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

    public static function create_montonio_shipping_method_items_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $collate    = $wpdb->get_charset_collate();
        $sql        = "CREATE TABLE IF NOT EXISTS $table_name (
            item_id CHAR(36) PRIMARY KEY,
            item_name VARCHAR(255),
            item_type VARCHAR(100),
            method_type VARCHAR(100),
            street_address VARCHAR(255),
            locality VARCHAR(100),
            postal_code VARCHAR(20),
            carrier_code VARCHAR(50),
            country_code CHAR(2)
        ) $collate;";

        dbDelta( $sql );
    }

    public static function drop_montonio_shipping_labels_table() {
        global $wpdb;

        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}montonio_shipping_labels`;" );
    }
}

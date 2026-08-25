<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_3_3 {

    /**
     * Add a composite index to the montonio_shipping_method_items table.
     *
     * The table previously had only its PRIMARY KEY on item_id, but the
     * storefront pickup-point / shipping-method lookups all filter by
     * country_code, carrier_code and item_type. Without a matching index every
     * checkout render triggered a full table scan (the table can grow to tens
     * of MB of pickup points), driving up DB/CPU load. This adds the index the
     * hot queries need.
     *
     * Idempotent and safe to re-run: it no-ops when the table is missing or the
     * index already exists. Adding a non-unique index on InnoDB is an online
     * operation and does not modify any row data.
     *
     * @return void
     */
    public static function migrate_up() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $index_name = 'idx_montonio_lookup';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                DB_NAME,
                $table_name
            )
        );

        if ( ! $table_exists ) {
            return;
        }

        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
                DB_NAME,
                $table_name,
                $index_name
            )
        );

        if ( $index_exists ) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE `{$table_name}`
            ADD INDEX {$index_name} (country_code, carrier_code, item_type)"
        );
    }
}

Montonio_Migration_10_3_3::migrate_up();

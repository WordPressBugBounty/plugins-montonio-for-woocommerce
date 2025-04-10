<?php

/**
 * Class Montonio_Lock_Manager
 * Provides locking functionality using a database to prevent multiple processes from running the same code simultaneously.
 * @since 7.0.0
 */
class Montonio_Lock_Manager {

    /**
     * Attempts to acquire a lock.
     * If the lock is already present and not expired, it will not be acquired.
     *
     * @since 7.0.0
     * @param string $lock_name Name of the lock to acquire.
     * @return bool Returns true if lock was successfully acquired, false otherwise.
     */
    public function acquire_lock( $lock_name ) {
        global $wpdb;

        $lock_name  = sanitize_text_field( $lock_name );
        $now        = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + 60 );

        $wpdb->query( $wpdb->prepare( "INSERT INTO `{$wpdb->prefix}montonio_locks` (`lock_name`, `created_at`, `expires_at`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `expires_at` = IF(`expires_at` <= %s, VALUES(`expires_at`), `expires_at`)", $lock_name, $now, $expires_at, $now ) );

        return $wpdb->rows_affected > 0;
    }

    /**
     * Releases a lock.
     *
     * @since 7.0.0
     * @param string $lock_name Name of the lock to release.
     * @return void
     */
    public function release_lock( $lock_name ) {
        global $wpdb;

        $lock_name  = sanitize_text_field( $lock_name );

        $wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}montonio_locks`  WHERE `lock_name` = %s", $lock_name ) );
    }

    /**
     * Checks if a lock exists and is not expired.
     *
     * @since 7.0.1
     * @param string $lock_name Name of the lock to check.
     * @return bool Returns true if lock exists and is not expired, false otherwise.
     */
    public function lock_exists( $lock_name ) {
        global $wpdb;

        $lock_name = sanitize_text_field( $lock_name );
        $now       = gmdate( 'Y-m-d H:i:s' );

        $query = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$wpdb->prefix}montonio_locks` WHERE `lock_name` = %s AND `expires_at` > %s", $lock_name, $now ) );

        return (bool) $query;
    }
}

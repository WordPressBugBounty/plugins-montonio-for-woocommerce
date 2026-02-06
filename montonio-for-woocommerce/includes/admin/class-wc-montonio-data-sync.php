<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Sync
 *
 * @since 9.3.0
 */
class WC_Montonio_Data_Sync {
    const CRON_HOOK = 'montonio_payment_methods_sync_event';

    public static function init() {
        add_filter( 'montonio_ota_sync', array( __CLASS__, 'sync_payment_methods_ota' ), 10, 1 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'sync_data' ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }

        // Fallback for disabled WP-Cron environments
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            add_action( 'init', array( __CLASS__, 'run_fallback_sync' ) );
        }
    }

    /**
     * Sync payment methods.
     *
     * @since 9.3.0
     */
    public static function sync_data() {
        $lock_manager = new Montonio_Lock_Manager();

        if ( ! $lock_manager->acquire_lock( 'montonio_payment_methods_sync' ) ) {
            return;
        }

        try {
            self::sync_payment_methods();
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Data sync failed: ' . $e->getMessage() );
        } finally {
            $lock_manager->release_lock( 'montonio_payment_methods_sync' );
        }
    }

    /**
     * Fallback sync for environments where WP-Cron is disabled.
     *
     * Performs a throttled sync during page loads.
     *
     * @since 9.3.0
     * @return void
     */
    public static function run_fallback_sync() {
        $last_sync = (int) get_option( 'montonio_last_sync', 0 );

        // Throttle: sync only if older than 24 hours
        if ( time() - $last_sync > 24 * HOUR_IN_SECONDS ) {
            self::sync_data();
            update_option( 'montonio_last_sync', time(), false );
        }
    }

    /**
     * Sync payment methods when over-the-air trigger is received.
     *
     * @since 9.3.0
     * @param array $status_report The status report of the OTA sync
     */
    public static function sync_payment_methods_ota( $status_report ) {
        try {
            self::sync_payment_methods();

            $status_report['sync_results'][] = array(
                'status'  => 'success',
                'message' => 'Payment method sync successful'
            );
        } catch ( Exception $e ) {
            $status_report['sync_results'][] = array(
                'status'  => 'error',
                'message' => 'Payment method sync failed: ' . $e->getMessage()
            );
        }

        return $status_report;
    }

    /**
     * Sync payment methods and update the option.
     *
     * @since 9.3.0
     */
    public static function sync_payment_methods() {
        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            throw new Exception( __( 'API keys not configured.', 'montonio-for-woocommerce' ) );
        }

        $montonio_api = new WC_Montonio_API();
        $response     = $montonio_api->get_payment_methods();

        if ( empty( $response ) ) {
            throw new Exception( __( 'Empty response from API.', 'montonio-for-woocommerce' ) );
        }

        update_option( 'montonio_payment_methods', $response, false );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
WC_Montonio_Data_Sync::init();

register_deactivation_hook( __FILE__, array( 'WC_Montonio_Data_Sync', 'deactivate' ) );
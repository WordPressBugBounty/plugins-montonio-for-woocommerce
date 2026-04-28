<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_0_1 {

    /**
     * Remove legacy options that are no longer used by the plugin.
     *
     * @return void
     */
    public static function migrate_up() {
        $options_to_delete = array(
            // Legacy shipping sender settings (replaced by Montonio Shipping API)
            'montonio_shipping_senderName',
            'montonio_shipping_senderPhone',
            'montonio_shipping_senderStreetAddress',
            'montonio_shipping_senderLocality',
            'montonio_shipping_senderRegion',
            'montonio_shipping_senderPostalCode',
            'montonio_shipping_senderCountry',

            // Legacy shipping UI/behavior settings
            'montonio_shipping_css',
            'montonio_shipping_enqueue_mode',
            'montonio_shipping_register_selectWoo',
            'montonio_shipping_is_tracking_webhook_registered',

            // Legacy gateway settings (migrated to wc_montonio_* in 6.4.2)
            'woocommerce_montonio_shipping_settings',
            'woocommerce_montonio_blik_payments_settings',
            'woocommerce_montonio_payments_settings',
            'woocommerce_montonio_split_settings',
            'woocommerce_montonio_card_payments_settings',
            'woocommerce_montonio_settings',

            // Legacy API credentials (migrated to wc_montonio_api_settings in 6.4.2)
            'montonio_shipping_accessKey',
            'montonio_shipping_secretKey',
            'montonio_sandbox_access_key',
            'montonio_sandbox_secret_key',
            'montonio_access_key',
            'montonio_secret_key',

            // Legacy shipping sync/setup state
            'montonio_pickup_points_synced_at',
            'montonio_carriers_pickup_points_countries',
            'montonio_carriers_pickup_point_countries',
            'montonio_shipping_methods',
            'montonio_shipping_webhook_url_hash',
            'montonio_shipping_enable_v2',
            'montonio_shipping_order_prefix',
            'montonio_shipping_is_shipping_set_up',
            'montonio_shipping_sandbox_mode',
        );

        foreach ( $options_to_delete as $option ) {
            delete_option( $option );
        }

        self::delete_legacy_shipping_provider_options();
        self::cleanup_payment_method_settings();
    }

    /**
     * Remove legacy country/provider/type specific shipping options.
     * Format: montonio_{country}_{provider}_{type}
     *
     * @return void
     */
    private static function delete_legacy_shipping_provider_options() {
        $countries = array( 'EE', 'LV', 'LT', 'FI', 'SE' );
        $providers = array( 'omniva', 'dpd', 'itella', 'venipak' );
        $types     = array( 'parcel_machine', 'post_office', 'parcel_shop' );

        foreach ( $countries as $country ) {
            foreach ( $providers as $provider ) {
                foreach ( $types as $type ) {
                    delete_option( 'montonio_' . $country . '_' . $provider . '_' . $type );
                }
            }
        }
    }

    /**
     * Remove unused keys from payment method settings arrays.
     *
     * @return void
     */
    private static function cleanup_payment_method_settings() {
        $settings_keys_to_remove = array(
            'woocommerce_wc_montonio_payments_settings' => array(
                'sandbox_mode',
                'sandbox_keys_title',
                'sandbox_access_key',
                'sandbox_secret_key',
                'live_keys_title',
                'access_key',
                'secret_key',
                'script_mode',
                'bank_list',
                'sandbox_settings',
                'bank_list_fetch_datetime',
                'test_mode',
                'get_user_country',
            ),
            'woocommerce_wc_montonio_card_settings' => array(
                'sandbox_mode',
                'sync_timestamp',
                'test_mode',
            ),
            'woocommerce_wc_montonio_blik_settings' => array(
                'sandbox_mode',
                'blick_in_chekout',
                'test_mode',
                'script_mode',
            ),
            'woocommerce_wc_montonio_bnpl_settings' => array(
                'sandbox_mode',
                'test_mode',
            ),
            'woocommerce_wc_montonio_hire_purchase_settings' => array(
                'sandbox_mode',
                'test_mode',
            ),
        );

        foreach ( $settings_keys_to_remove as $option_name => $keys ) {
            $settings = get_option( $option_name, array() );

            if ( empty( $settings ) ) {
                continue;
            }

            $updated = false;

            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $settings ) ) {
                    unset( $settings[ $key ] );
                    $updated = true;
                }
            }

            if ( $updated ) {
                update_option( $option_name, $settings );
            }
        }
    }
}

Montonio_Migration_10_0_1::migrate_up();

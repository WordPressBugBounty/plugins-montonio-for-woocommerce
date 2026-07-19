<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_3_1 {

    /**
     * Re-arm MobilePay for merchants with card payments enabled, mirroring the
     * 10.3.0 migration. Stores without MobilePay in the synced data keep the
     * toggle locked off, but the stored enabled=yes activates the method
     * automatically once Montonio makes it available.
     *
     * @return void
     */
    public static function migrate_up() {
        $card_settings = get_option( 'woocommerce_wc_montonio_card_settings', array() );

        if ( ! is_array( $card_settings ) || 'yes' !== ( $card_settings['enabled'] ?? 'no' ) ) {
            return;
        }

        $option_name = 'woocommerce_wc_montonio_mobilepay_settings';
        $settings    = get_option( $option_name, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $settings['enabled'] = 'yes';

        update_option( $option_name, $settings );
    }
}

Montonio_Migration_10_3_1::migrate_up();

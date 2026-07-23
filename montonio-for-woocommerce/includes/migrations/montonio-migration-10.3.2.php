<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_3_2 {

    /**
     * Enable the MobilePay payment method once on update so it is available
     * right away, but only for merchants who already have card payments
     * enabled. Runs a single time (gated by the plugin version), so merchants
     * are free to disable it again afterwards.
     *
     * @return void
     */
    public static function migrate_up() {
        self::position_mobilepay_first();

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

    /**
     * Move MobilePay to the topmost Montonio position in the saved gateway
     * order, shifting gateways at or below it down by one. No-ops when no
     * Montonio gateway has a numeric position or MobilePay is already first.
     *
     * @return void
     */
    private static function position_mobilepay_first() {
        $order = get_option( 'woocommerce_gateway_order', array() );

        if ( ! is_array( $order ) ) {
            return;
        }

        $montonio_gateways = array(
            'wc_montonio_payments',
            'wc_montonio_card',
            'wc_montonio_blik',
            'wc_montonio_bnpl',
            'wc_montonio_hire_purchase',
        );

        $target_position = null;

        foreach ( $montonio_gateways as $gateway_id ) {
            if ( isset( $order[ $gateway_id ] ) && is_numeric( $order[ $gateway_id ] ) ) {
                $position = (int) $order[ $gateway_id ];

                if ( null === $target_position || $position < $target_position ) {
                    $target_position = $position;
                }
            }
        }

        if ( null === $target_position ) {
            return;
        }

        if ( isset( $order['wc_montonio_mobilepay'] ) && is_numeric( $order['wc_montonio_mobilepay'] ) && (int) $order['wc_montonio_mobilepay'] <= $target_position ) {
            return;
        }

        foreach ( $order as $gateway_id => $position ) {
            if ( 'wc_montonio_mobilepay' !== $gateway_id && is_numeric( $position ) && (int) $position >= $target_position ) {
                $order[ $gateway_id ] = (int) $position + 1;
            }
        }

        $order['wc_montonio_mobilepay'] = $target_position;

        update_option( 'woocommerce_gateway_order', $order );
    }
}

Montonio_Migration_10_3_2::migrate_up();

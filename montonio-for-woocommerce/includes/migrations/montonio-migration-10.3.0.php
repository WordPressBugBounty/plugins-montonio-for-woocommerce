<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_10_3_0 {

    /**
     * Enable the MobilePay payment method once on update so it is available
     * right away, but only for merchants who already have card payments
     * enabled. Runs a single time (gated by the plugin version), so merchants
     * are free to disable it again afterwards.
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

        self::position_mobilepay_first();
    }

    /**
     * Make MobilePay the first Montonio payment method in the WooCommerce
     * gateway order. WooCommerce sorts gateways by the `woocommerce_gateway_order`
     * option (gateway id => numeric position, ascending). MobilePay takes the
     * position of the currently topmost Montonio gateway, and every gateway at
     * or below that position is shifted down by one — so MobilePay lands above
     * all other Montonio methods while any non-Montonio gateways above them and
     * the merchant's existing relative ordering are preserved.
     *
     * Only runs when at least one other Montonio gateway already has a numeric
     * position (i.e. the merchant has visited the Payments settings screen);
     * otherwise MobilePay keeps its default position and nothing is changed.
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

        // Find the position of the topmost (lowest numbered) Montonio gateway.
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

        foreach ( $order as $gateway_id => $position ) {
            if ( 'wc_montonio_mobilepay' !== $gateway_id && is_numeric( $position ) && (int) $position >= $target_position ) {
                $order[ $gateway_id ] = (int) $position + 1;
            }
        }

        $order['wc_montonio_mobilepay'] = $target_position;

        update_option( 'woocommerce_gateway_order', $order );
    }
}

Montonio_Migration_10_3_0::migrate_up();

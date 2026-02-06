<?php
defined( 'ABSPATH' ) || exit;

class Montonio_Migration_6_4_2 {
    public static function migrate_up() {
        $pis_v1_settings     = get_option( 'woocommerce_montonio_payments_settings', array() );
        $pis_v2_settings     = get_option( 'woocommerce_wc_montonio_payments_settings', array() );
        $cards_v1_settings   = get_option( 'woocommerce_montonio_card_payments_settings', array() );
        $blik_v1_settings    = get_option( 'woocommerce_montonio_blik_payments_settings', array() );
        $shipping_access_key = get_option( 'montonio_shipping_accessKey' );
        $shipping_secret_key = get_option( 'montonio_shipping_secretKey' );
        $api_settings        = get_option( 'woocommerce_wc_montonio_api_settings', array() );

        if ( empty( $api_settings['access_key'] ) || empty( $api_settings['secret_key'] ) ) {
            $access_key = null;
            $secret_key = null;

            if ( ! empty( $pis_v2_settings['access_key'] ) && ! empty( $pis_v2_settings['secret_key'] ) ) {
                $access_key = $pis_v2_settings['access_key'];
                $secret_key = $pis_v2_settings['secret_key'];
            } elseif ( isset( $pis_v1_settings['montonioPaymentsEnvironment'] ) &&
                'production' == $pis_v1_settings['montonioPaymentsEnvironment'] &&
                ! empty( $pis_v1_settings['montonioPaymentsAccessKey'] ) &&
                ! empty( $pis_v1_settings['montonioPaymentsSecretKey'] ) ) {
                $access_key = $pis_v1_settings['montonioPaymentsAccessKey'];
                $secret_key = $pis_v1_settings['montonioPaymentsSecretKey'];
            } elseif ( isset( $cards_v1_settings['montonioCardPaymentsEnvironment'] ) &&
                'production' == $cards_v1_settings['montonioCardPaymentsEnvironment'] &&
                ! empty( $cards_v1_settings['montonioCardPaymentsAccessKey'] ) &&
                ! empty( $cards_v1_settings['montonioCardPaymentsSecretKey'] ) ) {
                $access_key = $cards_v1_settings['montonioCardPaymentsAccessKey'];
                $secret_key = $cards_v1_settings['montonioCardPaymentsSecretKey'];
            } elseif ( isset( $blik_v1_settings['montonioBlikPaymentsEnvironment'] ) &&
                'production' == $blik_v1_settings['montonioBlikPaymentsEnvironment'] &&
                ! empty( $blik_v1_settings['montonioBlikPaymentsAccessKey'] ) &&
                ! empty( $blik_v1_settings['montonioBlikPaymentsSecretKey'] ) ) {
                $access_key = $blik_v1_settings['montonioBlikPaymentsAccessKey'];
                $secret_key = $blik_v1_settings['montonioBlikPaymentsSecretKey'];
            } elseif ( ! empty( $shipping_access_key ) && ! empty( $shipping_secret_key ) ) {
                $access_key = $shipping_access_key;
                $secret_key = $shipping_secret_key;
            }

            if ( null != $access_key && null != $secret_key ) {
                $api_settings['access_key'] = $access_key;
                $api_settings['secret_key'] = $secret_key;
                update_option( 'woocommerce_wc_montonio_api_settings', $api_settings );
            }
        }
    }
}

Montonio_Migration_6_4_2::migrate_up();

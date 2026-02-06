<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Utilities\OrderUtil;

class WC_Montonio_Helper {
    /**
     * Get the main Montonio settings option.
     *
     * @return array The Montonio main settings.
     */
    public static function get_api_settings() {
        $settings = get_option( 'woocommerce_wc_montonio_api_settings', array() );

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Checks if the plugin is in test mode.
     *
     * @return bool Whether the plugin is in test mode.
     */
    public static function is_test_mode() {
        $settings = self::get_api_settings();
        
        return ( $settings['test_mode'] ?? 'no' ) === 'yes';
    }

    /**
     * Get Montonio API keys.
     *
     * @return array {
     *     @type string $access_key API access key
     *     @type string $secret_key API secret key
     * }
     */
    public static function get_api_keys() {
        $settings = self::get_api_settings();

        $prefix = ( $settings['test_mode'] ?? 'no' ) === 'yes' ? 'sandbox_' : '';

        $keys = array(
            'access_key' => $settings[$prefix . 'access_key'] ?? '',
            'secret_key' => $settings[$prefix . 'secret_key'] ?? ''
        );

        return apply_filters( 'wc_montonio_api_keys', $keys );
    }

    /**
     * Check if API keys are configured.
     *
     * @since 9.3.0
     * @return bool True if both access_key and secret_key are set.
     */
    public static function has_api_keys() {
        $api_keys = self::get_api_keys();

        return ! empty( $api_keys['access_key'] ) && ! empty( $api_keys['secret_key'] );
    }

    /**
     * Get order ID by meta data
     *
     * @return int
     */
    public static function get_order_id_by_meta_data( $meta_value, $meta_key ) {
        global $wpdb;

        if ( self::is_hpos_enabled() ) {
            $order_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT {$wpdb->prefix}wc_orders.id FROM {$wpdb->prefix}wc_orders
                    INNER JOIN {$wpdb->prefix}wc_orders_meta ON {$wpdb->prefix}wc_orders_meta.order_id = {$wpdb->prefix}wc_orders.id
                    WHERE {$wpdb->prefix}wc_orders_meta.meta_value = %s AND {$wpdb->prefix}wc_orders_meta.meta_key = %s",
                    $meta_value,
                    $meta_key
                )
            );
        } else {
            $order_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT DISTINCT ID FROM $wpdb->posts as posts
                    LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id
                    WHERE meta.meta_value = %s AND meta.meta_key = %s",
                    $meta_value,
                    $meta_key
                )
            );
        }

        return $order_id;
    }

    /**
     * Method that returns the appropriate locale identifier used in Montonio's systems
     *
     * @return string identifier for locale if found, en_US by default
     */
    public static function get_locale() {
        $wpml_language = apply_filters( 'wpml_current_language', null );
        $locale        = $wpml_language ?: get_locale();

        $map = array(
            'lt' => 'lt',
            'lv' => 'lv',
            'ru' => 'ru',
            'et' => 'et',
            'ee' => 'et',
            'fi' => 'fi',
            'pl' => 'pl',
            'de' => 'de'
        );

        $lang = strtolower( substr( $locale, 0, 2 ) );

        return $map[$lang] ?? 'en';
    }

    /**
     * Get the client's current currency, with WPML Multi-Currency support.
     *
     * @return string Currency code (e.g., 'EUR', 'PLN', 'USD')
     */
    public static function get_client_currency() {
        global $woocommerce_wpml;

        $currency = get_woocommerce_currency();

        // WPML Multi-Currency support
        if ( $woocommerce_wpml && function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
            $currency = $woocommerce_wpml->multi_currency->get_client_currency();
        }

        return $currency;
    }

    /**
     * Get the currency to use for payment processing.
     *
     * Falls back to EUR if the client's currency is not supported.
     *
     * @return string Currency code ('EUR' or 'PLN')
     */
    public static function get_currency() {
        $currency = self::get_client_currency();
        return $currency === 'PLN' ? 'PLN' : 'EUR';
    }

    /**
     * Check if the client's currency is supported for payment processing.
     *
     * @param array $currencies List of supported currency codes. Default: ['EUR', 'PLN']
     * @return bool True if currency is supported, false otherwise
     */
    public static function is_client_currency_supported( $currencies = array( 'EUR', 'PLN' ) ) {
        return in_array( self::get_client_currency(), $currencies, true );
    }

    /**
     * Check if High-Performance Order Storage (HPOS) is used
     */
    public static function is_hpos_enabled() {
        return class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Check if checkout blocks are used
     */
    public static function is_checkout_block() {
        return class_exists( 'Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) && CartCheckoutUtils::is_checkout_block_default();
    }

    /**
     * Convert weight to kg
     */
    public static function convert_to_kg( $weight ) {
        switch ( get_option( 'woocommerce_weight_unit' ) ) {
            case 'g':
                return (float) $weight * 0.001;
            case 'lbs':
                return (float) $weight * 0.45;
            case 'oz':
                return (float) $weight * 0.028;
            default:
                return (float) $weight;
        }
    }

    /**
     * Convert dimension to cm
     *
     * @since 7.0.0
     * @param float $dimension The dimension to convert
     */
    public static function convert_to_cm( $dimension ) {
        switch ( get_option( 'woocommerce_dimension_unit' ) ) {
            case 'm':
                return (float) $dimension * 100;
            case 'mm':
                return (float) $dimension * 0.1;
            case 'in':
                return (float) $dimension * 2.54;
            case 'yd':
                return (float) $dimension * 91.44;
            default:
                return (float) $dimension;
        }
    }

    /**
     * Convert dimension to meters
     *
     * @since 7.0.0
     * @param float $dimension The dimension to convert
     */
    public static function convert_to_meters( $dimension ) {
        switch ( get_option( 'woocommerce_dimension_unit' ) ) {
            case 'cm':
                return (float) $dimension * 0.01;
            case 'mm':
                return (float) $dimension * 0.001;
            case 'in':
                return (float) $dimension * 0.0254;
            case 'yd':
                return (float) $dimension * 0.9144;
            case 'ft':
                return (float) $dimension * 0.3048;
            default:
                return (float) $dimension;
        }
    }

    /**
     * Checks if the given string is a valid UUIDV4.
     *
     * @since 7.0.0
     * @param string $uuid The string to check.
     * @return boolean True if the string is a valid UUID, false otherwise.
     */
    public static function is_valid_uuid( $uuid ) {
        return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) === 1;
    }

    /**
     * Create JWT token and sign it with secret key
     *
     * @since 7.0.1
     * @param array $data The data to encode
     * @return string The JWT token
     */
    public static function create_jwt_token( $data = array() ) {
        $api_keys = self::get_api_keys();

        $data['accessKey'] = $api_keys['access_key'];
        $data['iat']       = time();

        if ( ! isset( $data['exp'] ) ) {
            $data['exp'] = time() + ( 60 * 60 );
        }

        return \MontonioFirebaseV2\JWT\JWT::encode( $data, $api_keys['secret_key'] );
    }

    /**
     * Decode the Webhook Token
     * This is used to validate the integrity of a webhook sent from Montonio shipping API
     *
     * @since 7.0.1
     * @param string $token - The JWT token
     * @return object The decoded Payment token
     * @throws Exception If the token is invalid
     */
    public static function decode_jwt_token( $token ) {
        $api_keys = self::get_api_keys();

        \MontonioFirebaseV2\JWT\JWT::$leeway = 60 * 5; // 5 minutes
        return \MontonioFirebaseV2\JWT\JWT::decode( $token, $api_keys['secret_key'], array( 'HS256' ) );
    }

    /**
     * Get payment methods, syncing if necessary.
     *
     * @since 9.3.0
     * @param string|null $method Optional specific payment method to retrieve
     * @return array|null Payment methods array or null if unavailable
     */
    public static function get_payment_methods( $method = null ) {
        if ( ! self::has_api_keys() ) {
            return null;
        }
        
        $payment_methods = get_option( 'montonio_payment_methods' );

        if ( empty( $payment_methods ) ) {
            WC_Montonio_Data_Sync::sync_data();
            $payment_methods = get_option( 'montonio_payment_methods' );
        }

        if ( empty( $payment_methods ) ) {
            return null;
        }

        $decoded = json_decode( $payment_methods, true );
        $methods = $decoded['paymentMethods'] ?? null;

        if ( $method !== null ) {
            return $methods[$method] ?? null;
        }

        return $methods;
    }

    /**
     * Check if any other Montonio payment method is active.
     *
     * @since  9.3.0
     * @param  string $exclude Method ID to exclude from the check.
     * @return bool True if another Montonio method is active, false otherwise.
     */
    public static function has_other_active_method( $exclude = '' ) {
        $montonio_gateways = array(
            'wc_montonio_payments',
            'wc_montonio_card',
            'wc_montonio_blik',
            'wc_montonio_bnpl',
            'wc_montonio_hire_purchase',
        );
 
        foreach ( $montonio_gateways as $gateway_id ) {
            if ( $gateway_id === $exclude ) {
                continue;
            }
 
            $settings = get_option( 'woocommerce_' . $gateway_id . '_settings' );
 
            if ( is_array( $settings ) && isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if card payments are required to be enabled by subscription plan.
     *
     * @since 9.3.3
     * @return bool
     */
    public static function is_card_payment_required() {
        $card_config = self::get_payment_methods( 'cardPayments' );
        $is_required = $card_config['requiredToBeEnabled'] ?? false;

        return $is_required && self::has_other_active_method( 'wc_montonio_card' );
    }

    /**
     * Translates Montonio API error codes to user-friendly messages
     *
     * @since 8.0.5
     * @param string $raw_error The raw error response from the API
     * @return string User-friendly translated error message
     */
    public static function get_error_message( $raw_error ) {
        $decoded_error = json_decode( $raw_error, true );
        $message       = isset( $decoded_error['message'] ) ? $decoded_error['message'] : '';

        $error_translations = array(
            'ER_GENERAL'              => __( 'A general error has occurred. Please try again later.', 'montonio-for-woocommerce' ),
            'ER_TIC_USED'             => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'ER_TIC_STS'              => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'ER_TIC_EXPIRED'          => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'ER_WRONG_TICKET'         => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'INSUFFICIENT_FUNDS'      => __( 'Check the reason in the banking application and try again.', 'montonio-for-woocommerce' ),
            'TIMEOUT'                 => __( 'Payment failed - not confirmed on time in the banking application. Try again.', 'montonio-for-woocommerce' ),
            'ER_BAD_PIN'              => __( 'Check the reason in the banking application and try again.', 'montonio-for-woocommerce' ),
            'GENERAL_ERROR'           => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'ISSUER_DECLINED'         => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_TIC_USED'        => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_TIC_STS'         => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_TIC_EXPIRED'     => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_WRONG_TICKET'    => __( 'Incorrect BLIK code was entered. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_INSUFFICIENT_FUNDS' => __( 'Check the reason in the banking application and try again.', 'montonio-for-woocommerce' ),
            'BLIK_TIMEOUT'            => __( 'Payment failed - not confirmed on time in the banking application. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_BAD_PIN'         => __( 'Check the reason in the banking application and try again.', 'montonio-for-woocommerce' ),
            'BLIK_GENERAL_ERROR'      => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ISSUER_DECLINED'    => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_LIMIT_EXCEEDED'     => __( 'Check the reason in the banking application and try again.', 'montonio-for-woocommerce' ),
            'BLIK_USER_DECLINED'      => __( 'Payment rejected in a banking application. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_USER_TIMEOUT'       => __( 'Payment failed - not confirmed on time in the banking application. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_AM_TIMEOUT'         => __( 'Payment failed - not confirmed on time in the banking application. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_ER_DATAAMT_HUGE'    => __( 'Payment amount too high.', 'montonio-for-woocommerce' ),
            'BLIK_ALIAS_DECLINED'     => __( 'Payment requires BLIK code.', 'montonio-for-woocommerce' ),
            'BLIK_ALIAS_NOT_FOUND'    => __( 'Payment requires BLIK code.', 'montonio-for-woocommerce' ),
            'BLIK_TAS_DECLINED'       => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'BLIK_SYSTEM_ERROR'       => __( 'Payment failed. Try again.', 'montonio-for-woocommerce' ),
            'ALREADY_PAID_FOR'        => __( 'This order has already been paid for.', 'montonio-for-woocommerce' )
        );

        // Handle the message
        if ( ! empty( $message ) ) {
            if ( is_array( $message ) ) {
                // Arrays won't match translations, so just convert and return
                return implode( '; ', $message );
            }

            // Check if we have a translation for this string message
            if ( isset( $error_translations[$message] ) ) {
                return $error_translations[$message];
            }

            // Return the original string message if no translation found
            return $message;
        }

        // Fallback: Return the original error
        return $raw_error;
    }
}
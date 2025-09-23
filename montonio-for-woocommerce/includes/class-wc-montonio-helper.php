<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Automattic\WooCommerce\Utilities\OrderUtil;

class WC_Montonio_Helper {

    /**
     * Get Montonio API keys
     *
     * @return array
     */
    public static function get_api_keys( $sandbox_mode = 'no' ) {
        $api_settings = get_option( 'woocommerce_wc_montonio_api_settings' );

        $access_key = ! empty( $api_settings['access_key'] ) ? $api_settings['access_key'] : '';
        $secret_key = ! empty( $api_settings['secret_key'] ) ? $api_settings['secret_key'] : '';

        if ( $sandbox_mode === 'yes' ) {
            $access_key = ! empty( $api_settings['sandbox_access_key'] ) ? $api_settings['sandbox_access_key'] : '';
            $secret_key = ! empty( $api_settings['sandbox_secret_key'] ) ? $api_settings['sandbox_secret_key'] : '';
        }

        return apply_filters( 'wc_montonio_api_keys', array( 'access_key' => $access_key, 'secret_key' => $secret_key ), $sandbox_mode );
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
     * @param string $locale The locale to search for
     * @return string identifier for locale if found, en_US by default
     */
    public static function get_locale( $locale ) {
        if ( in_array( $locale, array( 'lt', 'lt_LT', 'lt_lt', 'lt-LT', 'lt-lt' ) ) ) {
            return 'lt';
        } else if ( in_array( $locale, array( 'lv', 'lv_LV', 'lv_lv', 'lv-LV', 'lv-lv' ) ) ) {
            return 'lv';
        } else if ( in_array( $locale, array( 'ru', 'ru_RU', 'ru_ru', 'ru-RU', 'ru-ru' ) ) ) {
            return 'ru';
        } else if ( in_array( $locale, array( 'et', 'ee', 'EE', 'ee_EE', 'ee-EE', 'et_EE', 'et-EE', 'et_ee', 'et-ee' ) ) ) {
            return 'et';
        } else if ( in_array( $locale, array( 'fi', 'fi_FI', 'fi_fi', 'fi-FI', 'fi-fi' ) ) ) {
            return 'fi';
        } else if ( in_array( $locale, array( 'pl', 'pl_PL', 'pl_pl', 'pl-PL', 'pl-pl' ) ) ) {
            return 'pl';
        } else if ( in_array( $locale, array( 'de', 'de_DE', 'de_de', 'de-DE', 'de-de' ) ) ) {
            return 'de';
        } else {
            return 'en';
        }
    }

    /**
     * Method that returns the appropriate currency identifier used in Montonio's systems
     *
     * @param string $locale The currency to search for
     * @return string identifier for currency if found, EUR by default
     */
    public static function get_currency() {
        global $woocommerce_wpml;

        $currency = get_woocommerce_currency();

        // WPML Multi-Currency support
        if ( ! is_null( $woocommerce_wpml ) ) {
            if ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
                $currency = $woocommerce_wpml->multi_currency->get_client_currency();
            }
        }

        if ( $currency === 'PLN' ) {
            return 'PLN';
        } else {
            return 'EUR';
        }
    }

    /**
     * Check if client currency is supported
     */
    public static function is_client_currency_supported( $supported_currencies = array( 'EUR', 'PLN' ) ) {
        global $woocommerce_wpml;

        $currency = get_woocommerce_currency();

        // WPML Multi-Currency support
        if ( ! is_null( $woocommerce_wpml ) ) {
            if ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
                $currency = $woocommerce_wpml->multi_currency->get_client_currency();
            }
        }

        return in_array( $currency, $supported_currencies );
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
     * @param string $sandbox_mode The sandbox mode
     * @return string The JWT token
     */
    public static function create_jwt_token( $sandbox_mode = 'no', $data = array() ) {
        $api_keys = self::get_api_keys( $sandbox_mode );

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
     * @param string $sandbox_mode The sandbox mode
     * @return object The decoded Payment token
     * @throws Exception If the token is invalid
     */
    public static function decode_jwt_token( $token, $sandbox_mode = 'no' ) {
        $api_keys = self::get_api_keys( $sandbox_mode );

        \MontonioFirebaseV2\JWT\JWT::$leeway = 60 * 5; // 5 minutes
        return \MontonioFirebaseV2\JWT\JWT::decode( $token, $api_keys['secret_key'], array( 'HS256' ) );
    }

    /**
     * Handle the result of an action and append it to the status report
     *
     * @since 7.1.2
     * @param array $status_report The status report of the OTA sync
     * @param string $status Status of the action ('success' or 'error')
     * @param string $message The message to include in the status report
     */
    public static function append_to_status_report( &$status_report, $status, $message ) {
        $status_report['sync_results'][] = array(
            'status'  => $status,
            'message' => gmdate( 'Y-m-d H:i:s' ) . ' - ' . $message
        );
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
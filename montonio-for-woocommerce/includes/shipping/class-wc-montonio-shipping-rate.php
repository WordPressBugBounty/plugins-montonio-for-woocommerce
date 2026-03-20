<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Shipping_Rate
 *
 * Caches Montonio Shipping API rate responses in the WooCommerce session.
 * Provides a two-layer cache (static + session) keyed on a hash of the
 * request parameters so rates are not re-fetched on every page load.
 *
 * @since 9.4.3
 */
class WC_Montonio_Shipping_Rate {
    /**
     * WooCommerce session key for storing carrier rates.
     *
     * @since 9.4.3
     */
    const SESSION_RATES_KEY = 'montonio_shipping_rates';

    /**
     * WooCommerce session key for storing the hash of the last cached request.
     *
     * @since 9.4.3
     */
    const SESSION_HASH_KEY = 'montonio_shipping_rates_hash';

    /**
     * Decoded carriers array for the current request (static cache).
     *
     * @since 9.4.3
     * @var array|null
     */
    private static $rates = null;

    /**
     * Hash used to populate $rates this request (static cache key).
     *
     * @since 9.4.3
     * @var string|null
     */
    private static $current_hash = null;

    /**
     * Read a value from the WooCommerce session.
     *
     * @since 9.4.3
     * @param string $key Session key to read.
     * @return mixed|null The stored value, or null if the session is unavailable.
     */
    private static function session_get( $key ) {
        if ( WC()->session instanceof WC_Session ) {
            return WC()->session->get( $key );
        }

        return null;
    }

    /**
     * Write a value to the WooCommerce session.
     *
     * @since 9.4.3
     * @param string $key   Session key to write.
     * @param mixed  $value Value to store.
     * @return void
     */
    private static function session_set( $key, $value ) {
        if ( WC()->session instanceof WC_Session ) {
            WC()->session->set( $key, $value );
        }
    }

    /**
     * Compute a cache hash for the given request parameters.
     *
     * @since 9.4.3
     * @param array  $parcels Array of parcel data passed to the Shipping API.
     * @param string $country Destination country code.
     * @return string MD5 hex digest of the serialised parameters.
     */
    private static function compute_hash( $parcels, $country ) {
        return md5( $country . wp_json_encode( $parcels ) );
    }

    /**
     * Fetch carrier rates directly from the Montonio Shipping API.
     *
     * @since 9.4.3
     * @param array  $parcels Array of parcel data.
     * @param string $country Destination country code.
     * @return array|null Carriers array on success, null on failure.
     */
    private static function fetch_from_api( $parcels, $country ) {
        try {
            $api      = new WC_Montonio_Shipping_API();
            $response = $api->get_shipping_rates( $country, $parcels );
            $decoded  = json_decode( $response, true );

            if ( isset( $decoded['carriers'] ) && is_array( $decoded['carriers'] ) ) {
                return $decoded['carriers'];
            }

            return null;
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'WC_Montonio_Shipping_Rate::fetch_from_api failed: ' . $e->getMessage() );

            return null;
        }
    }

    /**
     * Ensure carrier rates are loaded for the given request parameters.
     *
     * Falls back to a live API call when neither cache layer has a matching
     * entry, then populates both layers on success.
     *
     * @since 9.4.3
     * @param array  $parcels Array of parcel data.
     * @param string $country Destination country code.
     * @return void
     */
    private static function ensure_loaded( $parcels, $country ) {
        $hash = self::compute_hash( $parcels, $country );

        if ( self::$current_hash === $hash && null !== self::$rates ) {
            return;
        }

        if ( self::session_get( self::SESSION_HASH_KEY ) === $hash ) {
            self::$rates        = self::session_get( self::SESSION_RATES_KEY );
            self::$current_hash = $hash;
            return;
        }

        // No cache hit — fetch from the API and populate both layers.
        $carriers = self::fetch_from_api( $parcels, $country );

        if ( null !== $carriers ) {
            self::$rates        = $carriers;
            self::$current_hash = $hash;
            self::session_set( self::SESSION_RATES_KEY, $carriers );
            self::session_set( self::SESSION_HASH_KEY, $hash );
        } else {
            self::$rates        = null;
            self::$current_hash = null;
        }
    }

    /**
     * Return the cached carriers array for the given request parameters.
     *
     * Calls ensure_loaded() internally; the result may be null when the API
     * request fails or returns an unexpected response.
     *
     * @since 9.4.3
     * @param array  $parcels Array of parcel data.
     * @param string $country Destination country code.
     * @return array|null Carriers array, or null if rates could not be loaded.
     */
    public static function get_rates( $parcels, $country ) {
        self::ensure_loaded( $parcels, $country );
        return self::$rates;
    }

    /**
     * Get carriers filtered by carrier code and shipping method type.
     *
     * Returns null on API failure. Returns an empty array if the API responded
     * but no matching carrier/type was found.
     *
     * @since 9.4.3
     * @param string $carrier_code Montonio carrier code (e.g. 'dpd', 'novaPost').
     * @param string $type         Shipping method type: 'courier' or 'pickupPoint'.
     * @param array  $parcels      Parcel array from get_parcels_with_item_dimensions().
     * @param string $country      Two-letter ISO shipping country code.
     * @return array[]|null Filtered carriers array, or null on failure.
     */
    public static function get_rates_for( $carrier_code, $type, $parcels, $country ) {
        $all = self::get_rates( $parcels, $country );

        if ( null === $all ) {
            return null;
        }

        $result = array();

        foreach ( $all as $carrier ) {
            if ( $carrier['carrierCode'] !== $carrier_code ) {
                continue;
            }

            $matching_methods = array();

            if ( ! isset( $carrier['shippingMethods'] ) || ! is_array( $carrier['shippingMethods'] ) ) {
                continue;
            }

            foreach ( $carrier['shippingMethods'] as $method ) {
                if ( $method['type'] === $type ) {
                    $matching_methods[] = $method;
                }
            }

            if ( ! empty( $matching_methods ) ) {
                $result[] = array(
                    'carrierCode'     => $carrier['carrierCode'],
                    'shippingMethods' => $matching_methods,
                );
            }
        }

        return $result;
    }
}

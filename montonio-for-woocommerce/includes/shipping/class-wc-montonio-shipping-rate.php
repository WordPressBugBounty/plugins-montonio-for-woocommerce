<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_Montonio_Shipping_Rate
 *
 * Caches Montonio Shipping API rate responses in the WooCommerce session.
 *
 * @since 9.4.3
 */
class WC_Montonio_Shipping_Rate {
    /**
     * WooCommerce session key for the per-carrier+type rates cache.
     *
     * Structure: [carrier_code][type] = ['hash' => string, 'rates' => array]
     * where 'hash' is MD5 of country + parcels and 'rates' is a flat subtypes array.
     * A hash mismatch means the destination or cart has changed — the entry is overwritten.
     *
     * @since 9.4.3
     */
    const SESSION_RATES_KEY = 'montonio_shipping_rates';

    /**
     * In-request static cache. Two-level array: $rates_cache[carrier_code][type] = subtypes[].
     * Populated on first call per combination; cleared at end of PHP request.
     *
     * @since 9.4.3
     * @var array
     */
    private static $rates_cache = array();

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
     * Fetch carrier rates from the Montonio Shipping API for a specific carrier and type.
     *
     * Returns a flat array of subtype objects (code, rate, currency, optional operators).
     *
     * @since 9.4.3
     * @param string $carrier_code Montonio carrier code (e.g. 'dpd').
     * @param string $type         Shipping method type ('courier' or 'pickupPoint').
     * @param array  $parcels      Parcel data array.
     * @param string $country      Destination country code.
     * @return array[]|null Flat subtypes array on success, null on failure.
     */
    private static function fetch_from_api( $carrier_code, $type, $parcels, $country ) {
        try {
            $api      = new WC_Montonio_Shipping_API();
            $response = $api->get_shipping_rates( $country, $parcels, $type, $carrier_code );
            $decoded  = json_decode( $response, true );

            if ( ! isset( $decoded['carriers'] ) || ! is_array( $decoded['carriers'] ) ) {
                return null;
            }

            $subtypes = array();

            foreach ( $decoded['carriers'] as $carrier ) {
                if ( ! isset( $carrier['shippingMethods'] ) || ! is_array( $carrier['shippingMethods'] ) ) {
                    continue;
                }

                foreach ( $carrier['shippingMethods'] as $method ) {
                    if ( ! isset( $method['subtypes'] ) || ! is_array( $method['subtypes'] ) ) {
                        continue;
                    }

                    foreach ( $method['subtypes'] as $subtype ) {
                        $subtypes[] = $subtype;
                    }
                }
            }

            return $subtypes;
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'WC_Montonio_Shipping_Rate::fetch_from_api failed [' . $carrier_code . '/' . $type . ']: ' . $e->getMessage() );

            return null;
        }
    }

    /**
     * Ensure rates for the given carrier+type+country+parcels combination are loaded.
     *
     * Check order: static cache → WC session (hash must match) → live API call.
     * On API failure the combination is not cached, allowing a retry next request.
     *
     * @since 9.4.3
     * @param string $carrier_code Montonio carrier code (e.g. 'dpd').
     * @param string $type         Shipping method type ('courier' or 'pickupPoint').
     * @param array  $parcels      Parcel data array.
     * @param string $country      Destination country code.
     * @return void
     */
    private static function ensure_loaded( $carrier_code, $type, $parcels, $country ) {
        if ( isset( self::$rates_cache[ $carrier_code ][ $type ] ) ) {
            return;
        }

        $hash          = self::compute_hash( $parcels, $country );
        $session_rates = self::session_get( self::SESSION_RATES_KEY );

        if (
            is_array( $session_rates ) &&
            isset( $session_rates[ $carrier_code ][ $type ] ) &&
            $session_rates[ $carrier_code ][ $type ]['hash'] === $hash
        ) {
            self::$rates_cache[ $carrier_code ][ $type ] = $session_rates[ $carrier_code ][ $type ]['rates'];
            return;
        }

        $carriers = self::fetch_from_api( $carrier_code, $type, $parcels, $country );

        if ( null !== $carriers ) {
            self::$rates_cache[ $carrier_code ][ $type ] = $carriers;

            if ( ! is_array( $session_rates ) ) {
                $session_rates = array();
            }

            if ( ! isset( $session_rates[ $carrier_code ] ) ) {
                $session_rates[ $carrier_code ] = array();
            }

            $session_rates[ $carrier_code ][ $type ] = array(
                'hash'  => $hash,
                'rates' => $carriers,
            );

            self::session_set( self::SESSION_RATES_KEY, $session_rates );
        }
    }

    /**
     * Get subtypes for a specific carrier code and shipping method type.
     *
     * Returns null on API failure. Returns an empty array when the API responds
     * but returns no subtypes for the requested combination.
     *
     * @since 9.4.3
     * @param string $carrier_code Montonio carrier code (e.g. 'dpd', 'novaPost').
     * @param string $type         Shipping method type: 'courier' or 'pickupPoint'.
     * @param array  $parcels      Parcel array from get_parcels_with_item_dimensions().
     * @param string $country      Two-letter ISO shipping country code.
     * @return array[]|null Flat subtypes array, or null on API failure.
     */
    public static function get_rates_for( $carrier_code, $type, $parcels, $country ) {
        self::ensure_loaded( $carrier_code, $type, $parcels, $country );

        if ( ! isset( self::$rates_cache[ $carrier_code ][ $type ] ) ) {
            return null;
        }

        return self::$rates_cache[ $carrier_code ][ $type ];
    }
}

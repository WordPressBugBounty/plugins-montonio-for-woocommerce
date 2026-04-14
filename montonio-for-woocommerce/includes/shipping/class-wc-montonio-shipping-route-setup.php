<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Route_Setup
 *
 * Handles automatic creation of WooCommerce Shipping Zones and Montonio
 * shipping methods based on the merchant's active carriers from the Shipping API.
 *
 * @since 9.4.0
 */
class WC_Montonio_Shipping_Route_Setup {

    /**
     * Maps type_v2 (from shipping method classes) to the API's top-level type and subtype.
     *
     * Most pickup-based methods have type_v2 = their subtype code (parcelMachine, postOffice, parcelShop).
     * Courier methods always have type_v2 = 'courier', which maps to API type 'courier' + subtype 'standard'.
     *
     * @var array
     */
    private static $type_v2_to_api = array(
        'courier'       => array( 'type' => 'courier', 'subtype' => 'standard' ),
        'parcelMachine' => array( 'type' => 'pickupPoint', 'subtype' => 'parcelMachine' ),
        'postOffice'    => array( 'type' => 'pickupPoint', 'subtype' => 'postOffice' ),
        'parcelShop'    => array( 'type' => 'pickupPoint', 'subtype' => 'parcelShop' )
    );

    /**
     * Extra API map keys that should also resolve to a given WC method ID.
     *
     * Used when multiple API subtypes map to the same WC shipping method
     * (e.g. novaPost parcelShop and parcelMachine both → international_shipping_pickup_points).
     *
     * Key format: "{carrier}_{apiType}_{subtype}" => WC method ID.
     *
     * @var array
     */
    private static $method_map_extras = array(
        'novaPost_pickupPoint_parcelShop' => 'montonio_international_shipping_pickup_points'
    );

    /**
     * Carrier codes that use dynamic pricing (no flat rate).
     *
     * @var array
     */
    private static $dynamic_carrier_codes = array( 'novaPost' );

    /**
     * Carrier codes to exclude from specific countries during route setup.
     *
     * International shipping methods (novaPost) look out of place next to
     * local Baltic carriers (Omniva, Smartpost, etc.), so we skip them
     * for EE, LV, and LT.
     *
     * Key: country code, Value: array of carrier codes to exclude.
     *
     * @var array
     */
    private static $excluded_carriers_by_country = array(
        'EE' => array( 'novaPost' ),
        'LV' => array( 'novaPost' ),
        'LT' => array( 'novaPost' )
    );

    /**
     * Cached method map, labels, and dynamic methods.
     *
     * @var array|null
     */
    private static $cached_method_data = null;

    /**
     * Build the method map, labels, and dynamic methods list from registered WC shipping methods.
     *
     * Iterates all registered WooCommerce shipping methods that extend Montonio_Shipping_Method,
     * reads their carrier_code and type_v2, and builds the API key → WC method ID map.
     *
     * @since 9.4.0
     * @return array { method_map: array, method_labels: array, dynamic_rate_methods: array }
     */
    private static function get_method_data() {
        if ( null !== self::$cached_method_data ) {
            return self::$cached_method_data;
        }

        $method_map           = array();
        $method_labels        = array();
        $dynamic_rate_methods = array();
        $wc_shipping_methods  = WC()->shipping()->get_shipping_methods();

        foreach ( $wc_shipping_methods as $method ) {
            if ( ! $method instanceof Montonio_Shipping_Method ) {
                continue;
            }

            $carrier_code = $method->carrier_code;
            $type_v2      = $method->type_v2;
            $method_id    = $method->id;

            if ( empty( $carrier_code ) || empty( $type_v2 ) || empty( $method_id ) ) {
                continue;
            }

            if ( ! isset( self::$type_v2_to_api[$type_v2] ) ) {
                continue;
            }

            $api_type = self::$type_v2_to_api[$type_v2]['type'];
            $subtype  = self::$type_v2_to_api[$type_v2]['subtype'];
            $map_key  = $carrier_code . '_' . $api_type . '_' . $subtype;

            $method_map[$map_key] = $method_id;

            $method_labels[$method_id] = $method->method_title;

            if ( in_array( $carrier_code, self::$dynamic_carrier_codes, true ) ) {
                $dynamic_rate_methods[] = $method_id;
            }
        }

        // Add extra map entries (many-to-one subtype mappings)
        foreach ( self::$method_map_extras as $key => $wc_method_id ) {
            $method_map[$key] = $wc_method_id;
        }

        $dynamic_rate_methods = array_unique( $dynamic_rate_methods );

        self::$cached_method_data = array(
            'method_map'           => $method_map,
            'method_labels'        => $method_labels,
            'dynamic_rate_methods' => $dynamic_rate_methods
        );

        return self::$cached_method_data;
    }

    /**
     * Get the API key → WC method ID map.
     *
     * @since 9.4.0
     * @return array
     */
    private static function get_method_map() {
        return self::get_method_data()['method_map'];
    }

    /**
     * Get the WC method ID → label map.
     *
     * @since 9.4.0
     * @return array
     */
    private static function get_method_labels() {
        return self::get_method_data()['method_labels'];
    }

    /**
     * Get the list of WC method IDs that use dynamic pricing.
     *
     * @since 9.4.0
     * @return array
     */
    private static function get_dynamic_rate_methods() {
        return self::get_method_data()['dynamic_rate_methods'];
    }

    /**
     * Standard reference parcel used for default pricing.
     *
     * @var array
     */
    private static $default_parcel = array(
        'items' => array(
            array(
                'length'        => 30,
                'width'         => 20,
                'height'        => 15,
                'weight'        => 1,
                'quantity'      => 1,
                'dimensionUnit' => 'cm',
                'weightUnit'    => 'kg'
            )
        )
    );

    /**
     * Register AJAX handlers.
     *
     * @since 9.4.0
     */
    public static function init() {
        add_action( 'wp_ajax_montonio_setup_routes_preview_html', array( __CLASS__, 'ajax_preview_html' ) );
        add_action( 'wp_ajax_montonio_setup_routes_execute_html', array( __CLASS__, 'ajax_execute_html' ) );
    }

    /**
     * AJAX handler: compute plan and return PHP-rendered HTML preview.
     *
     * @since 9.4.0
     */
    public static function ajax_preview_html() {
        if ( ! check_ajax_referer( 'montonio_setup_routes', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expired. Please reload the page.', 'montonio-for-woocommerce' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'montonio-for-woocommerce' ) ), 403 );
        }

        try {
            $plan = self::compute_plan();
            $html = WC_Montonio_Shipping_Route_Setup_View::render_preview( $plan );

            wp_send_json_success( array(
                'html' => $html,
                'plan' => $plan
            ) );
        } catch ( Exception $e ) {
            $html = WC_Montonio_Shipping_Route_Setup_View::render_error( $e->getMessage() );
            wp_send_json_error( array(
                'html'    => $html,
                'message' => $e->getMessage()
            ) );
        }
    }

    /**
     * AJAX handler: execute the plan and return PHP-rendered HTML summary.
     *
     * Re-validates zone country codes at execute time to prevent duplicates (TOCTOU fix).
     * Validates dimension inputs server-side.
     *
     * @since 9.4.0
     */
    public static function ajax_execute_html() {
        if ( ! check_ajax_referer( 'montonio_setup_routes', '_ajax_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expired. Please reload the page.', 'montonio-for-woocommerce' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'montonio-for-woocommerce' ) ), 403 );
        }

        $plan = isset( $_POST['plan'] ) ? json_decode( wp_unslash( $_POST['plan'] ), true ) : null;

        if ( empty( $plan ) || ! is_array( $plan ) || empty( $plan['zones'] ) ) {
            WC_Montonio_Logger::log( 'Route setup: invalid or empty plan data in execute request' );
            $error_message = __( 'Something went wrong. Please close this window and try again.', 'montonio-for-woocommerce' );
            $html          = WC_Montonio_Shipping_Route_Setup_View::render_error( $error_message );
            wp_send_json_error( array(
                'html'    => $html,
                'message' => $error_message
            ) );
            return;
        }

        // Server-side dimension validation
        if ( ! empty( $plan['has_dynamic_methods'] ) ) {
            if ( empty( $plan['default_dimensions'] ) || ! is_array( $plan['default_dimensions'] ) ) {
                WC_Montonio_Logger::log( 'Route setup: missing default dimensions for dynamic methods' );
                $error_message = __( 'Please fill in all package dimension fields with values greater than 0.', 'montonio-for-woocommerce' );
                $html          = WC_Montonio_Shipping_Route_Setup_View::render_error( $error_message );
                wp_send_json_error( array(
                    'html'    => $html,
                    'message' => $error_message
                ) );
                return;
            }

            $dim_keys = array( 'default_length', 'default_width', 'default_height', 'default_weight' );
            foreach ( $dim_keys as $key ) {
                if ( ! isset( $plan['default_dimensions'][$key] )
                    || ! is_numeric( $plan['default_dimensions'][$key] )
                    || (float) $plan['default_dimensions'][$key] <= 0 ) {
                    WC_Montonio_Logger::log( 'Route setup: invalid dimension value for ' . $key );
                    $error_message = __( 'Please fill in all package dimension fields with values greater than 0.', 'montonio-for-woocommerce' );
                    $html          = WC_Montonio_Shipping_Route_Setup_View::render_error( $error_message );
                    wp_send_json_error( array(
                        'html'    => $html,
                        'message' => $error_message
                    ) );
                    return;
                }
            }
        }

        // Re-validate zone regions before creating (TOCTOU fix)
        try {
            $existing_zones_data = self::get_existing_zones_data();
        } catch ( Exception $e ) {
            WC_Montonio_Logger::log( 'Route setup: failed to load existing zones: ' . $e->getMessage() );
            $error_message = __( 'Could not verify existing zones. Please try again.', 'montonio-for-woocommerce' );
            $html          = WC_Montonio_Shipping_Route_Setup_View::render_error( $error_message );
            wp_send_json_error( array(
                'html'    => $html,
                'message' => $error_message
            ) );
            return;
        }

        $covered_countries = array();
        foreach ( $existing_zones_data as $ez ) {
            foreach ( $ez['countries'] as $cc ) {
                $covered_countries[$cc] = $ez['name'];
            }
        }

        foreach ( $plan['zones'] as $index => $zone_data ) {
            if ( ! empty( $zone_data['skipped'] ) ) {
                continue;
            }

            if ( empty( $zone_data['countries'] ) || ! is_array( $zone_data['countries'] ) ) {
                continue;
            }

            if ( ! isset( $zone_data['name'] ) || ! is_string( $zone_data['name'] ) ) {
                continue;
            }

            $covering_zone = null;
            foreach ( $zone_data['countries'] as $cc ) {
                if ( isset( $covered_countries[$cc] ) ) {
                    $covering_zone = $covered_countries[$cc];
                    break;
                }
            }

            if ( null !== $covering_zone ) {
                $plan['zones'][$index]['skipped']    = true;
                $plan['zones'][$index]['covered_by'] = $covering_zone;
            } else {
                foreach ( $zone_data['countries'] as $cc ) {
                    $covered_countries[$cc] = $zone_data['name'];
                }
            }
        }

        try {
            $result = self::execute_plan( $plan );
            $html   = WC_Montonio_Shipping_Route_Setup_View::render_summary( $result );

            wp_send_json_success( array( 'html' => $html ) );
        } catch ( Exception $e ) {
            $html = WC_Montonio_Shipping_Route_Setup_View::render_error( $e->getMessage() );
            wp_send_json_error( array(
                'html'    => $html,
                'message' => $e->getMessage()
            ) );
        }
    }

    /**
     * Compute the setup plan: fetch API data, group into zones, calculate rates.
     *
     * @since 9.4.0
     * @return array The setup plan with zones, methods, rates, and conflict info.
     * @throws Exception If the shipping methods API call fails.
     */
    public static function compute_plan() {
        $api      = new WC_Montonio_Shipping_API();
        $response = $api->get_shipping_methods();
        $data     = json_decode( $response, true );

        if ( empty( $data['countries'] ) || ! is_array( $data['countries'] ) ) {
            throw new Exception( __( 'No shipping methods available from the API.', 'montonio-for-woocommerce' ) );
        }

        // Step 1: Build country → methods matrix from API
        $country_methods = self::build_country_methods( $data['countries'] );

        if ( empty( $country_methods ) ) {
            throw new Exception( __( 'No supported shipping methods found.', 'montonio-for-woocommerce' ) );
        }

        // Step 2: Fetch rates per country
        $country_rates = self::fetch_rates_for_countries( array_keys( $country_methods ), $api );

        // Extract any rate fetch failures into warnings
        $warnings = array();
        if ( ! empty( $country_rates['_failures'] ) ) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated list of country codes */
                __( 'Could not fetch shipping rates for: %s. Some methods may be missing from the preview.', 'montonio-for-woocommerce' ),
                implode( ', ', $country_rates['_failures'] )
            );
            unset( $country_rates['_failures'] );
        }

        // Step 3: Group countries into zones
        $zones = self::group_into_zones( $country_methods, $country_rates );

        // Step 4: Check for existing zones
        $existing_zones_data = self::get_existing_zones_data();

        $covered_countries = array();
        foreach ( $existing_zones_data as $ez ) {
            foreach ( $ez['countries'] as $cc ) {
                $covered_countries[$cc] = $ez['name'];
            }
        }

        foreach ( $zones as &$zone ) {
            $covering_zone = null;
            foreach ( $zone['countries'] as $cc ) {
                if ( isset( $covered_countries[$cc] ) ) {
                    $covering_zone = $covered_countries[$cc];
                    break;
                }
            }

            $zone['skipped']    = ( null !== $covering_zone );
            $zone['covered_by'] = $covering_zone;
        }
        unset( $zone );

        // Check if any non-skipped zone contains dynamic-rate methods
        $has_dynamic_methods = false;
        foreach ( $zones as $zone ) {
            if ( ! empty( $zone['skipped'] ) ) {
                continue;
            }
            foreach ( $zone['methods'] as $method ) {
                if ( in_array( $method['method_id'], self::get_dynamic_rate_methods(), true ) ) {
                    $has_dynamic_methods = true;
                    break 2;
                }
            }
        }

        return array(
            'zones'               => $zones,
            'currency'            => get_woocommerce_currency(),
            'has_dynamic_methods' => $has_dynamic_methods,
            'dimension_unit'      => get_option( 'woocommerce_dimension_unit', 'cm' ),
            'weight_unit'         => get_option( 'woocommerce_weight_unit', 'kg' ),
            'warnings'            => $warnings
        );
    }

    /**
     * Build a country → method IDs matrix from the API response.
     *
     * @since 9.4.0
     * @param array $countries The countries array from the API response.
     * @return array Associative array: country_code => array of unique WC method IDs.
     */
    private static function build_country_methods( $countries ) {
        $method_map      = self::get_method_map();
        $country_methods = array();

        foreach ( $countries as $country ) {
            $country_code = $country['countryCode'];

            if ( empty( $country['carriers'] ) ) {
                continue;
            }

            $methods = array();

            foreach ( $country['carriers'] as $carrier ) {
                $carrier_code = $carrier['carrierCode'];

                // Skip carriers excluded for this country (e.g. novaPost in Baltic regions)
                if ( isset( self::$excluded_carriers_by_country[$country_code] )
                    && in_array( $carrier_code, self::$excluded_carriers_by_country[$country_code], true ) ) {
                    continue;
                }

                if ( empty( $carrier['shippingMethods'] ) ) {
                    continue;
                }

                foreach ( $carrier['shippingMethods'] as $method ) {
                    $type = $method['type'];

                    if ( empty( $method['subtypes'] ) ) {
                        continue;
                    }

                    foreach ( $method['subtypes'] as $subtype ) {
                        $subtype_code = $subtype['code'];
                        $map_key      = $carrier_code . '_' . $type . '_' . $subtype_code;

                        if ( isset( $method_map[$map_key] ) ) {
                            $wc_method_id = $method_map[$map_key];
                            if ( ! in_array( $wc_method_id, $methods, true ) ) {
                                $methods[] = $wc_method_id;
                            }
                        }
                    }
                }
            }

            if ( ! empty( $methods ) ) {
                $country_methods[$country_code] = $methods;
            }
        }

        return $country_methods;
    }

    /**
     * Fetch shipping rates for a list of countries using the standard reference parcel.
     *
     * @since 9.4.0
     * @param array                   $country_codes Array of ISO country codes.
     * @param WC_Montonio_Shipping_API $api           The shipping API instance.
     * @return array country_code => array( 'carrier_type_subtype' => rate )
     */
    private static function fetch_rates_for_countries( $country_codes, $api ) {
        $method_map    = self::get_method_map();
        $country_rates = array();

        foreach ( $country_codes as $country_code ) {
            try {
                $response = $api->get_shipping_rates( $country_code, array( self::$default_parcel ) );
                $data     = json_decode( $response, true );

                if ( empty( $data['carriers'] ) || ! is_array( $data['carriers'] ) ) {
                    continue;
                }

                $rates = array();

                foreach ( $data['carriers'] as $carrier ) {
                    $carrier_code = $carrier['carrierCode'];

                    if ( empty( $carrier['shippingMethods'] ) ) {
                        continue;
                    }

                    foreach ( $carrier['shippingMethods'] as $method ) {
                        $type = $method['type'];

                        if ( empty( $method['subtypes'] ) ) {
                            continue;
                        }

                        foreach ( $method['subtypes'] as $subtype ) {
                            $subtype_code = $subtype['code'];
                            $map_key      = $carrier_code . '_' . $type . '_' . $subtype_code;

                            if ( isset( $method_map[$map_key] ) && isset( $subtype['rate'] ) ) {
                                $wc_method_id = $method_map[$map_key];

                                if ( ! isset( $rates[$wc_method_id] ) || $subtype['rate'] > $rates[$wc_method_id] ) {
                                    $rates[$wc_method_id] = (float) $subtype['rate'];
                                }
                            }
                        }
                    }
                }

                $country_rates[$country_code] = $rates;
            } catch ( Exception $e ) {
                WC_Montonio_Logger::log( 'Route setup: rates API failed for ' . $country_code . ': ' . $e->getMessage() );
                $country_rates['_failures'][] = $country_code;
            }
        }

        return $country_rates;
    }

    /**
     * Group countries into zones based on the region map.
     *
     * @since 9.4.0
     * @param array $country_methods country_code => array of WC method IDs.
     * @param array $country_rates   country_code => array( method_id => rate ).
     * @return array Array of zone definitions.
     */
    private static $preferred_country_order = array( 'EE', 'LV', 'LT', 'PL' );

    private static function group_into_zones( $country_methods, $country_rates ) {
        $zones                = array();
        $wc_countries         = WC()->countries->get_countries();
        $dynamic_rate_methods = self::get_dynamic_rate_methods();
        $method_labels        = self::get_method_labels();

        // Sort: preferred countries first (in order), then the rest alphabetically by name
        $country_codes = array_keys( $country_methods );
        usort( $country_codes, function ( $a, $b ) use ( $wc_countries ) {
            $pos_a = array_search( $a, self::$preferred_country_order, true );
            $pos_b = array_search( $b, self::$preferred_country_order, true );

            if ( false !== $pos_a && false !== $pos_b ) {
                return $pos_a - $pos_b;
            }

            if ( false !== $pos_a ) {
                return -1;
            }

            if ( false !== $pos_b ) {
                return 1;
            }

            $name_a = isset( $wc_countries[$a] ) ? $wc_countries[$a] : $a;
            $name_b = isset( $wc_countries[$b] ) ? $wc_countries[$b] : $b;

            return strcmp( $name_a, $name_b );
        } );

        foreach ( $country_codes as $country_code ) {
            $methods_list = $country_methods[$country_code];
            $zone_name    = isset( $wc_countries[$country_code] ) ? $wc_countries[$country_code] : $country_code;

            $zone_rates = self::compute_zone_rates( array( $country_code ), $methods_list, $country_rates );

            $methods = array();
            foreach ( $methods_list as $method_id ) {
                $is_dynamic = in_array( $method_id, $dynamic_rate_methods, true );

                if ( ! $is_dynamic && ! isset( $zone_rates[$method_id] ) ) {
                    continue;
                }

                $methods[] = array(
                    'method_id' => $method_id,
                    'title'     => isset( $method_labels[$method_id] ) ? $method_labels[$method_id] : $method_id,
                    'rate'      => $is_dynamic ? null : $zone_rates[$method_id]
                );
            }

            // Sort: dynamic-rate first, then by rate ascending, pickup before courier as tie-breaker
            usort( $methods, array( __CLASS__, 'compare_methods_by_rate' ) );

            if ( empty( $methods ) ) {
                continue;
            }

            $zones[] = array(
                'name'      => $zone_name,
                'countries' => array( $country_code ),
                'methods'   => $methods,
                'skipped'   => false
            );
        }

        return $zones;
    }

    /**
     * Compute rates for a zone by taking the highest rate across its countries.
     *
     * @since 9.4.0
     * @param array $zone_countries Array of country codes in the zone.
     * @param array $method_ids     Array of WC method IDs.
     * @param array $country_rates  country_code => array( method_id => rate ).
     * @return array method_id => highest_rate
     */
    private static function compute_zone_rates( $zone_countries, $method_ids, $country_rates ) {
        $zone_rates = array();

        foreach ( $method_ids as $method_id ) {
            $highest_rate = null;

            foreach ( $zone_countries as $cc ) {
                if ( isset( $country_rates[$cc][$method_id] ) ) {
                    $rate = $country_rates[$cc][$method_id];
                    if ( null === $highest_rate || $rate > $highest_rate ) {
                        $highest_rate = $rate;
                    }
                }
            }

            if ( null !== $highest_rate ) {
                $zone_rates[$method_id] = $highest_rate;
            }
        }

        return $zone_rates;
    }

    /**
     * Compare two methods for sorting: dynamic-rate first, then by rate ascending,
     * pickup methods before courier as tie-breaker.
     *
     * @since 9.4.2
     * @param array $a Method array with 'method_id' and 'rate' keys.
     * @param array $b Method array with 'method_id' and 'rate' keys.
     * @return int
     */
    private static function compare_methods_by_rate( $a, $b ) {
        $a_dynamic = null === $a['rate'];
        $b_dynamic = null === $b['rate'];

        // Dynamic-rate methods come first
        if ( $a_dynamic !== $b_dynamic ) {
            return $a_dynamic ? -1 : 1;
        }

        // Both have numeric rates — sort ascending
        if ( ! $a_dynamic && $a['rate'] !== $b['rate'] ) {
            return $a['rate'] < $b['rate'] ? -1 : 1;
        }

        // Tie-breaker: courier methods sort after pickup methods
        $a_courier = false !== strpos( $a['method_id'], '_courier' );
        $b_courier = false !== strpos( $b['method_id'], '_courier' );

        if ( $a_courier !== $b_courier ) {
            return $a_courier ? 1 : -1;
        }

        return 0;
    }

    /**
     * Get existing WooCommerce shipping zone data (names and country locations).
     *
     * @since 9.4.2
     * @return array Array of arrays with 'name' and 'countries' keys.
     */
    private static function get_existing_zones_data() {
        $zones_data = array();
        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zone      = new WC_Shipping_Zone( $raw_zone );
            $locations = $zone->get_zone_locations();
            $countries = array();

            foreach ( $locations as $location ) {
                if ( isset( $location->type ) && 'country' === $location->type && ! empty( $location->code ) ) {
                    $countries[] = $location->code;
                }
            }

            $zones_data[] = array(
                'name'      => $zone->get_zone_name(),
                'countries' => $countries
            );
        }

        return $zones_data;
    }

    /**
     * Execute the setup plan: create WooCommerce Shipping Zones and add methods.
     *
     * @since 9.4.0
     * @param array $plan The setup plan (as returned by compute_plan / sent from JS).
     * @return array Summary of created/skipped zones.
     * @throws Exception If zone creation fails critically.
     */
    public static function execute_plan( $plan ) {
        $created          = array();
        $skipped          = array();
        $created_zone_ids = array();
        $valid_method_ids = array_values( self::get_method_map() );

        foreach ( $plan['zones'] as $zone_data ) {
            if ( ! empty( $zone_data['skipped'] ) ) {
                $skipped[] = array(
                    'name'       => $zone_data['name'],
                    'countries'  => $zone_data['countries'],
                    'covered_by' => isset( $zone_data['covered_by'] ) ? $zone_data['covered_by'] : null
                );
                continue;
            }

            if ( empty( $zone_data['countries'] ) || ! is_array( $zone_data['countries'] ) ) {
                continue;
            }

            try {
                $zone = new WC_Shipping_Zone();
                $zone->set_zone_name( $zone_data['name'] );

                // Add country locations
                $locations = array();
                foreach ( $zone_data['countries'] as $country_code ) {
                    $locations[] = array(
                        'code' => $country_code,
                        'type' => 'country'
                    );
                }
                $zone->set_locations( $locations );
                $zone->save();

                $created_zone_ids[] = $zone->get_id();

                $zone_method_count = 0;

                foreach ( $zone_data['methods'] as $method_data ) {
                    if ( ! in_array( $method_data['method_id'], $valid_method_ids, true ) ) {
                        WC_Montonio_Logger::log( 'Route setup: skipping unknown method_id ' . $method_data['method_id'] . ' in zone ' . $zone_data['name'] );
                        continue;
                    }

                    $is_dynamic = in_array( $method_data['method_id'], self::get_dynamic_rate_methods(), true );

                    if ( ! $is_dynamic && ! is_numeric( $method_data['rate'] ) ) {
                        WC_Montonio_Logger::log( 'Route setup: skipping method ' . $method_data['method_id'] . ' in zone ' . $zone_data['name'] . ' — no rate available.' );
                        continue;
                    }

                    try {
                        $instance_id = $zone->add_shipping_method( $method_data['method_id'] );

                        if ( ! $instance_id ) {
                            WC_Montonio_Logger::log( 'Route setup: add_shipping_method returned 0 for ' . $method_data['method_id'] . ' in zone ' . $zone_data['name'] . '. Method type may not be registered.' );
                            continue;
                        }

                        if ( $is_dynamic && ! empty( $plan['default_dimensions'] ) ) {
                            self::set_method_dimensions( $instance_id, $method_data['method_id'], $plan['default_dimensions'] );
                        } elseif ( ! $is_dynamic && is_numeric( $method_data['rate'] ) ) {
                            self::set_method_price( $instance_id, $method_data['method_id'], $method_data['rate'] );
                        }

                        $zone_method_count++;
                    } catch ( Exception $e ) {
                        WC_Montonio_Logger::log( 'Route setup: failed to add method ' . $method_data['method_id'] . ' to zone ' . $zone_data['name'] . ': ' . $e->getMessage() );
                    }
                }

                $created[] = array(
                    'name'         => $zone_data['name'],
                    'countries'    => $zone_data['countries'],
                    'method_count' => $zone_method_count
                );
            } catch ( Exception $e ) {
                // Roll back all zones created in this batch
                foreach ( $created_zone_ids as $zone_id ) {
                    $rollback_zone = new WC_Shipping_Zone( $zone_id );
                    $rollback_zone->delete();
                }

                throw new Exception(
                    sprintf(
                        /* translators: 1: zone name, 2: error message */
                        __( 'Failed to create zone "%1$s": %2$s. All changes have been rolled back.', 'montonio-for-woocommerce' ),
                        $zone_data['name'],
                        $e->getMessage()
                    )
                );
            }
        }

        return array(
            'created' => $created,
            'skipped' => $skipped
        );
    }

    /**
     * Set the flat rate price on a shipping method instance.
     *
     * @since 9.4.0
     * @param int    $instance_id The shipping method instance ID.
     * @param string $method_id   The WC shipping method ID.
     * @param float  $rate        The rate to set.
     */
    private static function set_method_price( $instance_id, $method_id, $rate ) {
        $option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
        $settings   = get_option( $option_key, array() );

        $settings['price'] = wc_format_decimal( $rate, 2 );

        update_option( $option_key, $settings );
    }

    /**
     * Set default package dimensions on a shipping method instance.
     *
     * @since 9.4.0
     * @param int    $instance_id The shipping method instance ID.
     * @param string $method_id   The WC shipping method ID.
     * @param array  $dimensions  Array with keys: default_length, default_width, default_height, default_weight.
     */
    private static function set_method_dimensions( $instance_id, $method_id, $dimensions ) {
        $option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
        $settings   = get_option( $option_key, array() );

        $dimension_keys = array( 'default_length', 'default_width', 'default_height', 'default_weight' );

        foreach ( $dimension_keys as $key ) {
            if ( isset( $dimensions[$key] ) ) {
                $settings[$key] = wc_format_decimal( $dimensions[$key], 2 );
            }
        }

        update_option( $option_key, $settings );
    }
}

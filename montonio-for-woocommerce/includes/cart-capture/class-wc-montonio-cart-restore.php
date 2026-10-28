<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Cart_Restore
 *
 * Fills a guest's checkout with the details Ladu captured when the shopper arrives
 * through a cart rescue email's restore link, and puts back the recommendation tag of each rebuilt
 * cart item that had a usable one, while tagging is on. The link goes through WooCommerce's
 * checkout-link, which rebuilds the cart's items, and carries only a restore id,
 * which is resolved with a signed request to Ladu.
 */
class WC_Montonio_Cart_Restore {
    const QUERY_ARG             = 'montonio_restore';
    const SESSION_TRIED_KEY     = 'montonio_cart_restore_tried';
    const SESSION_ELSEWHERE_KEY = 'montonio_cart_restore_ship_elsewhere';
    const TIMEOUT_SECONDS       = 3;
    // A shopper whose details are not filled only waits for the recommendation tags, so the wait is short.
    const TAGS_TIMEOUT_SECONDS  = 1;

    // CUSTOMER_FIELDS without the phone: a different phone number alone is no reason to ship elsewhere.
    const SHIP_ELSEWHERE_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

    /**
     * Registers the restore hooks.
     *
     * @return void
     */
    public static function init() {
        // Before WC_Montonio_Cart_Capture::capture_checkout_page (template_redirect, 10), so it captures the restored email on this request.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_restore' ), 5 );
        add_action( 'wp_head', array( __CLASS__, 'print_url_cleanup' ), 1 );
        add_filter( 'woocommerce_ship_to_different_address_checked', array( __CLASS__, 'ship_to_different_address' ) );
    }

    /**
     * Resolves the restore id in the checkout URL with Ladu and fills the checkout with the answer.
     *
     * @return void
     */
    public static function maybe_restore() {
        try {
            $token = self::requested_token();

            // The cart is checkout-link's to rebuild, so an empty one means the link did not restore it.
            if ( null === $token || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
                return;
            }

            // One answer per id and session: a reload or a second visit must not ask Ladu again.
            if ( WC()->session->get( self::SESSION_TRIED_KEY ) === $token ) {
                return;
            }

            $fillable = self::is_fillable_session();
            $retag    = class_exists( 'WC_Montonio_Recommendation_Tagging' ) && WC_Montonio_Recommendation_Tagging::is_active();

            if ( ! $fillable && ! $retag ) {
                return;
            }

            // Ladu answers only while cart rescue is on, and the endpoint is the one the capture sends to.
            if ( ! WC_Montonio_Cart_Capture::is_active() ) {
                return;
            }

            $result = self::fetch( untrailingslashit( get_option( WC_Montonio_Cart_Capture::OPTION_ENDPOINT, '' ) ) . '/restore', $token, $fillable ? self::TIMEOUT_SECONDS : self::TAGS_TIMEOUT_SECONDS );

            // A timeout or a server error may pass, so a reload asks again; for the tags alone it does not.
            if ( $result['definitive'] || ! $fillable ) {
                WC()->session->set( self::SESSION_TRIED_KEY, $token );
            }

            if ( null === $result['details'] ) {
                return;
            }

            // A logged-in shopper's or emailed session's answer is used for the tags only; its customer details are dropped here.
            if ( $retag ) {
                WC_Montonio_Recommendation_Tagging::restore_tags( isset( $result['details']['lines'] ) ? $result['details']['lines'] : null );
            }

            if ( $fillable ) {
                self::apply( $result['details'] );
            }
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart restore failed: ' . WC_Montonio_Logger::describe( $e ) );
        }
    }

    /**
     * Removes the restore id from the address bar, so it does not end up in bookmarks, shared links or analytics.
     *
     * @return void
     */
    public static function print_url_cleanup() {
        if ( ! WC_Montonio_Cart_Capture::is_checkout_page() || ! isset( $_GET[self::QUERY_ARG] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $script = "(function(){try{var u=new URL(window.location.href);u.searchParams.delete('" . self::QUERY_ARG . "');window.history.replaceState(window.history.state,'',u.toString());}catch(e){}})();";

        if ( function_exists( 'wp_print_inline_script_tag' ) ) {
            wp_print_inline_script_tag( $script );
        } else {
            echo '<script>' . $script . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Ticks the classic checkout's ship-elsewhere box when the restored shipping address differs from
     * the billing one. The Blocks checkout does not use this filter.
     *
     * @param int|bool $checked Whether the box is ticked.
     * @return int|bool
     */
    public static function ship_to_different_address( $checked ) {
        // WooCommerce keeps no state for this box, so the flag holds for the session: a reload must not hide
        // the restored address. It also re-ticks the box after the shopper unticks it and reloads.
        if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->session->get( self::SESSION_ELSEWHERE_KEY ) ) {
            return $checked;
        }

        return 1;
    }

    private static function requested_token() {
        if ( ! WC_Montonio_Cart_Capture::is_checkout_page() || ! isset( $_GET[self::QUERY_ARG] ) || ! is_string( $_GET[self::QUERY_ARG] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return null;
        }

        $token = wp_unslash( $_GET[self::QUERY_ARG] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return 1 === preg_match( '/^[0-9a-f]{64}$/', $token ) ? $token : null;
    }

    // A logged-in customer's details belong to the account, and a session that already has an email is the shopper's own.
    private static function is_fillable_session() {
        if ( is_user_logged_in() || ! WC()->customer ) {
            return false;
        }

        return '' === (string) WC()->customer->get_billing_email();
    }

    /**
     * @return array 'details': Ladu's answer, or null; 'definitive': whether asking again cannot change it.
     */
    private static function fetch( $url, $token, $timeout ) {
        $jwt = WC_Montonio_Helper::create_jwt_token( array( 'restore_token' => $token, 'exp' => time() + 60 ) );

        $response = wp_remote_post( $url, array(
            'timeout' => $timeout,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'data' => $jwt ) )
        ) );

        if ( is_wp_error( $response ) ) {
            WC_Montonio_Logger::log( 'Cart restore request failed: ' . $response->get_error_code() . ': ' . $response->get_error_message() );
            return array( 'details' => null, 'definitive' => false );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );

        // Ladu answers 404 for a restore id it no longer answers for, and 400 or 401 for a request it cannot verify.
        if ( 200 !== $status ) {
            WC_Montonio_Logger::log( 'Cart restore request answered HTTP ' . $status );
            return array( 'details' => null, 'definitive' => ! WC_Montonio_Cart_Capture::is_retryable_status( $status ) );
        }

        $details = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $details ) ) {
            WC_Montonio_Logger::log( 'Cart restore response was not JSON: ' . json_last_error_msg() );
            return array( 'details' => null, 'definitive' => true );
        }

        if ( ! isset( $details['email'] ) || ! is_string( $details['email'] ) ) {
            WC_Montonio_Logger::log( 'Cart restore response had no email' );
            return array( 'details' => null, 'definitive' => true );
        }

        return array( 'details' => $details, 'definitive' => true );
    }

    private static function apply( $details ) {
        $customer = WC()->customer;
        $email    = sanitize_email( $details['email'] );

        if ( is_email( $email ) ) {
            $customer->set_billing_email( $email );
        }

        $billing  = self::address( $details, 'billing' );
        $shipping = self::address( $details, 'shipping' );

        // A cart that recorded one country only still sets both.
        if ( '' === $billing['country'] ) {
            $billing['country'] = $shipping['country'];
        }

        if ( '' === $shipping['country'] ) {
            $shipping['country'] = $billing['country'];
        }

        self::set_address( $customer, 'billing', $billing, WC()->countries->get_allowed_countries() );
        self::set_address( $customer, 'shipping', $shipping, WC()->countries->get_shipping_countries() );
        $customer->save();

        if ( self::ships_elsewhere( $billing, $shipping ) ) {
            WC()->session->set( self::SESSION_ELSEWHERE_KEY, true );
        }

        self::choose_shipping_rate( $details );
        self::choose_payment_method( $details );
    }

    private static function address( $details, $side ) {
        $source  = isset( $details[$side] ) && is_array( $details[$side] ) ? $details[$side] : array();
        $address = array();

        foreach ( WC_Montonio_Cart_Capture::CUSTOMER_FIELDS as $field ) {
            $address[$field] = isset( $source[$field] ) && is_string( $source[$field] ) ? sanitize_text_field( $source[$field] ) : '';
        }

        return $address;
    }

    private static function set_address( $customer, $side, $address, $allowed_countries ) {
        foreach ( $address as $field => $value ) {
            if ( 'country' === $field || 'state' === $field || '' === $value ) {
                continue;
            }

            $setter = 'set_' . $side . '_' . $field;

            if ( method_exists( $customer, $setter ) ) {
                $customer->$setter( $value );
            }
        }

        // The defaulted state belonged to the defaulted country, so it follows the restored one: it is written
        // even when empty, to clear the stale default.
        if ( '' !== $address['country'] && isset( $allowed_countries[$address['country']] ) ) {
            $customer->{'set_' . $side . '_country'}( $address['country'] );
            $customer->{'set_' . $side . '_state'}( $address['state'] );
        }
    }

    private static function ships_elsewhere( $billing, $shipping ) {
        // A shipping side without a street, e.g. names only, is not a separate address.
        if ( '' === $shipping['address_1'] ) {
            return false;
        }

        foreach ( self::SHIP_ELSEWHERE_FIELDS as $field ) {
            if ( $billing[$field] !== $shipping[$field] ) {
                return true;
            }
        }

        return false;
    }

    // WooCommerce resets the choice whenever the session's stored rates differ from the package's, so the
    // rates for the restored address are calculated first and the choice is written after them. This request's
    // totals keep the default rate; the page render recalculates them. Only the first package is restored.
    private static function choose_shipping_rate( $details ) {
        if ( empty( $details['shipping_method_id'] ) || ! is_string( $details['shipping_method_id'] ) ) {
            return;
        }

        WC()->cart->calculate_totals();
        $packages = WC()->shipping()->get_packages();

        if ( isset( $packages[0]['rates'][$details['shipping_method_id']] ) ) {
            WC()->session->set( 'chosen_shipping_methods', array( $details['shipping_method_id'] ) );
        }
    }

    private static function choose_payment_method( $details ) {
        if ( empty( $details['payment_method'] ) || ! is_string( $details['payment_method'] ) ) {
            return;
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        if ( isset( $gateways[$details['payment_method']] ) ) {
            WC()->session->set( 'chosen_payment_method', $details['payment_method'] );
        }
    }
}

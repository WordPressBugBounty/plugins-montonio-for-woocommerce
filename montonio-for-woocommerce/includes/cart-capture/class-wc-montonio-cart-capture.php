<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Cart_Capture
 *
 * Pushes signed snapshots of block- and classic-checkout carts to Ladu's cart rescue endpoint.
 *
 * A capture stores the snapshot in a per-session transient and queues an Action Scheduler action to
 * push it. The push signs the snapshot as a JWT with the store's Montonio keys and posts it to the
 * configured endpoint. An "update" snapshot describes a cart still in checkout; a "placed" one tells
 * Ladu the cart became an order.
 */
class WC_Montonio_Cart_Capture {
    const OPTION_ENABLED       = 'montonio_cart_capture_enabled';
    const OPTION_ENDPOINT      = 'montonio_cart_capture_endpoint';
    const ACTION_HOOK          = 'montonio_cart_capture_push';
    const ACTION_GROUP         = 'montonio-cart-capture';
    // Ahead of Action Scheduler's default of 10, so a backlog of other plugins' jobs does not hold up a snapshot.
    const ACTION_PRIORITY      = 5;
    const SESSION_FP_KEY       = 'montonio_cart_capture_fp';
    // Set once the shopper reaches checkout and cleared when the order is placed: cart changes are captured only in between.
    const SESSION_CHECKOUT_KEY = 'montonio_cart_capture_checkout';
    // Set by keep_guest_key() when a guest logs in and cleared when the order is placed.
    const SESSION_CART_KEY     = 'montonio_cart_capture_key';
    // The rescue email's button opens WooCommerce's checkout-link, which 10.0.0 introduced.
    const MIN_WC_VERSION       = '10.0.0';
    const MAX_ATTEMPTS         = 5;
    const BACKOFF_BASE_SECONDS = 60;
    // Ladu answers in milliseconds; a slow Ladu must not hold Action Scheduler's queue, which other plugins' jobs share.
    const SEND_TIMEOUT_SECONDS = 3;
    const CUSTOMER_FIELDS      = array( 'first_name', 'last_name', 'company', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

    // The classic checkout fields WooCommerce does not refresh the order review for.
    const UNWATCHED_CHECKOUT_FIELDS = array( 'billing_email', 'billing_first_name', 'billing_last_name', 'billing_company', 'billing_phone', 'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_phone' );

    const REQUEST_IGNORE           = 'ignore';
    const REQUEST_CAPTURE          = 'capture';
    const REQUEST_MARK_AND_CAPTURE = 'mark_and_capture';

    /**
     * Registers the capture and push hooks.
     *
     * @return void
     */
    public static function init() {
        add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'capture_after_request' ), 10, 3 );
        add_action( 'template_redirect', array( __CLASS__, 'capture_checkout_page' ) );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'capture_placed' ), 20, 1 );
        add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'capture_order_review' ) );
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'capture_placed_classic' ), 20, 3 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_classic_checkout_script' ), 20 );
        add_filter( 'woocommerce_abandoned_cart_recovery_suppress', array( __CLASS__, 'suppress_woocommerce_recovery_email' ) );
        add_action( 'woocommerce_guest_session_to_user_id', array( __CLASS__, 'keep_guest_key' ) );
        add_action( self::ACTION_HOOK, array( __CLASS__, 'push' ), 10, 1 );
    }

    /**
     * Captures the cart after a Store API request from the Blocks checkout.
     *
     * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response The response, returned unchanged.
     * @param array                                            $handler  The route handler.
     * @param WP_REST_Request                                  $request  The request.
     * @return mixed The response.
     */
    public static function capture_after_request( $response, $handler, $request ) {
        try {
            if ( ! $request instanceof WP_REST_Request ) {
                return $response;
            }

            $in_checkout = WC()->session && WC()->session->get( self::SESSION_CHECKOUT_KEY );
            $decision    = self::request_decision( (string) $request->get_route(), (string) $request->get_method(), (bool) $in_checkout );

            if ( self::REQUEST_IGNORE === $decision || ! self::is_active() ) {
                return $response;
            }

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( $response instanceof WP_HTTP_Response && $response->get_status() >= 400 ) {
                return $response;
            }

            if ( self::REQUEST_MARK_AND_CAPTURE === $decision ) {
                self::mark_checkout();
            }

            self::capture_update();
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture failed: ' . $e->getMessage() );
        }

        return $response;
    }

    /**
     * Captures the cart when the checkout page loads, in both checkouts.
     *
     * @return void
     */
    public static function capture_checkout_page() {
        try {
            if ( ! self::is_checkout_page() || ! self::is_active() ) {
                return;
            }

            self::mark_checkout();
            self::capture_update();
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (checkout page) failed: ' . $e->getMessage() );
        }
    }

    // While Ladu's cart rescue emails this store's shoppers, WooCommerce's own recovery email would be a second reminder.
    public static function suppress_woocommerce_recovery_email( $suppress ) {
        try {
            return $suppress || self::is_active();
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (recovery email check) failed: ' . $e->getMessage() );
            return $suppress;
        }
    }

    /**
     * Classic checkout: update_order_review has recalculated the cart, or found it empty. The shopper's
     * email and name are read from the posted form, since WooCommerce copies only the address fields
     * onto the customer.
     *
     * @param array $fragments The order review fragments, returned unchanged.
     * @return array The fragments.
     */
    public static function capture_order_review( $fragments ) {
        try {
            if ( ! self::is_active() ) {
                return $fragments;
            }

            // WC_AJAX::update_order_review, this filter's only caller, has already run check_ajax_referer; each field is sanitised below.
            $raw    = isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $posted = array();
            parse_str( $raw, $posted );

            $email      = isset( $posted['billing_email'] ) && is_string( $posted['billing_email'] ) ? sanitize_email( $posted['billing_email'] ) : '';
            $first_name = isset( $posted['billing_first_name'] ) && is_string( $posted['billing_first_name'] ) ? sanitize_text_field( $posted['billing_first_name'] ) : '';

            self::mark_checkout();
            self::capture_update( array(
                'email'      => is_email( $email ) ? $email : '',
                'first_name' => $first_name,
                'form'       => $posted
            ) );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (order review) failed: ' . $e->getMessage() );
        }

        return $fragments;
    }

    /**
     * Classic checkout: captures the placed order.
     *
     * @param int           $order_id    The order id.
     * @param array         $posted_data The posted checkout data.
     * @param WC_Order|null $order       The order.
     * @return void
     */
    public static function capture_placed_classic( $order_id, $posted_data = array(), $order = null ) {
        try {
            if ( ! self::is_active() ) {
                return;
            }

            self::capture_placed( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (placed) failed for order ' . absint( $order_id ) . ': ' . $e->getMessage() );
        }
    }

    /**
     * WooCommerce refreshes the classic checkout only for address, shipping method and opt-in fields, so
     * a change to the email, a name, the company or the phone asks for an order review, which captures it.
     *
     * @return void
     */
    public static function enqueue_classic_checkout_script() {
        try {
            if ( ! self::is_checkout_page() || ! self::is_active() || ! wp_script_is( 'wc-checkout', 'enqueued' ) || WC_Montonio_Helper::is_checkout_block_rendering() ) {
                return;
            }

            $selector = implode( ', ', array_map( function ( $field ) {
                return 'form.checkout :input[name="' . $field . '"]';
            }, self::UNWATCHED_CHECKOUT_FIELDS ) );

            wp_add_inline_script(
                'wc-checkout',
                "jQuery( function( $ ) { $( document.body ).on( 'change', '" . $selector . "', function() { $( document.body ).trigger( 'update_checkout' ); } ); } );"
            );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (checkout script) failed: ' . $e->getMessage() );
        }
    }

    /**
     * Stores a placed snapshot for the order and queues its push. Ladu closes the cart on it, so the
     * shopper is not sent a rescue email for a cart they bought.
     *
     * @param WC_Order $order The placed order.
     * @return void
     */
    public static function capture_placed( $order ) {
        $order_id = 0;

        try {
            if ( ! self::is_active() || ! $order instanceof WC_Order ) {
                return;
            }

            $order_id    = (int) $order->get_id();
            $session_key = self::session_key();
            $email       = (string) $order->get_billing_email();

            if ( '' === $session_key || '' === $email ) {
                return;
            }

            $now            = self::timestamp();
            $payment_method = (string) $order->get_payment_method();

            $snapshot = array(
                'kind'             => 'placed',
                'order_id'         => $order_id,
                'placed_at'        => $now,
                'session_key'      => $session_key,
                'captured_at'      => $now,
                // The placed order is the draft's successor, so Ladu matches it to the captured cart either way.
                'draft_order_id'   => $order_id,
                'email'            => $email,
                'first_name'       => (string) $order->get_billing_first_name(),
                'billing_country'  => (string) $order->get_billing_country(),
                'shipping_country' => (string) $order->get_shipping_country(),
                'currency'         => (string) $order->get_currency(),
                'total'            => self::money( $order->get_total() ),
                'shipping_total'   => self::money( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() ),
                'shipping_method'  => self::order_shipping_method( $order ),
                'payment_method'   => '' === $payment_method ? null : $payment_method,
                'coupons'          => array_values( $order->get_coupon_codes() ),
                'lines'            => self::order_lines( $order ),
                'plugin_version'   => WC_MONTONIO_PLUGIN_VERSION,
                'customer'         => self::customer_from( $order, array() )
            );

            // Kept apart from the update transient so a later update capture on this session cannot overwrite it before it is sent.
            self::store( self::placed_transient_key( $session_key ), $snapshot );

            // The cart is an order now: an update not yet sent is stale, and the same cart started again must be captured anew.
            delete_transient( self::update_transient_key( $session_key ) );
            WC()->session->set( self::SESSION_CHECKOUT_KEY, null );
            WC()->session->set( self::SESSION_FP_KEY, null );
            WC()->session->set( self::SESSION_CART_KEY, null );

            self::ensure_pending_action( $session_key );
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (placed) failed for order ' . $order_id . ': ' . $e->getMessage() );
        }
    }

    /**
     * Logging in, and creating an account at checkout, moves a guest's session to the user id. A guest
     * whose cart Ladu already holds keeps that cart's key until the order is placed. Only a login keeps
     * it: WooCommerce's session link copies a guest's session, and a guest's session never holds the key.
     *
     * @param string $guest_id The guest's former session id.
     * @return void
     */
    public static function keep_guest_key( $guest_id ) {
        try {
            if ( WC()->session && WC()->session->get( self::SESSION_FP_KEY ) ) {
                WC()->session->set( self::SESSION_CART_KEY, (string) $guest_id );
            }
        } catch ( Throwable $e ) {
            WC_Montonio_Logger::log( 'Cart capture (login) failed: ' . $e->getMessage() );
        }
    }

    /**
     * Action Scheduler callback: sends the session's stored snapshots once and, when a later try may
     * succeed, schedules it, up to MAX_ATTEMPTS sends in all. The attempt count is kept in a transient,
     * not the action's arguments, so a pending retry is the pending push a later capture finds.
     *
     * @param string $session_key The WooCommerce session's customer id.
     * @return void
     * @throws Exception When the snapshots are given up on, so Action Scheduler marks the action failed.
     */
    public static function push( $session_key ) {
        $session_key = (string) $session_key;

        if ( '' === $session_key || ! self::is_active() ) {
            return;
        }

        $attempts_key = self::attempts_transient_key( $session_key );
        $failures     = self::deliver( $session_key );

        if ( empty( $failures ) ) {
            delete_transient( $attempts_key );
            return;
        }

        $attempt   = (int) get_transient( $attempts_key );
        $retryable = array_filter( $failures, function ( $failure ) {
            return $failure['retryable'];
        } );
        $message   = 'Cart capture push for ' . self::session_ref( $session_key ) . ' failed on attempt ' . ( $attempt + 1 ) . ' of ' . self::MAX_ATTEMPTS . ': ' . implode( '; ', wp_list_pluck( $failures, 'message' ) );

        // deliver() has already logged and dropped each snapshot Ladu refused for good.
        if ( empty( $retryable ) ) {
            delete_transient( $attempts_key );
            throw new Exception( $message . '; not retried' );
        }

        $delay = self::retry_delay( $attempt );

        if ( null === $delay ) {
            foreach ( $retryable as $failure ) {
                self::delete_if_unchanged( $failure['key'], $failure['fp'] );
            }

            delete_transient( $attempts_key );
            WC_Montonio_Logger::log( $message . '; giving up' );
            throw new Exception( $message . '; giving up' );
        }

        // A capture during this send has already queued the next push, which is the retry then.
        if ( ! self::has_pending_action( $session_key ) && ! as_schedule_single_action( time() + $delay, self::ACTION_HOOK, array( $session_key ), self::ACTION_GROUP, false, self::ACTION_PRIORITY ) ) {
            WC_Montonio_Logger::log( $message . '; scheduling the retry failed' );
            throw new Exception( $message . '; scheduling the retry failed' );
        }

        set_transient( $attempts_key, $attempt + 1, DAY_IN_SECONDS );
        WC_Montonio_Logger::log( $message . '; retrying in ' . $delay . 's' );
    }

    /**
     * Whether capture is switched on and able to send: WooCommerce MIN_WC_VERSION or later, an allowed
     * endpoint, Montonio API keys and Action Scheduler. A switched-on capture that cannot send logs why,
     * at most once a day.
     *
     * @return bool
     */
    public static function is_active() {
        if ( 'yes' !== get_option( self::OPTION_ENABLED, 'no' ) ) {
            return false;
        }

        $reason = self::inert_reason();

        if ( null !== $reason ) {
            self::log_inert( $reason );
            return false;
        }

        return true;
    }

    /**
     * Whether snapshots may be sent to $url: https on montonio.com or a subdomain of it, and ngrok
     * tunnels on a local or development site. Ladu's ValidateCartEndpointBase mirrors this list.
     *
     * @param mixed $url The configured endpoint.
     * @return bool
     */
    public static function endpoint_is_allowed( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }

        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        if ( 'https' !== strtolower( $parts['scheme'] ) ) {
            return false;
        }

        $host = strtolower( $parts['host'] );

        if ( 'montonio.com' === $host ) {
            return true;
        }

        $suffixes = array( '.montonio.com' );

        // Tunnels to a developer's local Ladu; a production store never points at one.
        if ( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
            $suffixes = array_merge( $suffixes, array( '.ngrok-free.app', '.ngrok-free.dev', '.ngrok.app' ) );
        }

        foreach ( $suffixes as $suffix ) {
            if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the request is the checkout form: the checkout page, but not its order-received or order-pay endpoint.
     *
     * @return bool
     */
    public static function is_checkout_page() {
        return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
    }

    /**
     * Whether a later try at a Ladu request may succeed. Ladu answers 400, 401, 404 and 413 for a request
     * no retry can fix; 0 is a transport error.
     *
     * @param int $status The HTTP status, or 0 when no response arrived.
     * @return bool
     */
    public static function is_retryable_status( $status ) {
        $status = (int) $status;

        return 0 === $status || 408 === $status || 429 === $status || $status >= 500;
    }

    /**
     * The current UTC time in RFC 3339 with microseconds, the precision Ladu stores.
     *
     * @return string
     */
    public static function timestamp() {
        // One clock read: separate reads for seconds and microseconds can straddle a second and move the time back.
        $t = microtime( true );

        return gmdate( 'Y-m-d\TH:i:s', (int) $t ) . sprintf( '.%06dZ', (int) ( ( $t - floor( $t ) ) * 1e6 ) );
    }

    /**
     * A Store API checkout read or update means the shopper is on the Blocks checkout, so it marks the
     * session and captures. Cart changes (items, coupons, shipping rate) are captured only once marked,
     * so carts that never reached checkout are not sent. WooCommerce also serves every route without the v1.
     */
    private static function request_decision( $route, $method, $in_checkout ) {
        $method = strtoupper( $method );

        if ( 1 === preg_match( '#^/wc/store(?:/v1)?/checkout$#', $route ) ) {
            return 'PUT' === $method || 'GET' === $method ? self::REQUEST_MARK_AND_CAPTURE : self::REQUEST_IGNORE;
        }

        if ( 1 === preg_match( '#^/wc/store(?:/v1)?/cart/#', $route ) && 'GET' !== $method && $in_checkout ) {
            return self::REQUEST_CAPTURE;
        }

        return self::REQUEST_IGNORE;
    }

    private static function retry_delay( $attempt ) {
        if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
            return null;
        }

        return self::BACKOFF_BASE_SECONDS * ( 2 ** $attempt );
    }

    /**
     * Sends the stored placed and update snapshots once. A snapshot is deleted once Ladu accepts it, or
     * refuses it for good, unless a capture replaced it during the send.
     *
     * @return array[] Per failed snapshot kind: the message, whether a later try may succeed, and its transient key and fingerprint.
     */
    private static function deliver( $session_key ) {
        $endpoint = (string) get_option( self::OPTION_ENDPOINT, '' );
        $keys     = array(
            'placed' => self::placed_transient_key( $session_key ),
            'update' => self::update_transient_key( $session_key )
        );
        $failures = array();
        $found    = false;

        foreach ( $keys as $kind => $key ) {
            $entry = get_transient( $key );

            if ( ! self::is_entry( $entry ) ) {
                continue;
            }

            $found = true;

            try {
                self::send( $endpoint, $entry['snapshot'] );
                self::delete_if_unchanged( $key, $entry['fp'] );
            } catch ( Throwable $e ) {
                $retryable = self::is_retryable_status( $e->getCode() );

                if ( ! $retryable ) {
                    self::delete_if_unchanged( $key, $entry['fp'] );
                    WC_Montonio_Logger::log( 'Ladu refused the ' . $kind . ' snapshot for ' . self::session_ref( $session_key ) . self::order_ref( $entry['snapshot'] ) . ', so it is dropped: ' . $e->getMessage() );
                }

                $failures[$kind] = array(
                    'message'   => $kind . ': ' . $e->getMessage(),
                    'retryable' => $retryable,
                    'key'       => $key,
                    'fp'        => $entry['fp']
                );
            }
        }

        // Routine when a push queued during a running one finds that the running one already sent the snapshot.
        if ( ! $found ) {
            WC_Montonio_Logger::log( 'Cart capture push for ' . self::session_ref( $session_key ) . ' found no snapshot to send.' );
        }

        return $failures;
    }

    private static function delete_if_unchanged( $transient_key, $fp ) {
        $current = get_transient( $transient_key );

        if ( self::is_entry( $current ) && $current['fp'] === $fp ) {
            delete_transient( $transient_key );
        }
    }

    private static function inert_reason() {
        if ( ! WC_Montonio_Helper::is_wc_at_least( self::MIN_WC_VERSION ) ) {
            return 'WooCommerce is older than ' . self::MIN_WC_VERSION . ', so a rescue email cannot restore the cart';
        }

        if ( ! self::endpoint_is_allowed( get_option( self::OPTION_ENDPOINT, '' ) ) ) {
            return 'endpoint is not an allowed https URL';
        }

        if ( ! WC_Montonio_Helper::has_api_keys() ) {
            return 'Montonio API keys are missing';
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
            return 'Action Scheduler is not available';
        }

        return null;
    }

    private static function log_inert( $reason ) {
        WC_Montonio_Logger::warning_once( 'cc_inert_' . md5( $reason ), 'Cart capture is enabled but inactive: ' . $reason . '.' );
    }

    private static function mark_checkout() {
        if ( WC()->session && ! WC()->session->get( self::SESSION_CHECKOUT_KEY ) ) {
            WC()->session->set( self::SESSION_CHECKOUT_KEY, true );
        }
    }

    private static function capture_update( $posted = array() ) {
        $snapshot = self::build_update_snapshot( $posted );

        if ( null === $snapshot ) {
            return;
        }

        // One capture per change of the cart or the shopper's details; the capture time alone is no change.
        $fp = self::fingerprint( $snapshot );

        if ( WC()->session->get( self::SESSION_FP_KEY ) === $fp ) {
            return;
        }

        $snapshot['captured_at'] = self::timestamp();

        self::store( self::update_transient_key( $snapshot['session_key'] ), $snapshot );
        self::ensure_pending_action( $snapshot['session_key'] );
        WC()->session->set( self::SESSION_FP_KEY, $fp );
    }

    /**
     * @param array $posted Classic checkout only: 'email' and 'first_name' typed into the form, and
     *                      'form', the whole posted checkout form.
     */
    private static function build_update_snapshot( $posted = array() ) {
        if ( ! WC()->session || ! WC()->cart || ! WC()->customer ) {
            return null;
        }

        $session_key = self::session_key();
        $email       = ! empty( $posted['email'] ) ? $posted['email'] : (string) WC()->customer->get_billing_email();

        if ( '' === $session_key || '' === $email ) {
            return null;
        }

        $cart           = WC()->cart;
        $payment_method = WC()->session->get( 'chosen_payment_method' );
        $lines          = array();

        foreach ( $cart->get_cart() as $item ) {
            $line = array(
                'product_id'   => (int) ( $item['product_id'] ?? 0 ),
                'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
                'quantity'     => (int) ( $item['quantity'] ?? 0 ),
                'line_total'   => self::money( (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ) )
            );

            $rec = class_exists( 'WC_Montonio_Recommendation_Tagging' ) ? WC_Montonio_Recommendation_Tagging::snapshot_rec( $item ) : null;

            if ( null !== $rec ) {
                $line['rec'] = $rec;
            }

            if ( self::is_valid_line( $line ) ) {
                $lines[] = $line;
            }
        }

        return array(
            'kind'             => 'update',
            'session_key'      => $session_key,
            'draft_order_id'   => (int) WC()->session->get( 'store_api_draft_order' ),
            'email'            => $email,
            'first_name'       => ! empty( $posted['first_name'] ) ? $posted['first_name'] : (string) WC()->customer->get_billing_first_name(),
            'billing_country'  => (string) WC()->customer->get_billing_country(),
            'shipping_country' => (string) WC()->customer->get_shipping_country(),
            'currency'         => (string) get_woocommerce_currency(),
            'total'            => self::money( $cart->get_total( 'edit' ) ),
            'shipping_total'   => self::money( (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() ),
            'shipping_method'  => self::cart_shipping_method( $cart ),
            'payment_method'   => is_string( $payment_method ) && '' !== $payment_method ? $payment_method : null,
            'coupons'          => array_values( $cart->get_applied_coupons() ),
            'lines'            => $lines,
            'plugin_version'   => WC_MONTONIO_PLUGIN_VERSION,
            'customer'         => self::customer_from( WC()->customer, isset( $posted['form'] ) && is_array( $posted['form'] ) ? $posted['form'] : array() )
        );
    }

    /**
     * @param WC_Customer|WC_Order $source Read for every field the form did not post.
     * @param array                $form   Classic checkout only: the posted checkout form.
     * @return array The 'billing' and 'shipping' addresses, each keyed by CUSTOMER_FIELDS.
     */
    private static function customer_from( $source, $form ) {
        $addresses = array();

        foreach ( array( 'billing', 'shipping' ) as $side ) {
            foreach ( self::CUSTOMER_FIELDS as $field ) {
                $key    = $side . '_' . $field;
                $getter = 'get_' . $key;

                if ( isset( $form[$key] ) && is_string( $form[$key] ) ) {
                    $value = sanitize_text_field( $form[$key] );
                } elseif ( method_exists( $source, $getter ) ) {
                    $value = (string) $source->$getter();
                } else {
                    $value = '';
                }

                $addresses[$side][$field] = $value;
            }
        }

        // WooCommerce ships to the billing address when the classic form's ship-elsewhere box is unticked.
        if ( ! empty( $form ) && empty( $form['ship_to_different_address'] ) ) {
            $addresses['shipping'] = $addresses['billing'];
        }

        return $addresses;
    }

    // Only the first package's chosen rate is captured, here and in WC_Montonio_Cart_Restore.
    private static function cart_shipping_method( $cart ) {
        if ( ! $cart->needs_shipping() ) {
            return null;
        }

        $chosen  = WC()->session->get( 'chosen_shipping_methods' );
        $rate_id = is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '';

        if ( '' === $rate_id ) {
            return null;
        }

        $title    = '';
        $packages = WC()->shipping()->get_packages();

        if ( isset( $packages[0]['rates'][$rate_id] ) && $packages[0]['rates'][$rate_id] instanceof WC_Shipping_Rate ) {
            $title = (string) $packages[0]['rates'][$rate_id]->get_label();
        }

        // Packages may not be calculated yet on the checkout page load (template_redirect); the method's title can differ from its rate label.
        if ( '' === $title ) {
            $title = self::method_instance_title( $rate_id );
        }

        return array( 'id' => $rate_id, 'title' => $title );
    }

    private static function method_instance_title( $rate_id ) {
        $parts       = explode( ':', $rate_id );
        $instance_id = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

        if ( $instance_id < 1 ) {
            return '';
        }

        $shipping_method = WC_Shipping_Zones::get_shipping_method( $instance_id );

        return $shipping_method instanceof WC_Shipping_Method ? (string) $shipping_method->get_title() : '';
    }

    private static function order_shipping_method( $order ) {
        foreach ( $order->get_shipping_methods() as $item ) {
            return array(
                'id'    => $item->get_method_id() . ':' . $item->get_instance_id(),
                'title' => (string) $item->get_name()
            );
        }

        return null;
    }

    private static function order_lines( $order ) {
        $lines = array();

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $line = array(
                'product_id'   => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity'     => (int) $item->get_quantity(),
                'line_total'   => self::money( (float) $item->get_total() + (float) $item->get_total_tax() )
            );

            if ( self::is_valid_line( $line ) ) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    // Ladu refuses a whole snapshot for one line without a product, e.g. an order item whose product was deleted.
    private static function is_valid_line( $line ) {
        return $line['product_id'] > 0 && $line['quantity'] > 0 && $line['variation_id'] >= 0;
    }

    private static function store( $transient_key, $snapshot ) {
        if ( ! set_transient( $transient_key, array( 'snapshot' => $snapshot, 'fp' => self::fingerprint( $snapshot ) ), DAY_IN_SECONDS ) ) {
            throw new Exception( 'storing the snapshot failed' );
        }
    }

    private static function ensure_pending_action( $session_key ) {
        if ( ! self::has_pending_action( $session_key ) && ! as_enqueue_async_action( self::ACTION_HOOK, array( $session_key ), self::ACTION_GROUP, false, self::ACTION_PRIORITY ) ) {
            throw new Exception( 'enqueueing the push failed' );
        }
    }

    // Pending only: a running action may already have read the transient, so a change arriving mid-run must enqueue.
    private static function has_pending_action( $session_key ) {
        $pending = as_get_scheduled_actions(
            array(
                'hook'     => self::ACTION_HOOK,
                'args'     => array( $session_key ),
                'group'    => self::ACTION_GROUP,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 1
            ),
            'ids'
        );

        return ! empty( $pending );
    }

    /**
     * @throws Exception On a failed send, with the HTTP status as its code (0 for a transport error).
     */
    private static function send( $endpoint, $snapshot ) {
        $jwt = WC_Montonio_Helper::create_jwt_token( array_merge( $snapshot, array( 'exp' => time() + 600 ) ) );

        $response = wp_remote_post( $endpoint, array(
            'timeout' => self::SEND_TIMEOUT_SECONDS,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'data' => $jwt ) )
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'transport error: ' . $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );

        if ( $status < 200 || $status > 299 ) {
            $body = substr( (string) preg_replace( '/\s+/', ' ', (string) wp_remote_retrieve_body( $response ) ), 0, 200 );
            throw new Exception( 'HTTP ' . $status . ': ' . $body, $status );
        }
    }

    private static function session_key() {
        if ( ! WC()->session ) {
            return '';
        }

        if ( ! method_exists( WC()->session, 'get_customer_id' ) ) {
            self::log_inert( 'the WooCommerce session has no customer id' );
            return '';
        }

        $kept = WC()->session->get( self::SESSION_CART_KEY );

        if ( is_string( $kept ) && '' !== $kept ) {
            return $kept;
        }

        return (string) WC()->session->get_customer_id();
    }

    // Names a session in logs without its customer id, matching the transient keys.
    private static function session_ref( $session_key ) {
        return 'session ' . substr( md5( $session_key ), 0, 8 );
    }

    private static function order_ref( $snapshot ) {
        return ! empty( $snapshot['order_id'] ) ? ' (order ' . (int) $snapshot['order_id'] . ')' : '';
    }

    private static function fingerprint( $snapshot ) {
        unset( $snapshot['captured_at'] );

        return md5( (string) wp_json_encode( $snapshot ) );
    }

    private static function money( $value ) {
        return (string) wc_format_decimal( (float) $value, wc_get_price_decimals() );
    }

    private static function is_entry( $entry ) {
        return is_array( $entry ) && isset( $entry['snapshot'], $entry['fp'] ) && is_array( $entry['snapshot'] );
    }

    private static function update_transient_key( $session_key ) {
        return 'montonio_cc_' . md5( $session_key );
    }

    private static function placed_transient_key( $session_key ) {
        return 'montonio_cc_placed_' . md5( $session_key );
    }

    private static function attempts_transient_key( $session_key ) {
        return 'montonio_cc_attempts_' . md5( $session_key );
    }
}
